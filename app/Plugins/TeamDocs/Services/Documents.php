<?php

declare(strict_types=1);

namespace Leantime\Plugins\TeamDocs\Services;

use InvalidArgumentException;
use Leantime\Core\Support\MarkdownRenderer;
use RuntimeException;

/**
 * Loads the allowlisted TeamDocs Markdown documents and converts them to safe HTML.
 *
 * Documents are packaged with the plugin (Resources/docs). The repository copy
 * under `docs/` is the editable source of truth; `scripts/check-teamdocs-docs.php`
 * fails when the two drift apart.
 */
final class Documents
{
    private const MAX_DOCUMENT_BYTES = 512_000;

    /**
     * Allowlisted documents, keyed by the public key used in the URL.
     *
     * `filename` is also the key that cross-document links written in the
     * Markdown resolve against, so a doc linking to `docs/leantime-zh-guide.md`
     * lands on this plugin's modal route instead of a repository path that does
     * not exist at runtime.
     *
     * @var array<string, array{filename: string, title: string, nav_title: string}>
     */
    private const DOCUMENTS = [
        'rules' => [
            'filename' => 'cost-tracking-rules.md',
            'title' => '团队费用记账规矩',
            'nav_title' => '记账规矩',
        ],
        'guide' => [
            'filename' => 'leantime-zh-guide.md',
            'title' => 'Leantime 中文操作指南',
            'nav_title' => '中文操作指南',
        ],
        'glossary' => [
            'filename' => 'leantime-zh-glossary.md',
            'title' => 'Leantime 中文术语表',
            'nav_title' => '中文术语表',
        ],
    ];

    /**
     * Documents long enough that a table of contents earns its space.
     *
     * @var list<string>
     */
    private const DOCUMENTS_WITH_TOC = ['rules', 'guide'];

    public function __construct(
        private readonly MarkdownRenderer $markdown,
    ) {}

    /**
     * Absolute path to the plugin's packaged resources.
     *
     * Also used by the stylesheet controller, which lives under the same tree.
     */
    public static function resourceRoot(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'Resources';
    }

    /**
     * Returns one allowlisted document as a title and safe HTML fragment.
     *
     * @param  string  $key  Public document key, never a filesystem path.
     * @return array{key: string, title: string, filename: string, has_toc: bool, html: string}
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

        $root = realpath(self::resourceRoot().DIRECTORY_SEPARATOR.'docs');
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
        if ($markdown === false) {
            throw new RuntimeException('TeamDocs document could not be read.');
        }

        return [
            'key' => $key,
            'title' => $document['title'],
            'filename' => $document['filename'],
            'has_toc' => in_array($key, self::DOCUMENTS_WITH_TOC, true),
            'html' => $this->markdown->render($markdown, [
                'toc' => in_array($key, self::DOCUMENTS_WITH_TOC, true),
                'documents' => self::crossDocumentLinks(),
            ]),
        ];
    }

    /**
     * Builds the link map handed to the renderer.
     *
     * Each document maps its own repository path to this plugin's modal route,
     * so a link written for GitHub keeps working inside the application.
     *
     * @return array<string, array{title: string, url: string}>
     */
    private static function crossDocumentLinks(): array
    {
        $links = [];

        foreach (self::DOCUMENTS as $key => $document) {
            $links['docs/'.$document['filename']] = [
                'title' => $document['nav_title'],
                'url' => '#/hx/teamdocs/document/show/'.$key,
            ];
        }

        return $links;
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
