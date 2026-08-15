<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\UserService;

final class UserController extends BaseController
{
    public function __construct(
        View $view,
        private readonly UserService $users
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        $viewerIsSuper = !empty(auth_user()['is_system_admin']);
        $this->render('users/index', [
            'title' => __('users.title'),
            'users' => $this->users->all($viewerIsSuper),
        ]);
    }

    public function create(Request $request): void
    {
        $this->render('users/form', [
            'title' => __('users.create'),
            'user' => null,
            'permissions' => $this->users->permissions(),
        ]);
    }

    public function store(Request $request): void
    {
        $data = $request->all();
        $this->rememberOld($data);
        $result = $this->users->create($data, $data['permissions'] ?? [], $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            redirect('/users/create');
        }
        $this->clearOld();
        $this->flash('success', __('users.created'));
        redirect('/users');
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
            'permissions' => $this->users->permissions(),
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
        $this->rememberOld($data);
        $result = $this->users->update((int) $id, $data, $data['permissions'] ?? [], $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            redirect('/users/' . $id . '/edit');
        }
        $this->clearOld();
        $this->flash('success', __('users.updated'));
        redirect('/users');
    }

    public function destroy(Request $request, string $id): void
    {
        $user = $this->users->find((int) $id);
        if (!$user || $this->hiddenSystemAdmin($user)) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $result = $this->users->delete((int) $id, $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', ['user' => __('users.cannot_delete_admin')]);
        } else {
            $this->flash('success', __('users.deleted'));
        }
        redirect('/users');
    }

    /** SuperAdmin accounts stay invisible to association users. */
    private function hiddenSystemAdmin(array $user): bool
    {
        return !empty($user['is_system_admin']) && empty(auth_user()['is_system_admin']);
    }
}
