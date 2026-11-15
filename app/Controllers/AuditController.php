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
use Socly\Services\AuditService;

/**
 * Read-only activity log (“who did what, when”) on top of audit_logs.
 */
final class AuditController extends BaseController
{
    public function __construct(View $view, private readonly AuditService $audit)
    {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        $filters = [
            'q' => (string) $request->input('q', ''),
            'user_id' => (string) $request->input('user_id', ''),
            'entity_type' => (string) $request->input('entity_type', ''),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];
        $result = $this->audit->search($filters, (int) $request->input('page', 1));

        $this->render('audit/index', [
            'title' => __('audit.title'),
            'result' => $result,
            'filters' => $result['filters'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'options' => $this->audit->filterOptions(),
            'summary' => $this->audit->summary(),
        ]);
    }
}
