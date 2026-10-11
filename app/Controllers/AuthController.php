<?php

declare(strict_types=1);

namespace Socly\Controllers;

use Socly\Core\Http\Request;
use Socly\Core\Validator;
use Socly\Core\View;
use Socly\Services\AuthService;
use Socly\Services\SettingsService;
use Socly\Services\SetupService;

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
                $needsSetup = $this->setup->allowsAnonymousSetup();
            } catch (\Throwable) {
                $needsSetup = true;
                $dbDown = true;
            }
        }

        $this->render('auth/login', [
            'title' => __('auth.welcome_title'),
            'needsSetup' => $needsSetup,
            'setupUrl' => $dbDown ? url('/install') : url('/setup'),
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
        ], 'layouts/guest');
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
            } else {
                $this->flash('errors', ['email' => __('auth.failed')]);
            }
            redirect('/login');
        }
        $this->clearOld();

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
        ], 'layouts/guest');
    }

    public function sendForgot(Request $request): void
    {
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

    /** @return array{expires_label:string}|null */
    private function demoLoginNotice(): ?array
    {
        try {
            if (!app()->isInstalled() || !is_temporary_instance()) {
                return null;
            }
            $exp = trim((string) app(SettingsService::class)->get('app.instance_expires_at', ''));
            $label = '';
            if ($exp !== '') {
                $ts = strtotime($exp);
                if ($ts !== false) {
                    $label = date('d/m/Y H:i', $ts);
                }
            }
            return ['expires_label' => $label];
        } catch (\Throwable) {
            return null;
        }
    }
}
