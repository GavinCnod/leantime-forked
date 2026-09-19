<?php

declare(strict_types=1);

namespace Leantime\Plugins\TeamDocs\Hxcontrollers;

use InvalidArgumentException;
use Leantime\Core\Controller\HtmxController;
use Leantime\Plugins\TeamDocs\Services\Documents;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

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
     * Returns a Response directly (the established HxController pattern, see
     * Wiki\Hxcontrollers\ArticleContent) so the HTTP status matches the outcome. Returning it
     * matters here: the base getResponse() rebuilds the response via displayFragment(), which
     * would silently downgrade any status set on the response bag back to 200.
     *
     * @param  array<string, mixed>  $params  Frontcontroller request parameters.
     */
    public function show($params): Response
    {
        $key = is_array($params) ? (string) ($params['request_parts'] ?? '') : '';
        $key = trim(explode('.', $key, 2)[0]);

        try {
            $document = $this->documents->getDocument($key);
            $this->tpl->assign('document', $document);
            $this->tpl->assign('error', null);

            return $this->tpl->displayFragment(static::$view);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            // Unknown key is a client error; a missing/unreadable packaged file is a server error.
            $this->tpl->assign('document', null);
            $this->tpl->assign('error', '这份团队文档暂时无法打开。');

            return $this->tpl->displayFragment(static::$view)
                ->setStatusCode($exception instanceof InvalidArgumentException ? 404 : 500);
        }
    }
}
