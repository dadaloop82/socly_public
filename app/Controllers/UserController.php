<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\MailService;
use Socly\Services\SettingsService;
use Socly\Services\UserService;
use Socly\Services\WorkflowService;

final class UserController extends BaseController
{
    public function __construct(
        View $view,
        private readonly UserService $users,
        private readonly MailService $mail,
        private readonly WorkflowService $workflow,
        private readonly SettingsService $settings
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        redirect('/settings#users');
    }

    public function create(Request $request): void
    {
        redirect('/settings#users');
    }

    public function store(Request $request): void
    {
        $data = $request->all();
        $this->rememberOld($data);
        $plainPassword = (string) ($data['password'] ?? '');
        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        if ($permissions === []) {
            // No checkboxes posted (or JS off): the role template is the source of truth.
            $permissions = UserService::rolePermissions((string) ($data['role'] ?? '')) ?? [];
        }
        $result = $this->users->create($data, $permissions, $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            $this->redirectUsers('settings');
        }
        if ($this->mail->isReady() && $plainPassword !== '') {
            $locale = (string) ($data['locale'] ?? 'it');
            $email = (string) $data['email'];
            $sent = $this->workflow->dispatch('user.created', [
                'email' => $email,
                'lang' => $locale,
                'operator_email' => $email,
                'operator_password' => $plainPassword,
                'login_url' => url('/login'),
                'association_name' => (string) $this->settings->get('association.name', ''),
            ], $locale, $request->ip());
            if ($sent === 0) {
                $this->mail->sendUserWelcome($email, $plainPassword, $locale);
            }
        }
        $this->clearOld();
        $this->flash('success', __('users.created'));
        $this->redirectUsers('settings');
    }

    public function edit(Request $request, string $id): void
    {
        $user = $this->users->find((int) $id);
        if (!$user || $this->hiddenSystemAdmin($user)) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $this->render('users/form', [
            'title' => __('users.edit'),
            'user' => $user,
            'returnTo' => (string) $request->input('return', ''),
            'mailReady' => $this->mail->isReady(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $user = $this->users->find((int) $id);
        if (!$user || $this->hiddenSystemAdmin($user)) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $data = $request->all();
        $returnTo = (string) ($data['return'] ?? '');
        $this->rememberOld($data);
        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        if ($permissions === []) {
            $permissions = UserService::rolePermissions((string) ($data['role'] ?? '')) ?? [];
        }
        $result = $this->users->update((int) $id, $data, $permissions, $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            redirect('/users/' . $id . '/edit' . ($returnTo === 'settings' ? '?return=settings' : ''));
        }
        $this->clearOld();
        $this->flash('success', __('users.updated'));
        $this->redirectUsers($returnTo === 'settings' ? 'settings' : '');
    }

    public function destroy(Request $request, string $id): void
    {
        $user = $this->users->find((int) $id);
        if (!$user || $this->hiddenSystemAdmin($user)) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $returnTo = (string) $request->input('return', '');
        $result = $this->users->delete((int) $id, $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', ['user' => __('users.cannot_delete_admin')]);
        } else {
            $this->flash('success', __('users.deleted'));
        }
        $this->redirectUsers($returnTo === 'settings' ? 'settings' : '');
    }

    /** SuperAdmin / maintenance accounts stay invisible to everyone. */
    private function hiddenSystemAdmin(array $user): bool
    {
        return !empty($user['is_system_admin'])
            || trim((string) ($user['name'] ?? '')) === 'SOCLY Platform';
    }

    private function redirectUsers(string $returnTo): void
    {
        if ($returnTo === 'settings') {
            redirect('/settings#users');
        }
        redirect('/users');
    }
}
