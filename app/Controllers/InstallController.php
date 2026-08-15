<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Database;
use Socly\Core\Encryptor;
use Socly\Core\Http\Request;
use Socly\Core\Migrator;
use Socly\Core\Validator;
use Socly\Core\View;
use Socly\Services\AuditService;
use Socly\Services\InstallerService;
use Socly\Services\SettingsService;

final class InstallController extends BaseController
{
    public function __construct(
        View $view,
        private readonly Validator $validator
    ) {
        parent::__construct($view);
    }

    public function index(Request $request): void
    {
        $step = (int) ($request->input('step', $_SESSION['install']['step'] ?? 1));
        $step = max(1, min(6, $step));
        $this->render('install/wizard', [
            'step' => $step,
            'data' => $_SESSION['install'] ?? [],
            'fields' => InstallerService::defaultFields(),
            'title' => __('install.title'),
        ], 'layouts/install');
    }

    public function save(Request $request): void
    {
        $step = (int) $request->input('step', 1);
        $_SESSION['install'] ??= [];
        $data = $request->all();
        $this->rememberOld($data);

        if ($step === 1) {
            if (!$this->validator->validate($data, [
                'db_host' => 'required|string',
                'db_port' => 'required|integer',
                'db_database' => 'required|string',
                'db_username' => 'required|string',
                'app_url' => 'required|string',
            ])) {
                $this->flash('errors', $this->validator->firstErrors());
                redirect('/install?step=1');
            }
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;charset=utf8mb4',
                    $data['db_host'],
                    $data['db_port']
                );
                $pdo = new \PDO($dsn, $data['db_username'], (string) $data['db_password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $data['db_database']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (\Throwable $e) {
                $this->flash('errors', ['db' => __('install.db_error') . ': ' . $e->getMessage()]);
                redirect('/install?step=1');
            }
            $appKey = Encryptor::generateKey();
            $_SESSION['install'] = array_merge($_SESSION['install'], [
                'db_host' => $data['db_host'],
                'db_port' => $data['db_port'],
                'db_database' => $data['db_database'],
                'db_username' => $data['db_username'],
                'db_password' => $data['db_password'] ?? '',
                'app_url' => rtrim($data['app_url'], '/'),
                'app_key' => $appKey,
                'step' => 2,
            ]);
            InstallerService::writeEnv($_SESSION['install']);
            redirect('/install?step=2');
        }

        if ($step === 2) {
            if (!$this->validator->validate($data, [
                'association_name' => 'required|string|max:120',
                'primary' => 'required|color',
                'accent' => 'required|color',
                'locale' => 'required|in:it,de,en',
            ])) {
                $this->flash('errors', $this->validator->firstErrors());
                redirect('/install?step=2');
            }
            $_SESSION['install'] = array_merge($_SESSION['install'], [
                'association_name' => $data['association_name'],
                'association_email' => $data['association_email'] ?? '',
                'association_phone' => $data['association_phone'] ?? '',
                'association_address' => $data['association_address'] ?? '',
                'primary' => $data['primary'],
                'accent' => $data['accent'],
                'locale' => $data['locale'],
                'gdpr_enabled' => !empty($data['gdpr_enabled']),
                'step' => 3,
            ]);
            redirect('/install?step=3');
        }

        if ($step === 3) {
            if (!$this->validator->validate($data, [
                'admin_name' => 'required|string|max:120',
                'admin_email' => 'required|email',
                'admin_password' => 'required|string|min:8|confirmed',
            ])) {
                $this->flash('errors', $this->validator->firstErrors());
                redirect('/install?step=3');
            }
            $_SESSION['install'] = array_merge($_SESSION['install'], [
                'admin_name' => $data['admin_name'],
                'admin_email' => $data['admin_email'],
                'admin_password' => $data['admin_password'],
                'step' => 4,
            ]);
            redirect('/install?step=4');
        }

        if ($step === 4) {
            if (!$this->validator->validate($data, [
                'period_label' => 'required|string|max:120',
                'starts_on' => 'required|date',
                'ends_on' => 'required|date',
                'type_name_it' => 'required|string|max:120',
                'type_price' => 'required|numeric|min:0',
            ])) {
                $this->flash('errors', $this->validator->firstErrors());
                redirect('/install?step=4');
            }
            $_SESSION['install'] = array_merge($_SESSION['install'], [
                'period_label' => $data['period_label'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'type_name_it' => $data['type_name_it'],
                'type_name_de' => $data['type_name_de'] ?? $data['type_name_it'],
                'type_name_en' => $data['type_name_en'] ?? $data['type_name_it'],
                'type_price' => $data['type_price'],
                'step' => 5,
            ]);
            redirect('/install?step=5');
        }

        if ($step === 5) {
            $enabled = $data['fields'] ?? [];
            $required = $data['required'] ?? [];
            $_SESSION['install']['fields_enabled'] = $enabled;
            $_SESSION['install']['fields_required'] = $required;
            $_SESSION['install']['step'] = 6;
            redirect('/install?step=6');
        }

        if ($step === 6) {
            $this->finalize($request);
        }

        redirect('/install');
    }

    private function finalize(Request $request): void
    {
        $s = $_SESSION['install'] ?? [];
        if (empty($s['app_key']) || empty($s['admin_email'])) {
            redirect('/install?step=1');
        }

        // Reload env into a fresh Database connection
        $dbConfig = [
            'host' => $s['db_host'],
            'port' => $s['db_port'],
            'database' => $s['db_database'],
            'username' => $s['db_username'],
            'password' => $s['db_password'],
        ];
        $database = new Database($dbConfig);
        $migrator = new Migrator($database, base_path('database/migrations'));
        $encryptor = new Encryptor($s['app_key']);
        $settings = new SettingsService($database, $encryptor);
        $audit = new AuditService($database);
        $installer = new InstallerService($database, $migrator, $settings, $audit);

        try {
            foreach (['association_name', 'admin_name', 'admin_email', 'admin_password', 'period_label', 'starts_on', 'ends_on', 'type_name_it', 'type_price'] as $key) {
                if (!isset($s[$key]) || $s[$key] === '') {
                    throw new \RuntimeException("Missing install field: {$key}. Restart the wizard from step 1.");
                }
            }

            $installer->runMigrations();
            $installer->seedPermissions();
            if (!$database->fetch('SELECT id FROM users WHERE email = :e', ['e' => $s['admin_email']])) {
                $installer->createSuperAdmin(
                    $s['admin_name'],
                    $s['admin_email'],
                    $s['admin_password'],
                    (string) ($s['locale'] ?? 'it')
                );
            }
            $installer->saveAssociation([
                'name' => $s['association_name'],
                'email' => $s['association_email'] ?? '',
                'phone' => $s['association_phone'] ?? '',
                'address' => $s['association_address'] ?? '',
                'primary' => $s['primary'] ?? '#0D6E66',
                'accent' => $s['accent'] ?? '#B84A1B',
                'locale' => $s['locale'] ?? 'it',
                'gdpr_enabled' => !empty($s['gdpr_enabled']),
            ]);
            if (!(int) ($database->fetch('SELECT COUNT(*) c FROM membership_periods')['c'] ?? 0)) {
                $installer->createPeriod((string) $s['period_label'], (string) $s['starts_on'], (string) $s['ends_on']);
            }
            if (!(int) ($database->fetch('SELECT COUNT(*) c FROM member_types')['c'] ?? 0)) {
                $installer->createMemberType([
                    'it' => $s['type_name_it'],
                    'de' => $s['type_name_de'] ?? $s['type_name_it'],
                    'en' => $s['type_name_en'] ?? $s['type_name_it'],
                ], (float) $s['type_price']);
            }

            $fields = InstallerService::defaultFields();
            $enabled = $s['fields_enabled'] ?? array_column($fields, 'key');
            $required = $s['fields_required'] ?? ['first_name', 'last_name'];
            foreach ($fields as &$field) {
                $field['is_enabled'] = in_array($field['key'], $enabled, true);
                $field['is_required'] = in_array($field['key'], $required, true);
            }
            unset($field);
            $installer->seedFields($fields);
            $installer->finalize($request->ip());
        } catch (\Throwable $e) {
            $this->flash('errors', ['finalize' => $e->getMessage()]);
            redirect('/install?step=6');
        }

        unset($_SESSION['install'], $_SESSION['_old']);
        $this->flash('success', __('install.success'));
        redirect('/login');
    }
}
