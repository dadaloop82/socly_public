-- Opening balances are a property of the wallet, not a cash-book movement.
--
-- The “Saldo iniziale” typed in Configuration → Financial accounts is the money the account already
-- held before Socly: it belongs to the wallet balance (cash desk / bank) but it must never appear in
-- the ledger, in the income/expense flows or in the reports. Existing rows created by older releases
-- (income movements flagged `is_opening_balance`) are converted here and removed from the cash book.
CREATE TABLE IF NOT EXISTS treasury_account_openings (
    account VARCHAR(40) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    updated_by INT UNSIGNED NULL,
    PRIMARY KEY (account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO treasury_account_openings (account, amount, updated_at)
SELECT TRIM(account), SUM(amount), NOW()
  FROM treasury_movements
 WHERE is_opening_balance = 1
   AND superseded_at IS NULL
   AND account IS NOT NULL
   AND TRIM(account) <> ''
 GROUP BY TRIM(account)
ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_at = VALUES(updated_at);

DELETE FROM treasury_movements WHERE is_opening_balance = 1;
