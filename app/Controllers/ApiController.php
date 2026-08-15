<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\GeoService;
use Socly\Services\SetupService;
use Socly\Support\Permission;

final class ApiController extends BaseController
{
    public function __construct(
        View $view,
        private readonly GeoService $geo,
        private readonly SetupService $setup
    ) {
        parent::__construct($view);
    }

    public function cities(Request $request): void
    {
        $this->json([
            'items' => $this->geo->searchComuni((string) $request->input('q', '')),
        ]);
    }

    public function addresses(Request $request): void
    {
        $this->json([
            'items' => $this->geo->searchAddresses(
                (string) $request->input('q', ''),
                (string) $request->input('city', '')
            ),
        ]);
    }

    public function fiscalCode(Request $request): void
    {
        try {
            if ($this->setup->isComplete() && !can(Permission::MEMBERS_MANAGE)) {
                $this->json(['ok' => false, 'error' => 'forbidden'], 403);
                return;
            }
        } catch (\Throwable) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }

        $result = $this->geo->computeFiscalCode(
            (string) $request->input('first_name', ''),
            (string) $request->input('last_name', ''),
            (string) $request->input('birth_date', ''),
            (string) $request->input('gender', ''),
            (string) $request->input('birth_place', '')
        );
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
