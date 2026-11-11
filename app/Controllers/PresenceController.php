<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\PresenceService;

/**
 * Presenza degli operatori: il client interroga questo endpoint in polling, che rinfresca la
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
        try {
            $this->presence->touch();
        } catch (\Throwable) {
            // la presenza non deve mai bloccare l'interfaccia
        }
        try {
            $data = $this->presence->snapshot((int) $request->input('since', 0));
        } catch (\Throwable) {
            $data = ['users' => [], 'online' => 0, 'new' => [], 'now' => time()];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => true,
            'online' => (int) $data['online'],
            'now' => (int) $data['now'],
            'users' => $data['users'],
            'new' => $data['new'],
            'messages' => [
                'connected' => (string) __('presence.connected_toast', ['name' => ':name']),
                'unknown' => (string) __('presence.unknown_user'),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
