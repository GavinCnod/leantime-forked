<?php

declare(strict_types=1);

namespace Unit\app\Core\Support;

use InvalidArgumentException;
use Leantime\Core\Support\MarkdownRenderer;
use Unit\TestCase;

final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new MarkdownRenderer;
    }

    public function test_escapes_raw_html_instead_of_parsing_it(): void
    {
        $html = $this->renderer->render('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_drops_unsafe_link_schemes(): void
    {
        $html = $this->renderer->render('[x](javascript:alert(1)) and ![y](data:text/html;base64,PHNjcmlwdD4=)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        // league would otherwise emit `<a>` with no href, which reads as a
        // working link; the label has to survive as plain text instead.
        $this->assertStringContainsString('<p>x and <img', $html);
        $this->assertStringNotContainsString('<a', $html);
    }

    public function test_keeps_https_and_mailto_links(): void
    {
        $html = $this->renderer->render('[docs](https://example.com/a) and [mail](mailto:a@example.com)');

        $this->assertStringContainsString('href="https://example.com/a"', $html);
        $this->assertStringContainsString('href="mailto:a@example.com"', $html);
    }

    public function test_renders_gfm_extensions(): void
    {
        $markdown = <<<'MD'
        # Heading

        | a | b |
        | --- | --- |
        | 1 | 2 |

        - [x] done
        - [ ] todo

        ~~gone~~ and https://example.com
        MD;

        $html = $this->renderer->render($markdown);

        $this->assertStringContainsString('<table class="md-table">', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('<del>gone</del>', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_converts_alert_blockquotes_into_callouts(): void
    {
        $html = $this->renderer->render("> [!WARNING]\n> Careful now.\n");

        $this->assertStringContainsString('md-callout', $html);
        $this->assertStringContainsString('md-callout--warning', $html);
        $this->assertStringContainsString('Careful now.', $html);
        $this->assertStringNotContainsString('[!WARNING]', $html);
    }

    public function test_leaves_plain_blockquotes_alone(): void
    {
        $html = $this->renderer->render("> Just a quote.\n");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringNotContainsString('md-callout', $html);
    }

    public function test_alert_without_body_keeps_the_previous_sibling(): void
    {
        $html = $this->renderer->render("> [!NOTE]\n>\n> Body text.\n");

        $this->assertStringContainsString('md-callout--note', $html);
        $this->assertStringContainsString('Body text.', $html);
        // The marker-only first paragraph is dropped, so no empty `<p>` and no
        // stray paragraph margin is left at the top of the callout.
        $this->assertStringNotContainsString('<p></p>', $html);
        $this->assertStringNotContainsString('[!NOTE]', $html);
    }

    public function test_alert_with_inline_body_keeps_its_text(): void
    {
        $html = $this->renderer->render("> [!CAUTION]\n> Do not delete the cost task.\n");

        $this->assertStringContainsString('md-callout--caution', $html);
        $this->assertStringContainsString('Do not delete the cost task.', $html);
        $this->assertStringNotContainsString('[!CAUTION]', $html);
    }

    public function test_does_not_treat_a_later_alert_marker_as_syntax(): void
    {
        $html = $this->renderer->render("> [!NOTE]\n> See the [!TIP] section for details.\n");

        $this->assertStringContainsString('md-callout--note', $html);
        $this->assertStringContainsString('[!TIP] section', $html);
        $this->assertStringNotContainsString('md-callout--tip', $html);
    }

    public function test_table_of_contents_is_opt_in_and_links_to_heading_ids(): void
    {
        $markdown = "## First\n\ntext\n\n## Second\n\nmore\n";

        $withoutToc = $this->renderer->render($markdown);
        $withToc = $this->renderer->render($markdown, ['toc' => true]);

        $this->assertStringNotContainsString('md-toc', $withoutToc);

        $this->assertStringContainsString('class="md-toc"', $withToc);
        // Heading text is used as the id, so the TOC fragment resolves.
        $this->assertStringContainsString('<h2 id="first">', $withToc);
        $this->assertStringContainsString('href="#md-first"', $withToc);
        $this->assertStringContainsString('href="#md-second"', $withToc);
    }

    public function test_table_of_contents_nests_by_heading_level(): void
    {
        $html = $this->renderer->render("## A\n\n### A1\n\n## B\n\n### B1\n", ['toc' => true]);

        // A1 nests under A; B starts a new top-level branch rather than nesting
        // under A1.
        $this->assertMatchesRegularExpression('/<ul class="md-toc">.*<li>.*A.*<ul>.*A1.*<\/ul>.*<\/li>/s', $html);
        $this->assertMatchesRegularExpression('/<\/li>\s*<li>.*B.*<ul>/s', $html);
    }

    public function test_rewrites_relative_document_links_and_keeps_external_ones(): void
    {
        $markdown = '[Guide](docs/other.md) [Site](https://example.com) [Top](#frag)';

        $html = $this->renderer->render($markdown, [
            'documents' => ['docs/other.md' => ['title' => '指南', 'url' => '#/hx/x/show/other']],
        ]);

        $this->assertStringContainsString('href="#/hx/x/show/other"', $html);
        $this->assertStringContainsString('md-cross-doc-link', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('href="#frag"', $html);
    }

    public function test_relative_link_without_a_mapping_is_left_alone(): void
    {
        $html = $this->renderer->render('[Missing](docs/unknown.md)', [
            'documents' => ['docs/other.md' => ['title' => 'Other', 'url' => '#/x']],
        ]);

        $this->assertStringContainsString('href="docs/unknown.md"', $html);
        $this->assertStringNotContainsString('md-cross-doc-link', $html);
    }

    public function test_rejects_non_utf8_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer->render("\xC3\x28");
    }

    public function test_renders_footnotes_with_theme_classes(): void
    {
        $html = $this->renderer->render("Text with a note.[^1]\n\n[^1]: The note.\n");

        $this->assertStringContainsString('md-footnotes', $html);
        $this->assertStringContainsString('md-footnote-backref', $html);
    }

    public function test_renders_empty_input_as_empty_output(): void
    {
        $this->assertSame('', trim($this->renderer->render('')));
    }
}
