<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\PlatformService;

final class SessionController extends BaseController
{
    public function __construct(View $view, private readonly PlatformService $platform)
    {
        parent::__construct($view);
    }

    public function ping(Request $request): void
    {
        $_SESSION['last_activity'] = time();
        try {
            $this->platform->maybeSendTelemetry();
        } catch (\Throwable) {
            // never block session ping
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ts' => time()]);
    }
}
