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

-- Reference months for bank statements (and other period documents) shown next to the year.

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'reference_month_from');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN reference_month_from TINYINT UNSIGNED NULL AFTER reference_year', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'reference_month_to');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN reference_month_to TINYINT UNSIGNED NULL AFTER reference_month_from', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Every bank-statement file (PDF + movement excerpts) belongs under “Estratti Conto”.
UPDATE association_documents
   SET category = 'balance'
 WHERE source_section = 'bank_statement_import'
   AND category <> 'balance'
   AND deleted_at IS NULL;

-- Seed months from the document date when the import did not store a period yet.
UPDATE association_documents
   SET reference_month_from = MONTH(document_date),
       reference_month_to = MONTH(document_date)
 WHERE source_section = 'bank_statement_import'
   AND document_date IS NOT NULL
   AND reference_month_from IS NULL
   AND deleted_at IS NULL;
