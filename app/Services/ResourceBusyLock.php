<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚══════╝ ╚══════╝   ╚═╝
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

/**
 * Instance-wide lock for heavy work (statement audit, excerpt repair, updates…).
 * Other sessions see a busy page until the holder finishes or the TTL expires.
 */
final class ResourceBusyLock
{
    /**
     * Seconds without heartbeat before the lock is treated as abandoned.
     * Must exceed the longest single audit/repair tick (OCR of a heavy PDF can
     * run several minutes); otherwise another user's refresh clears the lock
     * mid-job and the shared busy page disappears while work continues.
     */
    public const TTL_SECONDS = 600;

    /** Hard ceiling: no busy lock may block the instance longer than this. */
    public const MAX_HOLD_SECONDS = 1800; // 30 minutes

    public const OP_BANK_STATEMENT_AUDIT = 'bank_statement_audit';
    public const OP_BANK_STATEMENT_REPAIR = 'bank_statement_repair';
    public const OP_SYSTEM_UPDATE = 'system_update';

    public function path(): string
    {
        return storage_path('resource_busy.lock');
    }

    /**
     * @return array{
     *   operation:string,job:string,user_id:int,user_name:string,session_id:string,
     *   started_at:string,expires_at:string,label?:string
     * }|null
     */
    public function status(): ?array
    {
        $data = $this->read();
        if ($data === null) {
            return null;
        }
        if ($this->isExpired($data)) {
            // Soft TTL expiry: do not unlink — a long OCR tick may still reclaim
            // via heartbeat. Only purge past the hard ceiling (abandoned crash).
            $started = strtotime((string) ($data['started_at'] ?? ''));
            if ($started !== false && (time() - $started) >= self::MAX_HOLD_SECONDS) {
                $this->clearFile();
            }

            return null;
        }

        return $data;
    }

    public function isActive(): bool
    {
        return $this->status() !== null;
    }

    /**
     * Only the browser session that started the job is exempt. Other sessions of the same
     * user (another device/browser) and every other user see the busy page.
     *
     * @param array{
     *   operation:string,job:string,user_id:int,user_name:string,session_id:string,
     *   started_at:string,expires_at:string
     * } $lock
     */
    public function isHolder(?array $lock, ?int $userId, string $sessionId): bool
    {
        if ($lock === null) {
            return true;
        }
        $sessionId = trim($sessionId);
        $held = trim((string) ($lock['session_id'] ?? ''));
        if ($sessionId === '' || $held === '') {
            return false;
        }

        return hash_equals($held, $sessionId);
    }

    /**
     * @return array{ok:bool,error?:string,status?:array<string,mixed>}
     */
    public function acquire(
        string $operation,
        string $job,
        int $userId,
        string $userName,
        string $sessionId,
        ?string $label = null,
        ?int $ttlSeconds = null
    ): array {
        $operation = preg_replace('/[^a-z0-9_]/', '', strtolower($operation)) ?? '';
        $job = preg_replace('/[^a-f0-9]/', '', strtolower($job)) ?? '';
        if ($operation === '' || strlen($job) < 8) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $existing = $this->read();
        if ($existing !== null && $this->isExpired($existing)) {
            $this->clearFile();
            $existing = null;
        }
        if ($existing !== null && !$this->isHolder($existing, $userId, $sessionId)) {
            return ['ok' => false, 'error' => 'busy', 'status' => $existing];
        }
        // Same holder may replace their own lock (restart / repair after audit).
        $now = time();
        $ttl = $ttlSeconds !== null ? max(60, min(self::MAX_HOLD_SECONDS, $ttlSeconds)) : self::TTL_SECONDS;
        $hardCap = $now + self::MAX_HOLD_SECONDS;
        $payload = [
            'operation' => $operation,
            'job' => $job,
            'user_id' => max(0, $userId),
            'user_name' => mb_substr(trim($userName) !== '' ? trim($userName) : __('resource_busy.someone'), 0, 120),
            'session_id' => mb_substr($sessionId, 0, 128),
            'started_at' => date('c', $now),
            'expires_at' => date('c', min($now + $ttl, $hardCap)),
            'label' => $label !== null && trim($label) !== ''
                ? mb_substr(trim($label), 0, 190)
                : $this->operationLabel($operation),
        ];
        if (!$this->write($payload)) {
            return ['ok' => false, 'error' => 'write_failed'];
        }

        return ['ok' => true, 'status' => $payload];
    }

    /** Extend TTL while the heavy job keeps ticking (never past the 30-minute hard cap). */
    public function heartbeat(string $job, ?int $userId = null, string $sessionId = ''): bool
    {
        $lock = $this->read();
        if ($lock === null) {
            return false;
        }
        if ($this->isExpired($lock)) {
            $this->clearFile();

            return false;
        }
        $job = preg_replace('/[^a-f0-9]/', '', strtolower($job)) ?? '';
        if ($job === '' || !hash_equals((string) ($lock['job'] ?? ''), $job)) {
            return false;
        }
        if ($sessionId !== '' || $userId !== null) {
            if (!$this->isHolder($lock, $userId, $sessionId)) {
                return false;
            }
        }
        $started = strtotime((string) ($lock['started_at'] ?? ''));
        $hardCap = ($started !== false ? $started : time()) + self::MAX_HOLD_SECONDS;
        $next = min(time() + self::TTL_SECONDS, $hardCap);
        if ($next <= time()) {
            $this->clearFile();

            return false;
        }
        $lock['expires_at'] = date('c', $next);

        return $this->write($lock);
    }

    public function release(string $job = '', ?int $userId = null, string $sessionId = ''): bool
    {
        $lock = $this->read();
        if ($lock === null) {
            return true;
        }
        if ($job !== '') {
            $job = preg_replace('/[^a-f0-9]/', '', strtolower($job)) ?? '';
            if ($job === '' || !hash_equals((string) ($lock['job'] ?? ''), $job)) {
                return false;
            }
        }
        if ($sessionId !== '' || $userId !== null) {
            if (!$this->isHolder($lock, $userId, $sessionId) && !$this->isExpired($lock)) {
                return false;
            }
        }
        $this->clearFile();

        return true;
    }

    public function operationLabel(string $operation): string
    {
        $key = 'resource_busy.operation_' . $operation;
        $label = __($key);
        if ($label === $key) {
            return __('resource_busy.operation_generic');
        }

        return $label;
    }

    /**
     * @return array{
     *   operation:string,job:string,user_id:int,user_name:string,session_id:string,
     *   started_at:string,expires_at:string,label?:string
     * }|null
     */
    private function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $operation = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($data['operation'] ?? ''))) ?? '';
        $job = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($data['job'] ?? ''))) ?? '';
        if ($operation === '' || $job === '') {
            return null;
        }

        return [
            'operation' => $operation,
            'job' => $job,
            'user_id' => (int) ($data['user_id'] ?? 0),
            'user_name' => mb_substr(trim((string) ($data['user_name'] ?? '')), 0, 120),
            'session_id' => mb_substr((string) ($data['session_id'] ?? ''), 0, 128),
            'started_at' => (string) ($data['started_at'] ?? ''),
            'expires_at' => (string) ($data['expires_at'] ?? ''),
            'label' => mb_substr(trim((string) ($data['label'] ?? '')), 0, 190),
        ];
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): bool
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $ok = @file_put_contents($path, $json, LOCK_EX) !== false;
        if ($ok) {
            @chmod($path, 0664);
        }

        return $ok;
    }

    private function clearFile(): void
    {
        $path = $this->path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $data */
    private function isExpired(array $data): bool
    {
        $now = time();
        $started = strtotime((string) ($data['started_at'] ?? ''));
        // Absolute ceiling: unlock the instance no matter how lively the heartbeat is.
        if ($started !== false && ($now - $started) >= self::MAX_HOLD_SECONDS) {
            return true;
        }
        $expires = strtotime((string) ($data['expires_at'] ?? ''));
        if ($expires === false) {
            return true;
        }

        return $expires < $now;
    }
}
