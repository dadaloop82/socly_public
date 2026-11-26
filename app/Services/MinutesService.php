<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Validator;
use Socly\Support\PlausibleDate;

/**
 * “Crea nuovo verbale”: templates, people/quorum, agenda, PDF and archive entry.
 *
 * The wizard never asks the operator to retype data that SOCLY already owns: office
 * holders come from the association chart, members from the member archive and budget
 * totals from the approved forecast. The generated PDF is stored as an ordinary
 * document, so the minute book keeps one single archive.
 */
final class MinutesService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'approved';

    /** Template key => archive category + attendance mode. */
    public const TEMPLATES = [
        'board' => ['category' => 'board_minutes', 'mode' => 'board'],
        'assembly_ordinary' => ['category' => 'minutes', 'mode' => 'assembly'],
        'assembly_extraordinary' => ['category' => 'minutes', 'mode' => 'assembly'],
        'free' => ['category' => 'minutes', 'mode' => 'free'],
        'convocation' => ['category' => 'minutes', 'mode' => 'assembly'],
    ];

    /** Meeting platforms accepted by the video-conference block (free link stays mandatory). */
    private const VIDEO_PLATFORMS = ['socly', 'teams', 'google_meet', 'zoom', 'whatsapp', 'jitsi', 'other'];

    /** Attendance values accepted for board members. */
    private const BOARD_ATTENDANCE = ['present', 'absent_justified', 'absent'];

    /** Attendance values accepted for assembly members. */
    private const MEMBER_ATTENDANCE = ['present', 'delegate', 'absent'];

    public function __construct(
        private readonly Database $db,
        private readonly DocumentService $documents,
        private readonly AssociationPeopleService $people,
        private readonly BudgetService $budgets,
        private readonly SettingsService $settings,
        private readonly MinutesPdfService $pdf,
        private readonly Validator $validator
    ) {
    }

    /**
     * @return list<array{key:string,label:string,description:string,category:string,mode:string}>
     */
    public function templates(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $key => $config) {
            $out[] = [
                'key' => $key,
                'label' => (string) __('documents.minutes_template_' . $key),
                'description' => (string) __('documents.minutes_template_' . $key . '_lede'),
                'category' => (string) $config['category'],
                'mode' => (string) $config['mode'],
            ];
        }

        return $out;
    }

    /** @return array{category:string,mode:string}|null */
    public function template(string $key): ?array
    {
        return self::TEMPLATES[$key] ?? null;
    }

    /** Next progressive number of the year, formatted as “04/2026”. */
    public function nextNumber(string $category, ?string $date = null): string
    {
        $year = date('Y');
        if (is_string($date) && preg_match('/^(\d{4})/', $date, $match)) {
            $year = $match[1];
        }
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS c FROM association_documents
             WHERE deleted_at IS NULL AND category = :category
               AND document_date IS NOT NULL AND YEAR(document_date) = :year',
            ['category' => $category, 'year' => (int) $year]
        );

        return sprintf('%02d/%s', (int) ($row['c'] ?? 0) + 1, $year);
    }

    /** @return array<int,string> person id => “Name — Role” */
    public function peopleOptions(): array
    {
        $out = [];
        foreach ($this->groupedPeople($this->people->all()) as $person) {
            $out[(int) $person['id']] = $person['role'] !== ''
                ? $person['name'] . ' — ' . $person['role']
                : $person['name'];
        }

        return $out;
    }

    /**
     * Board of directors in office, ordered by hierarchy. A person holding several offices is one
     * row with the roles joined (“Segretario · Tesoriere”), never two attendance rows.
     *
     * @return list<array{id:int,name:string,role:string}>
     */
    public function boardMembers(): array
    {
        return array_map(
            static fn (array $person): array => [
                'id' => (int) $person['id'],
                'name' => (string) $person['name'],
                'role' => (string) $person['role'],
            ],
            $this->groupedPeople($this->people->all(), AssociationPeopleService::DIRECTOR_ROLE_KEYS)
        );
    }

    /**
     * The DB stores one row per office (a person with two offices has two rows). Group them by the
     * strongest identity available and merge the role labels, preserving the hierarchy order.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>|null $allowedRoles null = every role
     * @return list<array{id:int,name:string,role:string,roles:list<string>}>
     */
    private function groupedPeople(array $rows, ?array $allowedRoles = null): array
    {
        $out = [];
        $index = [];
        foreach ($rows as $person) {
            $roleKey = (string) ($person['role_key'] ?? '');
            if ($allowedRoles !== null && !in_array($roleKey, $allowedRoles, true)) {
                continue;
            }
            $name = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $role = $this->people->roleLabel([
                'key' => $roleKey,
                'label_key' => (string) ($person['role_label_key'] ?? ('association.role_' . $roleKey)),
            ]);
            $key = $this->personIdentityKey($person, $name);
            if (!isset($index[$key])) {
                $index[$key] = count($out);
                $out[] = [
                    'id' => (int) ($person['id'] ?? 0),
                    'name' => $name,
                    'role' => $role,
                    'roles' => $role !== '' ? [$role] : [],
                ];
                continue;
            }
            if ($role !== '' && !in_array($role, $out[$index[$key]]['roles'], true)) {
                $out[$index[$key]]['roles'][] = $role;
            }
        }
        foreach ($out as &$person) {
            $person['role'] = implode(' · ', $person['roles']);
        }
        unset($person);

        return $out;
    }

    /**
     * Same person across office rows: fiscal code is authoritative, then email/phone, then name.
     * Homonyms without a shared identifier stay distinct.
     *
     * @param array<string,mixed> $person
     */
    private function personIdentityKey(array $person, string $name): string
    {
        $normalize = static fn (string $value): string => mb_strtolower(
            trim((string) preg_replace('/\s+/u', ' ', $value))
        );
        foreach (['fiscal_code', 'email', 'phone'] as $field) {
            $identity = $normalize((string) ($person[$field] ?? ''));
            if ($identity !== '') {
                return 'id:' . $identity;
            }
        }

        return 'name:' . $normalize($name);
    }

    /**
     * Active members with the right to attend the assembly.
     *
     * @return list<array{id:int,label:string,number:string}>
     */
    public function activeMembers(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.member_number,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = m.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = m.id LIMIT 1) AS last_name
             FROM members m
             WHERE m.status = 'active'
             ORDER BY last_name ASC, first_name ASC, m.id ASC"
        );
        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['last_name'] ?? '') . ' ' . (string) ($row['first_name'] ?? ''));
            if ($label === '') {
                $label = (string) __('members.member_number') . ' ' . (string) ($row['member_number'] ?? $row['id']);
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => $label,
                'number' => (string) ($row['member_number'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Members waiting for admission: one click inserts their names in the agenda.
     *
     * @return list<string>
     */
    public function pendingMemberLabels(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.member_number,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = m.id LIMIT 1) AS first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = m.id LIMIT 1) AS last_name
             FROM members m
             WHERE m.status = 'pending'
             ORDER BY last_name ASC, first_name ASC, m.id ASC"
        );
        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['last_name'] ?? '') . ' ' . (string) ($row['first_name'] ?? ''));
            if ($label !== '') {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * Budget totals of a year (forecast), used for the “approvazione bilancio” points.
     *
     * @return array{year:int,income:float,expense:float,need:float,approved:bool}|null
     */
    public function budgetSummary(int $year): ?array
    {
        $budget = $this->budgets->findByYear($year);
        if ($budget === null) {
            return null;
        }

        return [
            'year' => (int) ($budget['fiscal_year'] ?? $year),
            'income' => (float) ($budget['planned_income'] ?? 0),
            'expense' => (float) ($budget['planned_expense'] ?? 0),
            'need' => (float) ($budget['planned_need'] ?? 0),
            'approved' => !empty($budget['is_approved']),
        ];
    }


    /**
     * Validate the wizard and archive the minute as a document (+ generated PDF).
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,id?:int,errors?:array<string,string>}
     */
    public function save(array $input, string $ip, ?int $userId = null): array
    {
        $type = trim((string) ($input['minutes_type'] ?? ''));
        $template = $this->template($type);
        if ($template === null) {
            return ['ok' => false, 'errors' => ['minutes_type' => __('validation.in')]];
        }
        $number = mb_substr(trim((string) ($input['minutes_number'] ?? '')), 0, 40);
        if ($number === '') {
            return ['ok' => false, 'errors' => ['minutes_number' => __('validation.required')]];
        }
        $date = trim((string) ($input['minutes_date'] ?? ''));
        if ($date === '' || !$this->validator->validate(['minutes_date' => $date], ['minutes_date' => 'date'])) {
            return ['ok' => false, 'errors' => ['minutes_date' => __('validation.date')]];
        }
        if (!PlausibleDate::isPlausible($date)) {
            return ['ok' => false, 'errors' => ['minutes_date' => __('validation.year_out_of_range', [
                'min' => (string) PlausibleDate::MIN_YEAR,
                'max' => (string) PlausibleDate::maxYear(),
            ])]];
        }
        $timeOpen = $this->normalizeTime((string) ($input['minutes_time_open'] ?? ''));
        if ($timeOpen === null) {
            return ['ok' => false, 'errors' => ['minutes_time_open' => __('validation.time')]];
        }
        $timeClose = $this->normalizeTime((string) ($input['minutes_time_close'] ?? ''));
        if ($timeClose === null) {
            return ['ok' => false, 'errors' => ['minutes_time_close' => __('validation.time')]];
        }
        $convoked = !empty($input['minutes_convoked']);
        $convocationDate = trim((string) ($input['minutes_convocation_date'] ?? ''));
        if ($convocationDate !== '' && !$this->validator->validate(
            ['minutes_convocation_date' => $convocationDate],
            ['minutes_convocation_date' => 'date']
        )) {
            return ['ok' => false, 'errors' => ['minutes_convocation_date' => __('validation.date')]];
        }

        $placeMode = (string) ($input['minutes_place_mode'] ?? 'in_person');
        if (!in_array($placeMode, ['in_person', 'video'], true)) {
            $placeMode = 'in_person';
        }
        $status = (string) ($input['minutes_status'] ?? self::STATUS_DRAFT);
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_FINAL], true)) {
            $status = self::STATUS_DRAFT;
        }

        $people = $this->peopleOptions();
        $presidentId = (int) ($input['president_id'] ?? 0);
        if ($presidentId > 0 && !isset($people[$presidentId])) {
            return ['ok' => false, 'errors' => ['president_id' => __('validation.in')]];
        }
        $secretaryId = (int) ($input['secretary_id'] ?? 0);
        if ($secretaryId > 0 && !isset($people[$secretaryId])) {
            return ['ok' => false, 'errors' => ['secretary_id' => __('validation.in')]];
        }
        $presidentName = $presidentId > 0 ? $people[$presidentId] : '';
        $secretaryName = $secretaryId > 0 ? $people[$secretaryId] : '';
        $presidentSubstitute = mb_substr(trim((string) ($input['president_substitute'] ?? '')), 0, 120);
        $secretarySubstitute = mb_substr(trim((string) ($input['secretary_substitute'] ?? '')), 0, 120);
        if ($presidentName === '' && $presidentSubstitute !== '') {
            $presidentName = $presidentSubstitute;
        }
        if ($secretaryName === '' && $secretarySubstitute !== '') {
            $secretaryName = $secretarySubstitute;
        }

        $board = $this->resolveBoardAttendance((array) ($input['attendance'] ?? []));
        $assemblyResult = $this->resolveAssemblyAttendance(
            (array) ($input['member_presence'] ?? []),
            (array) ($input['member_delegate_to'] ?? [])
        );
        $assembly = $assemblyResult['rows'];
        $assemblyTotal = $assemblyResult['total'];
        $agenda = $this->parseAgenda($input);
        if ($agenda === []) {
            return ['ok' => false, 'errors' => ['agenda_title' => __('validation.required')]];
        }


        $templateEntry = null;
        foreach ($this->templates() as $entry) {
            if ($entry['key'] === $type) {
                $templateEntry = $entry;
                break;
            }
        }
        $templateLabel = (string) ($templateEntry['label'] ?? $type);
        $titleKey = $type === 'convocation' ? 'documents.minutes_convocation_title' : 'documents.minutes_doc_title';
        $title = (string) __($titleKey, ['number' => $number, 'type' => $templateLabel]);

        $place = mb_substr(trim((string) ($input['minutes_place'] ?? '')), 0, 190);
        $videoLink = mb_substr(trim((string) ($input['minutes_video_link'] ?? '')), 0, 255);
        $videoPlatform = strtolower(trim((string) ($input['minutes_video_platform'] ?? '')));
        if (!in_array($videoPlatform, self::VIDEO_PLATFORMS, true)) {
            $videoPlatform = '';
        }
        if ($place === '' && $placeMode === 'in_person') {
            $place = $this->defaultPlace();
        }
        $delegationLimit = max(0, min(99, (int) ($input['delegation_limit'] ?? 3)));

        $minutes = [
            'type' => $type,
            'type_label' => $templateLabel,
            'mode' => (string) $template['mode'],
            'category' => (string) $template['category'],
            'number' => $number,
            'title' => $title,
            'date' => $date,
            'time_open' => (string) $timeOpen,
            'time_close' => (string) $timeClose,
            'place_mode' => $placeMode,
            'place' => $place,
            'video_link' => $videoLink,
            'video_platform' => $videoPlatform,
            'convoked' => $convoked,
            'convocation_date' => $convocationDate,
            'president' => $presidentName,
            'secretary' => $secretaryName,
            'board' => $board,
            'assembly' => $assembly,
            'assembly_total' => $assemblyTotal,
            'delegation_limit' => $delegationLimit,
            'agenda' => $agenda,
            'notes' => mb_substr(trim((string) ($input['minutes_notes'] ?? '')), 0, 4000),
            'status' => $status,
            'association' => (string) $this->settings->get('association.name', ''),
        ];

        $pdfBytes = $this->pdf->render($this->pdfDocument($minutes));
        $safeNumber = preg_replace('/[^0-9A-Za-z]+/', '-', $number) ?: 'verbale';
        $filename = mb_strtolower('verbale-' . trim((string) $safeNumber, '-') . '-' . $date . '-' . bin2hex(random_bytes(3)) . '.pdf');
        $paths = user_upload_paths('documents', $date, $filename);
        if (@file_put_contents($paths['absolute'], $pdfBytes) === false) {
            return ['ok' => false, 'errors' => ['minutes_type' => __('documents.minutes_pdf_failed')]];
        }

        $locale = 'it';
        try {
            $candidate = (string) app('translator')->getLocale();
            if (in_array($candidate, DocumentService::LANGUAGES, true)) {
                $locale = $candidate;
            }
        } catch (\Throwable) {
            $locale = 'it';
        }

        $result = $this->documents->create([
            'title' => $title,
            'document_number' => $number,
            'category' => (string) $template['category'],
            'language' => $locale,
            'document_date' => $date,
            'summary' => $this->summaryText($minutes),
            'status' => $status,
            'visibility' => 'internal',
            'source_section' => 'documents',
            'source_ref' => 'minutes',
            'uploaded_path' => $paths['relative'],
            'uploaded_mime' => 'application/pdf',
            'uploaded_name' => $filename,
        ], null, $ip, $userId);
        if (empty($result['ok'])) {
            @unlink($paths['absolute']);
            return ['ok' => false, 'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : []];
        }

        return ['ok' => true, 'id' => (int) ($result['id'] ?? 0)];
    }


    /** “14:30” / “14:30:00” → “14:30”; empty stays empty; invalid → null. */
    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $match)) {
            return null;
        }
        $hour = (int) $match[1];
        $minute = (int) $match[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /** Legal seat, pre-filled when the meeting is held in person. */
    public function defaultPlace(): string
    {
        return mb_substr(association_address_full(), 0, 190);
    }

    /**
     * @param array<int|string,mixed> $input
     * @return list<array{id:int,name:string,role:string,attendance:string}>
     */
    private function resolveBoardAttendance(array $input): array
    {
        $byId = [];
        foreach ($this->boardMembers() as $person) {
            $byId[$person['id']] = $person;
        }
        $out = [];
        foreach ($input as $id => $value) {
            $id = (int) $id;
            if (!isset($byId[$id])) {
                continue;
            }
            $state = (string) $value;
            if (!in_array($state, self::BOARD_ATTENDANCE, true)) {
                $state = 'absent';
            }
            $out[] = $byId[$id] + ['attendance' => $state];
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed> $presence
     * @param array<int|string,mixed> $delegates
     * @return array{rows:list<array{id:int,name:string,presence:string,delegate:string}>,total:int}
     */
    private function resolveAssemblyAttendance(array $presence, array $delegates): array
    {
        $members = $this->activeMembers();
        $byId = [];
        foreach ($members as $member) {
            $byId[$member['id']] = $member;
        }
        $out = [];
        foreach ($presence as $id => $value) {
            $id = (int) $id;
            if (!isset($byId[$id])) {
                continue;
            }
            $state = (string) $value;
            if (!in_array($state, self::MEMBER_ATTENDANCE, true)) {
                $state = 'absent';
            }
            $delegate = $state === 'delegate'
                ? mb_substr(trim((string) ($delegates[$id] ?? '')), 0, 120)
                : '';
            $out[] = [
                'id' => $id,
                'name' => (string) $byId[$id]['label'],
                'presence' => $state,
                'delegate' => $delegate,
            ];
        }

        return ['rows' => $out, 'total' => count($members)];
    }

    /**
     * @param array<string,mixed> $input
     * @return list<array{title:string,text:string,outcome:string,yes:int,no:int,abstain:int}>
     */
    private function parseAgenda(array $input): array
    {
        $titles = array_values((array) ($input['agenda_title'] ?? []));
        $texts = array_values((array) ($input['agenda_text'] ?? []));
        $outcomes = array_values((array) ($input['agenda_outcome'] ?? []));
        $yes = array_values((array) ($input['agenda_yes'] ?? []));
        $no = array_values((array) ($input['agenda_no'] ?? []));
        $abstain = array_values((array) ($input['agenda_abstain'] ?? []));
        $out = [];
        foreach ($titles as $index => $rawTitle) {
            $title = mb_substr(trim((string) $rawTitle), 0, 190);
            if ($title === '') {
                continue;
            }
            $outcome = (string) ($outcomes[$index] ?? 'majority');
            if (!in_array($outcome, ['unanimous', 'majority', 'rejected', 'not_voted'], true)) {
                $outcome = 'majority';
            }
            $out[] = [
                'title' => $title,
                'text' => mb_substr(trim((string) ($texts[$index] ?? '')), 0, 4000),
                'outcome' => $outcome,
                'yes' => max(0, min(9999, (int) ($yes[$index] ?? 0))),
                'no' => max(0, min(9999, (int) ($no[$index] ?? 0))),
                'abstain' => max(0, min(9999, (int) ($abstain[$index] ?? 0))),
            ];
        }

        return $out;
    }


    /** Attendance state label for board members. */
    private function attendanceLabel(string $state): string
    {
        return (string) __('documents.minutes_attendance_' . $state);
    }

    /** Presence label for assembly members (delegation carries the delegate name). */
    private function presenceLabel(array $row): string
    {
        $state = (string) ($row['presence'] ?? 'absent');
        if ($state === 'delegate') {
            $delegate = trim((string) ($row['delegate'] ?? ''));

            return $delegate !== ''
                ? (string) __('documents.minutes_presence_delegate', ['name' => $delegate])
                : (string) __('documents.minutes_presence_delegate_generic');
        }

        return (string) __('documents.minutes_presence_' . $state);
    }

    /**
     * @param array<string,mixed> $minutes
     * @return array{present:int,delegations:int,total:int,required:int,reached:bool}
     */
    private function quorum(array $minutes): array
    {
        if (($minutes['mode'] ?? '') === 'assembly') {
            $rows = (array) ($minutes['assembly'] ?? []);
            $present = 0;
            $delegations = 0;
            foreach ($rows as $row) {
                if ((string) ($row['presence'] ?? '') === 'present') {
                    $present++;
                } elseif ((string) ($row['presence'] ?? '') === 'delegate') {
                    $delegations++;
                }
            }
            $total = (int) ($minutes['assembly_total'] ?? count($rows));
        } else {
            $rows = (array) ($minutes['board'] ?? []);
            $present = 0;
            foreach ($rows as $row) {
                if ((string) ($row['attendance'] ?? '') === 'present') {
                    $present++;
                }
            }
            $delegations = 0;
            $total = count($rows);
        }
        $required = $total > 0 ? intdiv($total, 2) + 1 : 0;

        return [
            'present' => $present,
            'delegations' => $delegations,
            'total' => $total,
            'required' => $required,
            'reached' => $total > 0 && ($present + $delegations) >= $required,
        ];
    }


    /**
     * Plain-text summary shown in the archive row and full-text search.
     *
     * @param array<string,mixed> $minutes
     */
    private function summaryText(array $minutes): string
    {
        $lines = [];
        $lines[] = (string) ($minutes['title'] ?? '');
        $lines[] = (string) __('documents.minutes_summary_date', [
            'date' => format_date((string) ($minutes['date'] ?? '')) ?: (string) ($minutes['date'] ?? ''),
        ]);
        if (($minutes['place_mode'] ?? '') === 'video') {
            $video = $this->videoValue($minutes);
            if ($video !== '') {
                $lines[] = (string) __('documents.minutes_summary_video', ['value' => $video]);
            }
        }
        $mode = (string) ($minutes['mode'] ?? 'free');
        if (in_array($mode, ['board', 'assembly'], true)) {
            $quorum = $this->quorum($minutes);
            $lines[] = (string) __('documents.minutes_summary_attendance_' . $mode, [
                'present' => (string) $quorum['present'],
                'delegations' => (string) $quorum['delegations'],
                'total' => (string) $quorum['total'],
                'required' => (string) $quorum['required'],
            ]);
            $lines[] = (string) ($quorum['reached']
                ? __('documents.minutes_quorum_reached')
                : __('documents.minutes_quorum_missing'));
        }
        $titles = [];
        foreach ((array) ($minutes['agenda'] ?? []) as $index => $item) {
            $titles[] = ($index + 1) . ') ' . (string) ($item['title'] ?? '');
        }
        if ($titles !== []) {
            $lines[] = (string) __('documents.minutes_summary_agenda', ['items' => implode(' · ', $titles)]);
        }
        $outcomes = [];
        foreach ((array) ($minutes['agenda'] ?? []) as $item) {
            $outcomes[] = (string) __('documents.minutes_outcome_' . (string) ($item['outcome'] ?? 'majority'));
        }
        if ($outcomes !== []) {
            $lines[] = (string) __('documents.minutes_summary_outcome', ['outcome' => implode(' · ', $outcomes)]);
        }

        return mb_substr(implode("\n", array_filter($lines, static fn (string $l): bool => trim($l) !== '')), 0, 4000);
    }


    /**
     * “Microsoft Teams — https://…” for the PDF and the archive summary. An unknown/empty
     * platform simply leaves the pasted link, so the field never invents a URL.
     *
     * @param array<string,mixed> $minutes
     */
    private function videoValue(array $minutes): string
    {
        $parts = array_values(array_filter([
            $this->videoPlatformLabel((string) ($minutes['video_platform'] ?? '')),
            trim((string) ($minutes['video_link'] ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        return implode(' — ', $parts);
    }

    private function videoPlatformLabel(string $key): string
    {
        return match ($key) {
            'socly' => 'Socly',
            'teams' => 'Microsoft Teams',
            'google_meet' => 'Google Meet',
            'zoom' => 'Zoom',
            'whatsapp' => 'WhatsApp',
            'jitsi' => 'Jitsi Meet',
            'other' => (string) __('documents.minutes_platform_other'),
            default => '',
        };
    }

    /**
     * Structured content handed to the PDF writer.
     *
     * @param array<string,mixed> $minutes
     * @return array{
     *   title:string,association:string,status_label:string,brand:array{r:int,g:int,b:int},
     *   meta:list<array{label:string,value:string}>,
     *   sections:list<array{title:string,lines:list<string>}>,
     *   closing:string,
     *   signatures:list<array{role:string,name:string}>
     * }
     */
    private function pdfDocument(array $minutes): array
    {
        $meta = [
            ['label' => (string) __('documents.minutes_field_date'), 'value' => format_date((string) ($minutes['date'] ?? '')) ?: (string) ($minutes['date'] ?? '')],
            ['label' => (string) __('documents.minutes_field_time_open'), 'value' => (string) ($minutes['time_open'] ?? '')],
        ];
        if (($minutes['place_mode'] ?? 'in_person') === 'video') {
            $meta[] = ['label' => (string) __('documents.minutes_field_video'), 'value' => $this->videoValue($minutes)];
        } else {
            $meta[] = ['label' => (string) __('documents.minutes_field_place'), 'value' => (string) ($minutes['place'] ?? '')];
        }
        if (!empty($minutes['convoked'])) {
            $date = (string) ($minutes['convocation_date'] ?? '');
            $meta[] = [
                'label' => (string) __('documents.minutes_field_convocation'),
                'value' => $date !== ''
                    ? (string) __('documents.minutes_convoked_on', ['date' => format_date($date) ?: $date])
                    : (string) __('documents.minutes_convoked_regularly'),
            ];
        }
        $meta[] = ['label' => (string) __('documents.minutes_field_president'), 'value' => (string) ($minutes['president'] ?? '')];
        $meta[] = ['label' => (string) __('documents.minutes_field_secretary'), 'value' => (string) ($minutes['secretary'] ?? '')];

        $sections = [];
        $mode = (string) ($minutes['mode'] ?? 'free');
        if (in_array($mode, ['board', 'assembly'], true)) {
            $lines = [];
            if ($mode === 'board') {
                foreach ((array) ($minutes['board'] ?? []) as $row) {
                    $lines[] = sprintf(
                        '%s — %s: %s',
                        (string) ($row['name'] ?? ''),
                        (string) ($row['role'] ?? ''),
                        $this->attendanceLabel((string) ($row['attendance'] ?? 'absent'))
                    );
                }
            } else {
                $absent = 0;
                foreach ((array) ($minutes['assembly'] ?? []) as $row) {
                    if ((string) ($row['presence'] ?? '') === 'absent') {
                        $absent++;
                        continue;
                    }
                    $lines[] = (string) ($row['name'] ?? '') . ': ' . $this->presenceLabel($row);
                }
                if ($absent > 0) {
                    $lines[] = '';
                    $lines[] = (string) __('documents.minutes_pdf_absent_line', ['count' => (string) $absent]);
                }
            }
            $quorum = $this->quorum($minutes);
            $lines[] = '';
            $lines[] = (string) __('documents.minutes_summary_attendance_' . $mode, [
                'present' => (string) $quorum['present'],
                'delegations' => (string) $quorum['delegations'],
                'total' => (string) $quorum['total'],
                'required' => (string) $quorum['required'],
            ]);
            $lines[] = (string) ($quorum['reached']
                ? __('documents.minutes_quorum_reached')
                : __('documents.minutes_quorum_missing'));
            $sections[] = ['title' => (string) __('documents.minutes_pdf_attendance'), 'lines' => $lines];
        }

        $agendaLines = [];
        foreach ((array) ($minutes['agenda'] ?? []) as $index => $item) {
            $agendaLines[] = ($index + 1) . '. ' . (string) ($item['title'] ?? '');
            $text = trim((string) ($item['text'] ?? ''));
            if ($text !== '') {
                $agendaLines[] = $text;
            }
            $counts = (string) __('documents.minutes_outcome_counts', [
                'yes' => (string) ($item['yes'] ?? 0),
                'no' => (string) ($item['no'] ?? 0),
                'abstain' => (string) ($item['abstain'] ?? 0),
            ]);
            $agendaLines[] = (string) __('documents.minutes_outcome_line', [
                'outcome' => (string) __('documents.minutes_outcome_' . (string) ($item['outcome'] ?? 'majority')),
                'counts' => $counts,
            ]);
            $agendaLines[] = '';
        }
        if ($agendaLines !== []) {
            $sections[] = ['title' => (string) __('documents.minutes_pdf_agenda'), 'lines' => $agendaLines];
        }


        $notes = trim((string) ($minutes['notes'] ?? ''));
        if ($notes !== '') {
            $sections[] = ['title' => (string) __('documents.minutes_pdf_notes'), 'lines' => [$notes]];
        }

        $closing = '';
        $timeClose = (string) ($minutes['time_close'] ?? '');
        if ($timeClose !== '') {
            $closing = (string) __('documents.minutes_closing', ['time' => $timeClose]);
        }
        $closing .= ($closing !== '' ? ' ' : '') . (string) __('documents.minutes_closing_place', [
            'place' => (string) ($minutes['place'] ?? ''),
            'date' => format_date((string) ($minutes['date'] ?? '')) ?: (string) ($minutes['date'] ?? ''),
        ]);

        return [
            'title' => (string) ($minutes['title'] ?? ''),
            'association' => (string) ($minutes['association'] ?? ''),
            'status_label' => (string) ($minutes['status'] ?? '') === self::STATUS_DRAFT
                ? (string) __('documents.minutes_state_draft')
                : (string) __('documents.minutes_state_final'),
            'watermark' => (string) ($minutes['status'] ?? '') === self::STATUS_DRAFT
                ? (string) __('documents.minutes_state_draft')
                : '',
            'brand' => $this->hexRgb((string) $this->settings->get('branding.primary', '#0D6E66')),
            'logo_path' => (string) $this->settings->get('branding.logo', ''),
            'meta' => $meta,
            'sections' => $sections,
            'closing' => trim($closing),
            'signatures' => [
                ['role' => (string) __('documents.minutes_signature_president'), 'name' => (string) ($minutes['president'] ?? '')],
                ['role' => (string) __('documents.minutes_signature_secretary'), 'name' => (string) ($minutes['secretary'] ?? '')],
            ],
        ];
    }

    /** @return array{r:int,g:int,b:int} */
    private function hexRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return ['r' => 13, 'g' => 110, 'b' => 102];
        }

        return [
            'r' => (int) hexdec(substr($hex, 0, 2)),
            'g' => (int) hexdec(substr($hex, 2, 2)),
            'b' => (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
