<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;

final class SecurityHeadersMiddleware
{
    public function handle(Request $request): bool
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'");
        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        return true;
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
