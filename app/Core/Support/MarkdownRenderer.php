<?php

declare(strict_types=1);

namespace Leantime\Core\Support;

use InvalidArgumentException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListData;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalink;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\Node\TableOfContents;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Util\RegexHelper;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Renders Markdown into HTML that Leantime's theme can style.
 *
 * Core's MarkdownHelper goes the other direction (structured data becomes a
 * Markdown string), so this class owns the rendering direction. It exists so
 * features that display author-written Markdown - and plugins that ship
 * documents, like TeamDocs - share one hardened configuration instead of each
 * hand-rolling a converter.
 *
 * The configuration is deliberately safe by default:
 *  - `html_input: escape` means raw HTML in the source is shown as text and
 *    never parsed, so an author cannot inject markup or script.
 *  - `allow_unsafe_links: false` drops `javascript:`, `data:` and friends.
 *
 * Two details matter for anyone touching this class:
 *  - Overrides passed to the constructor are merged *over* the defaults. A
 *    caller can loosen `html_input`, but nothing is lost by omitting it.
 *  - `toc` and `documents` are per-call options, not constructor config, so a
 *    caller that caches rendered output must include them in its cache key.
 *
 * Usage:
 *
 *     $renderer = app(MarkdownRenderer::class);
 *     $html = $renderer->render($markdown);
 *     $html = $renderer->render($markdown, ['toc' => true]);
 */
final class MarkdownRenderer
{
    /** Alert types that become callouts, matching GitHub's alert syntax. */
    private const ALERT_TYPES = ['note', 'tip', 'important', 'warning', 'caution'];

    private MarkdownParser $parser;

    private HtmlRenderer $htmlRenderer;

    private string $fragmentPrefix;

    private int $tocMinLevel;

    private int $tocMaxLevel;

    /**
     * @param  array<string, mixed>  $config  Overrides for the CommonMark environment, merged over
     *                                        the safe defaults in {@see defaultConfig()}.
     *
     * @throws InvalidArgumentException When a value in the configuration is rejected by league/commonmark.
     */
    public function __construct(array $config = [])
    {
        $config = array_replace_recursive(self::defaultConfig(), $config);

        // Read the settings this class needs before handing the array to the
        // environment, which validates it.
        $this->fragmentPrefix = (string) $config['heading_permalink']['fragment_prefix'];
        $this->tocMinLevel = (int) $config['table_of_contents']['min_heading_level'];
        $this->tocMaxLevel = (int) $config['table_of_contents']['max_heading_level'];

        $environment = $this->buildEnvironment($config);

        // MarkdownParser and HtmlRenderer are `final` and can only be reached
        // through MarkdownConverter when going through `convert()`. Rendering
        // happens in two steps instead (parse, post-process, render) so this
        // class can rewrite the AST in between.
        $this->parser = new MarkdownParser($environment);
        $this->htmlRenderer = new HtmlRenderer($environment);
    }

    /**
     * Renders Markdown to a theme-styled HTML fragment.
     *
     * The output carries no wrapper element - callers place it inside their own
     * container, which must have the `md-body` class for the stylesheet to apply.
     *
     * @param  string  $markdown  Markdown source. Any UTF-8 text is accepted; raw HTML is escaped.
     * @param  array{
     *     toc?: bool,
     *     toc_class?: string,
     *     documents?: array<string, array{title?: string, url?: string}>,
     * }  $options  Per-call options. `toc` prepends a table of contents built from headings between
     *              the configured min/max level. `documents` maps a link target as written in the
     *              Markdown (e.g. `docs/leantime-zh-guide.md`) to a title and URL, so links between
     *              in-repo documents can point at where the document lives at runtime.
     * @return string Rendered HTML. An empty document renders as an empty string.
     *
     * @throws InvalidArgumentException When the Markdown is not valid UTF-8.
     */
    public function render(string $markdown, array $options = []): string
    {
        if (! mb_check_encoding($markdown, 'UTF-8')) {
            throw new InvalidArgumentException('Markdown source must be UTF-8 encoded.');
        }

        $document = $this->parser->parse($markdown);

        $this->markTables($document);
        $this->neutralizeUnsafeLinks($document);
        $this->convertAlerts($document);
        $this->rewriteRelativeLinks($document, $options['documents'] ?? []);

        // Runs last: it copies heading text and slugs, so it has to see the
        // headings in their final shape.
        if (($options['toc'] ?? false) && ($toc = $this->buildTableOfContents($document, (string) ($options['toc_class'] ?? 'md-toc'))) !== null) {
            $document->prependChild($toc);
        }

        return $this->htmlRenderer->renderDocument($document)->getContent();
    }

    /**
     * Returns the safe-by-default configuration.
     *
     * @return array<string, mixed>
     */
    private static function defaultConfig(): array
    {
        return [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'renderer' => [
                'block_separator' => "\n",
                'inner_separator' => "\n",
                'soft_break' => "\n",
            ],
            'heading_permalink' => [
                // Heading text doubles as the `id`, which is what internal
                // `#fragment` links and the generated TOC resolve against.
                'insert' => 'after',
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => 'md',
                'html_class' => 'md-heading-anchor',
                'symbol' => '#',
                'aria_hidden' => true,
            ],
            'table_of_contents' => [
                'min_heading_level' => 2,
                'max_heading_level' => 3,
            ],
            'footnote' => [
                'container_class' => 'md-footnotes',
                'container_add_hr' => false,
                'backref_class' => 'md-footnote-backref',
                'backref_symbol' => '↩',
            ],
        ];
    }

    /**
     * Registers the extensions this renderer relies on.
     *
     * GFM parity comes from Table, Strikethrough, TaskList and Autolink (the
     * same set as league's GithubFlavoredMarkdownConverter) plus Footnote,
     * which GFM's own spec does not cover but author-written docs use.
     * `DefaultAttributesExtension` is what makes the `attributes/*` data set by
     * the passes in this class land on the rendered elements.
     *
     * @param  array<string, mixed>  $config  Already-merged configuration.
     * @return Environment A ready-to-use environment.
     */
    private function buildEnvironment(array $config): Environment
    {
        $environment = new Environment($config);

        // Core provides the CommonMark node parsers/renderers; every other
        // extension below only adds syntax on top of it.
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TaskListExtension);
        $environment->addExtension(new AutolinkExtension);
        $environment->addExtension(new FootnoteExtension);
        $environment->addExtension(new HeadingPermalinkExtension);
        $environment->addExtension(new DefaultAttributesExtension);

        return $environment;
    }

    /**
     * Gives tables a hook the stylesheet can make scrollable.
     *
     * league's `Table` renderer cannot emit a wrapper element, so wide tables
     * are handled with `display: block; overflow-x: auto` on the table itself
     * via the `md-table` class set here.
     */
    private function markTables(Document $document): void
    {
        foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
            if ($node instanceof Table) {
                $node->data->set('attributes/class', 'md-table');
            }
        }
    }

    /**
     * Turns `> [!NOTE]` blockquotes into callouts carrying a modifier class.
     *
     * Only the marker is consumed; everything after it stays in the blockquote
     * as the callout body, so nested lists, links and emphasis inside an alert
     * survive untouched.
     */
    private function convertAlerts(Document $document): void
    {
        foreach (iterator_to_array($document->iterator()) as $node) {
            if (! $node instanceof BlockQuote || $node->firstChild() === null) {
                continue;
            }

            $paragraph = $node->firstChild();
            $text = StringContainerHelper::getChildText($paragraph);
            if (preg_match(self::alertPattern(), $text, $matches) !== 1) {
                continue;
            }

            // `Text` inlines hold plain strings, so only the node holding the
            // marker is rewritten; sibling nodes such as links are untouched.
            $this->stripFirstMatch($paragraph, self::alertPattern());

            $node->data->append('attributes/class', 'md-callout');
            $node->data->append('attributes/class', 'md-callout--' . strtolower($matches[1]));

            // `> [!NOTE]` followed by a blank line parses as its own paragraph,
            // which the marker removal leaves empty. It would still contribute
            // an empty `<p>` and its margin to the callout, so drop it - but
            // only when it has no other content, or the callout's first line
            // would disappear.
            if (trim(StringContainerHelper::getChildText($paragraph)) === '' && ! $this->containsElement($paragraph)) {
                $paragraph->detach();
            }
        }
    }

    /**
     * Whether $node is the only child of $parent other than whitespace.
     */
    private function isOnlyChild(Node $node, Node $parent): bool
    {
        foreach ($parent->children() as $sibling) {
            if ($sibling === $node) {
                continue;
            }

            if (! $sibling instanceof AbstractStringContainer || trim($sibling->getLiteral()) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Pattern matching the `[!TYPE]` marker at the start of an alert blockquote.
     */
    private static function alertPattern(): string
    {
        return '/^\s*\[!(' . implode('|', self::ALERT_TYPES) . ')\]\h*\n?/iu';
    }

    /**
     * Whether any descendant of $node is a non-text element (link, image, code…).
     *
     * Used to decide whether a paragraph is *only* whitespace before dropping it.
     */
    private function containsElement(Node $node): bool
    {
        foreach ($node->children() as $child) {
            if (! $child instanceof AbstractStringContainer || $this->containsElement($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces unsafe link destinations with plain text, mirroring league's
     * `LinkRenderer` behaviour.
     *
     * Without this, league renders the link as an `<a>` with no `href` at all,
     * which reads as a working link in the document but does nothing. Keeping
     * the label as text makes the dropped link visible, and leaves no `<a>` for
     * a stylesheet or script to act on.
     */
    private function neutralizeUnsafeLinks(Document $document): void
    {
        foreach (iterator_to_array($document->iterator()) as $node) {
            if (! $node instanceof Link || ! RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
                continue;
            }

            // Children are moved into a new paragraph rather than kept in a new
            // `<a>`: an anchor without an `href` reads as a working link but
            // does nothing, and leaves an `<a>` for a stylesheet or script to
            // act on. Moving (not cloning) the children keeps emphasis, code
            // spans and line breaks inside the label intact.
            $label = new Paragraph;
            foreach (iterator_to_array($node->children()) as $child) {
                $label->appendChild($child);
            }

            // A link that sits alone in its own paragraph - the common case, as
            // in a bare URL on its own line - leaves that paragraph empty once
            // the link is gone. Replace it outright instead of nesting, which
            // would emit `<p><p>…</p></p>`.
            $parent = $node->parent();
            if ($parent instanceof Paragraph && $this->isOnlyChild($node, $parent)) {
                // A link alone in its own paragraph - the common case, as in a
                // bare URL on its own line - leaves that paragraph empty once
                // the link is gone. Replace it outright instead of nesting,
                // which would emit `<p><p>…</p></p>`.
                $parent->replaceWith($label);

                continue;
            }

            // Inline within a sentence: swap just the anchor for its label.
            // Wrapping it in a paragraph here would nest block elements inside
            // the parent paragraph.
            foreach (iterator_to_array($label->children()) as $child) {
                $node->insertBefore($child);
            }

            $node->detach();
        }
    }

    /**
     * Points links at in-repo Markdown files at their runtime location.
     *
     * Documentation written for the repository links to its siblings by path
     * (`docs/leantime-zh-guide.md`). That path does not resolve inside the
     * application, so callers pass a `documents` map keyed by the written
     * target. Links with no entry are left exactly as written - this never
     * invents a URL, and external links are never rewritten.
     *
     * @param  array<string, array{title?: string, url?: string}>  $documents  Keyed by the link target as written.
     */
    private function rewriteRelativeLinks(Document $document, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $byTarget = [];
        foreach ($documents as $target => $info) {
            $url = (string) ($info['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $byTarget[self::normalizeLinkTarget((string) $target)] = [
                'url' => $url,
                'title' => (string) ($info['title'] ?? ''),
            ];
        }

        foreach (iterator_to_array($document->iterator()) as $node) {
            if (! $node instanceof Link) {
                continue;
            }

            $destination = $node->getUrl();
            if (self::isAbsoluteUrl($destination)) {
                continue;
            }

            $key = self::normalizeLinkTarget($destination);
            if (! isset($byTarget[$key])) {
                continue;
            }

            $node->setUrl($byTarget[$key]['url']);
            $node->data->set('attributes/class', 'md-cross-doc-link');
            $node->data->set('attributes/data-md-document', $key);

            // A bare `[text](docs/other.md)` keeps its text, but an autolink or
            // an empty label would render as the raw path, so give it a title.
            if ($byTarget[$key]['title'] !== '' && ! self::hasVisibleLabel($node)) {
                $node->appendChild(new Text($byTarget[$key]['title']));
            }
        }
    }

    /**
     * Builds a table of contents from the document's headings.
     *
     * Hand-rolled instead of using league's `TableOfContentsExtension`: that
     * extension only recognises headings carrying a `HeadingPermalink` child and
     * its builder is `final`, so it cannot be adapted. Walking the headings
     * directly keeps TOC generation independent of the permalink insertion mode.
     *
     * @return TableOfContents|null Null when the document has no headings in range.
     */
    private function buildTableOfContents(Document $document, string $cssClass): ?TableOfContents
    {
        $listData = new ListData;
        $listData->type = ListBlock::TYPE_BULLET;
        $toc = new TableOfContents($listData);
        // Rendered lists default to loose, which wraps every entry in a `<p>`
        // and pulls in the body's paragraph margins. A table of contents wants
        // tight entries.
        $toc->setTight(true);

        /** @var array<int, ListItem> $lastItemPerLevel */
        $lastItemPerLevel = [];

        foreach ($document->iterator(NodeIterator::FLAG_BLOCKS_ONLY) as $node) {
            if (! $node instanceof Heading) {
                continue;
            }

            $level = $node->getLevel();
            if ($level < $this->tocMinLevel || $level > $this->tocMaxLevel) {
                continue;
            }

            $anchor = null;
            foreach ($node->children() as $child) {
                if ($child instanceof HeadingPermalink) {
                    $anchor = $child;
                    break;
                }
            }

            // Without a permalink there is nothing to link to; skipping beats
            // emitting a dead anchor.
            if ($anchor === null) {
                continue;
            }

            $link = new Link(
                '#'.$this->fragmentPrefix.'-'.$anchor->getSlug(),
                StringContainerHelper::getChildText($node, [HtmlInline::class]),
            );

            $item = new ListItem($listData);
            $item->appendChild($link);

            // Nest under the nearest preceding heading shallower than this one.
            // The nested list is a sibling `<ul>` inside that heading's `<li>`:
            // a `<li>` may contain a list, but appending the item itself would
            // emit `<li><li>…</li></li>`, which is invalid HTML.
            $parentItem = null;
            for ($parentLevel = $level - 1; $parentLevel >= $this->tocMinLevel; $parentLevel--) {
                if (isset($lastItemPerLevel[$parentLevel])) {
                    $parentItem = $lastItemPerLevel[$parentLevel];
                    break;
                }
            }

            if ($parentItem === null) {
                $toc->appendChild($item);
            } else {
                $childList = null;
                foreach ($parentItem->children() as $child) {
                    if ($child instanceof ListBlock) {
                        $childList = $child;
                        break;
                    }
                }

                if ($childList === null) {
                    $childList = new ListBlock($listData);
                    $childList->setTight(true);
                    $parentItem->appendChild($childList);
                }

                $childList->appendChild($item);
            }

            $lastItemPerLevel[$level] = $item;

            // A shallower heading closes the deeper levels, so their nesting
            // context cannot leak into a later sibling branch.
            foreach (array_keys($lastItemPerLevel) as $trackedLevel) {
                if ($trackedLevel > $level) {
                    unset($lastItemPerLevel[$trackedLevel]);
                }
            }
        }

        if (! $toc->hasChildren()) {
            return null;
        }

        $toc->data->append('attributes/class', $cssClass);

        return $toc;
    }

    /**
     * Whether a link destination is absolute (`https://…`, `mailto:…`, `#…`).
     *
     * Anything else is treated as a relative path and is eligible for rewriting.
     */
    private static function isAbsoluteUrl(string $destination): bool
    {
        return $destination === ''
            || str_starts_with($destination, '#')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $destination) === 1;
    }

    /**
     * Normalizes a link target so `./docs/x.md`, `docs/x.md` and `docs\x.md` compare equal.
     */
    private static function normalizeLinkTarget(string $target): string
    {
        return ltrim(str_replace('\\', '/', trim($target)), './');
    }

    /**
     * Whether a link already renders visible text of its own.
     *
     * Images count as content: appending a title next to one would add stray
     * text to an otherwise visual link.
     */
    private static function hasVisibleLabel(Link $link): bool
    {
        foreach ($link->children() as $child) {
            if ($child instanceof Image) {
                return true;
            }

            if (trim(StringContainerHelper::getChildText($child)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes the first match of $pattern from the string containers under $node.
     *
     * String containers are plain-text leaves (`Text`, `Code`), so inline markup
     * such as the emphasis inside `*[!NOTE]*` is not one node and has to be
     * walked. The search stops after the first hit for the same reason alert
     * syntax is only recognized on the first line: a later `[!NOTE]` is content,
     * not syntax.
     *
     * @param  string  $pattern  PCRE pattern to strip.
     * @return bool Whether a match was found and removed.
     */
    private function stripFirstMatch(\League\CommonMark\Node\Node $node, string $pattern): bool
    {
        if ($node instanceof AbstractStringContainer) {
            $literal = $node->getLiteral();
            $updated = preg_replace($pattern, '', $literal, 1, $count);

            if ($count > 0) {
                $node->setLiteral($updated);

                return true;
            }

            return false;
        }

        foreach ($node->children() as $child) {
            if ($this->stripFirstMatch($child, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
