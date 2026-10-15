-- Prima cassa: i conti finanziari diventano un'entità gestibile (aggiungi,
-- rinomina, disattiva, elimina finché non ha movimenti). Il libro mastro resta
-- unico: `treasury_movements.account` / `transfer_account` continuano a
-- contenere lo slug del conto.
CREATE TABLE IF NOT EXISTS treasury_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL,
    label VARCHAR(80) NULL,
    is_builtin TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_treasury_account_slug (slug),
    INDEX idx_treasury_account_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conti predefiniti (label NULL = etichetta tradotta dal file di lingua).
INSERT IGNORE INTO treasury_accounts (slug, label, is_builtin, is_active, sort_order) VALUES
    ('cash', NULL, 1, 1, 10),
    ('bank', NULL, 1, 1, 20),
    ('postal', NULL, 1, 1, 30),
    ('paypal', NULL, 1, 1, 40),
    ('prepaid_card', NULL, 1, 1, 50),
    ('other', NULL, 1, 1, 900);
