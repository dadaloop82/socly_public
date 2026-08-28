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
        header('Content-Security-Policy: ' . $this->contentSecurityPolicy());
        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        return true;
    }

    private function contentSecurityPolicy(): string
    {
        $connect = $this->cspConnectSources();

        return implode('; ', [
            "default-src 'self'",
            "img-src 'self' data: blob: https:",
            "media-src 'self' blob:",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "script-src 'self'",
            'connect-src ' . $connect,
            "base-uri 'self'",
            "form-action 'self'",
        ]);
    }

    private function cspConnectSources(): string
    {
        $sources = ["'self'"];

        foreach ($this->externalSiteOrigins() as $origin) {
            $sources[] = $origin;
        }

        return implode(' ', array_values(array_unique($sources)));
    }

    /** @return list<string> */
    private function externalSiteOrigins(): array
    {
        $origins = [];

        $add = static function (string $url) use (&$origins): void {
            $url = trim($url);
            if ($url === '') {
                return;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return;
            }
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            $origins[] = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port;
        };

        if (function_exists('socly_site_url')) {
            $add(socly_site_url());
        }
        foreach ([
            (string) ($_ENV['SOCLY_NEWS_API_URL'] ?? ''),
            (string) ($_ENV['SOCLY_PLATFORM_API_URL'] ?? ''),
        ] as $configured) {
            $add($configured);
        }

        $expanded = [];
        foreach (array_unique($origins) as $origin) {
            $expanded[] = $origin;
            $parts = parse_url($origin);
            if (!is_array($parts) || empty($parts['host'])) {
                continue;
            }
            $host = (string) $parts['host'];
            $scheme = (string) ($parts['scheme'] ?? 'https');
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            if (str_starts_with($host, 'www.')) {
                $expanded[] = $scheme . '://' . substr($host, 4) . $port;
            } else {
                $expanded[] = $scheme . '://www.' . $host . $port;
            }
        }

        return array_values(array_unique($expanded));
    }

    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
