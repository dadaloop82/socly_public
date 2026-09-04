<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\AssociationPeopleService;
use Socly\Services\DeadlineService;
use Socly\Services\MemberService;

final class OrgController extends BaseController
{
    public function __construct(
        View $view,
        private readonly AssociationPeopleService $people,
        private readonly DeadlineService $deadlines,
        private readonly MemberService $members
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
            'votingMembersCount' => (int) ($this->members->dashboardStats()['members_active'] ?? 0),
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

    public function history(Request $request): void
    {
        require_component('org_roles');
        $rows = $this->people->history();
        $this->render('org/history', [
            'title' => __('org.history_title'),
            'history' => $rows,
            'canEdit' => $this->canEditOrg(),
        ]);
    }

    public function exportCsv(Request $request): void
    {
        require_component('org_roles');
        $rows = $this->people->exportActive();
        $filename = 'cariche-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            http_response_code(500);
            return;
        }
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, [
            'Ruolo',
            'Nome',
            'Cognome',
            'Codice fiscale',
            'Nomina',
            'Fine mandato',
            'Email',
        ], ';');
        foreach ($rows as $row) {
            $custom = trim((string) ($row['custom_label'] ?? ''));
            $role = $custom !== ''
                ? $custom
                : __((string) ($row['label_key'] ?? ('association.role_' . ($row['role_key'] ?? ''))));
            fputcsv($out, [
                $role,
                (string) ($row['first_name'] ?? ''),
                (string) ($row['last_name'] ?? ''),
                (string) ($row['fiscal_code'] ?? ''),
                (string) ($row['appointed_at'] ?? ''),
                (string) ($row['mandate_ends_at'] ?? ''),
                (string) ($row['email'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    public function memberProfile(Request $request, string $id): void
    {
        require_component('org_roles');
        $this->requireOrgEdit();
        $memberId = (int) $id;
        $roleKey = trim((string) $request->input('role', ''));
        $excludePersonId = (int) $request->input('exclude_person_id', 0);
        $eligibility = $this->members->orgEligibility($memberId);
        $profile = $this->members->orgPersonProfile($memberId);
        $existingRoles = $memberId > 0
            ? $this->people->memberActiveRoles($memberId, $excludePersonId > 0 ? $excludePersonId : null)
            : [];
        header('Content-Type: application/json; charset=utf-8');
        if ($profile === null) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'eligible' => $eligibility,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        $incompatible = [];
        if ($roleKey !== '') {
            foreach ($existingRoles as $item) {
                if ($this->people->rolesIncompatible($roleKey, (string) ($item['role_key'] ?? ''))) {
                    $incompatible[] = $item;
                }
            }
        }
        echo json_encode([
            'ok' => true,
            'eligible' => $eligibility,
            'profile' => $profile,
            'existing_roles' => $existingRoles,
            'incompatible_roles' => $incompatible,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed>|null $person */
    private function renderPersonForm(?array $person, string $roleKey): void
    {
        $old = old_input();
        $values = $old !== [] ? $old : ($person ?? [
            'role_key' => $roleKey,
            'member_id' => '',
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
        if ($person !== null && empty($values['member_id']) && !empty($person['member_id'])) {
            $values['member_id'] = (string) $person['member_id'];
        }
        $memberOptions = $this->members->listActiveForOrgSelect();
        if (!empty($values['member_id'])) {
            $selectedId = (int) $values['member_id'];
            $found = false;
            foreach ($memberOptions as $opt) {
                if ((int) ($opt['id'] ?? 0) === $selectedId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $current = $this->members->find($selectedId);
                if ($current) {
                    $fields = is_array($current['fields'] ?? null) ? $current['fields'] : [];
                    $name = trim((string) (($fields['last_name'] ?? '') . ' ' . ($fields['first_name'] ?? '')));
                    $num = trim((string) ($current['member_number'] ?? ''));
                    array_unshift($memberOptions, [
                        'id' => $selectedId,
                        'member_number' => $num,
                        'first_name' => (string) ($fields['first_name'] ?? ''),
                        'last_name' => (string) ($fields['last_name'] ?? ''),
                        'display_name' => $name !== ''
                            ? ($num !== '' ? $name . ' (' . $num . ')' : $name)
                            : ($num !== '' ? $num : '#' . $selectedId),
                    ]);
                }
            }
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
            'memberOptions' => $memberOptions,
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
