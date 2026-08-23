<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\AssociationPeopleService;
use Socly\Services\DeadlineService;

final class OrgController extends BaseController
{
    public function __construct(
        View $view,
        private readonly AssociationPeopleService $people,
        private readonly DeadlineService $deadlines
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        require_component('org_roles');
        $byRole = [];
        foreach ($this->people->roles() as $role) {
            $key = (string) ($role['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $byRole[$key] = [
                'role' => $role,
                'people' => $this->people->listByRole($key),
            ];
        }
        $branding = app()->branding();
        $legalCode = strtoupper(trim((string) ($branding['legal_name'] ?? '')));
        $legalLabel = '';
        if ($legalCode !== '') {
            $forms = \Socly\Setup\AssociationLegalForms::all();
            if (isset($forms[$legalCode])) {
                $legalLabel = __($forms[$legalCode]);
            }
        }
        $this->render('org/index', [
            'title' => __('org.title'),
            'byRole' => $byRole,
            'customOrgans' => $this->people->customOrgans(),
            'assocName' => assoc_capitalize_name((string) ($branding['name'] ?? 'SOCLY')),
            'assocLegalCode' => $legalCode,
            'assocLegalLabel' => $legalLabel,
            'canEdit' => $this->canEditOrg(),
        ]);
    }

    public function storeOrgan(Request $request): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $result = $this->people->createOrgan(trim((string) $request->input('label', '')));
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? ['label' => __('validation.required')]);
            redirect('/org');
        }
        $this->flash('success', __('org.organ_saved'));
        redirect('/org');
    }

    public function destroyOrgan(Request $request, string $key): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $result = $this->people->deleteOrgan(rawurldecode($key));
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? ['organ' => __('org.organ_cannot_delete')]);
            redirect('/org');
        }
        $this->flash('success', __('org.organ_deleted'));
        redirect('/org');
    }

    public function create(Request $request): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $roleKey = trim((string) $request->input('role', 'board'));
        if ($this->people->role($roleKey) === null) {
            $roleKey = AssociationPeopleService::ROLE_BOARD;
        }
        $this->renderPersonForm(null, $roleKey);
    }

    public function edit(Request $request, string $id): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $person = $this->people->find((int) $id);
        if ($person === null) {
            http_response_code(404);
            $this->flash('errors', ['person' => __('errors.404')]);
            redirect('/org');
        }
        $this->renderPersonForm($person, (string) ($person['role_key'] ?? 'board'));
    }

    public function store(Request $request): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $result = $this->people->savePerson($request->all(), null);
        if (empty($result['ok'])) {
            if (!empty($result['role_conflict'])) {
                $this->flash('role_conflict', $result['role_conflict']);
            }
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            $role = trim((string) $request->input('role_key', 'board'));
            redirect('/org/people/create?role=' . rawurlencode($role));
        }
        $this->deadlines->syncSystemDeadlines();
        $this->flash('success', __('org.person_saved'));
        redirect('/org');
    }

    public function update(Request $request, string $id): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $result = $this->people->savePerson($request->all(), (int) $id);
        if (empty($result['ok'])) {
            if (!empty($result['role_conflict'])) {
                $this->flash('role_conflict', $result['role_conflict']);
            }
            $this->flash('errors', $result['errors'] ?? []);
            $this->rememberOld($request->all());
            redirect('/org/people/' . (int) $id . '/edit');
        }
        $this->deadlines->syncSystemDeadlines();
        $this->flash('success', __('org.person_saved'));
        redirect('/org');
    }

    public function destroy(Request $request, string $id): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $result = $this->people->deletePerson((int) $id);
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? []);
            redirect('/org/people/' . (int) $id . '/edit');
        }
        $this->deadlines->syncSystemDeadlines();
        $this->flash('success', __('org.person_deleted'));
        redirect('/org');
    }

    /** @param array<string,mixed>|null $person */
    private function renderPersonForm(?array $person, string $roleKey): void
    {
        $old = old_input();
        $values = $old !== [] ? $old : ($person ?? [
            'role_key' => $roleKey,
            'first_name' => '',
            'last_name' => '',
            'fiscal_code' => '',
            'birth_date' => '',
            'gender' => '',
            'birth_place' => '',
            'email' => '',
            'phone' => '',
            'city' => '',
            'postal_code' => '',
            'address' => '',
            'house_number' => '',
            'appointed_at' => '',
            'mandate_ends_at' => '',
            'notes' => '',
        ]);
        if (empty($values['role_key'])) {
            $values['role_key'] = $roleKey;
        }
        $roleMeta = $this->people->role((string) $values['role_key']) ?? $this->people->role($roleKey);
        $roleConflict = flash('role_conflict');
        $this->render('org/person_form', [
            'title' => $person ? __('org.edit_person') : __('org.add_person'),
            'person' => $person,
            'values' => $values,
            'roles' => $this->people->roles(),
            'roleMeta' => $roleMeta,
            'roleConflict' => is_array($roleConflict) ? $roleConflict : null,
            'isEdit' => $person !== null,
        ]);
    }

    private function canEditOrg(): bool
    {
        return can('org.manage') || can('settings.manage');
    }

    private function requireOrgEdit(): void
    {
        if (!$this->canEditOrg()) {
            http_response_code(403);
            $this->flash('errors', ['org' => __('errors.403')]);
            redirect('/org');
        }
    }
}
