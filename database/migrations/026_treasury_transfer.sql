-- Cash book register: transfer between accounts (third variant: neither entry nor exit). `direction =
-- 'transfer'` remains outside the balance/income/expense aggregates (the sums use explicit CASE WHEN on
-- income/expense), while `transfer_method` records the destination account (`payment_method` = starting
-- account).
ALTER TABLE treasury_movements
    MODIFY COLUMN direction ENUM('income','expense','transfer') NOT NULL,
    ADD COLUMN transfer_method VARCHAR(40) NULL AFTER payment_method,
    ADD INDEX idx_treasury_transfer_method (transfer_method);
