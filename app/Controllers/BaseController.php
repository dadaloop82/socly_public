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

abstract class BaseController
{
    public function __construct(protected readonly View $view)
    {
    }

    protected function render(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        echo $this->view->render($template, $data, $layout);
    }

    protected function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    protected function rememberOld(array $data): void
    {
        $_SESSION['_old'] = $data;
    }

    protected function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    /**
     * True when the browser opened this URL as a normal page (address bar, bookmark, link),
     * not as an XHR/fetch that expects JSON.
     */
    protected function isBrowserDocumentRequest(Request $request): bool
    {
        $dest = strtolower(trim((string) $request->header('Sec-Fetch-Dest', '')));
        if ($dest === 'document') {
            return true;
        }
        $xhr = strtolower(trim((string) $request->header('X-Requested-With', ''))) === 'xmlhttprequest';
        $accept = strtolower((string) $request->header('Accept', ''));
        if ($xhr || str_contains($accept, 'application/json')) {
            return false;
        }

        return str_contains($accept, 'text/html');
    }

    /**
     * JSON-only endpoints must never dump a payload into a browser tab.
     * Returns true when the request was redirected away (caller should stop).
     */
    protected function rejectBrowserNavigationForJson(Request $request, string $fallback = '/dashboard'): bool
    {
        if (!$this->isBrowserDocumentRequest($request)) {
            return false;
        }
        redirect($fallback);
    }

    /**
     * Send a JSON payload (used by AJAX saves such as the budget autosave).
     *
     * @param array<string,mixed> $payload
     */
    protected function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
