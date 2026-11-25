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

-- Operator profile: gender (for Bentornato/a) and optional birth date.
SET @g_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'gender');
SET @g_sql := IF(@g_exists = 0,
    'ALTER TABLE users ADD COLUMN gender VARCHAR(1) NULL AFTER last_name',
    'SET @g_noop := 1');
PREPARE g_stmt FROM @g_sql; EXECUTE g_stmt; DEALLOCATE PREPARE g_stmt;

SET @bd_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'birth_date');
SET @bd_sql := IF(@bd_exists = 0,
    'ALTER TABLE users ADD COLUMN birth_date DATE NULL AFTER gender',
    'SET @bd_noop := 1');
PREPARE bd_stmt FROM @bd_sql; EXECUTE bd_stmt; DEALLOCATE PREPARE bd_stmt;
