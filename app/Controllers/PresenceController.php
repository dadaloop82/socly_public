<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\PresenceService;

/**
 * Operator presence: the client polls this endpoint, which refreshes the current session and
 * sessione corrente e restituisce chi è collegato (pallini, inattivi, nuovi arrivi).
 */
final class PresenceController extends BaseController
{
    public function __construct(View $view, private readonly PresenceService $presence)
    {
        parent::__construct($view);
    }

    public function state(Request $request): void
    {
        $entity = (string) $request->input('editing', '');
        $parts = $entity !== '' ? explode(':', $entity, 2) : [];
        $editingEntity = (string) ($parts[0] ?? '');
        $editingId = (int) ($parts[1] ?? 0);
        try {
            $this->presence->touch($editingEntity !== '' ? $editingEntity : null, $editingId > 0 ? $editingId : null);
        } catch (\Throwable) {
            // presence must never break the UI
        }
        try {
            $data = $this->presence->snapshot(
                (int) $request->input('since', 0),
                ['entity' => $editingEntity, 'id' => $editingId]
            );
        } catch (\Throwable) {
            $data = ['users' => [], 'online' => 0, 'new' => [], 'editors' => [], 'now' => time()];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => true,
            'online' => (int) $data['online'],
            'now' => (int) $data['now'],
            'users' => $data['users'],
            'new' => $data['new'],
            'editors' => $data['editors'] ?? [],
            'messages' => [
                'connected' => (string) __('presence.connected_toast', ['name' => ':name']),
                'unknown' => (string) __('presence.unknown_user'),
                'editing' => (string) __('concurrency.editing_now', ['name' => ':name']),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
