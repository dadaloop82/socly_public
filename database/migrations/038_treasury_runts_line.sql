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

-- RUNTS (DM 18 April 2020): item of the cash statement (Model D) to which the cash book movement belongs, in
-- addition to the internal chapter of the estimate.
SET @runts_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'runts_line'
);

SET @runts_sql := IF(
    @runts_exists = 0,
    'ALTER TABLE treasury_movements ADD COLUMN runts_line VARCHAR(48) NULL AFTER category, ADD INDEX idx_treasury_runts_line (runts_line)',
    'SELECT 1'
);

PREPARE runts_stmt FROM @runts_sql;
EXECUTE runts_stmt;
DEALLOCATE PREPARE runts_stmt;
