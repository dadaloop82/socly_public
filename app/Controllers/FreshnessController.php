<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\VersionService;

/**
 * Freshness check: tells whether the record open in the form was changed by someone else
 * after the page loaded (the client shows a banner without touching the typed values).
 */
final class FreshnessController extends BaseController
{
    public function __construct(View $view, private readonly VersionService $versions)
    {
        parent::__construct($view);
    }

    public function state(Request $request): void
    {
        $entity = (string) $request->input('entity', '');
        $id = (int) $request->input('id', 0);
        $expected = (int) $request->input('version', 0);
        $version = $this->versions->current($entity, $id);
        $editor = $version === null ? ['name' => '', 'at' => ''] : $this->versions->lastEditor($entity, $id);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => $version !== null,
            'id' => $id,
            'version' => (int) ($version ?? 0),
            'changed' => $version !== null && $expected > 0 && (int) $version !== $expected,
            'deleted' => $version === null,
            'editor' => $editor,
            'messages' => [
                'stale_title' => (string) __('concurrency.stale_title'),
                'stale_text' => (string) __('concurrency.stale_text', ['user' => ':user', 'at' => ':at']),
                'deleted' => (string) __('concurrency.deleted'),
                'reload' => (string) __('concurrency.reload'),
            ],
            'now' => time(),
        ], JSON_UNESCAPED_UNICODE);
    }
}
