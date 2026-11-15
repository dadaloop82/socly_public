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

-- Paid add-ons installed on this instance. The signed licence lives encrypted in settings;
-- this table only tracks the package on disk and the enable/disable state (which also drives
-- the `plugins` table). Packages are extracted under storage/addons/<id>/<version>/.
CREATE TABLE IF NOT EXISTS addons (
    id VARCHAR(100) NOT NULL,
    version VARCHAR(40) NOT NULL DEFAULT '',
    status ENUM('active','disabled') NOT NULL DEFAULT 'disabled',
    license_id VARCHAR(100) NULL,
    package_sha256 CHAR(64) NULL,
    meta_json JSON NULL,
    installed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addons_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
