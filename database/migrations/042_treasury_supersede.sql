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

-- "Historical" transfer: the original line is not deleted, it remains tracked and excluded from any
-- calculation (balance, income/expenses, RUNTS, statistics, budget/final comparison).
SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'superseded_at');
SET @s := IF(@c = 0,
    'ALTER TABLE treasury_movements
        ADD COLUMN superseded_at DATETIME NULL AFTER approved_by,
        ADD COLUMN superseded_account VARCHAR(40) NULL AFTER superseded_at,
        ADD COLUMN superseded_note VARCHAR(190) NULL AFTER superseded_account,
        ADD COLUMN superseded_by_id INT UNSIGNED NULL AFTER superseded_note,
        ADD INDEX idx_treasury_superseded (superseded_at)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c2 := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'transfer_origin_id');
SET @s2 := IF(@c2 = 0,
    'ALTER TABLE treasury_movements ADD COLUMN transfer_origin_id INT UNSIGNED NULL AFTER superseded_by_id, ADD INDEX idx_treasury_transfer_origin (transfer_origin_id)',
    'SELECT 1');
PREPARE st2 FROM @s2; EXECUTE st2; DEALLOCATE PREPARE st2;
