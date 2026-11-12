-- Budget estimate for financial year + allocation of cash book movements. An association has only one
-- estimate per year (single `budgets.fiscal_year`). The final balance is not a separate table: it is the sum
-- of the cash movements attributed to the items (`treasury_movements.budget_item_id`) - so there is no double
-- data to keep aligned.
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

-- Expandable sections of the estimate (Operating expenses, Cultural activities, Income...).
CREATE TABLE IF NOT EXISTS budget_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    budget_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_budget_sections_budget (budget_id),
    CONSTRAINT fk_budget_sections_budget FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Budget items: `category` links the item to the cash book categories, so the comparison and charging
-- suggestions remain consistent.
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

-- Optional allocation of the movement to a budget item (cost center).
ALTER TABLE treasury_movements
    ADD COLUMN budget_item_id INT UNSIGNED NULL AFTER category,
    ADD INDEX idx_treasury_budget_item (budget_item_id),
    ADD CONSTRAINT fk_treasury_budget_item FOREIGN KEY (budget_item_id) REFERENCES budget_items(id) ON DELETE SET NULL;
