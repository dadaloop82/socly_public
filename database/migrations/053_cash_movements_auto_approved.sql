-- The cash desk never waits for the first-note approval: movements on the cash wallet are booked as
-- they happen, so the pending ones are approved now (approved_by stays NULL: nobody signed them,
-- the cash desk did). New cash movements are written already approved by the services; the other
-- wallets keep the approval step.
UPDATE treasury_movements
   SET approved_at = NOW()
 WHERE approved_at IS NULL
   AND superseded_at IS NULL
   AND account = 'cash';
