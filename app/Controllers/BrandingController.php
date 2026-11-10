<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\BrandingService;

final class BrandingController extends BaseController
{
    public function __construct(
        View $view,
        private readonly BrandingService $branding
    ) {
        parent::__construct($view);
    }

    public function logo(Request $request): void
    {
        $path = $this->branding->logoAbsolutePath();
        if ($path === null) {
            http_response_code(404);
            return;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile($path);
    }

    /**
     * Upload/rimozione del logo dalle impostazioni: risposta JSON consumata da
     * `initLogoFilePickers()` (anteprima + stato inline, senza ricaricare).
     */
    public function upload(Request $request): void
    {
        if ((string) $request->input('remove', '') === '1') {
            $this->branding->removeLogo();
            $result = ['ok' => true, 'logo_url' => null];
        } else {
            $file = $request->file('logo');
            if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $this->jsonResponse(['ok' => false, 'error' => (string) __('setup.logo_error_upload')], 422);
                return;
            }
            $stored = $this->branding->storeLogoUpload($file, true);
            if (empty($stored['ok'])) {
                $this->jsonResponse([
                    'ok' => false,
                    'error' => (string) ($stored['error'] ?? __('setup.logo_error_upload')),
                ], 422);
                return;
            }
            // Scelta manuale: lo scraper del sito non deve più sostituirlo.
            $settings = app(\Socly\Services\SettingsService::class);
            $settings->set('branding.logo_source', 'user');
            $settings->set('branding.logo_configured', '1');
            $result = [
                'ok' => true,
                'logo_url' => $this->branding->logoUrl(),
                'primary' => (string) $settings->get('branding.primary', ''),
                'accent' => (string) $settings->get('branding.accent', ''),
            ];
        }
        $this->jsonResponse($result);
    }

    /** @param array<string,mixed> $payload */
    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
