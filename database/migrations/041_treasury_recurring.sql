-- Operazioni ricorrenti della prima nota (affitto, utenze, quote rateizzate, …).
-- Alla scadenza il sistema crea il movimento: da approvare se il conto non è la cassa.
CREATE TABLE IF NOT EXISTS treasury_recurring (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction VARCHAR(16) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_variable TINYINT(1) NOT NULL DEFAULT 0,
    category VARCHAR(80) NULL,
    description VARCHAR(500) NULL,
    account VARCHAR(40) NULL,
    transfer_account VARCHAR(40) NULL,
    payment_method VARCHAR(40) NULL,
    beneficiary VARCHAR(190) NULL,
    member_id INT UNSIGNED NULL,
    budget_item_id INT UNSIGNED NULL,
    runts_line VARCHAR(48) NULL,
    frequency VARCHAR(16) NOT NULL DEFAULT 'monthly',
    day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
    next_run_date DATE NOT NULL,
    last_run_date DATE NULL,
    ends_on DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_recurring_due (is_active, next_run_date),
    CONSTRAINT fk_recurring_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_recurring_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'recurring_id');
SET @s := IF(@c = 0,
    'ALTER TABLE treasury_movements ADD COLUMN recurring_id INT UNSIGNED NULL AFTER runts_line, ADD INDEX idx_treasury_recurring (recurring_id)',
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
