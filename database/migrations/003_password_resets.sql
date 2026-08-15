-- Password reset tokens

CREATE TABLE IF NOT EXISTS password_resets (
    email VARCHAR(190) NOT NULL,
    token CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (email),
    KEY idx_password_resets_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
