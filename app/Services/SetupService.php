<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Components\ComponentRegistry;
use Socly\Setup\AssociationLegalForms;
use Socly\Setup\SetupCatalogue;
use Socly\Services\BrandingService;
use Socly\Support\EnvWriter;
use Socly\Support\MemberFieldTypes;
use Socly\Support\Permission;
use Socly\Core\Database;

final class SetupService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AssociationPeopleService $people,
        private readonly BrandingService $branding,
        private readonly UserService $users,
        private readonly MemberService $members,
        private readonly Database $db,
        private readonly MailService $mail,
        private readonly ComponentService $components
    ) {
    }

    public function isAdmin(): bool
    {
        return can(Permission::SETTINGS_MANAGE);
    }

    public function isComplete(): bool
    {
        return $this->missingSteps() === [];
    }

    /** @return list<array<string, mixed>> */
    public function missingSteps(): array
    {
        $missing = [];
        foreach (SetupCatalogue::all() as $step) {
            if (!$this->stepIsConfigured($step)) {
                $missing[] = $step;
            }
        }
        return $missing;
    }

    /** @param array<string, mixed> $step */
    public function stepIsConfigured(array $step): bool
    {
        $type = (string) ($step['type'] ?? '');

        if ($type === 'colors' || $type === 'name_pair' || $type === 'field_group' || $type === 'address_block') {
            foreach ($step['fields'] ?? [] as $field) {
                $default = ($field['settings_key'] ?? '') === 'app.currency' ? 'EUR' : null;
                $val = $this->readValue((string) $field['settings_key'], (string) ($field['env_key'] ?? ''), $default);
                $required = array_key_exists('required', $field) ? !empty($field['required']) : !empty($step['required']);
                if ($required && trim((string) $val) === '') {
                    return false;
                }
            }
            // Prefill (e.g. scrape) must not skip review — user confirms with Avanti.
            if ($type === 'colors') {
                return $this->rawStored('branding.colors_confirmed', '') !== null;
            }
            if ($type === 'field_group' || $type === 'address_block') {
                return $this->isStepReviewed($step);
            }
            return true;
        }

        if ($type === 'logo') {
            if ($this->rawStored('branding.logo_configured', '') !== null) {
                return true;
            }
            // Logo already imported during website scrape — skip the dedicated upload step.
            return $this->branding->logoRelativePath() !== '';
        }

        if ($type === 'member_types') {
            if ($this->rawStored('membership.types_configured', '') === null) {
                return false;
            }
            return count($this->members->types(false)) > 0;
        }

        if ($type === 'membership_periods') {
            if (!$this->hasPeriodForYear((int) date('Y'))) {
                return false;
            }
            if ($this->rawStored('membership.periods_configured', '') === null) {
                return false;
            }
            return count($this->members->periods()) > 0;
        }

        if ($type === 'member_fields') {
            return $this->rawStored('membership.fields_configured', '') !== null;
        }

        if ($type === 'platform_consents') {
            return $this->rawStored('platform.consents_configured', '') !== null;
        }

        if ($type === 'smtp_config') {
            if ($this->isStepDeferred($step)) {
                return true;
            }
            if ($this->mail->isOutboundDisabled()) {
                return $this->rawStored('mail.configured', '') !== null;
            }

            return $this->mail->isReady();
        }

        if ($type === 'component_select') {
            return $this->components->isConfigured();
        }

        if ($type === 'admin_account') {
            $storedId = (int) $this->settings->get('app.admin_user_id', 0);
            if ($storedId > 0) {
                $user = $this->users->find($storedId);
                if ($user && empty($user['is_system_admin']) && !empty($user['is_active'])) {
                    return true;
                }
            }
            return $this->users->hasAssociationAdmin();
        }

        if ($type === 'president') {
            if ($this->isStepDeferred($step)) {
                return true;
            }
            $p = $this->people->getPresident();
            if ($p === null) {
                return false;
            }
            return trim((string) ($p['first_name'] ?? '')) !== ''
                && trim((string) ($p['last_name'] ?? '')) !== ''
                && trim((string) ($p['fiscal_code'] ?? '')) !== ''
                && trim((string) ($p['birth_date'] ?? '')) !== ''
                && trim((string) ($p['gender'] ?? '')) !== ''
                && trim((string) ($p['birth_place'] ?? '')) !== ''
                && trim((string) ($p['city'] ?? '')) !== ''
                && trim((string) ($p['postal_code'] ?? '')) !== ''
                && trim((string) ($p['address'] ?? '')) !== ''
                && trim((string) ($p['house_number'] ?? '')) !== ''
                && trim((string) ($p['appointed_at'] ?? '')) !== ''
                && trim((string) ($p['mandate_ends_at'] ?? '')) !== '';
        }

        if ($type === 'people_list') {
            // Scraped names alone must not skip the step — require explicit confirm.
            if ($this->isStepDeferred($step)) {
                return true;
            }
            if (!empty($step['settings_key'])) {
                return $this->rawStored((string) $step['settings_key'], '') !== null;
            }
            $role = (string) ($step['role'] ?? '');
            $min = (int) ($step['min'] ?? 0);
            $count = $this->people->countByRole($role);
            if (!empty($step['required'])) {
                return $count >= max(1, $min);
            }
            return false;
        }

        if ($type === 'checkbox' && !empty($step['required'])) {
            $raw = $this->rawStored((string) ($step['settings_key'] ?? ''), (string) ($step['env_key'] ?? ''));
            return $raw !== null;
        }

        if (($step['key'] ?? '') === 'legal.privacy') {
            // Privacy notice is only required when GDPR features are enabled.
            if (!$this->isGdprEnabled()) {
                return true;
            }
            $raw = $this->settings->get('legal.privacy', '');
            $text = localized(is_string($raw) ? $raw : (is_array($raw) ? $raw : ''));
            return trim($text) !== '';
        }

        if (($step['key'] ?? '') === 'legal.statute') {
            $raw = $this->settings->get((string) $step['settings_key'], '');
            $text = localized(is_string($raw) ? $raw : (is_array($raw) ? $raw : ''));
            return trim($text) !== '';
        }

        $val = $this->readValue((string) ($step['settings_key'] ?? ''), (string) ($step['env_key'] ?? ''));
        if (!empty($step['required'])) {
            // Required scalar steps: scrape may fill the value, user must still confirm the step.
            if (trim((string) $val) === '') {
                return false;
            }
            return $this->isStepReviewed($step);
        }
        return $this->rawStored((string) ($step['settings_key'] ?? ''), (string) ($step['env_key'] ?? '')) !== null;
    }

    /** @param array<string, mixed> $step */
    private function stepReviewSettingsKey(array $step): string
    {
        $key = trim((string) ($step['key'] ?? ''));
        return $key !== '' ? 'setup.reviewed.' . $key : '';
    }

    /** @param array<string, mixed> $step */
    private function isStepDeferred(array $step): bool
    {
        $key = trim((string) ($step['key'] ?? ''));
        if ($key === '') {
            return false;
        }

        return $this->rawStored('setup.deferred.' . $key, '') === '1';
    }

    /** @param array<string, mixed> $step */
    private function deferStep(array $step): array
    {
        $key = trim((string) ($step['key'] ?? ''));
        if ($key !== '') {
            $this->settings->set('setup.deferred.' . $key, '1');
        }
        $type = (string) ($step['type'] ?? '');
        if ($type === 'people_list' && !empty($step['settings_key'])) {
            $this->settings->set((string) $step['settings_key'], 'deferred');
        }
        $this->markStepReviewed($step);

        return ['ok' => true];
    }

    /** @param array<string, mixed> $step */
    private function isStepReviewed(array $step): bool
    {
        $reviewKey = $this->stepReviewSettingsKey($step);
        return $reviewKey !== '' && $this->rawStored($reviewKey, '') !== null;
    }

    /** @param array<string, mixed> $step */
    private function markStepReviewed(array $step): void
    {
        $reviewKey = $this->stepReviewSettingsKey($step);
        if ($reviewKey !== '') {
            $this->settings->set($reviewKey, '1');
        }
    }

    public function greetingPeriod(?\DateTimeInterface $now = null): string
    {
        $hour = (int) ($now ?? new \DateTimeImmutable('now'))->format('G');
        if ($hour >= 5 && $hour < 12) {
            return 'morning';
        }
        if ($hour >= 12 && $hour < 18) {
            return 'afternoon';
        }
        return 'evening';
    }

    /** @param array<string, mixed> $step */
    public function currentValue(array $step): mixed
    {
        $type = (string) ($step['type'] ?? '');

        if (in_array($type, ['colors', 'name_pair', 'field_group', 'address_block'], true)) {
            $out = [];
            foreach ($step['fields'] ?? [] as $field) {
                $default = '';
                if ($type === 'colors') {
                    $default = $field['key'] === 'primary' ? '#0D6E66' : '#B84A1B';
                }
                if (($field['settings_key'] ?? '') === 'app.currency') {
                    $default = 'EUR';
                }
                $out[$field['key']] = (string) $this->readValue(
                    (string) $field['settings_key'],
                    (string) ($field['env_key'] ?? ''),
                    $default
                );
            }
            if ($type === 'colors') {
                $palettes = $this->branding->paletteSuggestions();
                if ($palettes === []) {
                    $palettes = $this->branding->storePaletteSuggestions([
                        [
                            'id' => 'socly_default',
                            'name' => __('setup.palette_socly'),
                            'primary' => '#0D6E66',
                            'accent' => '#B84A1B',
                            'source' => 'default',
                        ],
                    ]);
                }
                $out['palettes'] = $palettes;
                $out['logo_url'] = $this->branding->logoUrl();
            }
            return $out;
        }

        if ($type === 'logo') {
            return [
                'logo_url' => $this->branding->logoUrl(),
                'has_logo' => $this->branding->logoRelativePath() !== '',
            ];
        }

        if ($type === 'member_types') {
            $types = [];
            foreach ($this->members->types(false) as $row) {
                $names = $this->decodeLocalizedMap($row['name_json'] ?? null);
                $types[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'name_it' => $names['it'],
                    'name_de' => $names['de'],
                    'name_en' => $names['en'],
                    'price' => (string) ($row['price'] ?? '0'),
                    'is_active' => !empty($row['is_active']),
                ];
            }
            return [
                'types' => $types,
                'name_it' => $types === [] ? 'Ordinaria' : '',
                'name_de' => $types === [] ? 'Ordentlich' : '',
                'name_en' => $types === [] ? 'Ordinary' : '',
                'price' => $types === [] ? '0' : '',
                'is_active' => true,
                'single_type' => count($types) === 1,
            ];
        }

        if ($type === 'membership_periods') {
            $periods = $this->members->periods();
            $year = (int) date('Y');
            $hasCurrentYear = $this->hasPeriodForYear($year);
            $suggestNewYear = $periods !== [] && !$hasCurrentYear;
            $prefill = $periods === [] || $suggestNewYear;
            return [
                'periods' => $periods,
                'needs_current_year' => $suggestNewYear,
                'label' => $prefill ? (string) $year : '',
                'starts_on' => $prefill ? sprintf('%d-01-01', $year) : '',
                'ends_on' => $prefill ? sprintf('%d-12-31', $year) : '',
                'is_current' => $prefill,
            ];
        }

        if ($type === 'member_fields') {
            return [
                'fields' => $this->members->fieldDefinitions(false),
                'form_steps' => $this->members->formSteps(),
                'type_options' => MemberFieldTypes::keys(),
                'new_label' => '',
                'new_key' => '',
                'new_type' => MemberFieldTypes::TEXT,
                'new_enabled' => true,
                'new_required' => false,
            ];
        }

        if ($type === 'component_select') {
            // First visit: all free base modules pre-selected.
            $enabled = $this->components->isConfigured()
                ? array_fill_keys($this->components->enabledKeys(), true)
                : array_fill_keys(ComponentRegistry::keys(), true);
            $items = [];
            foreach (ComponentRegistry::all() as $component) {
                $items[] = [
                    'key' => $component['key'],
                    'name' => $component['name_key'],
                    'description' => $component['description_key'],
                    'price' => $component['price_key'],
                    'enabled' => isset($enabled[$component['key']]),
                ];
            }
            return ['components' => $items];
        }

        if ($type === 'admin_account') {
            return [
                'name' => '',
                'email' => (string) $this->readValue('association.email', 'ASSOCIATION_EMAIL', ''),
                'locale' => (string) $this->readValue('app.locale', 'APP_LOCALE', 'it'),
            ];
        }

        if ($type === 'platform_consents') {
            return [
                'news_opt_in' => ((string) $this->settings->get('platform.news_opt_in', '1')) !== '0',
                'usage_stats_opt_in' => ((string) $this->settings->get('platform.usage_stats_opt_in', '1')) !== '0',
                'showcase_consent' => ((string) $this->settings->get('platform.showcase_consent', '1')) !== '0',
                'confirm_first_name' => '',
                'confirm_last_name' => '',
                'mail_ready' => $this->mail->isReady(),
            ];
        }

        if ($type === 'smtp_config') {
            $cfg = $this->mail->config();
            $assocEmail = (string) $this->readValue('association.email', 'ASSOCIATION_EMAIL', '');
            $defaultFrom = $cfg['from_address'];
            if ($defaultFrom === '') {
                $website = (string) $this->readValue('association.website', 'ASSOCIATION_WEBSITE', '');
                $domain = '';
                if ($website !== '') {
                    $host = parse_url($website, PHP_URL_HOST);
                    $domain = is_string($host) ? strtolower($host) : '';
                }
                if ($domain === '' && str_contains($assocEmail, '@')) {
                    $domain = strtolower((string) substr(strrchr($assocEmail, '@'), 1));
                }
                $domain = preg_replace('/^www\./', '', $domain) ?? $domain;
                $defaultFrom = $domain !== '' ? 'noreply@' . $domain : $assocEmail;
            }

            $neverConfigured = $this->rawStored('mail.configured', '') === null;

            return [
                'host' => $cfg['host'],
                'port' => $cfg['port'] > 0 ? (string) $cfg['port'] : '587',
                'encryption' => $cfg['encryption'] ?: 'tls',
                'username' => $cfg['username'],
                'password' => '',
                'from_address' => $defaultFrom,
                'from_name' => $cfg['from_name'] !== '' ? $cfg['from_name'] : (string) $this->readValue('association.name', 'ASSOCIATION_NAME', 'SOCLY'),
                'test_to' => $defaultFrom,
                'has_password' => $cfg['has_password'],
                'last_test_ok' => $cfg['last_test_ok'],
                'last_test_at' => $cfg['last_test_at'],
                'show_manual' => $cfg['host'] !== '' || !empty($_SESSION['_flash']['smtp_needs_manual']),
                'outbound_disabled' => $neverConfigured ? true : $this->mail->isOutboundDisabled(),
            ];
        }

        if (($step['key'] ?? '') === 'membership.enrollment_validation') {
            $v = (string) $this->readValue((string) ($step['settings_key'] ?? ''), '', '');
            return $v !== '' ? $v : 'none';
        }

        if ($type === 'president') {
            $p = $this->people->getPresident() ?? [];
            return [
                'first_name' => (string) ($p['first_name'] ?? ''),
                'last_name' => (string) ($p['last_name'] ?? ''),
                'fiscal_code' => (string) ($p['fiscal_code'] ?? ''),
                'birth_date' => (string) ($p['birth_date'] ?? ''),
                'gender' => (string) ($p['gender'] ?? ''),
                'birth_place' => (string) ($p['birth_place'] ?? ''),
                'city' => (string) ($p['city'] ?? ''),
                'postal_code' => (string) ($p['postal_code'] ?? ''),
                'address' => (string) ($p['address'] ?? ''),
                'house_number' => (string) ($p['house_number'] ?? ''),
                'appointed_at' => (string) ($p['appointed_at'] ?? ''),
                'mandate_ends_at' => (string) ($p['mandate_ends_at'] ?? ''),
            ];
        }

        if ($type === 'people_list') {
            $rows = $this->people->listByRole((string) ($step['role'] ?? ''));
            if ($rows === []) {
                return [['first_name' => '', 'last_name' => '', 'fiscal_code' => '']];
            }
            return array_map(static fn (array $r): array => [
                'first_name' => (string) ($r['first_name'] ?? ''),
                'last_name' => (string) ($r['last_name'] ?? ''),
                'fiscal_code' => (string) ($r['fiscal_code'] ?? ''),
                'organ_type' => (string) ($r['notes'] ?? ''),
            ], $rows);
        }

        if (in_array($step['key'] ?? '', ['legal.privacy', 'legal.statute'], true)) {
            $raw = $this->settings->get((string) $step['settings_key'], '');
            $text = localized(is_string($raw) ? $raw : (is_array($raw) ? $raw : ''));
            if (($step['key'] ?? '') === 'legal.privacy' && trim($text) === '' && $this->isGdprEnabled()) {
                return privacy_sample_draft();
            }

            return $text;
        }
        if ($type === 'checkbox') {
            $default = (($step['key'] ?? '') === 'gdpr.enabled') ? '1' : '0';

            return (string) $this->readValue((string) ($step['settings_key'] ?? ''), (string) ($step['env_key'] ?? ''), $default) === '1';
        }
        return (string) $this->readValue((string) ($step['settings_key'] ?? ''), (string) ($step['env_key'] ?? ''), '');
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function saveStep(array $step, array $input): array
    {
        if (!empty($input['setup_defer'])) {
            return $this->deferStep($step);
        }

        $type = (string) ($step['type'] ?? '');

        if ($type === 'name_pair') {
            return $this->saveNamePair($step, $input);
        }

        if ($type === 'field_group') {
            return $this->saveFieldGroup($step, $input);
        }

        if ($type === 'address_block') {
            return $this->saveAddressBlock($step, $input);
        }

        if ($type === 'president') {
            return $this->savePresident($input);
        }

        if ($type === 'people_list') {
            return $this->savePeopleList($step, $input);
        }

        if ($type === 'colors') {
            $primary = trim((string) ($input['primary'] ?? ''));
            $accent = trim((string) ($input['accent'] ?? ''));
            $errors = [];
            if ($primary === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) {
                $errors['primary'] = __('validation.color');
            }
            if ($accent === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $accent)) {
                $errors['accent'] = __('validation.color');
            }
            if ($errors === [] && strcasecmp($primary, $accent) === 0) {
                $errors['accent'] = __('validation.colors_distinct');
            }
            if ($errors) {
                return ['ok' => false, 'errors' => $errors];
            }
            [$primary, $accent] = $this->branding->ensureDistinctColors($primary, $accent);
            $this->settings->set('branding.primary', strtoupper($primary));
            $this->settings->set('branding.accent', strtoupper($accent));
            $this->settings->set('branding.colors_confirmed', '1');
            EnvWriter::setUserValues([
                'BRANDING_PRIMARY' => strtoupper($primary),
                'BRANDING_ACCENT' => strtoupper($accent),
            ]);
            return ['ok' => true];
        }

        if ($type === 'logo') {
            // Upload and removal are handled live via POST /setup/logo.
            $this->settings->set('branding.logo_configured', '1');
            return ['ok' => true];
        }

        if ($type === 'member_types') {
            return $this->saveMemberTypesStep($input);
        }

        if ($type === 'membership_periods') {
            return $this->saveMembershipPeriodsStep($input);
        }

        if ($type === 'member_fields') {
            return $this->saveMemberFieldsStep($input);
        }

        if ($type === 'component_select') {
            return $this->saveComponentSelect($input);
        }

        if ($type === 'admin_account') {
            return $this->saveAdminAccount($input);
        }

        if ($type === 'platform_consents') {
            $news = !empty($input['news_opt_in']);
            $stats = !empty($input['usage_stats_opt_in']);
            $showcase = !empty($input['showcase_consent']);
            if ($news || $stats || $showcase) {
                $confirmError = $this->validatePresidentNameConfirmation($input);
                if ($confirmError !== null) {
                    return ['ok' => false, 'errors' => $confirmError];
                }
            }
            $this->settings->set('platform.news_opt_in', $news ? '1' : '0');
            $this->settings->set('platform.usage_stats_opt_in', $stats ? '1' : '0');
            $this->settings->set('platform.showcase_consent', $showcase ? '1' : '0');
            $this->settings->set('platform.consents_configured', '1');
            if ($news || $stats || $showcase) {
                $this->settings->set('platform.consents_confirmed_at', date('c'));
                $this->settings->set('platform.consents_confirmed_name', trim(
                    (string) ($input['confirm_first_name'] ?? '') . ' ' . (string) ($input['confirm_last_name'] ?? '')
                ));
            }
            return ['ok' => true];
        }

        if ($type === 'smtp_config') {
            if (!empty($input['outbound_disabled'])) {
                $this->mail->disableOutbound();
                unset($_SESSION['_flash']['smtp_needs_manual']);
                return ['ok' => true];
            }

            $result = $this->mail->saveSimple($input, true);
            if (!$result['ok']) {
                if (!empty($result['needs_manual'])) {
                    $_SESSION['_flash']['smtp_needs_manual'] = true;
                }
                return $result;
            }
            unset($_SESSION['_flash']['smtp_needs_manual']);
            return ['ok' => true];
        }

        if (in_array($step['key'] ?? '', ['legal.privacy', 'legal.statute'], true)) {
            $text = trim((string) ($input['value'] ?? ''));
            $mustFill = !empty($step['required']);
            if (($step['key'] ?? '') === 'legal.privacy' && !$this->isGdprEnabled()) {
                $mustFill = false;
            }
            if ($mustFill && $text === '') {
                return ['ok' => false, 'errors' => ['value' => __('validation.required')]];
            }
            $payload = ['it' => $text, 'de' => $text, 'en' => $text];
            $this->settings->set((string) $step['settings_key'], $payload);
            return ['ok' => true];
        }

        if ($type === 'checkbox') {
            $enabled = !empty($input['value']) ? '1' : '0';
            $this->persistScalar($step, $enabled);
            return ['ok' => true];
        }

        if ($type === 'website') {
            $value = trim((string) ($input['value'] ?? ''));
            if ($value !== '') {
                $normalized = $this->normalizeWebsiteUrl($value);
                if ($normalized === null) {
                    return ['ok' => false, 'errors' => ['value' => __('setup.validation_website')]];
                }
                $value = $normalized;
            }
            $this->persistScalar($step, $value);
            return ['ok' => true];
        }

        $value = trim((string) ($input['value'] ?? ''));
        if (!empty($step['required']) && $value === '') {
            return ['ok' => false, 'errors' => ['value' => __('validation.required')]];
        }
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'errors' => ['value' => __('validation.email')]];
        }
        if ($type === 'select' && $value !== '') {
            $allowed = array_column($step['options'] ?? [], 'value');
            if ($allowed && !in_array($value, $allowed, true)) {
                return ['ok' => false, 'errors' => ['value' => __('validation.in')]];
            }
            if (($step['key'] ?? '') === 'membership.enrollment_validation' && $value === 'otp_email' && !$this->mail->isReady()) {
                return ['ok' => false, 'errors' => ['value' => __('mail.required_for_otp')]];
            }
        }
        $this->persistScalar($step, $value);
        if (!empty($step['required'])) {
            $this->markStepReviewed($step);
        }
        return ['ok' => true];
    }

    /**
     * Fill empty association settings from scrape results (never overwrite non-empty).
     *
     * @param array<string, string> $found
     * @return list<string> keys applied
     */
    public function applyScrapedHints(array $found, string $website = '', bool $updateWebsite = false): array
    {
        $map = [
            'email' => ['settings' => 'association.email', 'env' => 'ASSOCIATION_EMAIL'],
            'pec' => ['settings' => 'association.pec', 'env' => 'ASSOCIATION_PEC'],
            'phone' => ['settings' => 'association.phone', 'env' => 'ASSOCIATION_PHONE'],
            'fiscal_code' => ['settings' => 'association.fiscal_code', 'env' => 'ASSOCIATION_FISCAL_CODE'],
            'vat_number' => ['settings' => 'association.vat_number', 'env' => 'ASSOCIATION_VAT'],
            'city' => ['settings' => 'association.city', 'env' => 'ASSOCIATION_CITY'],
            'postal_code' => ['settings' => 'association.postal_code', 'env' => 'ASSOCIATION_POSTAL_CODE'],
            'province' => ['settings' => 'association.province', 'env' => 'ASSOCIATION_PROVINCE'],
            'address' => ['settings' => 'association.address', 'env' => 'ASSOCIATION_ADDRESS'],
            'house_number' => ['settings' => 'association.house_number', 'env' => 'ASSOCIATION_HOUSE_NUMBER'],
            'runts' => ['settings' => 'association.runts', 'env' => 'ASSOCIATION_RUNTS'],
        ];

        $applied = [];
        $env = [];
        $website = trim($website);
        $locked = $this->runtsLockedKeys();
        if ($website !== '') {
            $currentWeb = trim((string) $this->settings->get('association.website', ''));
            if ($updateWebsite || $currentWeb === '') {
                $this->settings->set('association.website', $website);
                $env['ASSOCIATION_WEBSITE'] = $website;
                $applied[] = 'website';
            }
        }

        foreach ($map as $key => $meta) {
            if (isset($locked[$key])) {
                continue;
            }
            $value = trim((string) ($found[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $current = trim((string) $this->settings->get($meta['settings'], ''));
            if ($current !== '') {
                continue;
            }
            $this->settings->set($meta['settings'], $value);
            $env[$meta['env']] = $value;
            $applied[] = $key;
        }

        $applied = array_merge($applied, $this->applyScrapedPeopleHints($found, $locked));

        if (
            isset($found['address'], $found['house_number'], $found['postal_code'], $found['city'])
            && trim((string) $this->settings->get('association.address_full', '')) === ''
        ) {
            $this->settings->set('association.address_full', trim(sprintf(
                '%s %s, %s %s',
                $found['address'],
                $found['house_number'],
                $found['postal_code'],
                $found['city']
            )));
        }

        $logoUrl = trim((string) ($found['logo_url'] ?? ''));
        $logoApplied = false;
        if ($logoUrl !== '' && $this->branding->logoRelativePath() === '') {
            $stored = $this->branding->downloadLogoFromUrl($logoUrl);
            if (!empty($stored['ok'])) {
                $applied[] = 'logo';
                $logoApplied = true;
            }
        }

        // Derive / apply palette from the stored logo (or scrape theme hints) whenever we have one.
        $colorsLocked = $this->rawStored('branding.colors_confirmed', '') !== null;
        $hasLogo = $this->branding->logoRelativePath() !== '';
        if ($hasLogo && ($logoApplied || $logoUrl !== '')) {
            $fromLogo = $this->branding->palettesFromLogoFile();
            $primary = '';
            $accent = '';
            if ($fromLogo !== []) {
                $this->branding->storePaletteSuggestions($fromLogo);
                $primary = (string) ($fromLogo[0]['primary'] ?? '');
                $accent = (string) ($fromLogo[0]['accent'] ?? '');
            } else {
                $themeColors = array_values(array_filter([
                    trim((string) ($found['theme_primary'] ?? '')),
                    trim((string) ($found['theme_accent'] ?? '')),
                ]));
                if ($themeColors !== []) {
                    $built = $this->branding->palettesFromColors($themeColors, 'logo', __('setup.palette_from_logo'));
                    $this->branding->storePaletteSuggestions($built);
                    $primary = strtoupper((string) ($built[0]['primary'] ?? $themeColors[0] ?? ''));
                    $accent = strtoupper((string) ($built[0]['accent'] ?? ''));
                }
            }

            if ($primary !== '' && $accent === '') {
                $built = $this->branding->palettesFromColors([$primary], 'logo', __('setup.palette_from_logo'));
                $accent = strtoupper((string) ($built[0]['accent'] ?? ''));
            }

            if (!$colorsLocked && $primary !== '') {
                [$primary, $accent] = $this->branding->ensureDistinctColors(
                    $primary,
                    $accent !== '' ? $accent : $primary
                );
                $this->settings->set('branding.primary', strtoupper($primary));
                $env['BRANDING_PRIMARY'] = strtoupper($primary);
                if (!in_array('theme_primary', $applied, true)) {
                    $applied[] = 'theme_primary';
                }
                $this->settings->set('branding.accent', strtoupper($accent));
                $env['BRANDING_ACCENT'] = strtoupper($accent);
                if (!in_array('theme_accent', $applied, true)) {
                    $applied[] = 'theme_accent';
                }
            }
        }

        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }
        return $applied;
    }

    /**
     * Replace association logo during setup and refresh palette suggestions.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}|null $file
     * @return array{ok:bool,error?:string,logo_url?:string|null,primary?:string,accent?:string,palettes?:list<array<string,mixed>>}
     */
    public function replaceSetupLogo(?array $file): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => __('validation.photo')];
        }

        $stored = $this->branding->storeLogoUpload($file, true);
        if (empty($stored['ok'])) {
            return ['ok' => false, 'error' => (string) ($stored['error'] ?? __('validation.photo'))];
        }

        $primary = strtoupper(trim((string) $this->settings->get('branding.primary', '')));
        $accent = strtoupper(trim((string) $this->settings->get('branding.accent', '')));
        $env = [];
        if ($primary !== '') {
            $env['BRANDING_PRIMARY'] = $primary;
        }
        if ($accent !== '') {
            $env['BRANDING_ACCENT'] = $accent;
        }
        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }

        return [
            'ok' => true,
            'logo_url' => $this->branding->logoUrl(),
            'primary' => $primary,
            'accent' => $accent,
            'palettes' => $this->branding->paletteSuggestions(),
        ];
    }

    /**
     * Remove association logo during setup and reset palette to SOCLY defaults.
     *
     * @return array{ok:bool,error?:string,logo_url?:null,primary:string,accent:string,palettes:list<array<string,mixed>>}
     */
    public function removeSetupLogo(): array
    {
        $relative = $this->branding->logoRelativePath();
        if ($relative !== '') {
            $absolute = storage_path('uploads/' . $relative);
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            $this->settings->set('branding.logo', '');
        }

        $primary = '#0D6E66';
        $accent = '#B84A1B';
        $this->settings->set('branding.primary', $primary);
        $this->settings->set('branding.accent', $accent);
        $palettes = $this->branding->storePaletteSuggestions([
            [
                'id' => 'socly_default',
                'name' => __('setup.palette_socly'),
                'primary' => $primary,
                'accent' => $accent,
                'source' => 'default',
            ],
        ]);
        EnvWriter::setUserValues([
            'BRANDING_PRIMARY' => $primary,
            'BRANDING_ACCENT' => $accent,
        ]);

        return [
            'ok' => true,
            'logo_url' => null,
            'primary' => $primary,
            'accent' => $accent,
            'palettes' => $palettes,
        ];
    }

    /**
     * Import a scraped logo candidate URL during setup and refresh palette suggestions.
     *
     * @return array{ok:bool,error?:string,logo_url?:string|null,primary?:string,accent?:string,palettes?:list<array<string,mixed>>}
     */
    public function importSetupLogoFromUrl(string $url): array
    {
        $stored = $this->branding->downloadLogoFromUrl($url, true);
        if (empty($stored['ok'])) {
            return ['ok' => false, 'error' => (string) ($stored['error'] ?? __('validation.photo'))];
        }

        $primary = strtoupper(trim((string) $this->settings->get('branding.primary', '')));
        $accent = strtoupper(trim((string) $this->settings->get('branding.accent', '')));
        $env = [];
        if ($primary !== '') {
            $env['BRANDING_PRIMARY'] = $primary;
        }
        if ($accent !== '') {
            $env['BRANDING_ACCENT'] = $accent;
        }
        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }

        return [
            'ok' => true,
            'logo_url' => $this->branding->logoUrl(),
            'primary' => $primary,
            'accent' => $accent,
            'palettes' => $this->branding->paletteSuggestions(),
        ];
    }

    /**
     * Persist RUNTS lookup results. Name / legal form / RUNTS overwrite; other empty fields are filled.
     *
     * @param array<string, string> $fields
     * @return list<string>
     */
    public function applyRuntsHints(array $fields): array
    {
        $applied = [];
        $env = [];
        $lock = [];

        $name = trim((string) ($fields['name'] ?? ''));
        if ($name !== '') {
            $name = assoc_capitalize_name($name);
            $this->settings->set('association.name', $name);
            $env['ASSOCIATION_NAME'] = $name;
            $applied[] = 'name';
            $lock['name'] = $name;
        }

        $legal = strtoupper(trim((string) ($fields['legal_name'] ?? '')));
        if ($legal !== '' && AssociationLegalForms::isValid($legal)) {
            $this->settings->set('association.legal_name', $legal);
            $env['ASSOCIATION_LEGAL_NAME'] = $legal;
            $applied[] = 'legal_name';
            $lock['legal_name'] = $legal;
        }

        $runts = preg_replace('/\D+/', '', (string) ($fields['runts'] ?? '')) ?? '';
        if ($runts !== '') {
            $this->settings->set('association.runts', $runts);
            $env['ASSOCIATION_RUNTS'] = $runts;
            $applied[] = 'runts';
            $lock['runts'] = $runts;
        }

        $optional = [
            'fiscal_code' => ['settings' => 'association.fiscal_code', 'env' => 'ASSOCIATION_FISCAL_CODE'],
            'city' => ['settings' => 'association.city', 'env' => 'ASSOCIATION_CITY'],
            'province' => ['settings' => 'association.province', 'env' => 'ASSOCIATION_PROVINCE'],
        ];
        foreach ($optional as $key => $meta) {
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($key === 'fiscal_code') {
                $value = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
            } elseif ($key === 'city') {
                $value = assoc_capitalize_name($value);
            } elseif ($key === 'province') {
                $value = strtoupper(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
            }
            $this->settings->set($meta['settings'], $value);
            $env[$meta['env']] = $value;
            $applied[] = $key;
            $lock[$key] = $value;
        }

        $personKey = 'president_name';
        if (trim((string) ($fields[$personKey] ?? '')) !== '') {
            $peopleApplied = $this->applyScrapedPeopleHints([$personKey => (string) $fields[$personKey]]);
            foreach ($peopleApplied as $key) {
                $applied[] = $key;
                $lock[$key] = trim((string) $fields[$personKey]);
            }
        }

        $this->settings->set('association.runts_lock', $lock);
        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }
        return $applied;
    }

    /**
     * @return array<string, string>
     */
    public function runtsLockedKeys(): array
    {
        $raw = $this->settings->get('association.runts_lock', '');
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $key => $value) {
                $out[(string) $key] = is_scalar($value) ? (string) $value : '';
            }
            return $out;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }
        return $out;
    }

    /**
     * Prefill association people from scrape when those roles are still empty.
     *
     * @param array<string, string> $found
     * @param array<string, string> $locked
     * @return list<string>
     */
    private function applyScrapedPeopleHints(array $found, array $locked = []): array
    {
        $applied = [];
        $roleMap = [
            'president_name' => AssociationPeopleService::ROLE_PRESIDENT,
            'vice_president_name' => AssociationPeopleService::ROLE_VICE_PRESIDENT,
            'secretary_name' => AssociationPeopleService::ROLE_SECRETARY,
            'treasurer_name' => AssociationPeopleService::ROLE_TREASURER,
        ];

        foreach ($roleMap as $foundKey => $role) {
            if (isset($locked[$foundKey])) {
                continue;
            }
            $person = $this->splitScrapedPersonName((string) ($found[$foundKey] ?? ''));
            if ($person === null) {
                continue;
            }
            if ($this->people->countByRole($role) > 0) {
                continue;
            }
            try {
                $this->people->replaceRole($role, [$person]);
                $applied[] = $foundKey;
            } catch (\Throwable) {
                // Best-effort: incomplete scraped names must not break scrape apply.
            }
        }

        $board = [];
        foreach (preg_split('/\s*,\s*/u', (string) ($found['board_names'] ?? '')) ?: [] as $chunk) {
            $person = $this->splitScrapedPersonName((string) $chunk);
            if ($person !== null) {
                $board[] = $person;
            }
        }
        if ($board !== [] && $this->people->countByRole(AssociationPeopleService::ROLE_BOARD) === 0) {
            try {
                $this->people->replaceRole(AssociationPeopleService::ROLE_BOARD, $board);
                $applied[] = 'board_names';
            } catch (\Throwable) {
            }
        }

        return $applied;
    }

    /** @return array{first_name:string,last_name:string}|null */
    private function splitScrapedPersonName(string $full): ?array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? '');
        if ($full === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $full) ?: [];
        if (count($parts) < 2) {
            return null;
        }
        $first = array_shift($parts);
        $last = implode(' ', $parts);
        if ($first === '' || $last === '') {
            return null;
        }
        return [
            'first_name' => $first,
            'last_name' => $last,
        ];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function saveNamePair(array $step, array $input): array
    {
        $env = [];
        $errors = [];
        $pending = [];
        foreach ($step['fields'] ?? [] as $field) {
            $key = (string) $field['key'];
            $value = trim((string) ($input[$key] ?? ''));
            $required = array_key_exists('required', $field) ? !empty($field['required']) : !empty($step['required']);
            if ($required && $value === '') {
                $errors[$key] = __('validation.required');
                continue;
            }
            if ($key === 'runts') {
                $value = preg_replace('/\D+/', '', $value) ?? '';
                if (strlen($value) > 6) {
                    $errors[$key] = __('validation.max_string', ['max' => '6']);
                    continue;
                }
                $pending[] = [
                    'settings_key' => (string) $field['settings_key'],
                    'env_key' => (string) ($field['env_key'] ?? ''),
                    'value' => $value,
                ];
                continue;
            }
            if ($value === '') {
                continue;
            }
            if (($field['type'] ?? '') === 'select') {
                $value = strtoupper($value);
                $allowed = array_column($field['options'] ?? [], 'value');
                if ($allowed && !in_array($value, $allowed, true)) {
                    $errors[$key] = __('validation.in');
                    continue;
                }
                if (strlen($value) > 6) {
                    $errors[$key] = __('validation.max_string', ['max' => '6']);
                    continue;
                }
            } else {
                $value = assoc_capitalize_name($value);
            }
            $pending[] = [
                'settings_key' => (string) $field['settings_key'],
                'env_key' => (string) ($field['env_key'] ?? ''),
                'value' => $value,
            ];
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        foreach ($pending as $row) {
            $this->settings->set($row['settings_key'], $row['value']);
            if ($row['env_key'] !== '') {
                $env[$row['env_key']] = $row['value'];
            }
        }
        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function saveFieldGroup(array $step, array $input): array
    {
        $env = [];
        $errors = [];
        foreach ($step['fields'] ?? [] as $field) {
            $key = (string) $field['key'];
            $value = trim((string) ($input[$key] ?? ''));
            $required = array_key_exists('required', $field) ? !empty($field['required']) : !empty($step['required']);
            if ($required && $value === '') {
                $errors[$key] = __('validation.required');
                continue;
            }
            if ($value === '') {
                $this->settings->set((string) $field['settings_key'], '');
                if (!empty($field['env_key'])) {
                    $env[(string) $field['env_key']] = '';
                }
                continue;
            }
            $fieldType = (string) ($field['type'] ?? 'text');
            if ($fieldType === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = __('validation.email');
                continue;
            }
            if ($key === 'fiscal_code') {
                $value = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
                if (!$this->isValidEntityFiscalCode($value)) {
                    $errors[$key] = __('validation.fiscal_code');
                    continue;
                }
            }
            if ($key === 'vat_number') {
                $value = preg_replace('/\s+/', '', $value) ?? '';
                if (!$this->isValidVat($value)) {
                    $errors[$key] = __('setup.validation_vat');
                    continue;
                }
            }
            if ($fieldType === 'tel') {
                $value = normalize_phone_value($value);
                if ($value !== '' && !is_valid_phone_value($value)) {
                    $errors[$key] = __('validation.phone');
                    continue;
                }
            }
            $this->settings->set((string) $field['settings_key'], $value);
            if (!empty($field['env_key'])) {
                $env[(string) $field['env_key']] = $value;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }
        $this->markStepReviewed($step);
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function saveAddressBlock(array $step, array $input): array
    {
        $env = [];
        $errors = [];
        $parts = [];
        foreach ($step['fields'] ?? [] as $field) {
            $key = (string) $field['key'];
            $value = trim((string) ($input[$key] ?? ''));
            $required = array_key_exists('required', $field) ? !empty($field['required']) : !empty($step['required']);
            if ($required && $value === '') {
                $errors[$key] = __('validation.required');
                continue;
            }
            if ($key === 'province') {
                $value = strtoupper(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
            }
            $this->settings->set((string) $field['settings_key'], $value);
            if (!empty($field['env_key'])) {
                $env[(string) $field['env_key']] = $value;
            }
            if ($value !== '') {
                $parts[$key] = $value;
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        // Composite line for legacy consumers of association.address display
        $composite = trim(sprintf(
            '%s %s, %s %s %s',
            $parts['address'] ?? '',
            $parts['house_number'] ?? '',
            $parts['postal_code'] ?? '',
            $parts['city'] ?? '',
            $parts['province'] ?? ''
        ));
        $this->settings->set('association.address_full', $composite);
        if ($env !== []) {
            EnvWriter::setUserValues($env);
        }
        $this->markStepReviewed($step);
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function savePresident(array $input): array
    {
        $errors = [];
        $keys = [
            'first_name', 'last_name',
            'birth_date', 'gender', 'birth_place',
            'city', 'postal_code', 'address', 'house_number',
            'appointed_at', 'mandate_ends_at',
            'fiscal_code',
        ];
        $person = [];
        foreach ($keys as $key) {
            $person[$key] = trim((string) ($input[$key] ?? ''));
            if ($person[$key] === '') {
                $errors[$key] = __('validation.required');
            }
        }
        if ($person['gender'] !== '') {
            $person['gender'] = strtoupper($person['gender']);
            if (!in_array($person['gender'], ['M', 'F', 'X'], true)) {
                $errors['gender'] = __('validation.in');
            }
        }
        if ($person['fiscal_code'] !== '') {
            $person['fiscal_code'] = strtoupper(preg_replace('/\s+/', '', $person['fiscal_code']) ?? '');
            if (!$this->isValidPersonFiscalCode($person['fiscal_code'])) {
                $errors['fiscal_code'] = __('validation.fiscal_code');
            }
        }
        foreach (['birth_date', 'appointed_at', 'mandate_ends_at'] as $dateKey) {
            if ($person[$dateKey] !== '' && !$this->isValidDate($person[$dateKey])) {
                $errors[$dateKey] = __('validation.date');
            }
        }
        if ($person['birth_date'] !== '') {
            $birthErr = validate_adult_birth_date($person['birth_date']);
            if ($birthErr !== null) {
                $errors['birth_date'] = __($birthErr);
            }
        }
        $today = date('Y-m-d');
        if ($person['appointed_at'] !== '' && $person['appointed_at'] > $today) {
            $errors['appointed_at'] = __('validation.appointed_future');
        }
        if ($person['mandate_ends_at'] !== '' && $person['mandate_ends_at'] <= $today) {
            $errors['mandate_ends_at'] = __('validation.mandate_past');
        }
        if (
            $person['appointed_at'] !== ''
            && $person['mandate_ends_at'] !== ''
            && $person['appointed_at'] >= $person['mandate_ends_at']
        ) {
            $errors['mandate_ends_at'] = __('validation.mandate_before_appointment');
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $this->people->replaceRole(AssociationPeopleService::ROLE_PRESIDENT, [$person]);
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function savePeopleList(array $step, array $input): array
    {
        $role = (string) ($step['role'] ?? '');
        $min = (int) ($step['min'] ?? 0);
        $raw = $input['people'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $people = [];
        $errors = [];
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $first = trim((string) ($row['first_name'] ?? ''));
            $last = trim((string) ($row['last_name'] ?? ''));
            $cf = strtoupper(preg_replace('/\s+/', '', (string) ($row['fiscal_code'] ?? '')) ?? '');
            if ($first === '' && $last === '' && $cf === '') {
                continue;
            }
            if ($first === '' || $last === '' || $cf === '') {
                $errors['people.' . $i] = __('validation.required');
                continue;
            }
            if (!$this->isValidPersonFiscalCode($cf)) {
                $errors['people.' . $i . '.fiscal_code'] = __('validation.fiscal_code');
                continue;
            }
            $people[] = [
                'first_name' => $first,
                'last_name' => $last,
                'fiscal_code' => $cf,
                'notes' => trim((string) ($row['organ_type'] ?? '')),
            ];
        }
        if (!empty($step['required']) && count($people) < max(1, $min)) {
            $errors['people'] = __('setup.validation_people_min', ['min' => (string) max(1, $min)]);
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        $this->people->replaceRole($role, $people);
        if (!empty($step['settings_key'])) {
            $this->settings->set((string) $step['settings_key'], '1');
        }
        return ['ok' => true];
    }

    /**
     * Accept bare domains (cineforum.bz.it) and normalize to https://…
     */
    private function normalizeWebsiteUrl(string $raw): ?string
    {
        $raw = trim($raw);
        $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        $raw = rtrim($raw, '.,;');
        if ($raw === '') {
            return null;
        }
        // Strip accidental wrappers / prefixes users paste.
        $raw = preg_replace('#^(?:URL|Sito|Website)\s*[:=]\s*#iu', '', $raw) ?? $raw;
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = preg_replace('#^//#', '', $raw) ?? $raw;
            $raw = 'https://' . $raw;
        }
        $parts = parse_url($raw);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host) && !filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            $path = '';
        }
        $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
        return $scheme . '://' . $host . $path . $query;
    }

    private function isValidEntityFiscalCode(string $value): bool
    {
        // Ente: 11 cifre (come P.IVA) oppure 16 alfanumerici
        if (preg_match('/^\d{11}$/', $value) === 1) {
            return true;
        }
        return preg_match('/^[A-Z0-9]{16}$/', $value) === 1;
    }

    private function isValidPersonFiscalCode(string $value): bool
    {
        return preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $value) === 1
            || preg_match('/^[A-Z0-9]{16}$/', $value) === 1;
    }

    private function isValidVat(string $value): bool
    {
        return preg_match('/^\d{11}$/', $value) === 1;
    }

    private function isValidDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $step */
    private function persistScalar(array $step, string $value): void
    {
        $storage = $step['storage'] ?? 'both';
        if ($storage === 'settings' || $storage === 'both') {
            if (!empty($step['settings_key'])) {
                $this->settings->set((string) $step['settings_key'], $value);
            }
        }
        if (($storage === 'env_user' || $storage === 'both') && !empty($step['env_key'])) {
            EnvWriter::setUserValues([(string) $step['env_key'] => $value]);
        }
        if (($step['settings_key'] ?? '') === 'app.locale' && in_array($value, ['it', 'de', 'en'], true)) {
            $_SESSION['locale'] = $value;
        }
    }

    private function readValue(string $settingsKey, string $envKey, mixed $default = null): mixed
    {
        if ($settingsKey !== '') {
            $fromSettings = $this->settings->get($settingsKey, null);
            if ($fromSettings !== null && $fromSettings !== '') {
                return $fromSettings;
            }
        }
        if ($envKey !== '') {
            $user = EnvWriter::parseFile(base_path('.env.user'));
            if (array_key_exists($envKey, $user) && $user[$envKey] !== '') {
                return $user[$envKey];
            }
        }
        return $default;
    }

    private function rawStored(string $settingsKey, string $envKey): mixed
    {
        if ($settingsKey !== '') {
            $all = $this->settings->all();
            if (array_key_exists($settingsKey, $all)) {
                return $all[$settingsKey]['value'];
            }
        }
        if ($envKey !== '') {
            $user = EnvWriter::parseFile(base_path('.env.user'));
            if (array_key_exists($envKey, $user)) {
                return $user[$envKey];
            }
        }
        return null;
    }

    /**
     * @return array{it:string,de:string,en:string}
     */
    private function decodeLocalizedMap(mixed $raw): array
    {
        if (is_array($raw)) {
            return [
                'it' => trim((string) ($raw['it'] ?? '')),
                'de' => trim((string) ($raw['de'] ?? '')),
                'en' => trim((string) ($raw['en'] ?? '')),
            ];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $plain = trim((string) $raw);
            return ['it' => $plain, 'de' => $plain, 'en' => $plain];
        }
        return [
            'it' => trim((string) ($decoded['it'] ?? '')),
            'de' => trim((string) ($decoded['de'] ?? '')),
            'en' => trim((string) ($decoded['en'] ?? '')),
        ];
    }

    private function hasPeriodForYear(int $year): bool
    {
        foreach ($this->members->periods() as $period) {
            $starts = (string) ($period['starts_on'] ?? '');
            $label = (string) ($period['label'] ?? '');
            if (str_starts_with($starts, (string) $year) || $label === (string) $year) {
                return true;
            }
            $ends = (string) ($period['ends_on'] ?? '');
            // Covers calendar year if range includes New Year's Day of that year.
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
    private function saveMemberTypesStep(array $input): array
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
            $allTypes = $this->members->types(false);
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
        $adding = $nameIt !== '';

        if ($adding) {
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
                'is_active' => 1,
                'sort_order' => count($this->members->types(false)),
            ]);
        }

        if (count($this->members->types(false)) === 0) {
            return ['ok' => false, 'errors' => ['types' => __('setup.validation_need_type')]];
        }

        $allTypes = $this->members->types(false);
        if (count($allTypes) === 1 && empty($allTypes[0]['is_active'])) {
            $this->db->update('member_types', ['is_active' => 1], 'id = :id', ['id' => (int) $allTypes[0]['id']]);
        }

        $this->settings->set('membership.types_configured', '1');
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function saveMembershipPeriodsStep(array $input): array
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
            $label = trim((string) ($row['label'] ?? ''));
            $starts = trim((string) ($row['starts_on'] ?? ''));
            $ends = trim((string) ($row['ends_on'] ?? ''));
            if ($starts === '' || !$this->isValidDate($starts) || !$this->isValidDate($ends) || $ends < $starts) {
                return ['ok' => false, 'errors' => ['periods' => __('validation.date')]];
            }
            $label = $this->members->autoPeriodLabel($starts, $ends);
            if (!empty($row['is_current'])) {
                $currentId = $id;
            }
            $this->db->update('membership_periods', [
                'label' => $label,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'is_current' => 0,
            ], 'id = :id', ['id' => $id]);
        }

        $starts = trim((string) ($input['starts_on'] ?? ''));
        $ends = trim((string) ($input['ends_on'] ?? ''));
        $adding = $starts !== '' && $ends !== '';
        $newId = 0;

        if ($adding) {
            if (!$this->isValidDate($starts) || !$this->isValidDate($ends)) {
                return ['ok' => false, 'errors' => ['starts_on' => __('validation.date')]];
            }
            if ($ends < $starts) {
                return ['ok' => false, 'errors' => ['ends_on' => __('validation.period_end_before_start')]];
            }
            $label = $this->members->autoPeriodLabel($starts, $ends);
            $newId = $this->db->insert('membership_periods', [
                'label' => $label,
                'starts_on' => $starts,
                'ends_on' => $ends,
                'is_current' => 0,
            ]);
            if (!empty($input['is_current']) || count($this->members->periods()) === 1) {
                $currentId = (int) $newId;
            }
        }

        if (count($this->members->periods()) === 0) {
            return ['ok' => false, 'errors' => ['periods' => __('setup.validation_need_period')]];
        }

        if (!$this->hasPeriodForYear((int) date('Y'))) {
            return ['ok' => false, 'errors' => ['periods' => __('setup.periods_need_current_year')]];
        }

        $this->db->query('UPDATE membership_periods SET is_current = 0');
        if ($currentId > 0) {
            $this->db->update('membership_periods', ['is_current' => 1], 'id = :id', ['id' => $currentId]);
        } else {
            // Keep at least one current period (prefer the newest).
            $latest = $this->db->fetch('SELECT id FROM membership_periods ORDER BY starts_on DESC, id DESC LIMIT 1');
            if ($latest) {
                $this->db->update('membership_periods', ['is_current' => 1], 'id = :id', ['id' => (int) $latest['id']]);
            }
        }

        $this->settings->set('membership.periods_configured', '1');
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function saveMemberFieldsStep(array $input): array
    {
        $result = $this->members->persistFieldsConfig($input, true);
        if (!empty($result['ok'])) {
            $this->settings->set('membership.fields_configured', '1');
        }
        return $result;
    }

    /**
     * Autosave fields layout without creating a new field.
     *
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function autosaveMemberFields(array $input): array
    {
        $result = $this->members->persistFieldsConfig($input, false);
        if (!empty($result['ok'])) {
            $this->settings->set('membership.fields_configured', '1');
        }
        return $result;
    }
    /**
     * @param array<string, mixed> $input
     * @return array{ok:bool,errors?:array<string,string>}
     */
    private function saveComponentSelect(array $input): array
    {
        $raw = $input['components'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $keys = [];
        foreach ($raw as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            return ['ok' => false, 'errors' => ['components' => __('setup.components_required')]];
        }
        foreach (['members', 'org_roles'] as $required) {
            if (!in_array($required, $keys, true)) {
                $keys[] = $required;
            }
        }
        $this->components->setEnabled($keys);
        $this->components->markConfigured();
        return ['ok' => true];
    }

    private function saveAdminAccount(array $input): array
    {
        if ($this->users->hasAssociationAdmin()) {
            // Upgrade / re-entry: association Admin already exists.
            $existing = null;
            foreach ($this->users->all(true) as $user) {
                if (empty($user['is_system_admin']) && !empty($user['is_active'])) {
                    $existing = $user;
                    break;
                }
            }
            if ($existing) {
                $this->settings->set('app.admin_user_id', (string) $existing['id']);
                return ['ok' => true, 'admin_user_id' => (int) $existing['id']];
            }
        }

        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' && $email !== '') {
            $local = str_contains($email, '@') ? strstr($email, '@', true) : $email;
            $name = is_string($local) && $local !== '' ? ucfirst($local) : 'Admin';
        }

        $result = $this->users->createAssociationAdmin([
            'name' => $name,
            'email' => $email,
            'password' => (string) ($input['password'] ?? ''),
            'password_confirmation' => (string) ($input['password_confirmation'] ?? ''),
            'locale' => (string) ($input['locale'] ?? $this->readValue('app.locale', 'APP_LOCALE', 'it')),
        ], (string) ($input['_client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')));

        if (empty($result['ok'])) {
            return ['ok' => false, 'errors' => $result['errors'] ?? ['name' => __('validation.required')]];
        }

        $id = (int) ($result['id'] ?? 0);
        $this->settings->set('app.admin_user_id', (string) $id);
        return ['ok' => true, 'admin_user_id' => $id];
    }

    public function hasProgress(): bool
    {
        return count($this->missingSteps()) < count(SetupCatalogue::all());
    }

    /**
     * First-run bootstrap: setup is open without login until an association Admin exists.
     * Leftover membership rows (types/periods) must not force the login screen.
     */
    public function allowsAnonymousSetup(): bool
    {
        return !$this->isComplete() && !$this->users->hasAssociationAdmin();
    }

    /**
     * True when the association already has a working base (Admin exists)
     * and only remaining / newly added catalogue steps are pending.
     * First-run and post-reset setups must use the “new configuration” copy,
     * even if some optional steps look pre-satisfied.
     */
    public function isIncrementalSetup(): bool
    {
        if ($this->isComplete()) {
            return false;
        }

        return $this->users->hasAssociationAdmin();
    }

    public function isGdprEnabled(): bool
    {
        $raw = $this->readValue('gdpr.enabled', 'GDPR_ENABLED', '0');
        return (string) $raw === '1';
    }

    /**
     * When at least one platform preference is enabled, the president’s name must be typed to confirm.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>|null
     */
    public function validatePresidentNameConfirmation(array $input): ?array
    {
        $first = $this->normalizePersonName((string) ($input['confirm_first_name'] ?? ''));
        $last = $this->normalizePersonName((string) ($input['confirm_last_name'] ?? ''));
        if ($first === '' || $last === '') {
            return [
                'confirm_first_name' => __('setup.platform_confirm_required'),
            ];
        }

        $president = $this->people->getPresident();
        if ($president === null) {
            return [
                'confirm_first_name' => __('setup.platform_confirm_no_president'),
            ];
        }

        $expectedFirst = $this->normalizePersonName((string) ($president['first_name'] ?? ''));
        $expectedLast = $this->normalizePersonName((string) ($president['last_name'] ?? ''));
        if ($expectedFirst === '' || $expectedLast === '') {
            return [
                'confirm_first_name' => __('setup.platform_confirm_no_president'),
            ];
        }

        if ($first !== $expectedFirst || $last !== $expectedLast) {
            return [
                'confirm_first_name' => __('setup.platform_confirm_mismatch'),
            ];
        }

        return null;
    }

    private function normalizePersonName(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
            $value = preg_replace('/\p{Mn}/u', '', $value) ?? $value;
        }
        return mb_strtolower($value, 'UTF-8');
    }

    public function discardProgress(): void
    {
        // Never destroy a working association when only additive steps are pending.
        if ($this->isIncrementalSetup()) {
            return;
        }

        $keys = [];
        foreach (SetupCatalogue::all() as $step) {
            $type = (string) ($step['type'] ?? '');
            if (in_array($type, ['colors', 'name_pair', 'field_group', 'address_block'], true)) {
                foreach ($step['fields'] ?? [] as $field) {
                    if (!empty($field['settings_key'])) {
                        $keys[] = (string) $field['settings_key'];
                    }
                }
                if ($type === 'colors') {
                    $keys[] = 'branding.colors_confirmed';
                    $keys[] = 'branding.palette_suggestions';
                }
                continue;
            }
            if ($type === 'logo') {
                $keys[] = 'branding.logo';
                $keys[] = 'branding.logo_configured';
                continue;
            }
            if (!empty($step['settings_key'])) {
                $keys[] = (string) $step['settings_key'];
            }
        }
        $keys[] = 'association.address_full';
        foreach (SetupCatalogue::all() as $step) {
            $reviewKey = $this->stepReviewSettingsKey($step);
            if ($reviewKey !== '') {
                $keys[] = $reviewKey;
            }
        }
        $this->settings->deleteMany(array_values(array_unique($keys)));
        EnvWriter::resetUserFile();
        $this->people->clearAll();
        $this->clearMembershipConfiguration();
    }

    /**
     * Full wipe of association data so first-run setup can be repeated.
     * Deletes every operational row and stored files (logo, photos, documents, enrollment).
     * Keeps only the platform SuperAdmin account(s), permissions catalogue and migrations.
     */
    public function resetAssociationConfiguration(): void
    {
        // Files first — permanent deletion of uploads/documents on disk.
        $this->purgeStoredUserFiles();

        // Operational modules (before members: FKs / orphan paths).
        $this->safeDelete('DELETE FROM treasury_movements');
        $this->safeDelete('DELETE FROM deadline_items');
        $this->safeDelete('DELETE FROM association_documents');

        $this->people->clearAll();
        $this->clearCustomOrgans();
        $this->clearMembershipConfiguration();

        $this->db->query('DELETE FROM settings');
        $this->safeDelete('DELETE FROM password_resets');
        $this->safeDelete('DELETE FROM remember_tokens');
        $this->safeDelete('DELETE FROM association_officers');
        $this->safeDelete('DELETE FROM audit_logs');
        EnvWriter::resetUserFile();

        $ids = array_map(
            static fn ($id): int => (int) $id,
            array_column(
                $this->db->fetchAll('SELECT id FROM users WHERE is_system_admin = 0'),
                'id'
            )
        );
        foreach ($ids as $id) {
            $this->db->query('DELETE FROM user_permissions WHERE user_id = :id', ['id' => $id]);
            $this->db->query('DELETE FROM users WHERE id = :id', ['id' => $id]);
        }

        unset(
            $_SESSION['setup_greeted'],
            $_SESSION['setup_show_thanks'],
            $_SESSION['setup_progress_keys'],
            $_SESSION['setup_dismissed'],
            $_SESSION['_setup_draft'],
            $_SESSION['_old'],
            $_SESSION['locale']
        );
    }

    /**
     * Membership catalogue lives in DB tables (not settings / .env.user).
     * Must be cleared with association config or setup still shows old types/periods.
     */
    private function clearMembershipConfiguration(): void
    {
        // members first: payments / field values / enrollment artifacts cascade.
        $this->db->query('DELETE FROM members');
        $this->db->query('DELETE FROM member_types');
        $this->db->query('DELETE FROM membership_periods');
        $this->db->query('DELETE FROM member_field_definitions');
        $this->safeDelete('DELETE FROM member_form_steps');
    }

    /** Remove custom organ roles created under Org chart (system roles stay). */
    private function clearCustomOrgans(): void
    {
        try {
            $this->db->query('DELETE FROM association_roles WHERE is_system = 0 OR `key` LIKE \'organ_%\'');
        } catch (\Throwable) {
            try {
                $this->db->query('DELETE FROM association_roles WHERE `key` LIKE \'organ_%\'');
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Permanently delete association files on disk (keep folder placeholders like .gitkeep).
     */
    private function purgeStoredUserFiles(): void
    {
        $this->purgeDirectoryContents(storage_path('uploads'));
        $this->purgeDirectoryContents(storage_path('documents'));
    }

    private function purgeDirectoryContents(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $root = realpath($dir);
        if ($root === false) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $name = $item->getFilename();
            if ($name === '.gitkeep') {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function safeDelete(string $sql): void
    {
        try {
            $this->db->query($sql);
        } catch (\Throwable) {
        }
    }
}
