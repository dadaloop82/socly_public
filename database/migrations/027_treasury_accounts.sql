-- Prima cassa: separa il "Conto finanziario" (dove stanno i soldi: cassa, banca,
-- conto postale…) dal "Metodo di pagamento" (come si sono mossi: bonifico, POS…).
-- `transfer_method` (conto di arrivo, introdotto in 026) diventa `transfer_account`.
ALTER TABLE treasury_movements
    ADD COLUMN account VARCHAR(40) NULL AFTER payment_method,
    ADD INDEX idx_treasury_account (account);

ALTER TABLE treasury_movements
    CHANGE COLUMN transfer_method transfer_account VARCHAR(40) NULL;

-- Backfill: i vecchi valori "conto/metodo" finiscono nel conto finanziario giusto.
UPDATE treasury_movements
   SET account = CASE
        WHEN payment_method = 'cash' THEN 'cash'
        WHEN payment_method = 'paypal' THEN 'paypal'
        WHEN payment_method = 'postal_slip' THEN 'postal'
        WHEN payment_method IN ('bank_transfer', 'bank', 'pos_card', 'pos', 'credit_card', 'check', 'sepa_direct_debit', 'online_gateway', 'satispay') THEN 'bank'
        ELSE account
       END
 WHERE account IS NULL;

UPDATE treasury_movements
   SET transfer_account = CASE
        WHEN transfer_account = 'cash' THEN 'cash'
        WHEN transfer_account = 'paypal' THEN 'paypal'
        WHEN transfer_account = 'postal_slip' THEN 'postal'
        WHEN transfer_account IN ('bank_transfer', 'bank', 'pos_card', 'pos', 'credit_card', 'check', 'sepa_direct_debit', 'online_gateway', 'satispay') THEN 'bank'
        ELSE transfer_account
       END
 WHERE transfer_account IS NOT NULL AND transfer_account <> '';

-- I movimenti "interni" non sono più un metodo di pagamento: il giroconto ha ora
-- la sua direzione dedicata, quindi questi valori legacy diventano "altro".
UPDATE treasury_movements
   SET payment_method = 'other'
 WHERE payment_method IN ('internal_transfer', 'offset_compensation');

UPDATE treasury_movements
   SET transfer_account = NULL
 WHERE transfer_account IN ('internal_transfer', 'offset_compensation');
