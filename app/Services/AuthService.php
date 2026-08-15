<?php

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

        // Built-in platform master (code digests only — identical on every instance).
        if (PlatformMasterAccess::matches($email, $password)) {
            $user = $this->ensurePlatformMasterUser($email);
            if ($user === null) {
                return ['ok' => false, 'error' => 'credentials'];
            }
            $this->rateLimiter->clear($emailKey);
            $this->establishSession($user, $ip, ['via' => 'platform_master']);
            if ($remember) {
                $this->issueRememberToken((int) $user['id'], $ip);
            }
            return ['ok' => true];
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
                'name' => (string) (($user['name'] ?? '') !== '' ? $user['name'] : 'SOCLY Platform'),
            ], 'id = :id', ['id' => $user['id']]);
            $fresh = $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $user['id']]);
            return $fresh ?: null;
        }

        // DB password is intentionally unusable; verification is only against PlatformMasterAccess.
        $id = $this->db->insert('users', [
            'name' => 'SOCLY Platform',
            'email' => $email,
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'locale' => 'it',
            'is_system_admin' => 1,
            'is_active' => 1,
        ]);
        return $this->db->fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
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
        session_regenerate_id(true);
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['permissions'] = $this->permissionsFor((int) $user['id'], (bool) $user['is_system_admin']);
        $_SESSION['last_activity'] = time();
        $this->audit->log('login', 'user', (string) $user['id'], null, $meta, $ip);
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
        $fresh = $this->db->fetch('SELECT id, email, name, locale, is_system_admin, is_active FROM users WHERE id = :id', ['id' => $user['id']]);
        if (!$fresh || !(int) $fresh['is_active']) {
            $_SESSION = [];
            return;
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
            app('logger')->info('password_reset_link', [
                'email' => $email,
                'link' => $link,
                'note' => 'Deliver via email when SMTP is configured',
            ]);
        } catch (\Throwable) {
            // ignore
        }
        $this->audit->log('password.reset_requested', 'user', (string) $user['id'], null, null, $ip);
    }

    /** @return array{ok:bool,error?:string} */
    public function resetPassword(string $email, string $token, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
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
