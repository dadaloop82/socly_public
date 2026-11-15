/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

-- Payment revocation: an unregistered payment is deleted; a payment that already
-- reached the cash book stays in the member history as a struck-through row and its
-- treasury movement is superseded, never hard-deleted.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'revoked_at');
SET @s := IF(@c = 0,
    'ALTER TABLE payments
        ADD COLUMN revoked_at DATETIME NULL AFTER created_at,
        ADD COLUMN revoked_by INT UNSIGNED NULL AFTER revoked_at,
        ADD COLUMN revoked_note VARCHAR(255) NULL AFTER revoked_by,
        ADD INDEX idx_payments_revoked (revoked_at)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
