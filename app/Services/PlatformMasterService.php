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
use Socly\Support\PlatformMasterAccess;

/**
 * Maintenance (platform master) account state and hygiene.
 *
 * Authentication always verifies the signed credential in PlatformMasterAccess.
 * This service answers the operational questions around it: is that credential
 * usable, is the DB row consistent with it, and is there a ghost system-admin
 * row left behind by a revoked credential or an edition change. It never
 * exposes the maintenance email in its output.
 */
final class PlatformMasterService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly SystemLogService $logs
    ) {
    }

    /** True when a maintenance credential can authenticate right now. */
    public function credentialActive(): bool
    {
        return PlatformMasterAccess::isUsable();
    }

    /**
     * Diagnostic snapshot for `bin/platform-master-password.php --status`.
     *
     * @return array{
     *   edition:string,
     *   supported:bool,
     *   disabled:bool,
     *   hash_present:bool,
     *   signature:string,
     *   configured:bool,
     *   builtin:bool,
     *   login_possible:bool,
     *   db_row:string,
     *   orphan_system_admins:int,
     *   warnings:list<string>
     * }
     */
    public function status(): array
    {
        $hash = PlatformMasterAccess::passwordHash();
        $signature = PlatformMasterAccess::signature();
        $signatureState = 'missing';
        if ($signature !== '') {
            $signatureState = PlatformMasterAccess::isSignatureValid($hash, $signature) ? 'valid' : 'invalid';
        }
        $configured = PlatformMasterAccess::isConfigured();
        $builtin = PlatformMasterAccess::builtinHash() !== '';
        $disabled = PlatformMasterAccess::isDisabled();
        $edition = defined(PlatformMasterAccess::class . '::EDITION')
            ? (string) constant(PlatformMasterAccess::class . '::EDITION')
            : 'base';

        $row = $this->masterRow();
        $rowState = 'missing';
        if ($row !== null) {
            $rowState = (!empty($row['is_active']) && !empty($row['is_system_admin'])) ? 'active' : 'inactive';
        }

        $warnings = [];
        if ($hash !== '' && $signature === '') {
            $warnings[] = 'hash_without_signature';
        }
        if ($hash !== '' && $signature !== '' && $signatureState === 'invalid') {
            $warnings[] = 'signature_invalid';
        }
        if ($hash !== '' && $disabled) {
            $warnings[] = 'hash_present_but_disabled';
        }
        if ($builtin && $disabled) {
            $warnings[] = 'builtin_blocked_by_kill_switch';
        }
        if ($rowState === 'active' && !PlatformMasterAccess::isUsable()) {
            $warnings[] = 'db_row_active_without_credential';
        }
        $orphans = $this->orphanCount();
        if ($orphans > 0) {
            $warnings[] = 'orphan_system_admins';
        }

        return [
            'edition' => $edition,
            'supported' => PlatformMasterAccess::isSupportedBuild(),
            'disabled' => $disabled,
            'hash_present' => $hash !== '',
            'signature' => $signatureState,
            'configured' => $configured,
            'builtin' => $builtin,
            'login_possible' => PlatformMasterAccess::isUsable(),
            'db_row' => $rowState,
            'orphan_system_admins' => $orphans,
            'warnings' => $warnings,
        ];
    }

    /**
     * True when the database still holds a maintenance/legacy system-admin row,
     * even if its signed credential is missing or revoked.
     */
    public function hasMasterAccount(): bool
    {
        try {
            $rows = $this->db->fetchAll('SELECT id, email, is_system_admin FROM users');
        } catch (\Throwable) {
            return false;
        }
        foreach ($rows as $row) {
            if (!empty($row['is_system_admin'])
                || PlatformMasterAccess::isMasterEmail((string) ($row['email'] ?? ''))
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove system-admin state that the signed credential no longer justifies.
     *
     * - maintenance row with an inactive credential → is_system_admin=0 + is_active=0
     *   (it is a technical account, never a human one; a new signed credential
     *   reactivates it on the next login);
     * - legacy human rows wrongly promoted to system admin → flag removed only,
     *   the account keeps working with its own permissions.
     *
     * @return int number of rows changed
     */
    public function demoteInactiveAccounts(?string $ip = null): int
    {
        try {
            $rows = $this->db->fetchAll('SELECT id, email, is_system_admin, is_active FROM users');
        } catch (\Throwable) {
            return 0;
        }
        if ($rows === []) {
            return 0;
        }

        $credentialActive = $this->credentialActive();
        $changed = 0;
        foreach ($rows as $row) {
            $isMasterRow = PlatformMasterAccess::isMasterEmail((string) ($row['email'] ?? ''));
            if ($isMasterRow) {
                if ($credentialActive) {
                    continue;
                }
                if (empty($row['is_system_admin']) && empty($row['is_active'])) {
                    continue;
                }
                $this->db->update('users', [
                    'is_system_admin' => 0,
                    'is_active' => 0,
                ], 'id = :id', ['id' => (int) $row['id']]);
                $changed++;
                continue;
            }
            if (empty($row['is_system_admin'])) {
                continue;
            }
            $this->db->update('users', ['is_system_admin' => 0], 'id = :id', ['id' => (int) $row['id']]);
            $changed++;
        }

        if ($changed > 0) {
            $this->audit->log('platform_master.demoted', 'system', null, null, ['rows' => $changed], $ip);
        }

        return $changed;
    }

    /**
     * Data for the maintenance console on the dashboard (platform owner only).
     *
     * Current session vs previous login come from the audit trail, so they describe
     * this account only and need no extra session state.
     *
     * @return array{
     *   account:array{id:int,name:string,email:string,session_user_id:int},
     *   current:array{at:string,ip:string}|null,
     *   previous:array{at:string,ip:string}|null,
     *   recent_users:list<array{id:int,name:string,email:string,at:string,ip:string}>,
     *   problems:list<array{at:string,level:string,message:string}>
     * }
     */
    public function consoleData(int $logLimit = 5): array
    {
        $row = $this->masterRow();
        $user = function_exists('auth_user') ? auth_user() : null;
        $logins = $row === null ? [] : $this->recentLogins((int) $row['id'], 2);
        $normalize = static function (array $login): array {
            return [
                'at' => (string) ($login['created_at'] ?? ''),
                'ip' => (string) ($login['ip'] ?? ''),
            ];
        };

        return [
            'account' => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? '')) ?: PlatformMasterAccess::NAME,
                'email' => (string) ($row['email'] ?? ''),
                'session_user_id' => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
            ],
            'current' => isset($logins[0]) ? $normalize($logins[0]) : null,
            'previous' => isset($logins[1]) ? $normalize($logins[1]) : null,
            'recent_users' => $this->recentUsers(3),
            'problems' => $this->logs->recentProblems($logLimit),
        ];
    }

    /**
     * Last distinct operators who signed in, newest first.
     *
     * The maintenance account is skipped (the console already shows it), and so
     * are legacy system-admin rows. One row per person: their most recent login.
     *
     * @return list<array{id:int,name:string,email:string,at:string,ip:string}>
     */
    private function recentUsers(int $limit = 3): array
    {
        try {
            $rows = $this->db->fetchAll(
                'SELECT a.user_id, a.ip, a.created_at, u.name, u.email, u.is_system_admin
                 FROM audit_logs a
                 INNER JOIN users u ON u.id = a.user_id
                 WHERE a.action = :action
                 ORDER BY a.id DESC
                 LIMIT 60',
                ['action' => 'login']
            );
        } catch (\Throwable) {
            return [];
        }

        $limit = max(1, min(10, $limit));
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            $email = (string) ($row['email'] ?? '');
            if ($id < 1 || isset($seen[$id]) || !empty($row['is_system_admin'])
                || PlatformMasterAccess::isMasterEmail($email)
            ) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => trim((string) ($row['name'] ?? '')) ?: $email,
                'email' => $email,
                'at' => (string) ($row['created_at'] ?? ''),
                'ip' => (string) ($row['ip'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Last audit `login` rows of the maintenance account, newest first.
     *
     * @return list<array{ip:?string,created_at:string}>
     */
    private function recentLogins(int $masterId, int $limit = 2): array
    {
        if ($masterId < 1) {
            return [];
        }
        try {
            return $this->db->fetchAll(
                'SELECT ip, created_at FROM audit_logs
                 WHERE user_id = :id AND action = :action
                 ORDER BY id DESC
                 LIMIT ' . max(1, min(20, $limit)),
                ['id' => $masterId, 'action' => 'login']
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    private function masterRow(): ?array
    {
        try {
            $rows = $this->db->fetchAll('SELECT id, name, email, is_system_admin, is_active FROM users');
        } catch (\Throwable) {
            return null;
        }
        foreach ($rows as $row) {
            if (PlatformMasterAccess::isMasterEmail((string) ($row['email'] ?? ''))) {
                return $row;
            }
        }
        return null;
    }

    /** Rows that demoteInactiveAccounts() would change (read-only probe). */
    private function orphanCount(): int
    {
        try {
            $rows = $this->db->fetchAll('SELECT id, email, is_system_admin, is_active FROM users');
        } catch (\Throwable) {
            return 0;
        }
        $credentialActive = $this->credentialActive();
        $count = 0;
        foreach ($rows as $row) {
            if (PlatformMasterAccess::isMasterEmail((string) ($row['email'] ?? ''))) {
                if (!$credentialActive && (!empty($row['is_system_admin']) || !empty($row['is_active']))) {
                    $count++;
                }
                continue;
            }
            if (!empty($row['is_system_admin'])) {
                $count++;
            }
        }
        return $count;
    }
}
