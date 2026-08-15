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
}
