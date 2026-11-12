-- Cash book register: separate the "Financial account" (where the money is: cash register, bank, postal
-- account...) from the "Payment method" (how it moved: bank transfer, POS...). `transfer_method` (arrival
-- account, introduced in 026) becomes `transfer_account`.
ALTER TABLE treasury_movements
    ADD COLUMN account VARCHAR(40) NULL AFTER payment_method,
    ADD INDEX idx_treasury_account (account);

ALTER TABLE treasury_movements
    CHANGE COLUMN transfer_method transfer_account VARCHAR(40) NULL;

-- Backfill: Old "account/method" values ​​end up in the right financial account.
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

-- "Internal" movements are no longer a payment method: the transfer now has its own dedicated direction, so
-- these legacy values ​​become "other".
UPDATE treasury_movements
   SET payment_method = 'other'
 WHERE payment_method IN ('internal_transfer', 'offset_compensation');

UPDATE treasury_movements
   SET transfer_account = NULL
 WHERE transfer_account IN ('internal_transfer', 'offset_compensation');
