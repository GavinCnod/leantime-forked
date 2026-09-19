<?php

declare(strict_types=1);

namespace Leantime\Plugins\TeamDocs\Services;

use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;

/**
 * Loads the allowlisted TeamDocs Markdown documents and converts them to safe HTML.
 */
final class Documents
{
    private const MAX_DOCUMENT_BYTES = 512_000;

    /** @var array<string, array{filename: string, title: string}> */
    private const DOCUMENTS = [
        'rules' => [
            'filename' => 'cost-tracking-rules.md',
            'title' => '团队费用记账规矩',
        ],
        'guide' => [
            'filename' => 'leantime-zh-guide.md',
            'title' => 'Leantime 中文操作指南',
        ],
        'glossary' => [
            'filename' => 'leantime-zh-glossary.md',
            'title' => 'Leantime 中文术语表',
        ],
    ];

    /**
     * Returns one allowlisted document as a title and safe HTML fragment.
     *
     * @param string $key Public document key, never a filesystem path.
     * @return array{key: string, title: string, filename: string, html: string}
     *
     * @throws InvalidArgumentException When the key is not allowlisted.
     * @throws RuntimeException When the packaged document cannot be read safely.
     */
    public function getDocument(string $key): array
    {
        $document = self::DOCUMENTS[$key] ?? null;
        if ($document === null) {
            throw new InvalidArgumentException('Unknown TeamDocs document.');
        }

        $root = realpath(__DIR__.'/../Resources/docs');
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('TeamDocs resources are unavailable.');
        }

        $path = $root.DIRECTORY_SEPARATOR.$document['filename'];
        $resolvedPath = realpath($path);
        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (
            $resolvedPath === false
            || ! is_file($resolvedPath)
            || is_link($path)
            || ! str_starts_with($resolvedPath, $rootPrefix)
        ) {
            throw new RuntimeException('TeamDocs document is unavailable.');
        }

        $size = filesize($resolvedPath);
        if ($size === false || $size > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('TeamDocs document is too large.');
        }

        $markdown = file_get_contents($resolvedPath);
        if ($markdown === false || ! mb_check_encoding($markdown, 'UTF-8')) {
            throw new RuntimeException('TeamDocs document could not be read.');
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);

        return [
            'key' => $key,
            'title' => $document['title'],
            'filename' => $document['filename'],
            'html' => $converter->convertToHtml($markdown)->getContent(),
        ];
    }

    /**
     * Returns the allowlisted document keys for tests and diagnostics.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::DOCUMENTS);
    }
}
