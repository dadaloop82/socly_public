-- Association people & role hierarchy (replaces association_officers)

CREATE TABLE IF NOT EXISTS association_roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(40) NOT NULL,
    label_key VARCHAR(120) NOT NULL,
    hierarchy_level INT NOT NULL DEFAULT 100,
    is_unique TINYINT(1) NOT NULL DEFAULT 0,
    requires_residence TINYINT(1) NOT NULL DEFAULT 0,
    requires_mandate TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_association_roles_key (`key`),
    INDEX idx_association_roles_hierarchy (hierarchy_level, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO association_roles (`key`, label_key, hierarchy_level, is_unique, requires_residence, requires_mandate, sort_order)
VALUES
    ('president', 'association.role_president', 10, 1, 1, 1, 10),
    ('vice_president', 'association.role_vice_president', 20, 0, 0, 1, 20),
    ('secretary', 'association.role_secretary', 30, 0, 0, 1, 30),
    ('treasurer', 'association.role_treasurer', 40, 0, 0, 1, 40),
    ('board', 'association.role_board', 50, 0, 0, 0, 50),
    ('auditor', 'association.role_auditor', 60, 0, 0, 0, 60)
ON DUPLICATE KEY UPDATE
    label_key = VALUES(label_key),
    hierarchy_level = VALUES(hierarchy_level),
    is_unique = VALUES(is_unique),
    requires_residence = VALUES(requires_residence),
    requires_mandate = VALUES(requires_mandate),
    sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS association_people (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(40) NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    fiscal_code VARCHAR(16) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    city VARCHAR(120) NULL,
    postal_code VARCHAR(12) NULL,
    address VARCHAR(255) NULL,
    house_number VARCHAR(20) NULL,
    appointed_at DATE NULL,
    mandate_ends_at DATE NULL,
    notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_association_people_role (role_key),
    INDEX idx_association_people_role_sort (role_key, sort_order),
    INDEX idx_association_people_active (is_active),
    CONSTRAINT fk_association_people_role
        FOREIGN KEY (role_key) REFERENCES association_roles (`key`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @officers_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'association_officers'
);

SET @people_count := (SELECT COUNT(*) FROM association_people);

SET @migrate_sql := IF(
    @officers_exists > 0 AND @people_count = 0,
    'INSERT INTO association_people (role_key, first_name, last_name, fiscal_code, city, postal_code, address, house_number, appointed_at, mandate_ends_at, sort_order, is_active) SELECT role, first_name, last_name, fiscal_code, city, postal_code, address, house_number, appointed_at, mandate_ends_at, sort_order, 1 FROM association_officers',
    'SELECT 1'
);

PREPARE migrate_stmt FROM @migrate_sql;
EXECUTE migrate_stmt;
DEALLOCATE PREPARE migrate_stmt;

DROP TABLE IF EXISTS association_officers;
