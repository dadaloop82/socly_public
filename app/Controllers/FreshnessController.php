<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */


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
        if ($this->rejectBrowserNavigationForJson($request)) {
            return;
        }
        $entity = (string) $request->input('entity', '');
        $id = (int) $request->input('id', 0);
        $expected = (int) $request->input('version', 0);
        $version = $this->versions->current($entity, $id);
        $editor = $version === null ? ['name' => '', 'at' => ''] : $this->versions->lastEditor($entity, $id);
        $changed = $version !== null && $expected > 0 && (int) $version !== $expected;
        // “Just created/modified by …”: only treasury movements show this box, because the copy is
        // movement-specific. The client keeps it up to date on every poll until 15 minutes pass.
        $activity = null;
        if ($entity === 'treasury' && $id > 0) {
            try {
                $activity = $this->versions->recentActivity($entity, $id, 15);
            } catch (\Throwable) {
                $activity = null;
            }
        }
        $messages = [
            'stale_title' => (string) __('concurrency.stale_title'),
            'stale_text' => (string) __('concurrency.stale_text', ['user' => ':user', 'at' => ':at']),
            'deleted' => (string) __('concurrency.deleted'),
            'reload' => (string) __('concurrency.reload'),
            'activity_title' => (string) __('concurrency.activity_title'),
            'activity_created' => (string) __('concurrency.activity_created', ['user' => ':user', 'at' => ':at']),
            'activity_updated' => (string) __('concurrency.activity_updated', ['user' => ':user', 'at' => ':at']),
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
            'activity' => $activity,
            'fields' => $fields,
            'messages' => $messages,
            'now' => time(),
        ], JSON_UNESCAPED_UNICODE);
    }
}
