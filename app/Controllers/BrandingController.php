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
        // Public endpoint and always requested by the header: any problem (missing file, permissions, missing
        // `fileinfo` extension, corrupt settings) must give 404, never 500.
        $path = null;
        try {
            $path = $this->branding->logoAbsolutePath();
        } catch (\Throwable $e) {
            try {
                app('logger')->error('branding.logo_failed', ['message' => $e->getMessage()]);
            } catch (\Throwable) {
                // the logger must not break the response
            }
        }
        if ($path === null || !is_file($path) || !is_readable($path)) {
            http_response_code(404);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            return;
        }
        $mime = 'application/octet-stream';
        if (class_exists(\finfo::class)) {
            try {
                $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            } catch (\Throwable) {
                // fallback sull'estensione
            }
        }
        if ($mime === 'application/octet-stream') {
            $mime = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                'ico' => 'image/x-icon',
                default => 'application/octet-stream',
            };
        }
        $size = @filesize($path);
        header('Content-Type: ' . $mime);
        if (is_int($size)) {
            header('Content-Length: ' . (string) $size);
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        readfile($path);
    }

    /**
     * Upload/remove logo from settings: JSON response consumed by `initLogoFilePickers()` (preview
     * + inline state, without reloading).
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
            // Manual choice: The site scraper no longer needs to replace it.
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
}
