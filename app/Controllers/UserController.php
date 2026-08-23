<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\MailService;
use Socly\Services\UserService;

final class UserController extends BaseController
{
    public function __construct(
        View $view,
        private readonly UserService $users,
        private readonly MailService $mail
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
        $result = $this->users->create($data, $data['permissions'] ?? [], $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            $this->redirectUsers('settings');
        }
        if ($this->mail->isReady() && $plainPassword !== '') {
            $this->mail->sendUserWelcome(
                (string) $data['email'],
                $plainPassword,
                (string) ($data['locale'] ?? 'it')
            );
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
        $result = $this->users->update((int) $id, $data, $data['permissions'] ?? [], $request->ip());
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

    /** SuperAdmin accounts stay invisible to association users. */
    private function hiddenSystemAdmin(array $user): bool
    {
        if (!empty($user['is_system_admin']) && empty(auth_user()['is_system_admin'])) {
            return true;
        }
        return trim((string) ($user['name'] ?? '')) === 'SOCLY Platform';
    }

    private function redirectUsers(string $returnTo): void
    {
        if ($returnTo === 'settings') {
            redirect('/settings#users');
        }
        redirect('/users');
    }
}
