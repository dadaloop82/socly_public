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

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->renderPartial($view, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->renderPartial($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function renderPartial(string $view, array $vars = []): string
    {
        $path = $this->basePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View [{$view}] not found.");
        }
        // Never name this parameter $data: a view payload key named "data"
        // would be skipped by EXTR_SKIP and shadow the extracted value.
        extract($vars, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
}
