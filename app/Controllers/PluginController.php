<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\PluginAdminService;

final class PluginController extends BaseController
{
    public function __construct(
        View $view,
        private readonly PluginAdminService $plugins
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        redirect('/settings#components');
    }

    public function enable(Request $request, string $id): void
    {
        $this->plugins->enable($id, $request->ip());
        $this->flash('success', __('plugins.enabled'));
        redirect('/settings#components');
    }

    public function disable(Request $request, string $id): void
    {
        $this->plugins->disable($id, $request->ip());
        $this->flash('success', __('plugins.disabled'));
        redirect('/settings#components');
    }

    public function saveSettings(Request $request, string $id): void
    {
        $this->plugins->savePluginSettings($id, $request->all(), $request->ip());
        $this->flash('success', __('settings.saved'));
        redirect('/settings#components');
    }
}
