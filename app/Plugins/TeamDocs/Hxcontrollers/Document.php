<?php

declare(strict_types=1);

namespace Leantime\Plugins\TeamDocs\Hxcontrollers;

use InvalidArgumentException;
use Leantime\Core\Controller\HtmxController;
use Leantime\Plugins\TeamDocs\Services\Documents;
use RuntimeException;

/**
 * Returns a packaged TeamDocs document as an HTMX modal fragment.
 */
final class Document extends HtmxController
{
    protected static string $view = 'teamdocs::partials.documentModal';

    private Documents $documents;

    /**
     * Injects the document service.
     */
    public function init(Documents $documents): void
    {
        $this->documents = $documents;
    }

    /**
     * Loads an allowlisted document for the existing hash-modal flow.
     *
     * @param array<string, mixed> $params Frontcontroller request parameters.
     */
    public function show($params): void
    {
        $key = is_array($params) ? (string) ($params['request_parts'] ?? '') : '';
        $key = trim(explode('.', $key, 2)[0]);

        try {
            $document = $this->documents->getDocument($key);
            $this->tpl->assign('document', $document);
            $this->tpl->assign('error', null);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->tpl->assign('document', null);
            $this->tpl->assign('error', '这份团队文档暂时无法打开。');
        }
    }
}
