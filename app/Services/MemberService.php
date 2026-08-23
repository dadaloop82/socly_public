<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Plugin\PluginManager;
use Socly\Core\Validator;
use Socly\Support\MemberFieldTypes;

final class MemberService
{
    public const STEP_TESSERA = 'tessera';
    public const STEP_ACKNOWLEDGEMENTS = 'acknowledgements';
    public const STEP_PAYMENT = 'payment';

    /** @return list<string> */
    public static function systemFormStepKeys(): array
    {
        return [self::STEP_TESSERA, self::STEP_ACKNOWLEDGEMENTS, self::STEP_PAYMENT];
    }

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Validator $validator,
        private readonly PluginManager $plugins,
        private readonly TreasuryService $treasury
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function fieldDefinitions(bool $enabledOnly = true): array
    {
        $this->ensureDefaultFormSteps();
        $this->repairEmptyCustomFormSteps();
        $this->normalizeProfileFieldPlacement();
        $sql = 'SELECT * FROM member_field_definitions';
        if ($enabledOnly) {
            $sql .= ' WHERE is_enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return $this->db->fetchAll($sql);
    }

    /**
     * Configurable field wizard steps (titles are multilingual JSON).
     *
     * @return list<array{id?:int,key:string,title_json:string|array,sort_order:int}>
     */
    public function formSteps(): array
    {
        $this->ensureDefaultFormSteps();
        return $this->db->fetchAll(
            'SELECT * FROM member_form_steps ORDER BY sort_order ASC, id ASC'
        );
    }

    public function ensureDefaultFormSteps(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $exists = $this->db->fetch('SELECT id FROM member_form_steps LIMIT 1');
        } catch (\Throwable) {
            return;
        }
        if ($exists) {
            $done = true;
            return;
        }
        $this->db->insert('member_form_steps', [
            'key' => 'profile',
            'title_json' => json_encode([
                'it' => 'Anagrafica',
                'de' => 'Stammdaten',
                'en' => 'Profile',
            ], JSON_UNESCAPED_UNICODE),
            'sort_order' => 10,
        ]);
        try {
            $this->db->query(
                "UPDATE member_field_definitions
                 SET form_step = CASE
                    WHEN field_type = 'checkbox' OR `key` IN ('privacy_ack','statute_ack') THEN 'acknowledgements'
                    ELSE 'profile'
                 END
                 WHERE form_step = '' OR form_step IS NULL OR form_step = 'profile'"
            );
        } catch (\Throwable) {
            // Column may not exist yet during early boot.
        }
        $done = true;
    }

    /**
     * Replace all field-form steps. Keys must be unique and non-empty.
     *
     * @param list<array{key:string,title_json:array<string,string>|string,sort_order?:int}> $steps
     */
    public function replaceFormSteps(array $steps): void
    {
        $normalized = [];
        $sort = 10;
        foreach ($steps as $step) {
            $key = trim((string) ($step['key'] ?? ''));
            if ($key === '' || isset($normalized[$key])) {
                continue;
            }
            if (in_array($key, self::systemFormStepKeys(), true)) {
                continue;
            }
            $titles = $step['title_json'] ?? [];
            if (is_string($titles)) {
                $decoded = json_decode($titles, true);
                $titles = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($titles)) {
                $titles = [];
            }
            $it = trim((string) ($titles['it'] ?? $titles['en'] ?? $titles['de'] ?? $key));
            if ($it === '') {
                $it = $key;
            }
            $normalized[$key] = [
                'key' => $key,
                'title_json' => json_encode([
                    'it' => $it,
                    'de' => trim((string) ($titles['de'] ?? $it)) ?: $it,
                    'en' => trim((string) ($titles['en'] ?? $it)) ?: $it,
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => (int) ($step['sort_order'] ?? $sort),
            ];
            $sort += 10;
        }
        if ($normalized === []) {
            $normalized['profile'] = [
                'key' => 'profile',
                'title_json' => json_encode([
                    'it' => 'Anagrafica',
                    'de' => 'Stammdaten',
                    'en' => 'Profile',
                ], JSON_UNESCAPED_UNICODE),
                'sort_order' => 10,
            ];
        }

        $this->db->query('DELETE FROM member_form_steps');
        foreach ($normalized as $row) {
            $this->db->insert('member_form_steps', $row);
        }
    }

    /**
     * Persist form steps + field order/step assignment from setup/settings POST.
     *
     * @param array<string, mixed> $input
     * @return list<string> Valid form step keys after save
     */
    public function saveFormStepsFromInput(array $input): array
    {
        $keys = $input['form_steps'] ?? [];
        if (!is_array($keys)) {
            $keys = [];
        }
        $titleIt = is_array($input['form_step_title_it'] ?? null) ? $input['form_step_title_it'] : [];
        $titleDe = is_array($input['form_step_title_de'] ?? null) ? $input['form_step_title_de'] : [];
        $titleEn = is_array($input['form_step_title_en'] ?? null) ? $input['form_step_title_en'] : [];
        $system = array_fill_keys(self::systemFormStepKeys(), true);

        $steps = [];
        $sort = 10;
        foreach ($keys as $rawKey) {
            $key = trim((string) $rawKey);
            if ($key === '' || isset($system[$key])) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '';
            $key = strtolower($key);
            if ($key === '' || isset($system[$key]) || isset($steps[$key])) {
                continue;
            }
            $steps[$key] = [
                'key' => $key,
                'title_json' => [
                    'it' => trim((string) ($titleIt[$rawKey] ?? $titleIt[$key] ?? '')),
                    'de' => trim((string) ($titleDe[$rawKey] ?? $titleDe[$key] ?? '')),
                    'en' => trim((string) ($titleEn[$rawKey] ?? $titleEn[$key] ?? '')),
                ],
                'sort_order' => $sort,
            ];
            $sort += 10;
        }
        $this->replaceFormSteps(array_values($steps));
        // replaceFormSteps() always keeps at least "profile" when input is empty —
        // the return list must include that custom key, otherwise resolveFieldFormStep
        // falls through to the first system step (tessera) and empties step 1.
        $customKeys = array_keys($steps);
        if ($customKeys === []) {
            $customKeys = ['profile'];
        }
        return array_values(array_unique([...$customKeys, ...self::systemFormStepKeys()]));
    }

    /**
     * Resolve target form_step for a field from POST map.
     *
     * @param array<string, string> $fieldSteps
     * @param list<string> $validSteps
     */
    public function resolveFieldFormStep(string $key, string $fieldType, array $fieldSteps, array $validSteps, ?string $fallback = null): string
    {
        if (in_array($key, ['privacy_ack', 'statute_ack'], true)) {
            return self::STEP_ACKNOWLEDGEMENTS;
        }
        $system = array_fill_keys(self::systemFormStepKeys(), true);
        $firstCustom = 'profile';
        foreach ($validSteps as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate !== '' && !isset($system[$candidate])) {
                $firstCustom = $candidate;
                break;
            }
        }
        $requested = trim((string) ($fieldSteps[$key] ?? ''));
        if ($requested !== '' && in_array($requested, $validSteps, true)) {
            return $requested;
        }
        if ($fallback !== null && $fallback !== '' && in_array($fallback, $validSteps, true) && !isset($system[$fallback])) {
            return $fallback;
        }
        return $firstCustom;
    }

    /**
     * If every custom wizard step has zero fields, move non-legal fields off system
     * steps (usually a bad autosave that parked everything on tessera).
     */
    public function repairEmptyCustomFormSteps(): void
    {
        try {
            $steps = $this->db->fetchAll('SELECT `key` FROM member_form_steps ORDER BY sort_order ASC, id ASC');
        } catch (\Throwable) {
            return;
        }
        $system = array_fill_keys(self::systemFormStepKeys(), true);
        $customKeys = [];
        foreach ($steps as $step) {
            $key = (string) ($step['key'] ?? '');
            if ($key !== '' && !isset($system[$key])) {
                $customKeys[] = $key;
            }
        }
        if ($customKeys === []) {
            $customKeys = ['profile'];
            try {
                $this->ensureDefaultFormSteps();
            } catch (\Throwable) {
                return;
            }
        }
        $placeholders = implode(',', array_fill(0, count($customKeys), '?'));
        try {
            $onCustom = $this->db->fetch(
                "SELECT COUNT(*) c FROM member_field_definitions
                 WHERE is_enabled = 1 AND form_step IN ($placeholders)",
                $customKeys
            );
        } catch (\Throwable) {
            return;
        }
        if ((int) ($onCustom['c'] ?? 0) > 0) {
            return;
        }
        $target = $customKeys[0];
        $systemKeys = self::systemFormStepKeys();
        $sysPlaceholders = implode(',', array_fill(0, count($systemKeys), '?'));
        try {
            $this->db->query(
                "UPDATE member_field_definitions
                 SET form_step = ?
                 WHERE form_step IN ($sysPlaceholders)
                   AND `key` NOT IN ('privacy_ack', 'statute_ack')",
                [$target, ...$systemKeys]
            );
        } catch (\Throwable) {
            // ignore
        }
    }

    /** Keep core anagrafica fields on a custom wizard step, not tessera/payment system steps. */
    public function normalizeProfileFieldPlacement(): void
    {
        $profileKeys = [
            'photo', 'first_name', 'last_name', 'gender', 'preferred_language',
            'birth_place', 'birth_date', 'fiscal_code',
            'city', 'address', 'house_number', 'postal_code',
            'email', 'phone',
        ];
        try {
            $steps = $this->db->fetchAll('SELECT `key` FROM member_form_steps ORDER BY sort_order ASC, id ASC');
        } catch (\Throwable) {
            return;
        }
        $system = array_fill_keys(self::systemFormStepKeys(), true);
        $target = 'profile';
        foreach ($steps as $step) {
            $key = (string) ($step['key'] ?? '');
            if ($key !== '' && !isset($system[$key])) {
                $target = $key;
                break;
            }
        }
        $sysKeys = self::systemFormStepKeys();
        $sysPlaceholders = implode(',', array_fill(0, count($sysKeys), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($profileKeys), '?'));
        try {
            $this->db->query(
                "UPDATE member_field_definitions
                 SET form_step = ?
                 WHERE is_enabled = 1
                   AND form_step IN ($sysPlaceholders)
                   AND `key` IN ($keyPlaceholders)",
                [$target, ...$sysKeys, ...$profileKeys]
            );
        } catch (\Throwable) {
            // ignore
        }
    }

    public function totalCount(): int
    {
        try {
            return (int) ($this->db->fetch('SELECT COUNT(*) AS c FROM members')['c'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function isSystemLockedFieldKey(string $key): bool
    {
        return in_array($key, ['privacy_ack', 'statute_ack'], true);
    }

    /**
     * @param list<array<string,mixed>> $formSteps
     * @return list<array{key:string,title_json:string|array,sort_order:int,is_system:bool}>
     */
    public function editorFormSteps(array $formSteps = []): array
    {
        $custom = [];
        foreach ($formSteps as $step) {
            $key = (string) ($step['key'] ?? '');
            if ($key === '' || in_array($key, self::systemFormStepKeys(), true)) {
                continue;
            }
            $custom[] = [
                'key' => $key,
                'title_json' => $step['title_json'] ?? [],
                'sort_order' => (int) ($step['sort_order'] ?? 10),
                'is_system' => false,
            ];
        }
        if ($custom === []) {
            $custom[] = [
                'key' => 'profile',
                'title_json' => [
                    'it' => 'Anagrafica',
                    'de' => 'Stammdaten',
                    'en' => 'Profile',
                ],
                'sort_order' => 10,
                'is_system' => false,
            ];
        }

        return [
            ...$custom,
            [
                'key' => self::STEP_TESSERA,
                'title_json' => [
                    'it' => __('members.wizard_step2_title'),
                    'de' => __('members.wizard_step2_title'),
                    'en' => __('members.wizard_step2_title'),
                ],
                'sort_order' => 900,
                'is_system' => true,
            ],
            [
                'key' => self::STEP_ACKNOWLEDGEMENTS,
                'title_json' => [
                    'it' => __('members.wizard_step3_title'),
                    'de' => __('members.wizard_step3_title'),
                    'en' => __('members.wizard_step3_title'),
                ],
                'sort_order' => 910,
                'is_system' => true,
            ],
            [
                'key' => self::STEP_PAYMENT,
                'title_json' => [
                    'it' => __('members.wizard_step4_title'),
                    'de' => __('members.wizard_step4_title'),
                    'en' => __('members.wizard_step4_title'),
                ],
                'sort_order' => 920,
                'is_system' => true,
            ],
        ];
    }

    /**
     * @return array<string, list<array{key:string,label:string}>>
     */
    public function systemStepPlaceholders(): array
    {
        return [
            self::STEP_TESSERA => [
                ['key' => '_sys_member_number', 'label' => __('members.member_number')],
                ['key' => '_sys_status', 'label' => __('members.status')],
                ['key' => '_sys_type', 'label' => __('members.type')],
                ['key' => '_sys_period', 'label' => __('members.period')],
                ['key' => '_sys_payment', 'label' => __('members.payment')],
                ['key' => '_sys_payment_method', 'label' => __('members.payment_method')],
                ['key' => '_sys_notes', 'label' => __('members.notes')],
            ],
            self::STEP_PAYMENT => [],
            self::STEP_ACKNOWLEDGEMENTS => [],
        ];
    }

    /**
     * Persist fields layout (steps, order, enable/required/type) from setup/settings POST.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function persistFieldsConfig(array $input, bool $createNew = true): array
    {
        $enabled = $input['fields'] ?? [];
        $required = $input['required'] ?? [];
        $types = $input['field_types'] ?? [];
        $fieldSteps = $input['field_step'] ?? [];
        if (!is_array($enabled)) {
            $enabled = [];
        }
        if (!is_array($required)) {
            $required = [];
        }
        if (!is_array($types)) {
            $types = [];
        }
        if (!is_array($fieldSteps)) {
            $fieldSteps = [];
        }
        $enabled = array_map('strval', $enabled);
        $required = array_map('strval', $required);
        /** @var array<string, string> $fieldSteps */
        $fieldSteps = array_map('strval', $fieldSteps);
        $validSteps = $this->saveFormStepsFromInput($input);
        $sortByKey = $this->fieldSortMap($input['field_order'] ?? null);

        foreach ($this->fieldDefinitions(false) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $locked = MemberFieldTypes::lockedTypeForKey($key);
            $typeRaw = (string) ($types[$key] ?? $field['field_type'] ?? MemberFieldTypes::TEXT);
            $type = $locked ?? MemberFieldTypes::resolve($typeRaw, $key);
            $core = MemberFieldTypes::isCoreArchiveField($key);
            $isEnabled = $core || in_array($key, $enabled, true);
            $isRequired = $isEnabled && ($core || in_array($key, $required, true));
            $payload = [
                'field_type' => $type,
                'validation_rule' => MemberFieldTypes::validationRule($type, $isRequired),
                'is_enabled' => $isEnabled ? 1 : 0,
                'is_required' => $isRequired ? 1 : 0,
                'form_step' => $this->resolveFieldFormStep(
                    $key,
                    $type,
                    $fieldSteps,
                    $validSteps,
                    (string) ($field['form_step'] ?? 'profile')
                ),
            ];
            if (isset($sortByKey[$key])) {
                $payload['sort_order'] = $sortByKey[$key];
            }
            $this->db->update('member_field_definitions', $payload, 'id = :id', ['id' => (int) $field['id']]);
        }

        if (!$createNew) {
            return ['ok' => true];
        }

        $newLabel = trim((string) ($input['new_label'] ?? ''));
        $newKeyRaw = trim((string) ($input['new_key'] ?? ''));
        if ($newLabel === '' && $newKeyRaw === '') {
            return ['ok' => true];
        }

        $newTypeRaw = trim((string) ($input['new_type'] ?? MemberFieldTypes::TEXT));
        $newType = MemberFieldTypes::isValid($newTypeRaw) ? $newTypeRaw : MemberFieldTypes::TEXT;
        $newKey = $newKeyRaw !== ''
            ? MemberFieldTypes::slugifyKey($newKeyRaw)
            : MemberFieldTypes::slugifyKey($newLabel !== '' ? $newLabel : 'campo');
        $locked = MemberFieldTypes::lockedTypeForKey($newKey);
        if ($locked !== null) {
            $newType = $locked;
        }
        if ($newLabel === '') {
            $newLabel = $newKey;
        }
        if ($this->db->fetch('SELECT id FROM member_field_definitions WHERE `key` = :k', ['k' => $newKey])) {
            return ['ok' => false, 'errors' => ['new_key' => __('setup.fields_key_exists')]];
        }

        $newEnabled = array_key_exists('new_enabled', $input) ? !empty($input['new_enabled']) : true;
        $newRequired = $newEnabled && !empty($input['new_required']);
        $maxSort = (int) ($this->db->fetch('SELECT COALESCE(MAX(sort_order), 0) AS m FROM member_field_definitions')['m'] ?? 0);
        $newStep = $validSteps[0] ?? 'profile';
        $requestedStep = trim((string) ($input['new_step'] ?? ''));
        if ($this->isSystemLockedFieldKey($newKey)) {
            $newStep = self::STEP_ACKNOWLEDGEMENTS;
        } elseif ($requestedStep !== '' && in_array($requestedStep, $validSteps, true)) {
            $newStep = $requestedStep;
        }

        $this->db->insert('member_field_definitions', [
            'key' => $newKey,
            'field_type' => $newType,
            'label_json' => json_encode([
                'it' => $newLabel,
                'de' => $newLabel,
                'en' => $newLabel,
            ], JSON_UNESCAPED_UNICODE),
            'is_required' => $newRequired ? 1 : 0,
            'validation_rule' => MemberFieldTypes::validationRule($newType, $newRequired),
            'is_enabled' => $newEnabled ? 1 : 0,
            'sort_order' => $maxSort + 10,
            'form_step' => $newStep,
        ]);

        return ['ok' => true];
    }

    /**
     * @param mixed $order
     * @return array<string, int>
     */
    private function fieldSortMap(mixed $order): array
    {
        if (!is_array($order)) {
            return [];
        }
        $map = [];
        $sort = 10;
        foreach ($order as $key) {
            $key = trim((string) $key);
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $map[$key] = $sort;
            $sort += 10;
        }
        return $map;
    }

    /** @return list<array<string,mixed>> */
    public function types(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM member_types';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return $this->db->fetchAll($sql);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function persistTypesConfig(array $input): array
    {
        $existing = $input['types'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        foreach ($existing as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) $id;
            if ($id <= 0 || !$this->db->fetch('SELECT id FROM member_types WHERE id = :id', ['id' => $id])) {
                continue;
            }
            $nameIt = trim((string) ($row['name_it'] ?? ''));
            $nameDe = trim((string) ($row['name_de'] ?? ''));
            $nameEn = trim((string) ($row['name_en'] ?? ''));
            $priceRaw = trim((string) ($row['price'] ?? ''));
            if ($nameIt === '') {
                return ['ok' => false, 'errors' => ['types' => __('validation.required')]];
            }
            if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
                return ['ok' => false, 'errors' => ['price' => __('validation.required')]];
            }
            $names = [
                'it' => $nameIt,
                'de' => $nameDe !== '' ? $nameDe : $nameIt,
                'en' => $nameEn !== '' ? $nameEn : $nameIt,
            ];
            $allTypes = $this->types(false);
            $isActive = !empty($row['is_active']) ? 1 : 0;
            if (count($allTypes) === 1) {
                $isActive = 1;
            }
            $this->db->update('member_types', [
                'name_json' => json_encode($names, JSON_UNESCAPED_UNICODE),
                'price' => (float) $priceRaw,
                'is_active' => $isActive,
            ], 'id = :id', ['id' => $id]);
        }

        $nameIt = trim((string) ($input['name_it'] ?? ''));
        $nameDe = trim((string) ($input['name_de'] ?? ''));
        $nameEn = trim((string) ($input['name_en'] ?? ''));
        $priceRaw = trim((string) ($input['price'] ?? ''));

        if ($nameIt !== '') {
            if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0) {
                return ['ok' => false, 'errors' => ['price' => __('validation.required')]];
            }
            $names = [
                'it' => $nameIt,
                'de' => $nameDe !== '' ? $nameDe : $nameIt,
                'en' => $nameEn !== '' ? $nameEn : $nameIt,
            ];
            $this->db->insert('member_types', [
                'name_json' => json_encode($names, JSON_UNESCAPED_UNICODE),
                'price' => (float) $priceRaw,
                'is_active' => !empty($input['is_active']) ? 1 : 1,
                'sort_order' => count($this->types(false)),
            ]);
        }

        if (count($this->types(false)) === 0) {
            return ['ok' => false, 'errors' => ['types' => __('setup.validation_need_type')]];
        }

        $allTypes = $this->types(false);
        if (count($allTypes) === 1 && empty($allTypes[0]['is_active'])) {
            $this->db->update('member_types', ['is_active' => 1], 'id = :id', ['id' => (int) $allTypes[0]['id']]);
        }

        return ['ok' => true];
    }

    /** @return list<array<string,mixed>> */
    public function periods(): array
    {
        return $this->db->fetchAll('SELECT * FROM membership_periods ORDER BY starts_on DESC');
    }

    public function currentPeriod(): ?array
    {
        $this->ensureMembershipPeriodRollover();

        return $this->db->fetch('SELECT * FROM membership_periods WHERE is_current = 1 LIMIT 1');
    }

    /** When the current period has ended, open the next window automatically (same duration, +1 year). */
    public function ensureMembershipPeriodRollover(): void
    {
        $current = $this->db->fetch('SELECT * FROM membership_periods WHERE is_current = 1 LIMIT 1');
        if (!$current) {
            return;
        }
        $ends = (string) ($current['ends_on'] ?? '');
        if ($ends === '' || $ends >= date('Y-m-d')) {
            return;
        }
        $starts = (string) ($current['starts_on'] ?? '');
        if ($starts === '' || !$this->isValidPeriodDate($starts) || !$this->isValidPeriodDate($ends)) {
            return;
        }

        $nextStarts = date('Y-m-d', strtotime($starts . ' +1 year'));
        $nextEnds = date('Y-m-d', strtotime($ends . ' +1 year'));
        if (!$this->isValidPeriodDate($nextStarts) || !$this->isValidPeriodDate($nextEnds)) {
            return;
        }

        $this->db->query('UPDATE membership_periods SET is_current = 0');
        $this->db->insert('membership_periods', [
            'label' => $this->autoPeriodLabel($nextStarts, $nextEnds),
            'starts_on' => $nextStarts,
            'ends_on' => $nextEnds,
            'is_current' => 1,
        ]);
    }

    public function hasPeriodForYear(int $year): bool
    {
        foreach ($this->periods() as $period) {
            $label = (string) ($period['label'] ?? '');
            $starts = (string) ($period['starts_on'] ?? '');
            if (str_starts_with($starts, (string) $year) || $label === (string) $year) {
                return true;
            }
            $ends = (string) ($period['ends_on'] ?? '');
            $pivot = sprintf('%d-01-01', $year);
            if ($starts !== '' && $ends !== '' && $starts <= $pivot && $ends >= $pivot) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function persistPeriodsConfig(array $input): array
    {
        $existing = $input['periods'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $currentId = 0;
        foreach ($existing as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) $id;
            if ($id <= 0 || !$this->db->fetch('SELECT id FROM membership_periods WHERE id = :id', ['id' => $id])) {
                continue;
            }
            $starts = trim((string) ($row['starts_on'] ?? ''));
            $ends = trim((string) ($row['ends_on'] ?? ''));
            if ($starts === '' || !$this->isValidPeriodDate($starts) || !$this->isValidPeriodDate($ends) || $ends < $starts) {
                return ['ok' => false, 'errors' => ['periods' => __('validation.period_end_before_start')]];
            }
            if (!empty($row['is_current'])) {
                $currentId = $id;
            }
            $this->db->update('membership_periods', [
                'label' => $this->autoPeriodLabel($starts, $ends),
                'starts_on' => $starts,
                'ends_on' => $ends,
                'is_current' => 0,
            ], 'id = :id', ['id' => $id]);
        }

        $starts = trim((string) ($input['starts_on'] ?? ''));
        $ends = trim((string) ($input['ends_on'] ?? ''));

        if ($starts !== '' || $ends !== '') {
            if ($starts === '' || $ends === '') {
                return ['ok' => false, 'errors' => ['periods' => __('validation.required')]];
            }
            if (!$this->isValidPeriodDate($starts) || !$this->isValidPeriodDate($ends)) {
                return ['ok' => false, 'errors' => ['starts_on' => __('validation.date')]];
            }
            if ($ends < $starts) {
                return ['ok' => false, 'errors' => ['ends_on' => __('validation.period_end_before_start')]];
            }
            $newId = $this->db->insert('membership_periods', [
                'label' => $this->autoPeriodLabel($starts, $ends),
                'starts_on' => $starts,
                'ends_on' => $ends,
                'is_current' => 0,
            ]);
            if (!empty($input['is_current']) || count($this->periods()) === 1) {
                $currentId = (int) $newId;
            }
        }

        if (count($this->periods()) === 0) {
            return ['ok' => false, 'errors' => ['periods' => __('setup.validation_need_period')]];
        }

        $this->db->query('UPDATE membership_periods SET is_current = 0');
        if ($currentId > 0) {
            $this->db->update('membership_periods', ['is_current' => 1], 'id = :id', ['id' => $currentId]);
        } else {
            $latest = $this->db->fetch('SELECT id FROM membership_periods ORDER BY starts_on DESC, id DESC LIMIT 1');
            if ($latest) {
                $this->db->update('membership_periods', ['is_current' => 1], 'id = :id', ['id' => (int) $latest['id']]);
            }
        }

        return ['ok' => true];
    }

    public function autoPeriodLabel(string $starts, string $ends): string
    {
        $ts1 = strtotime($starts);
        $ts2 = strtotime($ends);
        if ($ts1 === false || $ts2 === false) {
            return trim($starts);
        }
        $y1 = date('Y', $ts1);
        $y2 = date('Y', $ts2);

        return $y1 === $y2 ? $y1 : $y1 . '/' . $y2;
    }

    private function isValidPeriodDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array>,total:int}
     */
    public function search(array $filters, int $page = 1, int $perPage = 25): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(m.member_number LIKE :q OR m.notes LIKE :q OR EXISTS (
                SELECT 1 FROM member_field_values v WHERE v.member_id = m.id AND v.value LIKE :q
            ))';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'm.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['member_type_id'])) {
            $where[] = 'm.member_type_id = :type';
            $params['type'] = $filters['member_type_id'];
        }
        if (!empty($filters['payment'])) {
            if ($filters['payment'] === 'paid') {
                $where[] = 'm.balance_due <= 0';
            } elseif ($filters['payment'] === 'partial') {
                $where[] = 'm.balance_due > 0 AND m.balance_due < mt.price';
            } elseif ($filters['payment'] === 'due') {
                $where[] = 'm.balance_due > 0';
            }
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetch(
            "SELECT COUNT(*) AS c FROM members m INNER JOIN member_types mt ON mt.id = m.member_type_id WHERE {$whereSql}",
            $params
        )['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $items = $this->db->fetchAll(
            "SELECT m.*, mt.name_json AS type_name_json, mt.price AS type_price,
                    mp.label AS period_label
             FROM members m
             INNER JOIN member_types mt ON mt.id = m.member_type_id
             INNER JOIN membership_periods mp ON mp.id = m.membership_period_id
             WHERE {$whereSql}
             ORDER BY m.member_number ASC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        foreach ($items as &$item) {
            $item['payment_status'] = $this->paymentStatus($item);
            $item['fields'] = $this->fieldValues((int) $item['id']);
        }
        unset($item);
        $this->attachComplianceIssues($items);
        return ['items' => $items, 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $member = $this->db->fetch(
            'SELECT m.*, mt.name_json AS type_name_json, mt.price AS type_price, mp.label AS period_label
             FROM members m
             INNER JOIN member_types mt ON mt.id = m.member_type_id
             INNER JOIN membership_periods mp ON mp.id = m.membership_period_id
             WHERE m.id = :id',
            ['id' => $id]
        );
        if (!$member) {
            return null;
        }
        $member['payment_status'] = $this->paymentStatus($member);
        $member['fields'] = $this->fieldValues($id);
        $list = [$member];
        $this->attachComplianceIssues($list);
        return $list[0];
    }

    /**
     * Missing required fields / enrollment attestation for existing members
     * after rules changed (e.g. signature required, new mandatory fields).
     *
     * @param array<string,mixed> $member Must include id + fields
     * @param array<string,mixed>|null $ctx Preloaded defs/enrollment map
     * @return list<array{code:string,label:string,field?:string}>
     */
    public function complianceIssues(array $member, ?array $ctx = null): array
    {
        $issues = [];
        $fields = is_array($member['fields'] ?? null) ? $member['fields'] : [];
        $defs = $ctx['defs'] ?? $this->fieldDefinitions(true);
        $gdprEnabled = $ctx['gdpr'] ?? $this->isGdprEnabled();
        $enrollment = $ctx['enrollment'] ?? null;
        if (!is_array($enrollment)) {
            try {
                $enrollment = app(EnrollmentService::class);
            } catch (\Throwable) {
                $enrollment = null;
            }
        }
        $method = is_object($enrollment) ? $enrollment->method() : 'none';
        $hasArtifact = $ctx['has_artifact'] ?? null;
        if ($hasArtifact === null && is_object($enrollment) && isset($member['id'])) {
            $hasArtifact = $enrollment->hasArtifact((int) $member['id']);
        }

        foreach ($defs as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = MemberFieldTypes::resolve((string) ($def['field_type'] ?? 'text'), $key);
            if ($type === MemberFieldTypes::PHOTO) {
                continue;
            }
            if ($key === 'privacy_ack' && !$gdprEnabled) {
                continue;
            }
            if ((int) ($def['is_required'] ?? 0) !== 1) {
                continue;
            }
            $raw = $fields[$key] ?? null;
            $label = localized($def['label_json'] ?? $key) ?: $key;
            $missing = false;
            if ($type === MemberFieldTypes::CHECKBOX) {
                $missing = !($raw === true || $raw === 1 || $raw === '1' || $raw === 'on' || $raw === 'yes');
            } else {
                $missing = $raw === null || (is_string($raw) && trim($raw) === '');
            }
            if ($missing) {
                $issues[] = [
                    'code' => 'missing_field',
                    'field' => $key,
                    'label' => __('members.anomaly_missing_field', ['field' => $label]),
                ];
            }
        }

        if ($method !== 'none' && !$hasArtifact) {
            $issues[] = [
                'code' => 'missing_enrollment',
                'field' => 'enrollment',
                'label' => match ($method) {
                    'tablet_sign' => __('members.anomaly_missing_signature'),
                    'print_scan' => __('members.anomaly_missing_scan'),
                    'otp_email' => __('members.anomaly_missing_otp'),
                    default => __('members.anomaly_missing_enrollment'),
                },
            ];
        }

        return $issues;
    }

    /**
     * @param list<array<string,mixed>> $members
     */
    public function attachComplianceIssues(array &$members): void
    {
        if ($members === []) {
            return;
        }
        $defs = $this->fieldDefinitions(true);
        $gdpr = $this->isGdprEnabled();
        $enrollment = null;
        try {
            $enrollment = app(EnrollmentService::class);
        } catch (\Throwable) {
            $enrollment = null;
        }
        $ids = array_map(static fn (array $m): int => (int) ($m['id'] ?? 0), $members);
        $artifactMap = is_object($enrollment) ? $enrollment->artifactPresenceMap($ids) : [];
        foreach ($members as &$member) {
            $mid = (int) ($member['id'] ?? 0);
            $member['compliance_issues'] = $this->complianceIssues($member, [
                'defs' => $defs,
                'gdpr' => $gdpr,
                'enrollment' => $enrollment,
                'has_artifact' => $artifactMap[$mid] ?? false,
            ]);
        }
        unset($member);
    }

    /** @return array<string,string|null> */
    public function fieldValues(int $memberId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT d.`key`, v.value
             FROM member_field_definitions d
             LEFT JOIN member_field_values v ON v.field_definition_id = d.id AND v.member_id = :id',
            ['id' => $memberId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    public function paymentStatus(array $member): string
    {
        $due = (float) $member['balance_due'];
        $price = (float) ($member['type_price'] ?? 0);
        if ($due <= 0) {
            return 'paid';
        }
        if ($price > 0 && $due < $price) {
            return 'partial';
        }
        return 'due';
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $fieldData
     * @return array{ok:bool,errors?:array,id?:int}
     */
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $fieldData
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $photoFile
     * @return array{ok:bool,errors?:array,id?:int}
     */
    public function create(array $data, array $fieldData, string $ip, ?array $photoFile = null): array
    {
        $type = $this->db->fetch('SELECT * FROM member_types WHERE id = :id', ['id' => $data['member_type_id'] ?? 0]);
        if (!$type) {
            return ['ok' => false, 'errors' => ['member_type_id' => __('validation.required')]];
        }
        $fieldErrors = $this->validateFields($fieldData);
        if ($photoFile && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoError = $this->validatePhotoUpload($photoFile);
            if ($photoError) {
                $fieldErrors['photo'] = $photoError;
            }
        }
        if ($fieldErrors) {
            return ['ok' => false, 'errors' => $fieldErrors];
        }
        $dupErrors = $this->duplicateIdentityErrors($fieldData);
        if ($dupErrors) {
            return ['ok' => false, 'errors' => $dupErrors];
        }
        if (!$this->validator->validate($data, [
            'member_number' => 'required|string|max:50',
            'member_type_id' => 'required|integer',
            'membership_period_id' => 'required|integer',
            'status' => 'required|in:active,suspended,expired,cancelled',
            'payment_status' => 'required|in:paid,partial,unpaid',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        if ($this->db->fetch('SELECT id FROM members WHERE member_number = :n', ['n' => $data['member_number']])) {
            return ['ok' => false, 'errors' => ['member_number' => __('validation.unique')]];
        }

        $price = (float) $type['price'];
        $paidAmount = 0.0;
        if ($data['payment_status'] === 'paid') {
            $paidAmount = $price;
            $balance = 0.0;
        } elseif ($data['payment_status'] === 'partial') {
            $paidAmount = (float) ($data['partial_amount'] ?? 0);
            $balance = max(0, $price - $paidAmount);
        } else {
            $balance = $price;
        }

        $this->db->beginTransaction();
        try {
            $id = $this->db->insert('members', [
                'member_number' => $data['member_number'],
                'member_type_id' => (int) $data['member_type_id'],
                'membership_period_id' => (int) $data['membership_period_id'],
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'balance_due' => $balance,
            ]);
            if ($photoFile && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $stored = $this->storeMemberPhoto($id, $photoFile);
                if ($stored['ok']) {
                    $fieldData['photo'] = $stored['path'];
                } else {
                    $this->db->rollBack();
                    return ['ok' => false, 'errors' => ['photo' => $stored['error']]];
                }
            }
            $this->saveFieldValues($id, $fieldData);
            $paymentId = null;
            if ($paidAmount > 0) {
                $paymentId = $this->db->insert('payments', [
                    'member_id' => $id,
                    'amount' => $paidAmount,
                    'method' => $data['payment_method'] ?? 'cash',
                    'type' => 'membership',
                    'note' => $data['payment_note'] ?? null,
                    'created_by' => auth_user()['id'] ?? null,
                ]);
                $this->treasury->autoRegisterFromPayment(
                    (int) $paymentId,
                    (int) $id,
                    $paidAmount,
                    (string) ($data['payment_method'] ?? 'cash'),
                    date('Y-m-d')
                );
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $member = $this->find($id);
        $this->audit->log('member.created', 'member', (string) $id, null, $member, $ip);
        $this->plugins->fire('member.created', $member);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $fieldData
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $photoFile
     * @return array{ok:bool,errors?:array}
     */
    public function update(int $id, array $data, array $fieldData, string $ip, ?array $photoFile = null): array
    {
        $before = $this->find($id);
        if (!$before) {
            return ['ok' => false, 'errors' => ['id' => __('validation.required')]];
        }
        $fieldErrors = $this->validateFields($fieldData);
        if ($photoFile && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $photoError = $this->validatePhotoUpload($photoFile);
            if ($photoError) {
                $fieldErrors['photo'] = $photoError;
            }
        }
        if ($fieldErrors) {
            return ['ok' => false, 'errors' => $fieldErrors];
        }
        $dupErrors = $this->duplicateIdentityErrors($fieldData, $id);
        if ($dupErrors) {
            return ['ok' => false, 'errors' => $dupErrors];
        }
        if (!$this->validator->validate($data, [
            'member_number' => 'required|string|max:50',
            'member_type_id' => 'required|integer',
            'membership_period_id' => 'required|integer',
            'status' => 'required|in:active,suspended,expired,cancelled',
        ])) {
            return ['ok' => false, 'errors' => $this->validator->firstErrors()];
        }
        $dup = $this->db->fetch(
            'SELECT id FROM members WHERE member_number = :n AND id <> :id',
            ['n' => $data['member_number'], 'id' => $id]
        );
        if ($dup) {
            return ['ok' => false, 'errors' => ['member_number' => __('validation.unique')]];
        }

        if (!empty($data['remove_photo'])) {
            $this->deleteMemberPhoto($id, (string) ($before['fields']['photo'] ?? ''));
            $fieldData['photo'] = null;
        } elseif ($photoFile && ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $stored = $this->storeMemberPhoto($id, $photoFile, (string) ($before['fields']['photo'] ?? ''));
            if (!$stored['ok']) {
                return ['ok' => false, 'errors' => ['photo' => $stored['error']]];
            }
            $fieldData['photo'] = $stored['path'];
        } else {
            unset($fieldData['photo']);
        }

        $this->db->update('members', [
            'member_number' => $data['member_number'],
            'member_type_id' => (int) $data['member_type_id'],
            'membership_period_id' => (int) $data['membership_period_id'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ], 'id = :id', ['id' => $id]);
        $this->saveFieldValues($id, $fieldData);
        $after = $this->find($id);
        $this->audit->log('member.updated', 'member', (string) $id, $before, $after, $ip);
        $this->plugins->fire('member.updated', $before, $after);
        return ['ok' => true];
    }

    public function delete(int $id, string $ip): bool
    {
        $before = $this->find($id);
        if (!$before) {
            return false;
        }
        $this->deleteMemberPhoto($id, (string) ($before['fields']['photo'] ?? ''));
        $this->db->query('DELETE FROM members WHERE id = :id', ['id' => $id]);
        $this->audit->log('member.deleted', 'member', (string) $id, $before, null, $ip);
        $this->plugins->fire('member.deleted', $id);
        return true;
    }

    /** @return array<string,string> */
    private function validateFields(array $fieldData): array
    {
        $errors = [];
        $gdprEnabled = $this->isGdprEnabled();
        foreach ($this->fieldDefinitions(true) as $def) {
            $key = (string) ($def['key'] ?? '');
            $type = \Socly\Support\MemberFieldTypes::resolve(
                (string) ($def['field_type'] ?? 'text'),
                $key
            );
            if ($type === \Socly\Support\MemberFieldTypes::PHOTO) {
                continue;
            }
            // Privacy acknowledgement is collected only when GDPR features are on.
            if ($key === 'privacy_ack' && !$gdprEnabled) {
                continue;
            }

            $value = $fieldData[$key] ?? null;
            $required = (int) ($def['is_required'] ?? 0) === 1;

            if ($type === \Socly\Support\MemberFieldTypes::CHECKBOX) {
                $checked = $value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'yes';
                $value = $checked ? '1' : null;
                if (!$required) {
                    continue;
                }
                $rule = 'accepted';
            } else {
                if (is_string($value)) {
                    $value = trim($value);
                    if ($value === '') {
                        $value = null;
                    }
                }
                $rule = \Socly\Support\MemberFieldTypes::validationRule($type, $required, $key);
            }

            if (!$this->validator->validate([$key => $value], [$key => $rule])) {
                $label = localized($def['label_json'] ?? $key);
                if ($label === '') {
                    $label = $key;
                }
                $errors[$key] = $label . ': ' . $this->validator->firstErrors()[$key];
            }
        }
        return $errors;
    }

    private function isGdprEnabled(): bool
    {
        try {
            return (string) app(SettingsService::class)->get('gdpr.enabled', '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Prevent duplicate identity (legacy: name+surname+dob). Soft-check on enabled identity fields.
     * @return array<string,string>
     */
    private function duplicateIdentityErrors(array $fieldData, ?int $ignoreMemberId = null): array
    {
        $first = trim((string) ($fieldData['first_name'] ?? ''));
        $last = trim((string) ($fieldData['last_name'] ?? ''));
        $dob = trim((string) ($fieldData['birth_date'] ?? ''));
        $cf = strtoupper(trim((string) ($fieldData['fiscal_code'] ?? '')));

        if ($cf !== '') {
            $sql = 'SELECT m.id FROM members m
                    INNER JOIN member_field_values v ON v.member_id = m.id
                    INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = \'fiscal_code\'
                    WHERE UPPER(TRIM(v.value)) = :cf';
            $params = ['cf' => $cf];
            if ($ignoreMemberId) {
                $sql .= ' AND m.id <> :id';
                $params['id'] = $ignoreMemberId;
            }
            if ($this->db->fetch($sql . ' LIMIT 1', $params)) {
                return ['fiscal_code' => __('validation.unique')];
            }
        }

        if ($first === '' || $last === '' || $dob === '') {
            return [];
        }

        $sql = 'SELECT m.id
                FROM members m
                INNER JOIN member_field_values vf ON vf.member_id = m.id
                INNER JOIN member_field_definitions df ON df.id = vf.field_definition_id AND df.`key` = \'first_name\'
                INNER JOIN member_field_values vl ON vl.member_id = m.id
                INNER JOIN member_field_definitions dl ON dl.id = vl.field_definition_id AND dl.`key` = \'last_name\'
                INNER JOIN member_field_values vd ON vd.member_id = m.id
                INNER JOIN member_field_definitions dd ON dd.id = vd.field_definition_id AND dd.`key` = \'birth_date\'
                WHERE LOWER(TRIM(vf.value)) = LOWER(:fn)
                  AND LOWER(TRIM(vl.value)) = LOWER(:ln)
                  AND vd.value = :dob';
        $params = ['fn' => $first, 'ln' => $last, 'dob' => $dob];
        if ($ignoreMemberId) {
            $sql .= ' AND m.id <> :id';
            $params['id'] = $ignoreMemberId;
        }
        if ($this->db->fetch($sql . ' LIMIT 1', $params)) {
            return ['first_name' => __('validation.duplicate_member')];
        }
        return [];
    }

    private function saveFieldValues(int $memberId, array $fieldData): void
    {
        foreach ($this->fieldDefinitions(false) as $def) {
            if ($def['field_type'] === 'checkbox') {
                $raw = $fieldData[$def['key']] ?? null;
                $value = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'on' || $raw === 'yes') ? '1' : '0';
            } elseif (!array_key_exists($def['key'], $fieldData)) {
                continue;
            } else {
                $value = $fieldData[$def['key']];
                if (is_string($value)) {
                    $value = trim($value);
                    if ($value === '') {
                        $value = null;
                    }
                }
            }

            $existing = $this->db->fetch(
                'SELECT id FROM member_field_values WHERE member_id = :m AND field_definition_id = :f',
                ['m' => $memberId, 'f' => $def['id']]
            );
            if ($existing) {
                $this->db->update('member_field_values', ['value' => $value], 'id = :id', ['id' => $existing['id']]);
            } else {
                $this->db->insert('member_field_values', [
                    'member_id' => $memberId,
                    'field_definition_id' => $def['id'],
                    'value' => $value,
                ]);
            }
        }
    }

    /** @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
    private function validatePhotoUpload(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return __('validation.photo');
        }
        if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
            return __('validation.photo');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return __('validation.photo');
        }
        return null;
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{ok:bool,path?:string,error?:string}
     */
    private function storeMemberPhoto(int $memberId, array $file, string $previous = ''): array
    {
        $error = $this->validatePhotoUpload($file);
        if ($error) {
            return ['ok' => false, 'error' => $error];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: '';
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }

        $paths = user_upload_paths('members/' . $memberId, null, 'photo.' . $ext);
        $relative = $paths['relative'];
        $absolute = $paths['absolute'];
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }
        @chmod($absolute, 0644);

        if ($previous !== '' && $previous !== $relative) {
            $this->deleteMemberPhoto($memberId, $previous);
        }

        return ['ok' => true, 'path' => $paths['relative']];
    }

    private function deleteMemberPhoto(int $memberId, string $relative): void
    {
        if ($relative === '' || str_contains($relative, '..')) {
            return;
        }
        $absolute = resolve_upload_absolute_path(ltrim($relative, '/'));
        if ($absolute !== null) {
            @unlink($absolute);
            $dir = dirname($absolute);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    public function memberPhotoAbsolutePath(string $relative): ?string
    {
        return resolve_upload_absolute_path(ltrim($relative, '/'));
    }

    public function nextMemberNumber(): string
    {
        $row = $this->db->fetch('SELECT member_number FROM members ORDER BY id DESC LIMIT 1');
        if (!$row) {
            return '1';
        }
        if (preg_match('/(\d+)$/', $row['member_number'], $m)) {
            return (string) ((int) $m[1] + 1);
        }
        return $row['member_number'] . '-1';
    }

    public function dashboardStats(): array
    {
        return [
            'members_total' => (int) ($this->db->fetch('SELECT COUNT(*) c FROM members')['c'] ?? 0),
            'members_active' => (int) ($this->db->fetch("SELECT COUNT(*) c FROM members WHERE status = 'active'")['c'] ?? 0),
            'members_expired' => (int) ($this->db->fetch("SELECT COUNT(*) c FROM members WHERE status = 'expired'")['c'] ?? 0),
            'members_suspended' => (int) ($this->db->fetch("SELECT COUNT(*) c FROM members WHERE status = 'suspended'")['c'] ?? 0),
            'overdue_count' => (int) ($this->db->fetch('SELECT COUNT(*) c FROM members WHERE balance_due > 0')['c'] ?? 0),
            'members_settled' => (int) ($this->db->fetch('SELECT COUNT(*) c FROM members WHERE balance_due <= 0')['c'] ?? 0),
            'new_members_year' => (int) ($this->db->fetch(
                'SELECT COUNT(*) c FROM members WHERE YEAR(created_at) = YEAR(CURDATE())'
            )['c'] ?? 0),
            'collected_year' => (float) ($this->db->fetch(
                'SELECT COALESCE(SUM(amount), 0) c FROM payments WHERE YEAR(created_at) = YEAR(CURDATE())'
            )['c'] ?? 0),
            'collected_month' => (float) ($this->db->fetch(
                'SELECT COALESCE(SUM(amount), 0) c FROM payments
                 WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())'
            )['c'] ?? 0),
            'charts' => $this->dashboardCharts(),
        ];
    }

    /**
     * @return array{
     *   collections: array{labels: list<string>, values: list<float>},
     *   new_members: array{labels: list<string>, values: list<int>},
     *   by_type: array{labels: list<string>, values: list<int>},
     *   payment_standing: array{labels: list<string>, values: list<int>}
     * }
     */
    public function dashboardCharts(): array
    {
        $locale = auth_user()['locale'] ?? config('app.locale', 'it');
        $months = $this->lastTwelveMonthKeys();

        $paymentRows = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS total
             FROM payments
             WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
             GROUP BY ym"
        );
        $paymentsByMonth = [];
        foreach ($paymentRows as $row) {
            $paymentsByMonth[$row['ym']] = (float) $row['total'];
        }

        $memberRows = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
             FROM members
             WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
             GROUP BY ym"
        );
        $membersByMonth = [];
        foreach ($memberRows as $row) {
            $membersByMonth[$row['ym']] = (int) $row['total'];
        }

        $collectionLabels = [];
        $collectionValues = [];
        $newMemberValues = [];
        foreach ($months as $ym) {
            $collectionLabels[] = $this->formatMonthLabel($ym, $locale);
            $collectionValues[] = round($paymentsByMonth[$ym] ?? 0.0, 2);
            $newMemberValues[] = $membersByMonth[$ym] ?? 0;
        }

        $typeRows = $this->db->fetchAll(
            'SELECT mt.name_json, COUNT(m.id) AS total
             FROM member_types mt
             LEFT JOIN members m ON m.member_type_id = mt.id
             GROUP BY mt.id, mt.name_json
             HAVING total > 0
             ORDER BY total DESC, mt.id ASC'
        );
        $typeLabels = [];
        $typeValues = [];
        foreach ($typeRows as $row) {
            $typeLabels[] = localized($row['name_json'], $locale);
            $typeValues[] = (int) $row['total'];
        }

        $settled = (int) ($this->db->fetch('SELECT COUNT(*) c FROM members WHERE balance_due <= 0')['c'] ?? 0);
        $overdue = (int) ($this->db->fetch('SELECT COUNT(*) c FROM members WHERE balance_due > 0')['c'] ?? 0);

        return [
            'collections' => [
                'labels' => $collectionLabels,
                'values' => $collectionValues,
            ],
            'new_members' => [
                'labels' => $collectionLabels,
                'values' => $newMemberValues,
            ],
            'by_type' => [
                'labels' => $typeLabels,
                'values' => $typeValues,
            ],
            'payment_standing' => [
                'labels' => [
                    __('dashboard.chart_settled'),
                    __('dashboard.chart_overdue'),
                ],
                'values' => [$settled, $overdue],
            ],
        ];
    }

    /** @return list<string> */
    private function lastTwelveMonthKeys(): array
    {
        $keys = [];
        $cursor = new \DateTimeImmutable('first day of this month');
        for ($i = 11; $i >= 0; $i--) {
            $keys[] = $cursor->modify("-{$i} months")->format('Y-m');
        }
        return $keys;
    }

    private function formatMonthLabel(string $ym, string $locale): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
        if (!$date) {
            return $ym;
        }
        $map = [
            'it' => 'it_IT',
            'de' => 'de_DE',
            'en' => 'en_GB',
        ];
        $intlLocale = $map[$locale] ?? 'it_IT';
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                $intlLocale,
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                null,
                null,
                'MMM yy'
            );
            $label = $formatter->format($date);
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }
        return $date->format('M y');
    }

    /** @return list<array{id:int,first_name:string,last_name:string,member_number:string}> */
    public function listForSelect(): array
    {
        return $this->db->fetchAll(
            "SELECT m.id, m.member_number,
                    COALESCE((
                        SELECT v.value FROM member_field_values v
                        INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                        WHERE v.member_id = m.id LIMIT 1
                    ), '') AS last_name,
                    COALESCE((
                        SELECT v.value FROM member_field_values v
                        INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                        WHERE v.member_id = m.id LIMIT 1
                    ), '') AS first_name
             FROM members m
             ORDER BY last_name ASC, first_name ASC, m.id ASC
             LIMIT 500"
        );
    }
}
