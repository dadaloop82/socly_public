-- Default treasury wallets: on a fresh install only "Cassa contanti" and
-- "Banca / Conto corrente" are active. Migration 028 seeded every built-in as
-- active, so this migration also fixes installations upgraded from older
-- releases (the setup step used to pre-select all of them).
--
-- Safety net, the update only touches wallets that are still untouched:
--   * default (translated) label, no custom name;
--   * no movement booked on the wallet and no incoming transfer.
-- A treasurer can re-enable any wallet at any time from the setup wizard or
-- Settings → Conti finanziari.
UPDATE treasury_accounts AS ta
SET ta.is_active = 0
WHERE ta.is_builtin = 1
  AND ta.slug IN ('postal', 'paypal', 'prepaid_card', 'other')
  AND ta.is_active = 1
  AND ta.label IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM treasury_movements m WHERE m.account = ta.slug
  )
  AND NOT EXISTS (
      SELECT 1 FROM treasury_movements m2 WHERE m2.transfer_account = ta.slug
  );
