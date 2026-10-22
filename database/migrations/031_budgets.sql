-- Bilancio preventivo per anno sociale + imputazione dei movimenti di prima cassa.
--
-- Un'associazione ha un solo preventivo per anno (`budgets.fiscal_year` unico).
-- Il consuntivo non è una tabella separata: è la somma dei movimenti di cassa
-- imputati alle voci (`treasury_movements.budget_item_id`) — così non esiste un
-- doppio dato da mantenere allineato.
CREATE TABLE IF NOT EXISTS budgets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fiscal_year SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(160) NULL,
    status ENUM('draft','approved') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    approved_at DATETIME NULL,
    approved_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_budget_year (fiscal_year),
    INDEX idx_budget_status (status),
    CONSTRAINT fk_budget_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_budget_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sezioni espandibili del preventivo (Spese di funzionamento, Attività culturali, Entrate…).
CREATE TABLE IF NOT EXISTS budget_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    budget_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_budget_sections_budget (budget_id),
    CONSTRAINT fk_budget_sections_budget FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voci di preventivo: `category` collega la voce alle categorie della prima cassa,
-- così il confronto e i suggerimenti di imputazione restano coerenti.
CREATE TABLE IF NOT EXISTS budget_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    budget_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NULL,
    direction ENUM('income','expense') NOT NULL DEFAULT 'expense',
    label VARCHAR(190) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    category VARCHAR(80) NULL,
    notes VARCHAR(500) NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_budget_items_budget (budget_id),
    INDEX idx_budget_items_section (section_id),
    INDEX idx_budget_items_category (category),
    CONSTRAINT fk_budget_items_budget FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_items_section FOREIGN KEY (section_id) REFERENCES budget_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imputazione facoltativa del movimento a una voce di preventivo (centro di costo).
ALTER TABLE treasury_movements
    ADD COLUMN budget_item_id INT UNSIGNED NULL AFTER category,
    ADD INDEX idx_treasury_budget_item (budget_item_id),
    ADD CONSTRAINT fk_treasury_budget_item FOREIGN KEY (budget_item_id) REFERENCES budget_items(id) ON DELETE SET NULL;
