ALTER TABLE treasury_movements
    ADD COLUMN invoice_title VARCHAR(190) NULL AFTER beneficiary;
