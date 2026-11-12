-- Traceability of member imports from CSV: one run per file and one line per member processed. It is used for
-- the final report, for downloading errors and for auditing the operation.

CREATE TABLE IF NOT EXISTS member_import_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    source_name VARCHAR(255) NULL,
    source_hash CHAR(64) NULL,
    delimiter VARCHAR(4) NULL,
    encoding VARCHAR(32) NULL,
    mapping_json JSON NULL,
    options_json JSON NULL,
    stats_json JSON NULL,
    status ENUM('preview','committed','failed') NOT NULL DEFAULT 'preview',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    committed_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_import_runs_status (status),
    KEY idx_import_runs_hash (source_hash),
    CONSTRAINT fk_import_run_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_import_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    line_no INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NULL,
    card_id INT UNSIGNED NULL,
    payment_id INT UNSIGNED NULL,
    action ENUM('create','update','skip','error') NOT NULL,
    result ENUM('created','updated','skipped','failed') NULL,
    message VARCHAR(255) NULL,
    payload_json JSON NULL,
    diff_json JSON NULL,
    errors_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_import_rows_run (run_id),
    KEY idx_import_rows_member (member_id),
    CONSTRAINT fk_import_row_run FOREIGN KEY (run_id) REFERENCES member_import_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_import_row_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
