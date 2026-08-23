<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Database;
use Socly\Core\Http\Request;
use Socly\Core\Plugin\PluginManager;
use Socly\Core\Validator;
use Socly\Core\View;
use Socly\Components\ComponentRegistry;
use Socly\Services\AssociationPeopleService;
use Socly\Services\AuditService;
use Socly\Services\BrandingService;
use Socly\Services\ComponentService;
use Socly\Services\DocumentService;
use Socly\Services\DeadlineService;
use Socly\Services\InstallerService;
use Socly\Services\MailService;
use Socly\Services\MemberService;
use Socly\Services\PlatformService;
use Socly\Services\SettingsService;
use Socly\Services\SetupService;
use Socly\Services\UserService;

final class SettingsController extends BaseController
{
    public function __construct(
        View $view,
        private readonly SettingsService $settings,
        private readonly MemberService $members,
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly AuditService $audit,
        private readonly PluginManager $plugins,
        private readonly AssociationPeopleService $people,
        private readonly BrandingService $branding,
        private readonly MailService $mail,
        private readonly SetupService $setup,
        private readonly ComponentService $components,
        private readonly DocumentService $documents,
        private readonly DeadlineService $deadlines,
        private readonly PlatformService $platform,
        private readonly UserService $users
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        $this->members->ensureMembershipPeriodRollover();
        $people = $this->people->all();
        if ($people === []) {
            $people = [[
                'role_key' => AssociationPeopleService::ROLE_PRESIDENT,
                'first_name' => '',
                'last_name' => '',
                'fiscal_code' => '',
                'city' => '',
                'postal_code' => '',
                'address' => '',
                'house_number' => '',
                'appointed_at' => '',
                'mandate_ends_at' => '',
            ]];
        }
        $this->render('settings/index', [
            'title' => __('settings.title'),
            'settings' => [
                'association.name' => $this->settings->get('association.name'),
                'association.legal_name' => $this->settings->get('association.legal_name'),
                'association.fiscal_code' => $this->settings->get('association.fiscal_code'),
                'association.vat_number' => $this->settings->get('association.vat_number'),
                'association.city' => $this->settings->get('association.city'),
                'association.postal_code' => $this->settings->get('association.postal_code'),
                'association.province' => $this->settings->get('association.province'),
                'association.address' => $this->settings->get('association.address'),
                'association.house_number' => $this->settings->get('association.house_number'),
                'association.pec' => $this->settings->get('association.pec'),
                'association.email' => $this->settings->get('association.email'),
                'association.phone' => $this->settings->get('association.phone'),
                'association.runts' => $this->settings->get('association.runts'),
                'branding.primary' => $this->settings->get('branding.primary', '#0D6E66'),
                'branding.accent' => $this->settings->get('branding.accent', '#B84A1B'),
                'app.locale' => $this->settings->get('app.locale', 'it'),
                'gdpr.enabled' => $this->settings->get('gdpr.enabled', '0'),
                'legal.privacy' => $this->legalTexts('legal.privacy'),
                'legal.statute' => $this->legalTexts('legal.statute'),
                'membership.enrollment_validation' => $this->settings->get('membership.enrollment_validation', 'none'),
                'platform.news_opt_in' => $this->settings->get('platform.news_opt_in', '1'),
                'platform.usage_stats_opt_in' => $this->settings->get('platform.usage_stats_opt_in', '1'),
                'platform.showcase_consent' => $this->settings->get('platform.showcase_consent', '1'),
            ],
            'roles' => $this->people->roles(),
            'people' => $people,
            'types' => $this->members->types(),
            'periods' => $this->members->periods(),
            'fields' => $this->members->fieldDefinitions(false),
            'formSteps' => $this->members->formSteps(),
            'defaultFields' => InstallerService::defaultFields(),
            'mailConfig' => $this->mail->config(),
            'mailReady' => $this->mail->isReady(),
            'presidentFirstPlaceholder' => trim((string) (($this->people->getPresident() ?? [])['first_name'] ?? '')),
            'presidentLastPlaceholder' => trim((string) (($this->people->getPresident() ?? [])['last_name'] ?? '')),
            'components' => ComponentRegistry::all(),
            'enabledComponents' => array_fill_keys($this->components->enabledKeys(), true),
            'componentConfigs' => [
                'treasury' => $this->components->config('treasury', ['auto_from_payments' => true]),
                'org_roles' => $this->components->config('org_roles', []),
                'deadlines' => $this->components->config('deadlines', ['warn_days' => 30]),
                'documents' => array_merge(
                    $this->components->config('documents', ['default_category' => 'minutes']),
                    ['category_options' => $this->documents->categoryOptions()]
                ),
            ],
            'plugin_catalog' => $this->pluginCatalogForSettings(),
            'panelUsers' => $this->users->panelUsers(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function pluginCatalogForSettings(): array
    {
        if (!can('plugins.manage')) {
            return [];
        }
        try {
            $admin = app(\Socly\Services\PluginAdminService::class);
            $catalog = $admin->catalog();
            foreach ($catalog as &$item) {
                $values = [];
                foreach (($item['settings'] ?? []) as $key => $def) {
                    $values[$key] = $this->settings->get((string) $key, '');
                }
                $item['values'] = $values;
            }
            unset($item);
            return $catalog;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{it:string,de:string,en:string} */
    private function legalTexts(string $key): array
    {
        $raw = $this->settings->get($key, '');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = ['it' => (string) $raw, 'de' => '', 'en' => ''];
        }
        return [
            'it' => (string) ($decoded['it'] ?? ''),
            'de' => (string) ($decoded['de'] ?? ''),
            'en' => (string) ($decoded['en'] ?? ''),
        ];
    }

    public function saveLegal(Request $request): void
    {
        $data = $request->all();
        $privacy = [
            'it' => trim((string) ($data['privacy_it'] ?? '')),
            'de' => trim((string) ($data['privacy_de'] ?? '')),
            'en' => trim((string) ($data['privacy_en'] ?? '')),
        ];
        $statute = [
            'it' => trim((string) ($data['statute_it'] ?? '')),
            'de' => trim((string) ($data['statute_de'] ?? '')),
            'en' => trim((string) ($data['statute_en'] ?? '')),
        ];
        $this->settings->set('legal.privacy', $privacy);
        $this->settings->set('legal.statute', $statute);
        $this->settings->set('gdpr.enabled', !empty($data['gdpr_enabled']) ? '1' : '0');
        \Socly\Support\EnvWriter::setUserValues([
            'GDPR_ENABLED' => !empty($data['gdpr_enabled']) ? '1' : '0',
        ]);
        $this->audit->log('settings.saved', 'settings', 'legal', null, [
            'privacy_len' => array_map('mb_strlen', $privacy),
            'statute_len' => array_map('mb_strlen', $statute),
            'gdpr' => !empty($data['gdpr_enabled']),
        ], $request->ip());
        $this->plugins->fire('settings.saved', ['legal.privacy', 'legal.statute', 'gdpr.enabled']);
        $this->settingsFinish($request, 'legal');
    }

    public function saveGeneral(Request $request): void
    {
        $data = $request->all();
        if (!$this->validator->validate($data, [
            'association_name' => 'required|string|max:120',
            'association_legal_name' => 'required|string|max:6|in:' . implode(',', \Socly\Setup\AssociationLegalForms::codes()),
            'association_fiscal_code' => 'required|string|max:16',
            'association_city' => 'required|string|max:120',
            'association_postal_code' => 'required|string|max:12',
            'association_province' => 'required|string|max:4',
            'association_address' => 'required|string|max:255',
            'association_house_number' => 'required|string|max:20',
            'association_pec' => 'required|email|max:190',
            'association_email' => 'required|email|max:190',
            'association_runts' => 'nullable|string|max:6',
            'primary' => 'required|color',
            'accent' => 'required|color',
            'locale' => 'required|in:it,de,en',
        ])) {
            $this->settingsFail($request, $this->validator->firstErrors(), 'general');
        }
        $legal = strtoupper(trim((string) $data['association_legal_name']));
        $name = assoc_capitalize_name((string) $data['association_name']);
        $fiscal = strtoupper(preg_replace('/\s+/', '', (string) $data['association_fiscal_code']) ?? '');
        $vat = preg_replace('/\s+/', '', (string) ($data['association_vat'] ?? '')) ?? '';
        $before = [
            'association.name' => $this->settings->get('association.name'),
            'association.legal_name' => $this->settings->get('association.legal_name'),
            'branding.primary' => $this->settings->get('branding.primary'),
        ];
        $this->settings->set('association.name', $name);
        $this->settings->set('association.legal_name', $legal);
        $this->settings->set('association.fiscal_code', $fiscal);
        $this->settings->set('association.vat_number', $vat);
        $this->settings->set('association.city', $data['association_city']);
        $this->settings->set('association.postal_code', $data['association_postal_code']);
        $province = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($data['association_province'] ?? '')) ?? '');
        $this->settings->set('association.province', $province);
        $this->settings->set('association.address', $data['association_address']);
        $this->settings->set('association.house_number', $data['association_house_number']);
        $this->settings->set('association.address_full', trim(sprintf(
            '%s %s, %s %s %s',
            $data['association_address'],
            $data['association_house_number'],
            $data['association_postal_code'],
            $data['association_city'],
            $province
        )));
        $this->settings->set('association.pec', $data['association_pec']);
        $this->settings->set('association.email', $data['association_email']);
        $phone = normalize_phone_value($data['association_phone'] ?? '');
        if ($phone !== '' && !is_valid_phone_value($phone)) {
            $this->settingsFail($request, ['association_phone' => __('validation.phone')], 'general');
        }
        $this->settings->set('association.phone', $phone);
        $this->settings->set('association.runts', $data['association_runts']);
        [$primary, $accent] = $this->branding->ensureDistinctColors(
            (string) $data['primary'],
            (string) $data['accent']
        );
        $this->settings->set('branding.primary', $primary);
        $this->settings->set('branding.accent', $accent);
        $this->settings->set('app.locale', $data['locale']);
        if (in_array((string) $data['locale'], ['it', 'de', 'en'], true)) {
            $_SESSION['locale'] = (string) $data['locale'];
        }
        if (!empty($data['remove_logo'])) {
            $relative = $this->branding->logoRelativePath();
            if ($relative !== '') {
                $absolute = storage_path('uploads/' . $relative);
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
                $this->settings->set('branding.logo', '');
            }
        } else {
            $logoFile = $request->file('logo');
            if ($logoFile !== null) {
                $stored = $this->branding->storeLogoUpload($logoFile);
                if (!$stored['ok']) {
                    $this->settingsFail($request, ['logo' => (string) ($stored['error'] ?? __('validation.photo'))], 'general');
                }
            }
        }
        \Socly\Support\EnvWriter::setUserValues([
            'ASSOCIATION_NAME' => $name,
            'ASSOCIATION_LEGAL_NAME' => $legal,
            'ASSOCIATION_FISCAL_CODE' => $fiscal,
            'ASSOCIATION_VAT' => $vat,
            'ASSOCIATION_CITY' => $data['association_city'],
            'ASSOCIATION_POSTAL_CODE' => $data['association_postal_code'],
            'ASSOCIATION_PROVINCE' => $province,
            'ASSOCIATION_ADDRESS' => $data['association_address'],
            'ASSOCIATION_HOUSE_NUMBER' => $data['association_house_number'],
            'ASSOCIATION_PEC' => $data['association_pec'],
            'ASSOCIATION_EMAIL' => $data['association_email'],
            'ASSOCIATION_PHONE' => $phone,
            'ASSOCIATION_RUNTS' => $data['association_runts'],
            'BRANDING_PRIMARY' => $data['primary'],
            'BRANDING_ACCENT' => $data['accent'],
            'APP_LOCALE' => $data['locale'],
        ]);
        $this->audit->log('settings.saved', 'settings', 'general', $before, $data, $request->ip());
        $this->plugins->fire('settings.saved', array_keys($data));
        $this->settingsFinish($request, 'general');
    }

    public function savePeople(Request $request): void
    {
        $data = $request->all();
        $allowed = $this->people->roleKeys();
        $rows = [];
        foreach ((array) ($data['people'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = trim((string) ($row['role_key'] ?? ''));
            $first = trim((string) ($row['first_name'] ?? ''));
            $last = trim((string) ($row['last_name'] ?? ''));
            $cf = strtoupper(preg_replace('/\s+/', '', (string) ($row['fiscal_code'] ?? '')) ?? '');
            if ($first === '' && $last === '' && $cf === '') {
                continue;
            }
            if ($role === '' || !in_array($role, $allowed, true)) {
                $this->flash('errors', ['people' => __('validation.in')]);
                redirect('/settings#people');
            }
            if ($first === '' || $last === '' || $cf === '') {
                $this->flash('errors', ['people' => __('validation.required')]);
                redirect('/settings#people');
            }
            $rows[] = [
                'role_key' => $role,
                'first_name' => $first,
                'last_name' => $last,
                'fiscal_code' => $cf,
                'city' => trim((string) ($row['city'] ?? '')),
                'postal_code' => trim((string) ($row['postal_code'] ?? '')),
                'address' => trim((string) ($row['address'] ?? '')),
                'house_number' => trim((string) ($row['house_number'] ?? '')),
                'appointed_at' => trim((string) ($row['appointed_at'] ?? '')),
                'mandate_ends_at' => trim((string) ($row['mandate_ends_at'] ?? '')),
            ];
        }

        $hasPresident = false;
        $hasBoard = false;
        foreach ($rows as $row) {
            if ($row['role_key'] === AssociationPeopleService::ROLE_PRESIDENT) {
                $hasPresident = true;
                foreach (['city', 'postal_code', 'address', 'house_number', 'appointed_at', 'mandate_ends_at'] as $req) {
                    if ($row[$req] === '') {
                        $this->flash('errors', ['president' => __('validation.required')]);
                        redirect('/settings#people');
                    }
                }
            }
            if (in_array($row['role_key'], [
                AssociationPeopleService::ROLE_BOARD,
                AssociationPeopleService::ROLE_VICE_PRESIDENT,
                AssociationPeopleService::ROLE_SECRETARY,
                AssociationPeopleService::ROLE_TREASURER,
            ], true)) {
                $hasBoard = true;
            }
        }
        if (!$hasPresident) {
            $this->flash('errors', ['president' => __('settings.people_need_president')]);
            redirect('/settings#people');
        }
        if (!$hasBoard) {
            $this->flash('errors', ['board' => __('setup.validation_people_min', ['min' => '1'])]);
            redirect('/settings#people');
        }

        try {
            $this->people->replaceAll($rows);
        } catch (\Throwable $e) {
            $this->flash('errors', ['people' => __('settings.people_unique_role')]);
            redirect('/settings#people');
        }

        $this->settings->set('association.board_configured', '1');
        $this->settings->set('association.auditors_configured', '1');
        $this->flash('success', __('settings.saved'));
        redirect('/settings#people');
    }

    /** @deprecated */
    public function saveOfficers(Request $request): void
    {
        $this->savePeople($request);
    }

    public function saveType(Request $request): void
    {
        $result = $this->members->persistTypesConfig($request->all());
        if (empty($result['ok'])) {
            $this->settingsFail($request, $result['errors'] ?? ['types' => __('validation.required')], 'types');
        }
        $this->settings->set('membership.types_configured', '1');
        $this->audit->log('settings.saved', 'settings', 'types', null, null, $request->ip());
        $this->settingsFinish($request, 'types');
    }

    public function savePeriod(Request $request): void
    {
        $result = $this->members->persistPeriodsConfig($request->all());
        if (empty($result['ok'])) {
            $this->settingsFail($request, $result['errors'] ?? ['periods' => __('validation.required')], 'periods');
        }
        $this->settings->set('membership.periods_configured', '1');
        $this->deadlines->syncSystemDeadlines();
        $this->audit->log('settings.saved', 'settings', 'periods', null, null, $request->ip());
        $this->settingsFinish($request, 'periods');
    }

    public function saveFields(Request $request): void
    {
        $result = $this->members->persistFieldsConfig($request->all(), true);
        if (empty($result['ok'])) {
            $this->flash('errors', $result['errors'] ?? ['fields' => __('validation.required')]);
            redirect('/settings#fields');
        }
        $this->settings->set('membership.fields_configured', '1');
        $this->flash('success', __('settings.saved'));
        redirect('/settings#fields');
    }

    public function autosaveFields(Request $request): void
    {
        $result = $this->members->persistFieldsConfig($request->all(), false);
        if (!empty($result['ok'])) {
            $this->settings->set('membership.fields_configured', '1');
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(!empty($result['ok']) ? 200 : 422);
        echo json_encode([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? __('setup.fields_autosaved') : __('setup.fields_autosave_failed'),
            'errors' => $result['errors'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function saveEnrollment(Request $request): void
    {
        $method = (string) $request->input('enrollment_validation', 'none');
        $allowed = ['none', 'print_scan', 'tablet_sign', 'otp_email'];
        if (!in_array($method, $allowed, true)) {
            $this->settingsFail($request, ['enrollment_validation' => __('validation.in')], 'enrollment');
        }
        if ($method === 'otp_email' && !$this->mail->isReady()) {
            $this->settingsFail($request, ['enrollment_validation' => __('mail.required_for_otp')], 'enrollment');
        }
        $this->settings->set('membership.enrollment_validation', $method);
        $this->audit->log('settings.saved', 'settings', 'enrollment', null, ['method' => $method], $request->ip());
        $this->settingsFinish($request, 'enrollment');
    }

    public function savePlatform(Request $request): void
    {
        $data = $request->all();
        $news = !empty($data['news_opt_in']);
        $stats = !empty($data['usage_stats_opt_in']);
        $showcase = !empty($data['showcase_consent']);
        if ($news || $stats || $showcase) {
            $confirmError = app(\Socly\Services\SetupService::class)->validatePresidentNameConfirmation($data);
            if ($confirmError !== null) {
                $this->settingsFail($request, $confirmError, 'platform');
            }
            $this->settings->set('platform.consents_confirmed_at', date('c'));
            $this->settings->set('platform.consents_confirmed_name', trim(
                (string) ($data['confirm_first_name'] ?? '') . ' ' . (string) ($data['confirm_last_name'] ?? '')
            ));
        }
        $this->settings->set('platform.news_opt_in', $news ? '1' : '0');
        $this->settings->set('platform.usage_stats_opt_in', $stats ? '1' : '0');
        $this->settings->set('platform.showcase_consent', $showcase ? '1' : '0');
        $this->settings->set('platform.consents_configured', '1');
        try {
            if ($stats) {
                $this->platform->maybeSendTelemetry();
            }
            $this->platform->syncShowcase();
        } catch (\Throwable) {
        }
        $this->audit->log('settings.saved', 'settings', 'platform', null, [
            'news' => $news,
            'stats' => $stats,
            'showcase' => $showcase,
        ], $request->ip());
        $this->settingsFinish($request, 'platform');
    }

    public function saveMail(Request $request): void
    {
        $data = $request->all();
        $action = (string) ($data['action'] ?? 'save_test');

        if (!empty($data['outbound_disabled'])) {
            $this->mail->disableOutbound();
            unset($_SESSION['_flash']['smtp_needs_manual']);
            $this->flash('success', __('settings.saved'));
            $this->audit->log('settings.saved', 'settings', 'mail', null, ['outbound_disabled' => true], $request->ip());
            redirect('/settings#mail');
        }

        $result = $this->mail->saveSimple($data, $action === 'save_test');
        if (!$result['ok']) {
            if (!empty($result['needs_manual'])) {
                $_SESSION['_flash']['smtp_needs_manual'] = true;
            }
            $this->flash('errors', $result['errors'] ?? ['mail' => __('mail.test_failed')]);
            redirect('/settings#mail');
        }
        unset($_SESSION['_flash']['smtp_needs_manual']);
        if ($action === 'save_test') {
            $this->flash('success', __('mail.test_ok'));
        } else {
            $this->flash('success', __('settings.saved'));
            if (!$this->mail->isReady()) {
                $this->flash('errors', ['test' => __('mail.test_needed')]);
            }
        }
        $this->audit->log('settings.saved', 'settings', 'mail', null, [
            'host' => (string) ($data['host'] ?? ''),
            'tested' => $action === 'save_test',
        ], $request->ip());
        redirect('/settings#mail');
    }

    public function saveComponents(Request $request): void
    {
        $data = $request->all();
        $raw = $data['components'] ?? [];
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
            $this->flash('errors', ['components' => __('setup.components_required')]);
            redirect('/settings#components');
        }
        $this->components->setEnabled($keys);
        $this->components->markConfigured();

        if ($this->components->isEnabled('treasury')) {
            $this->components->saveConfig('treasury', [
                'auto_from_payments' => !empty($data['treasury_auto_from_payments']),
            ]);
        }
        if ($this->components->isEnabled('deadlines')) {
            $existing = $this->components->config('deadlines', ['warn_days' => 30, 'default_category' => 'general']);
            $warnDays = max(1, min(90, (int) ($data['deadlines_warn_days'] ?? 30)));
            $existing['warn_days'] = $warnDays;
            $this->components->saveConfig('deadlines', $existing);
        }
        if ($this->components->isEnabled('documents')) {
            $existing = $this->components->config('documents', ['default_category' => 'minutes']);
            $category = trim((string) ($data['documents_default_category'] ?? 'minutes'));
            $existing['default_category'] = $category !== '' ? $category : 'minutes';
            $this->components->saveConfig('documents', $existing);
        }

        $this->audit->log('settings.saved', 'settings', 'components', null, ['enabled' => $keys], $request->ip());
        $this->flash('success', __('settings.saved'));
        redirect('/settings#components');
    }

    public function resetUserData(Request $request): void
    {
        $user = auth_user();
        if (empty($user['is_system_admin'])) {
            http_response_code(403);
            $this->flash('errors', ['reset' => __('settings.reset_forbidden')]);
            redirect('/settings');
        }

        $confirm1 = (string) $request->input('confirm_reset_1', '');
        $confirm2 = (string) $request->input('confirm_reset_2', '');
        if ($confirm1 !== '1' || $confirm2 !== '1') {
            $this->flash('errors', ['reset' => __('settings.reset_confirm_required')]);
            redirect('/settings#danger-zone');
        }

        try {
            $this->audit->log('settings.reset_user_data', 'settings', 'reset', null, [
                'by' => (string) ($user['email'] ?? ''),
                'includes_files' => true,
            ], $request->ip());
        } catch (\Throwable) {
        }

        $this->setup->resetAssociationConfiguration();
        $this->flash('success', __('settings.reset_done'));
        redirect('/setup?intro=1');
    }

    private function settingsWantsJson(Request $request): bool
    {
        return strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
    }

    private function settingsFinish(Request $request, string $anchor = ''): never
    {
        if ($this->settingsWantsJson($request)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'message' => __('settings.autosaved')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $this->flash('success', __('settings.saved'));
        redirect('/settings' . ($anchor !== '' ? '#' . $anchor : ''));
    }

    /** @param array<string,string> $errors */
    private function settingsFail(Request $request, array $errors, string $anchor = ''): never
    {
        if ($this->settingsWantsJson($request)) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'message' => __('settings.autosave_failed'),
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $this->flash('errors', $errors);
        redirect('/settings' . ($anchor !== '' ? '#' . $anchor : ''));
    }
}
