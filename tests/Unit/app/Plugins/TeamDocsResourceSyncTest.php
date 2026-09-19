<?php

declare(strict_types=1);

namespace Unit\app\Plugins;

use Unit\TestCase;

final class TeamDocsResourceSyncTest extends TestCase
{
    /**
     * The runtime copy must stay byte-for-byte aligned with the canonical docs.
     */
    public function test_canonical_documents_are_synced_into_plugin_resources(): void
    {
        $root = dirname(__DIR__, 4);
        $documents = [
            'cost-tracking-rules.md',
            'leantime-zh-guide.md',
            'leantime-zh-glossary.md',
        ];

        foreach ($documents as $document) {
            $source = $root.'/docs/'.$document;
            $resource = $root.'/app/Plugins/TeamDocs/Resources/docs/'.$document;

            $this->assertFileExists($source);
            $this->assertFileExists($resource);
            $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $resource), $document);
        }
    }
}
