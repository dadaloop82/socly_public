<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Socly\Controllers\AuthController;
use Socly\Controllers\BrandingController;
use Socly\Controllers\DashboardController;
use Socly\Controllers\DeadlinesController;
use Socly\Controllers\DocumentsController;
use Socly\Controllers\InstallController;
use Socly\Controllers\MemberController;
use Socly\Controllers\OrgController;
use Socly\Controllers\ApiController;
use Socly\Controllers\I18nController;
use Socly\Controllers\PluginController;
use Socly\Controllers\SettingsController;
use Socly\Controllers\SetupController;
use Socly\Controllers\TreasuryController;
use Socly\Controllers\UpdateController;
use Socly\Controllers\UserController;
use Socly\Core\App;
use Socly\Core\Csrf;
use Socly\Core\Database;
use Socly\Core\Encryptor;
use Socly\Core\Http\Request;
use Socly\Core\Http\Router;
use Socly\Core\Migrator;
use Socly\Core\Plugin\HookBus;
use Socly\Core\Plugin\PluginManager;
use Socly\Core\Translator;
use Socly\Core\Validator;
use Socly\Core\View;
use Socly\Middleware\AuthMiddleware;
use Socly\Middleware\CsrfMiddleware;
use Socly\Middleware\GuestMiddleware;
use Socly\Middleware\InstallGate;
use Socly\Middleware\LocaleMiddleware;
use Socly\Middleware\SetupBootstrapMiddleware;
use Socly\Middleware\SetupGate;
use Socly\Middleware\SetupOrAuthMiddleware;
use Socly\Middleware\SessionIdleMiddleware;
use Socly\Core\Logger;
use Socly\Middleware\InstanceExpiredMiddleware;
use Socly\Middleware\SecurityHeadersMiddleware;
use Socly\Controllers\SessionController;
use Socly\Services\AssociationPeopleService;
use Socly\Services\AssociationWebsiteScrapeService;
use Socly\Services\AuditService;
use Socly\Services\AuthService;
use Socly\Services\BrandingService;
use Socly\Services\ComponentService;
use Socly\Services\CurrencyService;
use Socly\Services\DeadlineService;
use Socly\Services\DocumentService;
use Socly\Services\EmailTemplateService;
use Socly\Services\MemberRegistryService;
use Socly\Services\EnrollmentFormService;
use Socly\Services\EnrollmentService;
use Socly\Services\GeoService;
use Socly\Services\InstallerService;
use Socly\Services\MailService;
use Socly\Services\MemberService;
use Socly\Services\SmtpDiscoveryService;
use Socly\Services\PaymentService;
use Socly\Services\PlatformService;
use Socly\Services\PluginAdminService;
use Socly\Services\RateLimiter;
use Socly\Services\RuntsLookupService;
use Socly\Services\SettingsService;
use Socly\Services\SetupService;
use Socly\Services\TreasuryService;
use Socly\Services\UpdateService;
use Socly\Services\UserService;
use Socly\Services\WorkflowService;
use Socly\Support\EnvWriter;

$codePath = defined('SOCLY_CODE_PATH') ? (string) SOCLY_CODE_PATH : dirname(__DIR__);
$basePath = defined('SOCLY_INSTANCE_PATH') ? (string) SOCLY_INSTANCE_PATH : $codePath;
if (!defined('SOCLY_CODE_PATH')) {
    define('SOCLY_CODE_PATH', $codePath);
}
if (!defined('SOCLY_INSTANCE_PATH')) {
    define('SOCLY_INSTANCE_PATH', $basePath);
}

if (is_file($basePath . '/.env')) {
    Dotenv::createImmutable($basePath)->safeLoad();
}
EnvWriter::loadUserEnv($basePath);

$app = App::getInstance();
$app->setConfig([
    'app' => [
        'name' => $_ENV['APP_NAME'] ?? 'SOCLY',
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'url' => $_ENV['APP_URL'] ?? '',
        'key' => $_ENV['APP_KEY'] ?? '',
        'locale' => $_ENV['APP_LOCALE'] ?? 'it',
        'version' => is_file($codePath . '/VERSION')
            ? (function_exists('sanitize_app_version')
                ? sanitize_app_version((string) file_get_contents($codePath . '/VERSION'))
                : trim((string) file_get_contents($codePath . '/VERSION')))
            : '0.0.0',
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'database' => $_ENV['DB_DATABASE'] ?? 'socly',
        'username' => $_ENV['DB_USERNAME'] ?? 'socly',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
    'update' => [
        'notify' => filter_var($_ENV['UPDATE_NOTIFY'] ?? 'true', FILTER_VALIDATE_BOOL),
        'manifest_url' => $_ENV['UPDATE_MANIFEST_URL'] ?? 'https://raw.githubusercontent.com/dadaloop82/socly_public/main/latest.json',
        'repo' => $_ENV['UPDATE_REPO'] ?? 'git@github.com-socly:dadaloop82/socly.git',
        'channel' => $_ENV['UPDATE_CHANNEL'] ?? 'main',
        'enabled' => filter_var($_ENV['UPDATE_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL),
    ],
]);

$sessionPath = $basePath . '/storage/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (str_starts_with((string) ($_ENV['APP_URL'] ?? ''), 'https://'));
$isIsolatedInstance = $basePath !== $codePath;
$sessionCookiePath = '/';
$sessionCookieName = 'socly_session';
if ($isIsolatedInstance) {
    $instanceCode = basename($basePath);
    $sessionCookieName = 'socly_inst_' . preg_replace('/[^a-zA-Z0-9]/', '', $instanceCode);
    $appUrlPath = parse_url((string) ($_ENV['APP_URL'] ?? ''), PHP_URL_PATH);
    if (is_string($appUrlPath) && $appUrlPath !== '' && $appUrlPath !== '/') {
        $sessionCookiePath = rtrim($appUrlPath, '/') . '/';
    } else {
        $sessionCookiePath = '/';
    }
}
session_save_path($sessionPath);
session_name($sessionCookieName);
session_set_cookie_params([
    'lifetime' => ((int) ($_ENV['SESSION_LIFETIME'] ?? 120)) * 60,
    'path' => $sessionCookiePath,
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$app->bind(Request::class, fn () => Request::capture());
$app->bind('request', fn (App $a) => $a->get(Request::class));
$app->bind(Router::class, fn (App $a) => new Router($a));
$app->bind('router', fn (App $a) => $a->get(Router::class));
$app->bind(Csrf::class, fn () => new Csrf());
$app->bind('csrf', fn (App $a) => $a->get(Csrf::class));
$app->bind(Translator::class, fn () => new Translator($codePath . '/lang'));
$app->bind('translator', fn (App $a) => $a->get(Translator::class));
$app->bind(View::class, fn () => new View($codePath . '/resources/views'));
$app->bind(Validator::class, fn () => new Validator());
$app->bind(HookBus::class, fn () => new HookBus());
$app->bind(Logger::class, fn () => new Logger($basePath . '/storage/logs', 7));
$app->bind('logger', fn (App $a) => $a->get(Logger::class));
$app->bind(RateLimiter::class, fn () => new RateLimiter($basePath . '/storage/cache/rate_limits'));

$app->bind(Database::class, fn (App $a) => new Database($a->config('db')));
$app->bind(Encryptor::class, function (App $a) {
    $key = (string) $a->config('app.key');
    if ($key === '' || strlen($key) < 10) {
        return new Encryptor(Encryptor::generateKey());
    }
    try {
        return new Encryptor($key);
    } catch (\Throwable) {
        return new Encryptor(Encryptor::generateKey());
    }
});
$app->bind(Migrator::class, fn (App $a) => new Migrator($a->get(Database::class), $codePath . '/database/migrations'));
$app->bind(SettingsService::class, fn (App $a) => new SettingsService($a->get(Database::class), $a->get(Encryptor::class)));
$app->bind(AuditService::class, fn (App $a) => new AuditService($a->get(Database::class)));
$app->bind(AuthService::class, fn (App $a) => new AuthService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(RateLimiter::class)
));
$app->bind(InstallerService::class, fn (App $a) => new InstallerService(
    $a->get(Database::class),
    $a->get(Migrator::class),
    $a->get(SettingsService::class),
    $a->get(AuditService::class)
));
$app->bind(PluginManager::class, fn (App $a) => new PluginManager($a, $codePath . '/plugins', $a->get(HookBus::class)));
$app->bind('plugins', fn (App $a) => $a->get(PluginManager::class));
$app->bind(MemberService::class, fn (App $a) => new MemberService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(Validator::class),
    $a->get(PluginManager::class),
    $a->get(TreasuryService::class)
));
$app->bind(PaymentService::class, fn (App $a) => new PaymentService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(Validator::class),
    $a->get(PluginManager::class),
    $a->get(TreasuryService::class)
));
$app->bind(UserService::class, fn (App $a) => new UserService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(Validator::class),
    $a->get(AuthService::class)
));
$app->bind(PluginAdminService::class, fn (App $a) => new PluginAdminService(
    $a->get(Database::class),
    $a->get(PluginManager::class),
    $a->get(Migrator::class),
    $a->get(AuditService::class),
    $a->get(SettingsService::class)
));

$app->bind(InstallGate::class, fn (App $a) => new InstallGate($a));
$app->bind(SessionIdleMiddleware::class, fn (App $a) => new SessionIdleMiddleware($a->get(AuthService::class)));
$app->bind(AuthMiddleware::class, fn (App $a) => new AuthMiddleware(
    $a->get(AuthService::class),
    $a->get(SessionIdleMiddleware::class)
));
$app->bind(GuestMiddleware::class, fn (App $a) => new GuestMiddleware($a->get(AuthService::class)));
$app->bind(CsrfMiddleware::class, fn (App $a) => new CsrfMiddleware(
    $a->get(Csrf::class),
    $a->get(SetupService::class)
));
$app->bind(LocaleMiddleware::class, fn (App $a) => new LocaleMiddleware(
    $a->get(Translator::class),
    $a,
    $a->get(SetupService::class)
));
$app->bind(SecurityHeadersMiddleware::class, fn () => new SecurityHeadersMiddleware());
$app->bind(InstanceExpiredMiddleware::class, fn (App $a) => new InstanceExpiredMiddleware($a->get(SettingsService::class)));

$app->bind('mw.install', fn (App $a) => $a->get(InstallGate::class));
$app->bind('mw.auth', fn (App $a) => $a->get(AuthMiddleware::class));
$app->bind('mw.guest', fn (App $a) => $a->get(GuestMiddleware::class));
$app->bind('mw.csrf', fn (App $a) => $a->get(CsrfMiddleware::class));
$app->bind('mw.locale', fn (App $a) => $a->get(LocaleMiddleware::class));
$app->bind('mw.security', fn (App $a) => $a->get(SecurityHeadersMiddleware::class));
$app->bind('mw.idle', fn (App $a) => $a->get(SessionIdleMiddleware::class));
$app->bind('mw.instance_expired', fn (App $a) => $a->get(InstanceExpiredMiddleware::class));
$app->bind(SessionController::class, fn (App $a) => new SessionController($a->get(View::class), $a->get(PlatformService::class)));
$app->bind(SetupGate::class, fn (App $a) => new SetupGate($a->get(SetupService::class)));
$app->bind('mw.setup', fn (App $a) => $a->get(SetupGate::class));
$app->bind(SetupBootstrapMiddleware::class, fn (App $a) => new SetupBootstrapMiddleware($a->get(SetupService::class)));
$app->bind('mw.setup_open', fn (App $a) => $a->get(SetupBootstrapMiddleware::class));
$app->bind(SetupOrAuthMiddleware::class, fn (App $a) => new SetupOrAuthMiddleware(
    $a->get(SetupService::class),
    $a->get(AuthMiddleware::class)
));
$app->bind('mw.setup_or_auth', fn (App $a) => $a->get(SetupOrAuthMiddleware::class));

$app->bind(AssociationPeopleService::class, fn (App $a) => new AssociationPeopleService($a->get(Database::class)));
$app->bind(AssociationWebsiteScrapeService::class, fn () => new AssociationWebsiteScrapeService());
$app->bind(RuntsLookupService::class, fn () => new RuntsLookupService());
$app->bind(MailService::class, fn (App $a) => new MailService($a->get(SettingsService::class)));
$app->bind(EmailTemplateService::class, fn (App $a) => new EmailTemplateService(
    $a->get(Database::class),
    $a->get(Validator::class),
    $a->get(SettingsService::class)
));
$app->bind(WorkflowService::class, fn (App $a) => new WorkflowService(
    $a->get(Database::class),
    $a->get(Validator::class),
    $a->get(EmailTemplateService::class),
    $a->get(MailService::class),
    $a->get(AuditService::class)
));
$app->bind(PlatformService::class, fn (App $a) => new PlatformService(
    $a->get(SettingsService::class),
    $a->get(BrandingService::class),
    $a->get(ComponentService::class),
    $a->get(MemberService::class),
    $a->get(Database::class)
));
$app->bind(SmtpDiscoveryService::class, fn (App $a) => new SmtpDiscoveryService($a->get(MailService::class)));
$app->bind(SetupService::class, fn (App $a) => new SetupService(
    $a->get(SettingsService::class),
    $a->get(AssociationPeopleService::class),
    $a->get(BrandingService::class),
    $a->get(UserService::class),
    $a->get(MemberService::class),
    $a->get(Database::class),
    $a->get(MailService::class),
    $a->get(ComponentService::class)
));
$app->bind(ComponentService::class, fn (App $a) => new ComponentService($a->get(SettingsService::class)));
$app->bind(CurrencyService::class, fn (App $a) => new CurrencyService($a->get(SettingsService::class)));
$app->bind(TreasuryService::class, fn (App $a) => new TreasuryService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(Validator::class),
    $a->get(ComponentService::class),
    $a->get(DocumentService::class)
));
$app->bind(DeadlineService::class, fn (App $a) => new DeadlineService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(Validator::class),
    $a->get(ComponentService::class)
));
$app->bind(DocumentService::class, fn (App $a) => new DocumentService(
    $a->get(Database::class),
    $a->get(AuditService::class),
    $a->get(Validator::class),
    $a->get(ComponentService::class)
));
$app->bind(UpdateService::class, fn (App $a) => new UpdateService($a->get(Migrator::class), $a->get(AuditService::class)));

$app->bind(BrandingService::class, fn (App $a) => new BrandingService($a->get(SettingsService::class)));
$app->bind(BrandingController::class, fn (App $a) => new BrandingController($a->get(View::class), $a->get(BrandingService::class)));
$app->bind(GeoService::class, fn () => new GeoService());
$app->bind(ApiController::class, fn (App $a) => new ApiController(
    $a->get(View::class),
    $a->get(GeoService::class),
    $a->get(SetupService::class)
));
$app->bind(I18nController::class, fn (App $a) => new I18nController($a->get(View::class)));
$app->bind(InstallController::class, fn (App $a) => new InstallController($a->get(View::class), $a->get(Validator::class)));
$app->bind(AuthController::class, fn (App $a) => new AuthController(
    $a->get(View::class),
    $a->get(AuthService::class),
    $a->get(Validator::class),
    $a->get(SetupService::class)
));
$privateBindings = $codePath . '/bootstrap/bindings_private.php';
if (!is_file($privateBindings) && defined('SOCLY_PRIVATE_PATH')) {
    $privateBindings = rtrim((string) SOCLY_PRIVATE_PATH, '/') . '/bootstrap/bindings_private.php';
}
if (is_file($privateBindings)) {
    require $privateBindings;
}
$app->bind(DashboardController::class, fn (App $a) => new DashboardController(
    $a->get(View::class),
    $a->get(MemberService::class),
    $a->get(ComponentService::class),
    $a->get(TreasuryService::class),
    $a->get(DeadlineService::class),
    $a->get(DocumentService::class),
    $a->get(AssociationPeopleService::class),
    $a->get(CurrencyService::class)
));
$app->bind(MemberRegistryService::class, fn (App $a) => new MemberRegistryService(
    $a->get(SettingsService::class),
    $a->get(MemberService::class)
));
$app->bind(EnrollmentFormService::class, fn (App $a) => new EnrollmentFormService(
    $a->get(SettingsService::class),
    $a->get(MemberService::class)
));
$app->bind(EnrollmentService::class, fn (App $a) => new EnrollmentService(
    $a->get(Database::class),
    $a->get(SettingsService::class),
    $a->get(AuditService::class),
    $a->get(WorkflowService::class)
));
$app->bind(MemberController::class, fn (App $a) => new MemberController(
    $a->get(View::class),
    $a->get(MemberService::class),
    $a->get(PaymentService::class),
    $a->get(SettingsService::class),
    $a->get(EnrollmentService::class),
    $a->get(ComponentService::class),
    $a->get(EnrollmentFormService::class),
    $a->get(MemberRegistryService::class)
));
$app->bind(SettingsController::class, fn (App $a) => new SettingsController(
    $a->get(View::class),
    $a->get(SettingsService::class),
    $a->get(MemberService::class),
    $a->get(Database::class),
    $a->get(Validator::class),
    $a->get(AuditService::class),
    $a->get(PluginManager::class),
    $a->get(AssociationPeopleService::class),
    $a->get(BrandingService::class),
    $a->get(MailService::class),
    $a->get(SetupService::class),
    $a->get(ComponentService::class),
    $a->get(DocumentService::class),
    $a->get(DeadlineService::class),
    $a->get(PlatformService::class),
    $a->get(UserService::class),
    $a->get(EmailTemplateService::class),
    $a->get(WorkflowService::class)
));
$app->bind(TreasuryController::class, fn (App $a) => new TreasuryController(
    $a->get(View::class),
    $a->get(TreasuryService::class),
    $a->get(MemberService::class),
    $a->get(ComponentService::class),
    $a->get(CurrencyService::class)
));
$app->bind(DeadlinesController::class, fn (App $a) => new DeadlinesController(
    $a->get(View::class),
    $a->get(DeadlineService::class),
    $a->get(MemberService::class)
));
$app->bind(DocumentsController::class, fn (App $a) => new DocumentsController(
    $a->get(View::class),
    $a->get(DocumentService::class)
));
$app->bind(OrgController::class, fn (App $a) => new OrgController(
    $a->get(View::class),
    $a->get(AssociationPeopleService::class),
    $a->get(DeadlineService::class)
));
$app->bind(SetupController::class, fn (App $a) => new SetupController(
    $a->get(View::class),
    $a->get(SetupService::class),
    $a->get(AuthService::class),
    $a->get(AssociationWebsiteScrapeService::class),
    $a->get(MailService::class),
    $a->get(RuntsLookupService::class)
));
$app->bind(UpdateController::class, fn (App $a) => new UpdateController(
    $a->get(View::class),
    $a->get(UpdateService::class),
    $a->get(SetupService::class)
));
$app->bind(UserController::class, fn (App $a) => new UserController(
    $a->get(View::class),
    $a->get(UserService::class),
    $a->get(MailService::class),
    $a->get(WorkflowService::class),
    $a->get(SettingsService::class)
));
$app->bind(PluginController::class, fn (App $a) => new PluginController(
    $a->get(View::class),
    $a->get(PluginAdminService::class)
));

return $app;
