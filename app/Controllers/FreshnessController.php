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
        $changed = $version !== null && $expected > 0 && (int) $version !== $expected;
        $messages = [
            'stale_title' => (string) __('concurrency.stale_title'),
            'stale_text' => (string) __('concurrency.stale_text', ['user' => ':user', 'at' => ':at']),
            'deleted' => (string) __('concurrency.deleted'),
            'reload' => (string) __('concurrency.reload'),
        ];
        // Which fields changed: the client compares them with its own values and lists the
        // differences, so the banner says *what* the other operator saved.
        $fields = null;
        if ($changed && $entity === 'treasury') {
            try {
                $fields = app(\Socly\Services\TreasuryService::class)->formSnapshot($id);
                $messages['stale_changes_title'] = (string) __('concurrency.stale_changes_title', ['user' => ':user']);
                $messages['stale_changes_none'] = (string) __('concurrency.stale_changes_none');
            } catch (\Throwable) {
                $fields = null;
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => $version !== null,
            'id' => $id,
            'version' => (int) ($version ?? 0),
            'changed' => $changed,
            'deleted' => $version === null,
            'editor' => $editor,
            'fields' => $fields,
            'messages' => $messages,
            'now' => time(),
        ], JSON_UNESCAPED_UNICODE);
    }
}
