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

    public function check(Request $request): void
    {
        $force = (string) $request->input('force', '') === '1';
        try {
            $info = $this->updates->check($force);
            $this->json([
                'ok' => true,
                'available' => !empty($info['available']),
                'current' => (string) ($info['current'] ?? app_version()),
                'remote' => (string) ($info['remote'] ?? ''),
                'develop_version' => (string) ($info['develop_version'] ?? ''),
                'public_version' => (string) ($info['public_version'] ?? ''),
                'source' => (string) ($info['source'] ?? ''),
                'released_at' => (string) ($info['released_at'] ?? ''),
                'repository_url' => (string) ($info['repository_url'] ?? ''),
                'last_commit' => is_array($info['last_commit'] ?? null) ? $info['last_commit'] : null,
                'install_available' => !empty($info['install_available']),
                'notes_url' => (string) ($info['notes_url'] ?? ''),
                'download_url' => (string) ($info['download_url'] ?? ''),
                'install_guide_url' => (string) ($info['install_guide_url'] ?? ''),
                'error' => (string) ($info['error'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
