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

-- Field-level presence: which field of the open record each operator is working in. The client sends
-- it with the presence heartbeat, so the other operators can outline that field with the colour of
-- the person's badge instead of only saying “X is editing this record”.
SET @field_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_presence' AND column_name = 'editing_field'
);

SET @field_sql := IF(
    @field_exists = 0,
    'ALTER TABLE user_presence ADD COLUMN editing_field VARCHAR(120) NULL AFTER editing_id',
    'SELECT 1'
);

PREPARE field_stmt FROM @field_sql;
EXECUTE field_stmt;
DEALLOCATE PREPARE field_stmt;
