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
}
