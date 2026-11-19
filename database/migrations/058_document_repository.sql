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

-- Central file repository: every upload of the app and of the add-ons lands here.
-- One physical file per SHA-256 (dedupe); `document_links` records every section/context that
-- references it, so the same bytes are never stored twice and the origin is always known.

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'content_hash');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN content_hash CHAR(64) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'file_size');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN file_size BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'original_name');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN original_name VARCHAR(255) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'source_section');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN source_section VARCHAR(80) NOT NULL DEFAULT ''documents''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'source_ref');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN source_ref VARCHAR(190) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'reference_year');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN reference_year SMALLINT UNSIGNED NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'uploaded_by_type');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN uploaded_by_type VARCHAR(20) NOT NULL DEFAULT ''operator''', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'uploaded_by_id');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN uploaded_by_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'uploaded_by_name');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN uploaded_by_name VARCHAR(190) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'uploaded_ip');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN uploaded_ip VARCHAR(45) NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'download_count');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN download_count INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'last_downloaded_at');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN last_downloaded_at TIMESTAMP NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND column_name = 'deleted_at');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN deleted_at TIMESTAMP NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND index_name = 'idx_doc_hash');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD INDEX idx_doc_hash (content_hash)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND index_name = 'idx_doc_section');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD INDEX idx_doc_section (source_section)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND index_name = 'idx_doc_year');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD INDEX idx_doc_year (reference_year)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'association_documents' AND index_name = 'idx_doc_uploader');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD INDEX idx_doc_uploader (uploaded_by_type, uploaded_by_id)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Every place that references a repository file (one row per section/context).
CREATE TABLE IF NOT EXISTS document_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    section VARCHAR(80) NOT NULL DEFAULT 'documents',
    context_ref VARCHAR(190) NOT NULL DEFAULT '',
    linked_by_type VARCHAR(20) NOT NULL DEFAULT 'operator',
    linked_by_id INT UNSIGNED NULL,
    linked_by_name VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_document_link (document_id, section, context_ref),
    INDEX idx_document_links_section (section, context_ref),
    CONSTRAINT fk_document_link_document FOREIGN KEY (document_id) REFERENCES association_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing rows: derive the reference year and the origin section.
UPDATE association_documents
   SET reference_year = COALESCE(YEAR(document_date), YEAR(created_at))
 WHERE reference_year IS NULL;

UPDATE association_documents
   SET source_section = 'documents'
 WHERE source_section IS NULL OR source_section = '';
