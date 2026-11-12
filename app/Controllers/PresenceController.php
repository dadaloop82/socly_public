<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\PresenceService;

/**
 * Operator presence: the client polls this endpoint, which refreshes the current session and
 * current session and returns who is connected (dots, inactive, new arrivals).
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
        // Field the operator is working in and the draft value (field-level presence): plain field
        // name + short text, both validated server-side.
        $editingField = (string) $request->input('field', '');
        $editingValue = (string) $request->input('value', '');
        try {
            $this->presence->touch(
                $editingEntity !== '' ? $editingEntity : null,
                $editingId > 0 ? $editingId : null,
                $editingField,
                $editingValue
            );
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
                'editing_field' => (string) __('presence.editing_field', ['name' => ':name']),
                'field_locked' => (string) __('presence.field_locked', ['name' => ':name']),
                'field_value' => (string) __('presence.field_value', ['name' => ':name', 'value' => ':value']),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
