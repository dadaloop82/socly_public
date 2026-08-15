-- Remember-me tokens (split selector/validator) and enrollment artifacts

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector VARCHAR(64) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    user_agent VARCHAR(255) NULL,
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    KEY idx_remember_expires (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_enrollment_artifacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    method VARCHAR(32) NOT NULL,
    storage_path VARCHAR(255) NULL,
    content_hash VARCHAR(128) NULL,
    meta_json JSON NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_enroll_member (member_id),
    CONSTRAINT fk_enroll_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_enroll_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
