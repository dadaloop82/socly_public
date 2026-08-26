<?php

declare(strict_types=1);

use Socly\Controllers\AuthController;
use Socly\Controllers\BrandingController;
use Socly\Controllers\DashboardController;
use Socly\Controllers\DeadlinesController;
use Socly\Controllers\DocumentsController;
use Socly\Controllers\InstallController;
use Socly\Controllers\MemberController;
use Socly\Controllers\OrgController;
use Socly\Controllers\ApiController;
use Socly\Controllers\PluginController;
use Socly\Controllers\SettingsController;
use Socly\Controllers\SetupController;
use Socly\Controllers\SessionController;
use Socly\Controllers\TreasuryController;
use Socly\Controllers\UpdateController;
use Socly\Controllers\UserController;
use Socly\Core\Http\Router;
use Socly\Controllers\I18nController;

/** @var Router $router */
$router = $router ?? null;

$router->get('/', function () {
    if (!app()->isInstalled()) {
        redirect('/install');
    }
    try {
        $setup = app(\Socly\Services\SetupService::class);
        if (!$setup->isComplete()) {
            // After abandoning setup, stay on the guest home instead of bouncing back into the wizard.
            if (!empty($_SESSION['setup_dismissed']) && $setup->allowsAnonymousSetup()) {
                redirect('/login');
            }
            redirect('/setup');
        }
    } catch (\Throwable) {
    }
    if (!auth_user()) {
        redirect('/login');
    }
    redirect('/dashboard');
}, null, ['mw.locale', 'mw.install']);

$router->get('/install', [InstallController::class, 'index'], null, ['mw.locale', 'mw.install']);
$router->post('/install', [InstallController::class, 'save'], null, ['mw.locale', 'mw.install', 'mw.csrf']);

$router->get('/login', [AuthController::class, 'showLogin'], null, ['mw.locale', 'mw.install', 'mw.guest']);
$router->post('/login', [AuthController::class, 'login'], null, ['mw.locale', 'mw.install', 'mw.guest', 'mw.csrf']);
$privateRoutes = code_path('bootstrap/routes_private.php');
if (is_file($privateRoutes)) {
    require $privateRoutes;
}
$router->post('/logout', [AuthController::class, 'logout'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/session/ping', [SessionController::class, 'ping'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/password/forgot', [AuthController::class, 'showForgot'], null, ['mw.locale', 'mw.install', 'mw.guest']);
$router->post('/password/forgot', [AuthController::class, 'sendForgot'], null, ['mw.locale', 'mw.install', 'mw.guest', 'mw.csrf']);
$router->get('/password/reset', [AuthController::class, 'showReset'], null, ['mw.locale', 'mw.install', 'mw.guest']);
$router->post('/password/reset', [AuthController::class, 'reset'], null, ['mw.locale', 'mw.install', 'mw.guest', 'mw.csrf']);

$router->get('/setup', [SetupController::class, 'index'], null, ['mw.locale', 'mw.install', 'mw.setup_open']);
$router->post('/setup/greet', [SetupController::class, 'greet'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/thanks', [SetupController::class, 'thanks'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/discard', [SetupController::class, 'discard'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/exit', [SetupController::class, 'exitSetup'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/scrape', [SetupController::class, 'scrape'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/runts-lookup', [SetupController::class, 'lookupRunts'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->get('/setup/runts-legal-prefill-status', [SetupController::class, 'runtsLegalPrefillStatus'], null, ['mw.locale', 'mw.install', 'mw.setup_open']);
$router->get('/setup/runts-document/{id}', [SetupController::class, 'viewRuntsDocument'], null, ['mw.locale', 'mw.install', 'mw.setup_open']);
$router->post('/setup/mail/discover', [SetupController::class, 'discoverMail'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/mail/test', [SetupController::class, 'testMail'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/fields/autosave', [SetupController::class, 'autosaveFields'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup/logo', [SetupController::class, 'uploadLogo'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);
$router->post('/setup', [SetupController::class, 'save'], null, ['mw.locale', 'mw.install', 'mw.setup_open', 'mw.csrf']);

$router->post('/updates/install', [UpdateController::class, 'install'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/api/updates/check', [UpdateController::class, 'check'], null, ['mw.locale', 'mw.install']);

$router->get('/dashboard', [DashboardController::class, 'index'], 'dashboard.view', ['mw.locale', 'mw.install', 'mw.auth']);

$router->get('/api/geo/cities', [ApiController::class, 'cities'], null, ['mw.locale', 'mw.install', 'mw.setup_or_auth']);
$router->get('/api/geo/addresses', [ApiController::class, 'addresses'], null, ['mw.locale', 'mw.install', 'mw.setup_or_auth']);
$router->post('/api/fiscal-code', [ApiController::class, 'fiscalCode'], null, ['mw.locale', 'mw.install', 'mw.setup_or_auth', 'mw.csrf']);
$router->post('/api/translate', [ApiController::class, 'translate'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/i18n/messages', [I18nController::class, 'messages'], null, ['mw.locale', 'mw.install']);
$router->get('/branding/logo', [BrandingController::class, 'logo'], null, ['mw.locale', 'mw.install']);

$router->get('/members', [MemberController::class, 'index'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/export', [MemberController::class, 'export'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/export/registry', [MemberController::class, 'exportRegistry'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/create', [MemberController::class, 'create'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/members/bulk', [MemberController::class, 'bulk'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/members', [MemberController::class, 'store'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/members/enrollment/otp', [MemberController::class, 'sendEnrollmentOtp'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/members/{id}', [MemberController::class, 'show'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/{id}/photo', [MemberController::class, 'photo'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/{id}/enrollment', [MemberController::class, 'enrollmentArtifact'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/{id}/enrollment-form', [MemberController::class, 'enrollmentForm'], 'members.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/members/{id}/edit', [MemberController::class, 'edit'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/members/{id}', [MemberController::class, 'update'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/members/{id}/delete', [MemberController::class, 'destroy'], 'members.delete', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/members/{id}/payments', [MemberController::class, 'storePayment'], 'payments.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/members/{id}/remind-payment', [MemberController::class, 'remindPayment'], 'members.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);

$router->get('/treasury', [TreasuryController::class, 'index'], 'treasury.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/treasury', [TreasuryController::class, 'store'], 'treasury.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/treasury/{id}/attachment', [TreasuryController::class, 'attachment'], 'treasury.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/treasury/{id}/edit', [TreasuryController::class, 'edit'], 'treasury.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/treasury/{id}', [TreasuryController::class, 'update'], 'treasury.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);

$router->get('/deadlines', [DeadlinesController::class, 'index'], 'deadlines.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/deadlines', [DeadlinesController::class, 'store'], 'deadlines.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/deadlines/{id}/edit', [DeadlinesController::class, 'edit'], 'deadlines.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/deadlines/{id}', [DeadlinesController::class, 'update'], 'deadlines.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/deadlines/{id}/done', [DeadlinesController::class, 'done'], 'deadlines.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);

$router->get('/documents', [DocumentsController::class, 'index'], 'documents.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/documents', [DocumentsController::class, 'store'], 'documents.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/documents/upload', [DocumentsController::class, 'upload'], 'documents.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/documents/{id}', [DocumentsController::class, 'show'], 'documents.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/documents/{id}/edit', [DocumentsController::class, 'edit'], 'documents.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/documents/{id}', [DocumentsController::class, 'update'], 'documents.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/documents/{id}/file', [DocumentsController::class, 'download'], 'documents.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/documents/{id}/download', [DocumentsController::class, 'forceDownload'], 'documents.view', ['mw.locale', 'mw.install', 'mw.auth']);

$router->get('/org', [OrgController::class, 'index'], 'org.view', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/org/organs', [OrgController::class, 'storeOrgan'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/org/organs/{key}/delete', [OrgController::class, 'destroyOrgan'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/org/people/create', [OrgController::class, 'create'], null, ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/org/people', [OrgController::class, 'store'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/org/people/{id}/edit', [OrgController::class, 'edit'], null, ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/api/org/members/{id}/profile', [OrgController::class, 'memberProfile'], null, ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/org/people/{id}', [OrgController::class, 'update'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/org/people/{id}/delete', [OrgController::class, 'destroy'], null, ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);

$router->get('/settings', [SettingsController::class, 'index'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/settings/general', [SettingsController::class, 'saveGeneral'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/people', [SettingsController::class, 'savePeople'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/officers', [SettingsController::class, 'savePeople'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/legal', [SettingsController::class, 'saveLegal'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/types', [SettingsController::class, 'saveType'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/periods', [SettingsController::class, 'savePeriod'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/fields', [SettingsController::class, 'saveFields'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/fields/autosave', [SettingsController::class, 'autosaveFields'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/enrollment', [SettingsController::class, 'saveEnrollment'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/platform', [SettingsController::class, 'savePlatform'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/mail', [SettingsController::class, 'saveMail'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/email-templates', [SettingsController::class, 'saveEmailTemplate'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/workflow', [SettingsController::class, 'saveWorkflow'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/components', [SettingsController::class, 'saveComponents'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/settings/reset-user-data', [SettingsController::class, 'resetUserData'], 'settings.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);

$router->get('/users', [UserController::class, 'index'], 'users.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->get('/users/create', [UserController::class, 'create'], 'users.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/users', [UserController::class, 'store'], 'users.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->get('/users/{id}/edit', [UserController::class, 'edit'], 'users.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/users/{id}', [UserController::class, 'update'], 'users.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/users/{id}/delete', [UserController::class, 'destroy'], 'users.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);

$router->get('/plugins', [PluginController::class, 'index'], 'plugins.manage', ['mw.locale', 'mw.install', 'mw.auth']);
$router->post('/plugins/{id}/enable', [PluginController::class, 'enable'], 'plugins.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/plugins/{id}/disable', [PluginController::class, 'disable'], 'plugins.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
$router->post('/plugins/{id}/settings', [PluginController::class, 'saveSettings'], 'plugins.manage', ['mw.locale', 'mw.install', 'mw.auth', 'mw.csrf']);
