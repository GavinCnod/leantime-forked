<?php

declare(strict_types=1);

namespace Unit\app\Plugins\TeamDocs\Services;

use InvalidArgumentException;
use Leantime\Plugins\TeamDocs\Services\Documents;
use Unit\TestCase;

final class DocumentsTest extends TestCase
{
    private Documents $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->documents = new Documents();
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
        $this->assertStringContainsString('<h1>', $document['html']);
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
}
