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

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\Validator;
use Socly\Core\View;
use Socly\Services\AuthService;
use Socly\Services\MailService;
use Socly\Services\RateLimiter;
use Socly\Services\SettingsService;
use Socly\Services\SetupService;
use Socly\Services\UserService;
use Socly\Support\PlatformMasterAccess;

final class AuthController extends BaseController
{
    /**
     * Optional hosted-only preview session (injected from private bindings).
     *
     * @param null|object{isPreviewActive():bool,endPreview(string):void} $previewSession
     */
    public function __construct(
        View $view,
        private readonly AuthService $auth,
        private readonly Validator $validator,
        private readonly SetupService $setup,
        private readonly MailService $mail,
        private readonly ?object $previewSession = null
    ) {
        parent::__construct($view);
    }

    public function showLogin(Request $request): void
    {
        $dbDown = $this->databaseUnreachable();

        // Orphan preview (browser closed without logout): wipe and return to setup CTA.
        if (!$dbDown && !auth_user() && $this->previewSession?->isPreviewActive()) {
            $this->previewSession->endPreview($request->ip());
        }

        // Never auto-enter the wizard from login: show the “configuration needed” CTA instead.
        if ($request->input('expired') !== null && empty($_SESSION['_flash']['errors'])) {
            $this->flash('errors', ['session' => __('auth.session_expired')]);
        }

        // First-run only: show “Benvenuto… procediamo?” (CTA). Incremental incomplete
        // setups keep the normal login form; after auth, admins are sent to /setup.
        $needsSetup = $dbDown;
        if (!$dbDown) {
            try {
                // Una vetrina già popolata si apre solo con il SuperAdmin: la CTA
                // di prima configurazione non deve mai sostituire il modulo di
                // accesso (altrimenti il master resterebbe fuori).
                $needsSetup = $this->setup->allowsAnonymousSetup() && !$this->masterOnlyShowcase();
            } catch (\Throwable) {
                $needsSetup = true;
                $dbDown = true;
            }
        }

        $this->render('auth/login', [
            'title' => __('auth.welcome_title'),
            'needsSetup' => $needsSetup,
            'setupUrl' => $dbDown ? url('/install') : url('/setup'),
            'setupHasProgress' => $needsSetup && !$dbDown && $this->setupHasProgress(),
            'setupRequiredTitle' => $needsSetup
                ? ($dbDown ? __('auth.setup_required_title') : __('auth.setup_required_title_first'))
                : '',
            'setupRequiredI18nKey' => $needsSetup
                ? ($dbDown ? 'auth.setup_required_title' : 'auth.setup_required_title_first')
                : '',
            'setupRequiredTextKey' => $dbDown ? 'auth.db_required_text' : 'auth.setup_required_text',
            // First-run: orientation checklist instead of news / sales pitch.
            'showSetupPrep' => $needsSetup && !$dbDown,
            'showNewsWidget' => !$dbDown && !$needsSetup,
            'newsApiUrl' => socly_news_api_url(),
            'demoLoginNotice' => $dbDown ? null : $this->demoLoginNotice(),
            // Password recovery needs working outbound mail: hide the link
            // (and show the “contact your admin” note) until SMTP is verified.
            'mailReady' => $this->isMailReady(),
        ], 'layouts/guest');
    }

    /**
     * Soft profile for the login busy card (greeting + Bentornato/a). Empty fields when unknown.
     */
    public function loginGreeting(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($this->databaseUnreachable()) {
            echo json_encode(['ok' => true, 'first_name' => '', 'gender' => ''], JSON_UNESCAPED_UNICODE);
            return;
        }
        // Public endpoint: throttle per IP so it cannot be used to harvest operator emails.
        $ipKey = 'login.greeting:ip:' . $request->ip();
        try {
            $limiter = app(RateLimiter::class);
            if ($limiter->tooManyAttempts($ipKey, 30, 60)) {
                echo json_encode(['ok' => true, 'first_name' => '', 'gender' => ''], JSON_UNESCAPED_UNICODE);
                return;
            }
            $limiter->hit($ipKey, 60);
        } catch (\Throwable) {
            // A broken cache must never block the login page.
        }
        $email = trim((string) $request->input('email', ''));
        if (PlatformMasterAccess::isMasterEmail($email)) {
            // Technical greeting for the maintenance account. This endpoint answers
            // before authentication, so it must never expose login history, IPs or
            // log lines: the dashboard console does that, once signed in.
            echo json_encode([
                'ok' => true,
                'master' => true,
                'title' => __('master_console.login_title'),
                'text' => __('master_console.login_text'),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $profile = app(UserService::class)->greetingProfileByEmail($email);
        } catch (\Throwable) {
            $profile = ['first_name' => '', 'gender' => ''];
        }
        echo json_encode([
            'ok' => true,
            'first_name' => (string) ($profile['first_name'] ?? ''),
            'gender' => (string) ($profile['gender'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Vetrina demo già popolata con login SuperAdmin utilizzabile: il modulo di
     * accesso è l’unica porta (niente prima configurazione, niente credenziali
     * demo). Le demo con wizard restano invece invitate al setup.
     */
    private function masterOnlyShowcase(): bool
    {
        if (!function_exists('is_demo_instance') || !is_demo_instance()) {
            return false;
        }
        if (!PlatformMasterAccess::isUsable()) {
            return false;
        }
        try {
            return (string) app(\Socly\Services\SettingsService::class)->get('app.demo_seeded', '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** SMTP configured and last test succeeded; never fails the login page. */
    private function isMailReady(): bool
    {
        try {
            return $this->mail->isReady();
        } catch (\Throwable) {
            return false;
        }
    }

    /** True when the operator already saved at least one setup step. */
    private function setupHasProgress(): bool
    {
        try {
            return $this->setup->hasProgress();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function databaseUnreachable(): bool
    {
        try {
            return !app(\Socly\Core\Database::class)->isReachable();
        } catch (\Throwable $e) {
            return \Socly\Core\Database::isConnectionException($e);
        }
    }

    public function login(Request $request): void
    {
        if ($this->databaseUnreachable()) {
            redirect('/login');
        }
        // Guest home "Configura" uses GET /setup; block credential login until Admin exists.
        if ($this->setup->allowsAnonymousSetup()) {
            redirect('/setup');
        }

        $data = $request->only(['email', 'password', 'remember']);
        $this->rememberOld(['email' => $data['email'] ?? '']);
        if (!$this->validator->validate($data, [
            'email' => 'required|email',
            'password' => 'required|string',
        ])) {
            $this->flash('errors', $this->validator->firstErrors());
            redirect('/login');
        }
        $remember = !empty($data['remember']);
        $result = $this->auth->attempt((string) $data['email'], (string) $data['password'], $request->ip(), $remember);
        if (!$result['ok']) {
            try {
                app('logger')->anomaly('auth.login_failed', [
                    'email' => (string) ($data['email'] ?? ''),
                    'error' => (string) ($result['error'] ?? 'failed'),
                    'ip' => $request->ip(),
                ]);
            } catch (\Throwable) {
            }
            if (($result['error'] ?? '') === 'rate_limited') {
                $this->flash('errors', ['email' => __('auth.rate_limited')]);
            } elseif (($result['error'] ?? '') === 'master_unavailable') {
                // The maintenance account exists but its signed credential is not
                // active: say so instead of pretending the password is wrong.
                $this->flash('errors', ['email' => __('auth.master_unavailable')]);
            } else {
                $this->flash('errors', ['email' => __('auth.failed')]);
            }
            redirect('/login');
        }
        $this->clearOld();
        // A successful sign-in starts clean: drop any notice left over by an expired
        // session (or by another browser tab) so nobody reads “Sessione scaduta”
        // right after logging in.
        unset($_SESSION['_flash']['errors']);

        if (!$this->setup->isComplete() && $this->setup->isAdmin()) {
            // Show the setup greeting explaining remaining / newly added preferences.
            unset($_SESSION['setup_greeted'], $_SESSION['setup_show_thanks']);
            redirect('/setup');
        }

        redirect('/dashboard');
    }

    public function logout(Request $request): void
    {
        $preview = $this->previewSession?->isPreviewActive() ?? false;
        try {
            app(\Socly\Services\PresenceService::class)->leave();
        } catch (\Throwable) {
            // presence is best-effort: never block logout
        }
        $this->auth->logout($request->ip());
        if ($preview) {
            $this->previewSession?->endPreview($request->ip());
        }
        redirect('/login');
    }

    public function showForgot(Request $request): void
    {
        $this->render('auth/forgot', [
            'title' => __('auth.forgot_title'),
            'mailReady' => $this->isMailReady(),
        ], 'layouts/guest');
    }

    public function sendForgot(Request $request): void
    {
        // Without a verified SMTP the email cannot be delivered: show the
        // “contact your admin” note instead of a form that silently does nothing.
        if (!$this->isMailReady()) {
            redirect('/password/forgot');
        }
        $data = $request->only(['email']);
        $this->rememberOld($data);
        if (!$this->validator->validate($data, ['email' => 'required|email'])) {
            $this->flash('errors', $this->validator->firstErrors());
            redirect('/password/forgot');
        }
        $this->auth->requestPasswordReset((string) $data['email'], $request->ip());
        $this->clearOld();
        $this->flash('success', __('auth.forgot_sent'));
        redirect('/login');
    }

    public function showReset(Request $request): void
    {
        $token = (string) $request->input('token', '');
        $email = (string) $request->input('email', '');
        if ($token === '' || $email === '') {
            $this->flash('errors', ['token' => __('auth.reset_invalid')]);
            redirect('/password/forgot');
        }
        $this->render('auth/reset', [
            'title' => __('auth.reset_title'),
            'token' => $token,
            'email' => $email,
        ], 'layouts/guest');
    }

    public function reset(Request $request): void
    {
        $data = $request->only(['email', 'token', 'password', 'password_confirmation']);
        if (!$this->validator->validate($data, [
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ])) {
            $this->flash('errors', $this->validator->firstErrors());
            redirect('/password/reset?token=' . urlencode((string) $data['token']) . '&email=' . urlencode((string) $data['email']));
        }
        $result = $this->auth->resetPassword(
            (string) $data['email'],
            (string) $data['token'],
            (string) $data['password'],
            $request->ip()
        );
        if (!$result['ok']) {
            $key = ($result['error'] ?? '') === 'expired' ? 'auth.reset_expired' : 'auth.reset_invalid';
            $this->flash('errors', ['token' => __($key)]);
            redirect('/password/forgot');
        }
        $this->flash('success', __('auth.reset_success'));
        redirect('/login');
    }

    /**
     * Login notice for temporary evaluation instances.
     *
     * Permanent instances (the private presentation) carry no `app.instance_expires_at`:
     * they never expire, so the notice must not claim a date — and must not render an
     * empty “scade il .” clause.
     *
     * @return array{expires_label:string,permanent:bool}|null
     */
    private function demoLoginNotice(): ?array
    {
        try {
            if (!app()->isInstalled() || !is_temporary_instance()) {
                return null;
            }
            $exp = trim((string) app(SettingsService::class)->get('app.instance_expires_at', ''));
            if ($exp === '') {
                return ['expires_label' => '', 'permanent' => true];
            }
            $label = '';
            $ts = strtotime($exp);
            if ($ts !== false) {
                $label = date('d/m/Y H:i', $ts);
            }
            return ['expires_label' => $label, 'permanent' => false];
        } catch (\Throwable) {
            return null;
        }
    }
}
