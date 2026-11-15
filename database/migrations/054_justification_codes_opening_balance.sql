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

-- Two independent additions to the cash book:
--   1) every supporting document (“pezza giustificativa”) gets a stable code made of a letter and
--      the movement number: P + the id zero-padded to six digits (P000123). It is unique by
--      construction and readable on the printed financial statement;
--   2) the opening balance of a wallet is stored as a normal income movement flagged here, so the
--      Financial accounts settings can edit or remove it without guessing from the description.
SET @justification_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'justification_code'
);

SET @justification_sql := IF(
    @justification_exists = 0,
    'ALTER TABLE treasury_movements ADD COLUMN justification_code VARCHAR(16) NULL AFTER document_type',
    'SELECT 1'
);

PREPARE justification_stmt FROM @justification_sql;
EXECUTE justification_stmt;
DEALLOCATE PREPARE justification_stmt;

SET @opening_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'is_opening_balance'
);

SET @opening_sql := IF(
    @opening_exists = 0,
    'ALTER TABLE treasury_movements ADD COLUMN is_opening_balance TINYINT(1) NOT NULL DEFAULT 0 AFTER justification_code',
    'SELECT 1'
);

PREPARE opening_stmt FROM @opening_sql;
EXECUTE opening_stmt;
DEALLOCATE PREPARE opening_stmt;

-- Rows that already carry a document receive their code once; new ones are numbered on save.
UPDATE treasury_movements
   SET justification_code = CONCAT('P', LPAD(id, 6, '0'))
 WHERE justification_code IS NULL
   AND (
       (invoice_number IS NOT NULL AND TRIM(invoice_number) <> '')
       OR (document_type IS NOT NULL AND TRIM(document_type) <> '')
   );

-- Opening balances written by the setup wizard become editable from the accounts screen.
UPDATE treasury_movements
   SET is_opening_balance = 1
 WHERE is_opening_balance = 0
   AND direction = 'income'
   AND category = 'other'
   AND (
       description LIKE 'Saldo iniziale%'
       OR description LIKE 'Opening balance%'
       OR description LIKE 'Anfangssaldo%'
   );

SET @justification_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements'
      AND index_name = 'uq_treasury_justification'
);

SET @justification_index_sql := IF(
    @justification_index = 0,
    'ALTER TABLE treasury_movements ADD UNIQUE INDEX uq_treasury_justification (justification_code)',
    'SELECT 1'
);

PREPARE justification_index_stmt FROM @justification_index_sql;
EXECUTE justification_index_stmt;
DEALLOCATE PREPARE justification_index_stmt;
