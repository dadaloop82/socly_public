<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\SetupService;
use Socly\Services\UpdateService;
use Socly\Support\Permission;

final class UpdateController extends BaseController
{
    public function __construct(
        View $view,
        private readonly UpdateService $updates,
        private readonly SetupService $setup
    ) {
        parent::__construct($view);
    }

    public function install(Request $request): void
    {
        if (!can(Permission::SETTINGS_MANAGE)) {
            http_response_code(403);
            $this->render('errors/403');
            return;
        }
        $result = $this->updates->apply($request->ip());
        if ($result['ok']) {
            $this->flash('success', $result['message']);
            if (!$this->setup->isComplete()) {
                unset($_SESSION['setup_greeted']);
                redirect('/setup');
            }
            redirect('/dashboard');
        }
        $this->flash('errors', [$result['message']]);
        redirect('/dashboard');
    }
}
