-- Association officers: legal representative, board, and control organs

CREATE TABLE IF NOT EXISTS association_officers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(40) NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    fiscal_code VARCHAR(16) NOT NULL,
    city VARCHAR(120) NULL,
    postal_code VARCHAR(12) NULL,
    address VARCHAR(255) NULL,
    house_number VARCHAR(20) NULL,
    appointed_at DATE NULL,
    mandate_ends_at DATE NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_association_officers_role (role),
    INDEX idx_association_officers_role_sort (role, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
