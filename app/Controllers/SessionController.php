<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;

final class SessionController extends BaseController
{
    public function __construct(View $view)
    {
        parent::__construct($view);
    }

    public function ping(Request $request): void
    {
        $_SESSION['last_activity'] = time();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ts' => time()]);
    }
}
