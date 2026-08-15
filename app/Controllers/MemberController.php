<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\View;
use Socly\Services\EnrollmentService;
use Socly\Services\MemberService;
use Socly\Services\PaymentService;
use Socly\Services\SettingsService;

final class MemberController extends BaseController
{
    public function __construct(
        View $view,
        private readonly MemberService $members,
        private readonly PaymentService $payments,
        private readonly SettingsService $settings,
        private readonly EnrollmentService $enrollment
    ) {
        parent::__construct($view);
    }

    private function guardMembers(): void
    {
        require_component('members');
    }

    /** @return array{privacy:string,statute:string} */
    private function legalDocuments(): array
    {
        $decode = static function (mixed $raw): array {
            if (is_array($raw)) {
                return $raw;
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : ['it' => (string) $raw];
        };

        return [
            'privacy' => localized($decode($this->settings->get('legal.privacy', ''))),
            'statute' => localized($decode($this->settings->get('legal.statute', ''))),
        ];
    }

    public function index(Request $request): void
    {
        $this->guardMembers();
        $filters = [
            'q' => (string) $request->input('q', ''),
            'status' => (string) $request->input('status', ''),
            'member_type_id' => $request->input('member_type_id', ''),
            'payment' => (string) $request->input('payment', ''),
        ];
        $page = max(1, (int) $request->input('page', 1));
        $result = $this->members->search($filters, $page);
        $this->render('members/index', [
            'title' => __('members.title'),
            'result' => $result,
            'filters' => $filters,
            'page' => $page,
            'types' => $this->members->types(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->guardMembers();
        // Keep drafted input only when redisplaying after a failed store.
        if (empty($_SESSION['_flash']['errors'])) {
            $this->clearOld();
        }

        $this->render('members/form', [
            'title' => __('members.create'),
            'member' => null,
            'fields' => $this->members->fieldDefinitions(true),
            'formSteps' => $this->members->formSteps(),
            'types' => $this->members->types(true),
            'periods' => $this->members->periods(),
            'nextNumber' => $this->members->nextMemberNumber(),
            'payments' => [],
            'legal' => $this->legalDocuments(),
            'enrollmentMethod' => $this->enrollment->method(),
        ]);
    }

    public function store(Request $request): void
    {
        $this->guardMembers();
        $data = $request->all();
        $fieldData = $data['fields'] ?? [];
        unset($fieldData['photo']);
        $this->rememberOld($data);
        $scan = $request->file('enrollment_scan');
        $check = $this->enrollment->validateCreatePayload($data, $scan);
        if (!$check['ok']) {
            $this->flash('errors', $check['errors'] ?? []);
            redirect('/members/create');
        }
        $result = $this->members->create($data, $fieldData, $request->ip(), $request->nestedFile('fields', 'photo'));
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            redirect('/members/create');
        }
        $this->enrollment->storeArtifact((int) $result['id'], $data, $scan, $request->ip());
        $this->clearOld();
        $this->flash('success', __('members.created'));
        redirect('/members/' . $result['id']);
    }

    public function sendEnrollmentOtp(Request $request): void
    {
        $this->guardMembers();
        $email = (string) $request->input('email', '');
        $result = $this->enrollment->sendOtp($email, $request->ip());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($result['ok'] ? 200 : 422);
        echo json_encode($result);
    }

    public function show(Request $request, string $id): void
    {
        $this->guardMembers();
        $member = $this->members->find((int) $id);
        if (!$member) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        $this->render('members/show', [
            'title' => __('members.show'),
            'member' => $member,
            'fieldDefs' => $this->members->fieldDefinitions(false),
            'payments' => $this->payments->forMember((int) $id),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $this->guardMembers();
        $member = $this->members->find((int) $id);
        if (!$member) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }
        // Don't reuse create/other form drafts when opening an edit screen.
        if (empty($_SESSION['_flash']['errors'])) {
            $this->clearOld();
        }
        $this->render('members/form', [
            'title' => __('members.edit'),
            'member' => $member,
            'fields' => $this->members->fieldDefinitions(true),
            'formSteps' => $this->members->formSteps(),
            'types' => $this->members->types(),
            'periods' => $this->members->periods(),
            'nextNumber' => $member['member_number'],
            'payments' => $this->payments->forMember((int) $id),
            'legal' => $this->legalDocuments(),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $this->guardMembers();
        $data = $request->all();
        $fieldData = $data['fields'] ?? [];
        unset($fieldData['photo']);
        $this->rememberOld($data);
        $result = $this->members->update((int) $id, $data, $fieldData, $request->ip(), $request->nestedFile('fields', 'photo'));
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
            redirect('/members/' . $id . '/edit');
        }
        $this->clearOld();
        $this->flash('success', __('members.updated'));
        redirect('/members/' . $id);
    }

    public function photo(Request $request, string $id): void
    {
        $this->guardMembers();
        $member = $this->members->find((int) $id);
        if (!$member) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $relative = (string) ($member['fields']['photo'] ?? '');
        $absolute = $this->members->memberPhotoAbsolutePath($relative);
        if (!$absolute) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($absolute));
        header('Cache-Control: private, max-age=3600');
        readfile($absolute);
    }

    public function destroy(Request $request, string $id): void
    {
        $this->guardMembers();
        $this->members->delete((int) $id, $request->ip());
        $this->flash('success', __('members.deleted'));
        redirect('/members');
    }

    public function storePayment(Request $request, string $id): void
    {
        $this->guardMembers();
        $result = $this->payments->record((int) $id, $request->all(), $request->ip());
        if (!$result['ok']) {
            $this->flash('errors', $result['errors']);
        } else {
            $this->flash('success', __('payments.recorded'));
        }
        redirect('/members/' . $id);
    }

    public function export(Request $request): void
    {
        $this->guardMembers();
        $filters = [
            'q' => (string) $request->input('q', ''),
            'status' => (string) $request->input('status', ''),
            'member_type_id' => $request->input('member_type_id', ''),
            'payment' => (string) $request->input('payment', ''),
        ];
        $result = $this->members->search($filters, 1, 100000);
        $defs = $this->members->fieldDefinitions(true);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="members-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        $headers = ['member_number', 'status', 'type', 'period', 'balance_due', 'payment_status', 'notes'];
        foreach ($defs as $def) {
            $headers[] = $def['key'];
        }
        fputcsv($out, $headers);
        foreach ($result['items'] as $item) {
            $row = [
                $item['member_number'],
                $item['status'],
                localized($item['type_name_json']),
                $item['period_label'],
                $item['balance_due'],
                $item['payment_status'],
                $item['notes'],
            ];
            foreach ($defs as $def) {
                $row[] = $item['fields'][$def['key']] ?? '';
            }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}
