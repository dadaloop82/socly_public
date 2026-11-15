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

-- “Already collected”: the money is in the bank statement (or otherwise already in hand)
-- but no cash-book movement needs to be linked, so the payment stops being an anomaly.
-- The flag is only a classification: the payment row and its amount stay untouched.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'cashbook_ack_at');
SET @s := IF(@c = 0,
    'ALTER TABLE payments
        ADD COLUMN cashbook_ack_at DATETIME NULL AFTER revoked_note,
        ADD COLUMN cashbook_ack_by INT UNSIGNED NULL AFTER cashbook_ack_at,
        ADD INDEX idx_payments_cashbook_ack (cashbook_ack_at)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
