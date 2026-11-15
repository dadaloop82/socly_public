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

-- Counterparty and movement description: the first letter is always upper case.
-- Only existing rows that still start with a lower-case letter are touched; the rest of the
-- text is kept exactly as it was written.
UPDATE treasury_movements
SET description = CONCAT(UPPER(LEFT(description, 1)), SUBSTRING(description, 2))
WHERE description IS NOT NULL AND description <> ''
  AND BINARY LEFT(description, 1) <> BINARY UPPER(LEFT(description, 1));

UPDATE treasury_movements
SET beneficiary = CONCAT(UPPER(LEFT(beneficiary, 1)), SUBSTRING(beneficiary, 2))
WHERE beneficiary IS NOT NULL AND beneficiary <> ''
  AND BINARY LEFT(beneficiary, 1) <> BINARY UPPER(LEFT(beneficiary, 1));
