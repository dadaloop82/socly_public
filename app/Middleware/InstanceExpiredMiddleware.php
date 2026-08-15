<?php

declare(strict_types=1);

namespace Socly\Middleware;

use Socly\Core\Http\Request;
use Socly\Services\SettingsService;

/**
 * Blocks access to expired temporary evaluation instances.
 */
final class InstanceExpiredMiddleware
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request): bool
    {
        if ((string) $this->settings->get('app.temporary_instance', '0') !== '1') {
            return true;
        }

        $expires = trim((string) $this->settings->get('app.instance_expires_at', ''));
        if ($expires === '') {
            return true;
        }

        $ts = strtotime($expires);
        if ($ts === false || $ts >= time()) {
            return true;
        }

        http_response_code(410);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><title>Istanza scaduta</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#1a2b2a}';
        echo 'h1{font-size:1.4rem}p{line-height:1.5;color:#445}</style></head><body>';
        echo '<h1>Questa istanza di prova è scaduta</h1>';
        echo '<p>L’istanza SOCLY non è più disponibile. Contatta il supporto per una nuova prova o per l’installazione definitiva.</p>';
        echo '<p><a href="https://socly.it">Torna a socly.it</a></p>';
        echo '</body></html>';
        return false;
    }
}
