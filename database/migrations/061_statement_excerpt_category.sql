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

-- Movement crops belong under “Ritagli dell'estratto conto”, not under the statement PDFs.

UPDATE association_documents
   SET category = 'statement_excerpt'
 WHERE source_section = 'bank_statement_import'
   AND deleted_at IS NULL
   AND category <> 'statement_excerpt'
   AND (
        file_mime LIKE 'image/%'
     OR LOWER(COALESCE(original_name, '')) LIKE '%.png'
     OR LOWER(COALESCE(original_name, '')) LIKE '%.jpg'
     OR LOWER(COALESCE(original_name, '')) LIKE '%.jpeg'
     OR LOWER(COALESCE(original_name, '')) LIKE '%.webp'
     OR source_ref LIKE 'movement:%'
   );

-- Statement PDFs stay in Estratti Conto.
UPDATE association_documents
   SET category = 'balance'
 WHERE source_section = 'bank_statement_import'
   AND deleted_at IS NULL
   AND category <> 'balance'
   AND (
        file_mime = 'application/pdf'
     OR LOWER(COALESCE(original_name, '')) LIKE '%.pdf'
     OR source_ref LIKE 'run:%'
   );
