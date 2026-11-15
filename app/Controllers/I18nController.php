<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
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

final class I18nController extends BaseController
{
    public function __construct(
        View $view
    ) {
        parent::__construct($view);
    }

    public function messages(Request $request): void
    {
        $locale = (string) $request->input('lang', '');
        if (!in_array($locale, ['it', 'de', 'en'], true)) {
            $locale = (string) (app()->config('app.locale', 'it') ?: 'it');
        }

        $path = code_path('lang/' . $locale . '/messages.php');
        $messages = is_file($path) ? (require $path) : [];

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'locale' => $locale, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
    }
}

