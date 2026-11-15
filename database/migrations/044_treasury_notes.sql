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

-- Free-text annotations of a movement (internal memo: who asked for the expense, references, ...).
-- Kept next to the description because the description is the short "causale" that goes into the
-- reports, while the note is a working memo that never replaces it.
SET @notes_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'notes'
);

SET @notes_sql := IF(
    @notes_exists = 0,
    'ALTER TABLE treasury_movements ADD COLUMN notes TEXT NULL AFTER description',
    'SELECT 1'
);

PREPARE notes_stmt FROM @notes_sql;
EXECUTE notes_stmt;
DEALLOCATE PREPARE notes_stmt;
