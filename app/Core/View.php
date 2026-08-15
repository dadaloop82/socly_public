<?php

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

    public function renderPartial(string $view, array $data = []): string
    {
        $path = $this->basePath . '/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View [{$view}] not found.");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
}
