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
        private readonly AuditService $audit
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

    /** @return array<string,mixed>|null */
    private function masterRow(): ?array
    {
        try {
            $rows = $this->db->fetchAll('SELECT id, email, is_system_admin, is_active FROM users');
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
