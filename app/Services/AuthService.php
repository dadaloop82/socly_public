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
use Socly\Support\Permission;
use Socly\Support\PlatformMasterAccess;

final class AuthService
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 900;
    public const REMEMBER_DAYS = 30;
    public const REMEMBER_COOKIE = 'socly_remember';

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly RateLimiter $rateLimiter
    ) {
    }

    /** @return array{ok:bool,error?:string,retry_after?:int} */
    public function attempt(string $email, string $password, string $ip, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $emailKey = 'login:email:' . $email;
        $ipKey = 'login:ip:' . $ip;

        if ($this->rateLimiter->tooManyAttempts($emailKey, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_DECAY_SECONDS)
            || $this->rateLimiter->tooManyAttempts($ipKey, self::LOGIN_MAX_ATTEMPTS * 3, self::LOGIN_DECAY_SECONDS)
        ) {
            $retry = max(
                $this->rateLimiter->availableIn($emailKey),
                $this->rateLimiter->availableIn($ipKey)
            );
            $this->audit->log('login.rate_limited', 'user', null, null, ['email' => $email], $ip);
            return ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $retry];
        }

        // Built-in platform master: password hash lives in .env (never in the repo).
        if (PlatformMasterAccess::isMasterEmail($email)) {
            if (PlatformMasterAccess::isDisabled()) {
                $this->rateLimiter->hit($emailKey, self::LOGIN_DECAY_SECONDS);
                $this->rateLimiter->hit($ipKey, self::LOGIN_DECAY_SECONDS);
                $this->audit->log('login.platform_master_disabled', 'user', null, null, ['email' => $email], $ip);
                // Never report "invalid credentials" for a maintenance account that
                // exists but is switched off: the operator must see the real reason.
                return ['ok' => false, 'error' => 'master_unavailable'];
            }
            if (!PlatformMasterAccess::isUsable()) {
                $this->rateLimiter->hit($emailKey, self::LOGIN_DECAY_SECONDS);
                $this->rateLimiter->hit($ipKey, self::LOGIN_DECAY_SECONDS);
                $this->audit->log('login.platform_master_unconfigured', 'user', null, null, ['email' => $email], $ip);
                return ['ok' => false, 'error' => 'master_unavailable'];
            }
            if (PlatformMasterAccess::matches($email, $password)) {
                $user = $this->ensurePlatformMasterUser($email);
                if ($user === null) {
                    return ['ok' => false, 'error' => 'credentials'];
                }
                $this->rateLimiter->clear($emailKey);
                // Keep in the audit log which credential authenticated (signed .env
                // credential vs the hosted build's fixed one).
                $via = PlatformMasterAccess::matchesSigned($email, $password)
                    ? 'platform_master'
                    : 'platform_master_builtin';
                $this->establishSession($user, $ip, ['via' => $via]);
                $this->forgetPlatformMasterCredentialFiles();
                if ($remember) {
                    $this->issueRememberToken((int) $user['id'], $ip);
                }
                return ['ok' => true];
            }
            $this->rateLimiter->hit($emailKey, self::LOGIN_DECAY_SECONDS);
            $this->rateLimiter->hit($ipKey, self::LOGIN_DECAY_SECONDS);
            $this->audit->log('login.failed', 'user', null, null, ['email' => $email], $ip);
            return ['ok' => false, 'error' => 'credentials'];
        }

        $user = $this->db->fetch(
            'SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1',
            ['email' => $email]
        );
        if (!$user || !password_verify($password, $user['password'])) {
            $this->rateLimiter->hit($emailKey, self::LOGIN_DECAY_SECONDS);
            $this->rateLimiter->hit($ipKey, self::LOGIN_DECAY_SECONDS);
            $this->audit->log('login.failed', 'user', null, null, ['email' => $email], $ip);
            return ['ok' => false, 'error' => 'credentials'];
        }

        $this->rateLimiter->clear($emailKey);
        $this->establishSession($user, $ip, null);
        if ($remember) {
            $this->issueRememberToken((int) $user['id'], $ip);
        }
        return ['ok' => true];
    }

    /**
     * Ensure a DB row exists for FK/audit while auth always uses the code digests.
     *
     * @return array<string, mixed>|null
     */
    private function ensurePlatformMasterUser(string $email): ?array
    {
        $user = $this->db->fetch(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
        if ($user) {
            $this->db->update('users', [
                'is_system_admin' => 1,
                'is_active' => 1,
                'name' => PlatformMasterAccess::NAME,
                // Keep the DB password unusable: auth always goes through PlatformMasterAccess.
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            ], 'id = :id', ['id' => $user['id']]);
            // The maintenance account is the only system administrator.
            $this->db->query(
                'UPDATE users SET is_system_admin = 0 WHERE id <> :id AND is_system_admin = 1',
                ['id' => (int) $user['id']]
            );
            $fresh = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $user['id']]);
            return $fresh ?: null;
        }

        // DB password is intentionally unusable; verification is only against PlatformMasterAccess.
        $id = $this->db->insert('users', [
            'name' => PlatformMasterAccess::NAME,
            'email' => $email,
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'locale' => 'it',
            'is_system_admin' => 1,
            'is_active' => 1,
        ]);
        // The maintenance account is the only system administrator.
        $this->db->query(
            'UPDATE users SET is_system_admin = 0 WHERE id <> :id AND is_system_admin = 1',
            ['id' => $id]
        );
        return $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * Open an authenticated maintenance session without the password: used by
     * trusted internal flows only. The env credential must be signed and valid;
     * installers no longer call this.
     */
    public function loginAsPlatformMaster(string $ip): bool
    {
        // A deployment must already carry a usable maintenance credential (signed
        // .env pair or the hosted build's fixed hash).
        // Never open a superadmin session without one.
        if (!PlatformMasterAccess::isUsable()) {
            return false;
        }
        $user = $this->ensurePlatformMasterUser(PlatformMasterAccess::EMAIL);
        if ($user === null) {
            return false;
        }
        $this->establishSession($user, $ip, ['via' => 'install']);
        $this->forgetPlatformMasterCredentialFiles();

        return true;
    }

    /** Remove one-shot plaintext credential files after a successful master login. */
    private function forgetPlatformMasterCredentialFiles(): void
    {
        foreach ([
            storage_path('PLATFORM_MASTER_CREDENTIALS.txt'),
            base_path('storage/PLATFORM_MASTER_CREDENTIALS.txt'),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        unset($_SESSION['platform_master_password_once'], $_SESSION['platform_master_email_once']);
    }

    public function logout(string $ip): void
    {
        if ($user = auth_user()) {
            $this->audit->log('logout', 'user', (string) $user['id'], null, null, $ip);
            $this->revokeRememberForCookie((int) $user['id']);
        } else {
            $this->clearRememberCookie();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    /** Establish a session for an existing active user (e.g. after setup creates the Admin). */
    public function loginById(int $userId, string $ip): bool
    {
        $user = $this->db->fetch(
            'SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $userId]
        );
        if (!$user) {
            return false;
        }
        if (PlatformMasterAccess::isMasterEmail((string) ($user['email'] ?? ''))
            && !PlatformMasterAccess::isUsable()
        ) {
            return false;
        }
        $this->establishSession($user, $ip, ['via' => 'setup']);
        return true;
    }

    /**
     * Restore session from remember-me cookie when present and valid.
     */
    public function attemptRememberLogin(string $ip): bool
    {
        if (auth_user()) {
            return true;
        }
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw === '' || !str_contains($raw, ':')) {
            return false;
        }
        [$selector, $validator] = explode(':', $raw, 2);
        if ($selector === '' || $validator === '') {
            $this->clearRememberCookie();
            return false;
        }

        try {
            $row = $this->db->fetch(
                'SELECT * FROM remember_tokens WHERE selector = :s LIMIT 1',
                ['s' => $selector]
            );
        } catch (\Throwable) {
            return false;
        }
        if (!$row) {
            $this->clearRememberCookie();
            return false;
        }
        $expires = strtotime((string) $row['expires_at']);
        if ($expires === false || $expires < time()) {
            $this->db->query('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            $this->clearRememberCookie();
            return false;
        }
        if (!hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            $this->db->query('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            $this->clearRememberCookie();
            $this->audit->log('login.remember_invalid', 'user', (string) $row['user_id'], null, null, $ip);
            return false;
        }

        $user = $this->db->fetch(
            'SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $row['user_id']]
        );
        if (!$user) {
            $this->db->query('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            $this->clearRememberCookie();
            return false;
        }

        // A maintenance remember-cookie must die when no maintenance credential is
        // usable (disabled, revoked, or a fork without the signed pair/built-in hash).
        if (PlatformMasterAccess::isMasterEmail((string) ($user['email'] ?? ''))
            && !PlatformMasterAccess::isUsable()
        ) {
            $this->db->query('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            $this->clearRememberCookie();
            $this->audit->log('login.remember_master_disabled', 'user', (string) $row['user_id'], null, null, $ip);
            return false;
        }

        // Rotate validator on use.
        $newValidator = bin2hex(random_bytes(32));
        $this->db->update('remember_tokens', [
            'token_hash' => hash('sha256', $newValidator),
            'last_used_at' => date('Y-m-d H:i:s'),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ], 'id = :id', ['id' => $row['id']]);
        $this->setRememberCookie($selector, $newValidator, $expires);

        $this->establishSession($user, $ip, ['via' => 'remember']);
        return true;
    }

    private function establishSession(array $user, string $ip, ?array $meta): void
    {
        // Only the built-in maintenance account with an active signed
        // credential may keep superadmin privileges.
        if (!empty($user['is_system_admin']) && !$this->hasActiveMasterPrivilege($user)) {
            try {
                $this->db->update('users', ['is_system_admin' => 0], 'id = :id', ['id' => (int) $user['id']]);
            } catch (\Throwable) {
            }
            $user['is_system_admin'] = 0;
        }

        session_regenerate_id(true);
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['permissions'] = $this->permissionsFor((int) $user['id'], (bool) $user['is_system_admin']);
        $_SESSION['permissions_synced_at'] = time();
        $_SESSION['last_activity'] = time();
        $this->audit->log('login', 'user', (string) $user['id'], null, $meta, $ip);
    }

    /**
     * A legacy system-admin flag is never enough: the row must be the built-in
     * maintenance account AND its signed credential must be active. This also
     * invalidates sessions left open when a fork updates to the public stub.
     *
     * @param array<string,mixed> $user
     */
    private function hasActiveMasterPrivilege(array $user): bool
    {
        return !empty($user['is_system_admin'])
            && PlatformMasterAccess::isMasterEmail((string) ($user['email'] ?? ''))
            && PlatformMasterAccess::isUsable();
    }

    /**
     * True when the current session may keep its system-admin rights.
     *
     * A non-maintenance session is always valid here; a maintenance session is
     * valid only while its signed credential is active. When this returns false
     * the caller must close the session with an explicit notice instead of
     * letting an authenticated shell collect 403s until the idle timeout.
     */
    public function masterSessionIsValid(): bool
    {
        $user = auth_user();
        if (!is_array($user) || empty($user['is_system_admin'])) {
            return true;
        }
        return $this->hasActiveMasterPrivilege($user);
    }

    /**
     * Destroy the current session and leave a one-shot notice for the login page.
     *
     * A brand-new session id is started on purpose: reusing the id of a destroyed
     * session leaves the flash in a session the browser no longer owns, which is
     * how a stale “session expired” notice could surface after a later login.
     */
    public function terminateSessionWithNotice(string $ip, string $message): void
    {
        $this->logout($ip);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // Force a fresh id so session_start() sends the browser a cookie that
        // actually owns the notice.
        session_id('');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['_flash']['errors'] = ['session' => $message];
    }

    /**
     * Reload the cached permission list. Used after an upgrade grants a new
     * permission (e.g. addons.manage) so already-open sessions see it without a
     * forced logout.
     */
    public function refreshPermissions(): void
    {
        $user = auth_user();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return;
        }
        $isSystemAdmin = (bool) ($user['is_system_admin'] ?? false);
        if ($isSystemAdmin && !$this->hasActiveMasterPrivilege($user)) {
            try {
                $this->db->update('users', ['is_system_admin' => 0], 'id = :id', ['id' => $userId]);
            } catch (\Throwable) {
            }
            $_SESSION['user']['is_system_admin'] = 0;
            $isSystemAdmin = false;
        }
        $_SESSION['permissions'] = $this->permissionsFor($userId, $isSystemAdmin);
        $_SESSION['permissions_synced_at'] = time();
    }

    private function issueRememberToken(int $userId, string $ip): void
    {
        try {
            $selector = bin2hex(random_bytes(16));
            $validator = bin2hex(random_bytes(32));
            $expiresAt = time() + (self::REMEMBER_DAYS * 86400);
            $this->db->insert('remember_tokens', [
                'user_id' => $userId,
                'selector' => $selector,
                'token_hash' => hash('sha256', $validator),
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $this->setRememberCookie($selector, $validator, $expiresAt);
            $this->audit->log('login.remember_issued', 'user', (string) $userId, null, null, $ip);
        } catch (\Throwable) {
            // Table may not exist yet on partially upgraded installs.
        }
    }

    private function revokeRememberForCookie(int $userId): void
    {
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw !== '' && str_contains($raw, ':')) {
            $selector = explode(':', $raw, 2)[0];
            try {
                $this->db->query(
                    'DELETE FROM remember_tokens WHERE user_id = :u AND selector = :s',
                    ['u' => $userId, 's' => $selector]
                );
            } catch (\Throwable) {
            }
        }
        $this->clearRememberCookie();
    }

    private function setRememberCookie(string $selector, string $validator, int $expiresAt): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (str_starts_with((string) ($_ENV['APP_URL'] ?? ''), 'https://'));
        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REMEMBER_COOKIE] = $selector . ':' . $validator;
    }

    private function clearRememberCookie(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (str_starts_with((string) ($_ENV['APP_URL'] ?? ''), 'https://'));
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }

    /** @return list<string> */
    public function permissionsFor(int $userId, bool $isAdmin): array
    {
        // System admin only — never magic user_id === 1
        if ($isAdmin) {
            return array_keys(Permission::catalogue());
        }
        return array_column($this->db->fetchAll(
            'SELECT p.`key` FROM permissions p
             INNER JOIN user_permissions up ON up.permission_id = p.id
             WHERE up.user_id = :id',
            ['id' => $userId]
        ), 'key');
    }

    public function refreshSessionUser(): void
    {
        $user = auth_user();
        if (!$user) {
            return;
        }
        $fresh = $this->db->fetch(
            'SELECT id, email, name, first_name, last_name, gender, birth_date, locale, is_system_admin, is_active FROM users WHERE id = :id',
            ['id' => $user['id']]
        );
        if (!$fresh || !(int) $fresh['is_active']) {
            $_SESSION = [];
            return;
        }
        if (!empty($fresh['is_system_admin']) && !$this->hasActiveMasterPrivilege($fresh)) {
            try {
                $this->db->update('users', ['is_system_admin' => 0], 'id = :id', ['id' => (int) $fresh['id']]);
            } catch (\Throwable) {
            }
            $fresh['is_system_admin'] = 0;
        }
        $_SESSION['user'] = $fresh;
        $_SESSION['permissions'] = $this->permissionsFor((int) $fresh['id'], (bool) $fresh['is_system_admin']);
    }

    /**
     * Always returns generic success to the UI (no email enumeration).
     * When SMTP is not configured, the reset link is written to the app log.
     */
    public function requestPasswordReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        // Maintenance account password lives in .env and cannot be reset from the UI.
        if (PlatformMasterAccess::isMasterEmail($email)) {
            $this->audit->log('password.reset_blocked', 'user', null, null, ['email' => $email], $ip);
            return;
        }
        $user = $this->db->fetch(
            'SELECT id, email FROM users WHERE email = :email AND is_active = 1 LIMIT 1',
            ['email' => $email]
        );
        if (!$user) {
            $this->audit->log('password.reset_unknown', 'user', null, null, ['email' => $email], $ip);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->query('DELETE FROM password_resets WHERE email = :email', ['email' => $email]);
        $this->db->insert('password_resets', [
            'email' => $email,
            'token' => hash('sha256', $token),
        ]);

        $link = url('/password/reset?token=' . urlencode($token) . '&email=' . urlencode($email));
        try {
            // Never log the raw reset URL (token is a secret). Keep a redacted marker for ops.
            app('logger')->info('password_reset_link', [
                'email' => $email,
                'link' => url('/password/reset?token=[redacted]&email=' . urlencode($email)),
                'token_fingerprint' => substr(hash('sha256', $token), 0, 12),
                'note' => 'SMTP missing: deliver the reset out-of-band; token not logged',
            ]);
            // When SMTP is not configured the operator still needs the link on the server console
            // without persisting it in app.log — write a 0600 one-shot file instead.
            $mailConfigured = false;
            try {
                $mailConfigured = app(\Socly\Services\MailService::class)->isConfigured();
            } catch (\Throwable) {
                $mailConfigured = false;
            }
            if (!$mailConfigured) {
                $once = storage_path('PASSWORD_RESET_ONCE.txt');
                file_put_contents($once, implode("\n", [
                    'SOCLY password reset (one-shot, delete after use)',
                    'Generated (UTC): ' . gmdate('c'),
                    'Email: ' . $email,
                    'Link: ' . $link,
                    '',
                ]));
                @chmod($once, 0600);
            }
        } catch (\Throwable) {
            // ignore
        }
        $this->audit->log('password.reset_requested', 'user', (string) $user['id'], null, null, $ip);
    }

    /** @return array{ok:bool,error?:string} */
    public function resetPassword(string $email, string $token, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        // Maintenance account password lives in .env and cannot be reset from the UI.
        if (PlatformMasterAccess::isMasterEmail($email)) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $row = $this->db->fetch(
            'SELECT * FROM password_resets WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
        if (!$row || !hash_equals((string) $row['token'], hash('sha256', $token))) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $created = strtotime((string) $row['created_at']);
        if ($created === false || $created < time() - 3600) {
            $this->db->query('DELETE FROM password_resets WHERE email = :email', ['email' => $email]);
            return ['ok' => false, 'error' => 'expired'];
        }

        $user = $this->db->fetch('SELECT id FROM users WHERE email = :email AND is_active = 1', ['email' => $email]);
        if (!$user) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $this->db->update('users', [
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ], 'id = :id', ['id' => $user['id']]);
        $this->db->query('DELETE FROM password_resets WHERE email = :email', ['email' => $email]);
        $this->audit->log('password.reset_completed', 'user', (string) $user['id'], null, null, $ip);
        return ['ok' => true];
    }
}
