<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\View;

abstract class BaseController
{
    public function __construct(protected readonly View $view)
    {
    }

    protected function render(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        echo $this->view->render($template, $data, $layout);
    }

    protected function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    protected function rememberOld(array $data): void
    {
        $_SESSION['_old'] = $data;
    }

    protected function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    /**
     * Send a JSON payload (used by AJAX saves such as the budget autosave).
     *
     * @param array<string,mixed> $payload
     */
    protected function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
