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
use Socly\Services\FileRepositoryService;

/**
 * Signed access to the central file repository. Used by add-ons that authenticate their own
 * participants (internal forum, board voting): the HMAC signature is issued server-side by
 * FileRepositoryService::signedUrl() and expires, so no operator session is required.
 */
final class FilesController extends BaseController
{
    public function __construct(
        View $view,
        private readonly FileRepositoryService $files
    ) {
        parent::__construct($view);
    }

    public function show(Request $request, string $id): void
    {
        $documentId = (int) $id;
        $signature = trim((string) $request->input('sig', ''));
        $expires = (int) $request->input('exp', '0');
        if ($documentId < 1 || $signature === '' || !$this->files->verifySignature($documentId, $signature, $expires)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $row = $this->files->fileInfo($documentId);
        $path = $this->files->absolutePath($documentId);
        if ($row === null || $path === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $this->files->registerDownload($documentId);
        $mime = trim((string) ($row['file_mime'] ?? '')) ?: (mime_content_type($path) ?: 'application/octet-stream');
        $name = trim((string) ($row['original_name'] ?? '')) ?: basename($path);
        $name = str_replace(['"', "\r", "\n"], '', $name);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) (filesize($path) ?: 0));
        readfile($path);
    }
}
