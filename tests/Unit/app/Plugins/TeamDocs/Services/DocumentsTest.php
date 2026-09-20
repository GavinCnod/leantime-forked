<?php

declare(strict_types=1);

namespace Unit\app\Plugins\TeamDocs\Services;

use InvalidArgumentException;
use Leantime\Core\Support\MarkdownRenderer;
use Leantime\Plugins\TeamDocs\Services\Documents;
use Unit\TestCase;

final class DocumentsTest extends TestCase
{
    private Documents $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documents = new Documents(new MarkdownRenderer);
    }

    public function test_exposes_only_the_three_allowlisted_keys(): void
    {
        $this->assertSame(['rules', 'guide', 'glossary'], $this->documents->keys());
    }

    public function test_loads_a_packaged_document_and_renders_markdown(): void
    {
        $document = $this->documents->getDocument('rules');

        $this->assertSame('rules', $document['key']);
        $this->assertSame('团队费用记账规矩', $document['title']);
        $this->assertStringContainsString('<h1', $document['html']);
        $this->assertStringContainsString('团队费用记账规矩', $document['html']);
    }

    public function test_rejects_unknown_and_path_like_keys(): void
    {
        foreach (['missing', '../cost-tracking-rules.md', '/etc/passwd', 'rules/../guide', ''] as $key) {
            try {
                $this->documents->getDocument($key);
                $this->fail('Expected key to be rejected: '.$key);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Unknown TeamDocs document.', $exception->getMessage());
            }
        }
    }

    public function test_packaged_content_does_not_allow_raw_html_or_unsafe_links(): void
    {
        $document = $this->documents->getDocument('guide');

        $this->assertStringNotContainsString('<script', strtolower($document['html']));
        $this->assertStringNotContainsString('javascript:', strtolower($document['html']));
        $this->assertStringNotContainsString('onerror=', strtolower($document['html']));
    }

    public function test_long_documents_get_a_table_of_contents_and_the_glossary_does_not(): void
    {
        $rules = $this->documents->getDocument('rules');
        $glossary = $this->documents->getDocument('glossary');

        $this->assertTrue($rules['has_toc']);
        $this->assertStringContainsString('class="md-toc"', $rules['html']);

        // The glossary is flat reference material; a TOC there is noise.
        $this->assertFalse($glossary['has_toc']);
        $this->assertStringNotContainsString('md-toc', $glossary['html']);
    }

    public function test_alert_blockquotes_become_callouts(): void
    {
        $html = $this->documents->getDocument('rules')['html'];

        $this->assertStringContainsString('md-callout--warning', $html);
        $this->assertStringContainsString('md-callout--important', $html);
        // The marker itself must be consumed, not rendered as text.
        $this->assertStringNotContainsString('[!WARNING]', $html);
        $this->assertStringNotContainsString('[!IMPORTANT]', $html);
    }

    public function test_markdown_tables_render_as_tables(): void
    {
        $html = $this->documents->getDocument('glossary')['html'];

        $this->assertStringContainsString('<table class="md-table">', $html);
        $this->assertStringContainsString('<th>', $html);
        // The glossary's tables have empty third cells; a malformed render would
        // show the pipe syntax as literal text instead.
        $this->assertStringContainsString('<td>项目</td>', $html);
    }

    public function test_links_between_packaged_documents_are_rewritten_to_modal_routes(): void
    {
        $html = $this->documents->getDocument('rules')['html'];

        $this->assertStringContainsString('href="#/hx/teamdocs/document/show/guide"', $html);
        $this->assertStringContainsString('href="#/hx/teamdocs/document/show/glossary"', $html);
        // The repository path is not a reachable URL at runtime.
        $this->assertStringNotContainsString('href="docs/leantime-zh-guide.md"', $html);
    }

    public function test_rendered_markup_carries_the_classes_core_stylesheet_targets(): void
    {
        // The plugin ships no CSS of its own. Core's markdown.css (bundled into
        // main.css via public/assets/less/main.less) styles these classes, so a
        // renderer change that renames one would silently strip the documents'
        // layout with nothing else failing.
        //
        // `md-body` is excluded deliberately: the renderer emits a bare fragment
        // and the caller's container adds that class, so it never appears here.
        $this->assertStringContainsString('md-body', (string) file_get_contents(
            dirname(__DIR__, 6).'/app/Plugins/TeamDocs/Templates/partials/documentModal.blade.php',
        ));

        $html = $this->documents->getDocument('rules')['html'];

        foreach (['md-table', 'md-callout', 'md-toc'] as $class) {
            $this->assertMatchesRegularExpression(
                '/\b'.preg_quote($class, '/').'\b/',
                $html,
                'Renderer no longer emits '.$class,
            );
        }
    }
}
