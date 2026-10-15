-- Prima cassa: giroconto tra conti (terza variante: né entrata né uscita).
-- `direction = 'transfer'` resta fuori dagli aggregati di saldo/entrate/uscite
-- (le somme usano CASE WHEN espliciti su income/expense), mentre `transfer_method`
-- registra il conto di destinazione (`payment_method` = conto di partenza).
ALTER TABLE treasury_movements
    MODIFY COLUMN direction ENUM('income','expense','transfer') NOT NULL,
    ADD COLUMN transfer_method VARCHAR(40) NULL AFTER payment_method,
    ADD INDEX idx_treasury_transfer_method (transfer_method);
