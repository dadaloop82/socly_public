ALTER TABLE treasury_movements
    ADD COLUMN amount_currency CHAR(3) NULL AFTER amount,
    ADD COLUMN amount_entered DECIMAL(12,2) NULL AFTER amount_currency,
    ADD COLUMN invoice_date DATE NULL AFTER invoice_number,
    ADD COLUMN invoice_due_date DATE NULL AFTER invoice_date;
