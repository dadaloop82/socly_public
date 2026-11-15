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

namespace Socly\Core;

final class Csrf
{
    public function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function validate(?string $token): bool
    {
        $candidate = $token;
        if ($candidate === null || $candidate === '') {
            $candidate = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        return is_string($candidate)
            && isset($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $candidate);
    }
}
