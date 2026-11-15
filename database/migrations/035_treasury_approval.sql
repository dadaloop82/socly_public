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

-- Manual approval of first-note (treasury) movements.
-- Movements generated automatically (membership payments, imports, reconciliations) are created
-- as "pending": they do not affect balances, charts, budgets or reports until an administrator
-- approves them. Manually created movements are approved on save.
ALTER TABLE treasury_movements
    ADD COLUMN approved_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN approved_by INT UNSIGNED NULL DEFAULT NULL,
    ADD INDEX idx_treasury_approved (approved_at),
    ADD CONSTRAINT fk_treasury_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Existing history is already accounted for: treat it as approved.
UPDATE treasury_movements SET approved_at = created_at WHERE approved_at IS NULL;
