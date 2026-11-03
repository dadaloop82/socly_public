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

/**
 * Infrastructure installer: database credentials, migrations and the
 * installed.lock only. Everything the association needs (name, seat, logo,
 * membership types/periods, profile fields, mail, admin account, …) is
 * collected by the guided setup at /setup, which the maintenance login opens
 * automatically right after this step.
 */
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
        $recovery = app()->isInstalled();

        // A one-click installer already created the database and wrote .env:
        // lock the installation and let the operator continue in /setup.
        if (!$recovery && $this->databaseConfigured()
            && $this->lockInstallation($request, $this->envConfig())) {
            redirect('/login');
        }

        $this->render('install/wizard', [
            'data' => $this->envConfig(),
            'recovery' => $recovery,
            'title' => $recovery ? __('install.recovery_title') : __('install.title'),
        ], 'layouts/install');
    }

    public function save(Request $request): void
    {
        $data = $request->all();
        $this->rememberOld($data);

        if (!$this->validator->validate($data, [
            'app_url' => 'required|string',
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
        ])) {
            $this->flash('errors', $this->validator->firstErrors());
            redirect('/install');
        }

        // An empty field keeps the password already stored in .env.
        $password = trim((string) ($data['db_password'] ?? ''));
        if ($password === '') {
            $password = (string) ($_ENV['DB_PASSWORD'] ?? '');
        }

        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $data['db_host'], $data['db_port']),
                (string) $data['db_username'],
                $password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', (string) $data['db_database'])
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (\Throwable $e) {
            $this->flash('errors', ['db' => __('install.db_error') . ': ' . $e->getMessage()]);
            redirect('/install');
        }

        $config = [
            'app_url' => rtrim((string) $data['app_url'], '/'),
            'app_key' => $this->usableAppKey(trim((string) ($_ENV['APP_KEY'] ?? ''))),
            'db_host' => (string) $data['db_host'],
            'db_port' => (string) $data['db_port'],
            'db_database' => (string) $data['db_database'],
            'db_username' => (string) $data['db_username'],
            'db_password' => $password,
        ];
        InstallerService::writeEnv($config);
        unset($_SESSION['install'], $_SESSION['_old']);

        // Recovery of an installed instance: only the credentials had to change.
        if (app()->isInstalled()) {
            $this->flash('success', __('install.recovery_success'));
            redirect('/login');
        }

        if ($this->lockInstallation($request, $config)) {
            redirect('/login');
        }
        redirect('/install');
    }

    /**
     * Migrations, permissions, default profile fields and installed.lock. No
     * association data is asked here: /setup collects it afterwards.
     *
     * @param array<string,string> $config
     */
    private function lockInstallation(Request $request, array $config): bool
    {
        try {
            $database = new Database([
                'host' => $config['db_host'],
                'port' => $config['db_port'],
                'database' => $config['db_database'],
                'username' => $config['db_username'],
                'password' => $config['db_password'],
            ]);
            $installer = new InstallerService(
                $database,
                new Migrator($database, base_path('database/migrations')),
                new SettingsService($database, new Encryptor($config['app_key'])),
                new AuditService($database)
            );
            $installer->runMigrations();
            $installer->seedPermissions();
            $installer->seedFields(InstallerService::defaultFields());
            $installer->finalize($request->ip());
        } catch (\Throwable $e) {
            $this->flash('errors', ['finalize' => $e->getMessage()]);
            return false;
        }

        $this->flash('success', __('install.success'));
        return true;
    }

    /** .env already carries a valid APP_KEY and a database that answers. */
    private function databaseConfigured(): bool
    {
        $config = $this->envConfig();
        if ($config['app_key'] === '' || $config['db_database'] === '' || $config['db_username'] === '') {
            return false;
        }
        try {
            new Encryptor($config['app_key']);
            return app()->get(Database::class)->isReachable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A corrupted/legacy APP_KEY would abort the first migration: replace it. */
    private function usableAppKey(string $candidate): string
    {
        if ($candidate !== '') {
            try {
                new Encryptor($candidate);
                return $candidate;
            } catch (\Throwable $e) {
                // Fall through and generate a valid key.
            }
        }

        return Encryptor::generateKey();
    }

    /** @return array<string,string> */
    private function envConfig(): array
    {
        return [
            'app_url' => (string) ($_ENV['APP_URL'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))),
            'db_host' => (string) ($_ENV['DB_HOST'] ?? '127.0.0.1'),
            'db_port' => (string) ($_ENV['DB_PORT'] ?? '3306'),
            'db_database' => (string) ($_ENV['DB_DATABASE'] ?? ''),
            'db_username' => (string) ($_ENV['DB_USERNAME'] ?? ''),
            'db_password' => (string) ($_ENV['DB_PASSWORD'] ?? ''),
            'app_key' => (string) ($_ENV['APP_KEY'] ?? ''),
        ];
    }
}
