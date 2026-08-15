-- Base-package components: treasury, deadlines, documents, org role templates

CREATE TABLE IF NOT EXISTS treasury_movements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    movement_date DATE NOT NULL,
    direction ENUM('income','expense') NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    description VARCHAR(500) NOT NULL DEFAULT '',
    payment_method VARCHAR(40) NOT NULL DEFAULT 'cash',
    member_id INT UNSIGNED NULL,
    attachment_path VARCHAR(255) NULL,
    payment_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_treasury_date (movement_date),
    INDEX idx_treasury_direction (direction),
    INDEX idx_treasury_member (member_id),
    CONSTRAINT fk_treasury_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_treasury_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT fk_treasury_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deadline_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'general',
    due_date DATE NOT NULL,
    member_id INT UNSIGNED NULL,
    status ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    source VARCHAR(60) NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_deadline_due (due_date),
    INDEX idx_deadline_status (status),
    INDEX idx_deadline_member (member_id),
    CONSTRAINT fk_deadline_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS association_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'minutes',
    document_date DATE NULL,
    file_path VARCHAR(255) NULL,
    file_mime VARCHAR(120) NULL,
    summary TEXT NULL,
    status ENUM('draft','approved','signed') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_category (category),
    INDEX idx_doc_date (document_date),
    CONSTRAINT fk_doc_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
