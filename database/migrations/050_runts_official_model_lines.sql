-- RUNTS Model D (DM 18 April 2020): align the stored line codes with the official rows of the
-- ministerial workbook (CSV Emilia). The old internal taxonomy was a simplified subset: section A
-- income used its own numbering, B income had a single generic line and the investments lived in
-- section D. The code now writes the official codes, so existing rows are remapped once here.
--
-- The CASE works on the old value; mappings that would overwrite each other (A-I2 → A-I8 and
-- A-I8 → A-I10) are resolved in a single statement, so no row is remapped twice.

UPDATE treasury_movements
SET runts_line = CASE
    -- A) income: old numbering → official numbering (rows 1–10 of the workbook).
    WHEN UPPER(TRIM(runts_line)) = 'A-I2' THEN 'A-I8'
    WHEN UPPER(TRIM(runts_line)) = 'A-I3' THEN 'A-I6'
    WHEN UPPER(TRIM(runts_line)) = 'A-I4' THEN 'A-I9'
    WHEN UPPER(TRIM(runts_line)) = 'A-I5' THEN 'A-I7'
    WHEN UPPER(TRIM(runts_line)) = 'A-I6' THEN 'A-I4'
    WHEN UPPER(TRIM(runts_line)) = 'A-I7' THEN 'A-I5'
    WHEN UPPER(TRIM(runts_line)) = 'A-I8' THEN 'A-I10'
    -- B) income: the old generic “proventi da attività diverse” is now “prestazioni e cessioni a terzi”.
    WHEN UPPER(TRIM(runts_line)) = 'B-I1' THEN 'B-I3'
    -- General taxes stored under the old “other management costs” line move to general support.
    WHEN UPPER(TRIM(runts_line)) = 'A-E5' AND category = 'taxes' THEN 'E-E5'
    -- D) expenses/incomes and the investments.
    WHEN UPPER(TRIM(runts_line)) = 'D-E1' THEN 'INV-E1'
    WHEN UPPER(TRIM(runts_line)) = 'D-E2' THEN 'INV-E3'
    WHEN UPPER(TRIM(runts_line)) = 'D-E3' AND category = 'member_loan' THEN 'INV-E4'
    WHEN UPPER(TRIM(runts_line)) = 'D-E3' AND category = 'bank_fees' THEN 'D-E1'
    WHEN UPPER(TRIM(runts_line)) = 'D-E3' THEN 'D-E5'
    WHEN UPPER(TRIM(runts_line)) = 'D-I2' AND category = 'security_deposit' THEN 'INV-I3'
    WHEN UPPER(TRIM(runts_line)) = 'D-I2' THEN 'D-I3'
    WHEN UPPER(TRIM(runts_line)) = 'D-I3' AND category = 'member_loan' THEN 'INV-I4'
    WHEN UPPER(TRIM(runts_line)) = 'D-I3' THEN 'D-I5'
    -- E) income: the old generic support line is now “altre entrate di supporto generale”.
    WHEN UPPER(TRIM(runts_line)) = 'E-I1' THEN 'E-I2'
    ELSE runts_line
END
WHERE UPPER(TRIM(runts_line)) IN (
    'A-I2', 'A-I3', 'A-I4', 'A-I5', 'A-I6', 'A-I7', 'A-I8',
    'A-E5',
    'B-I1',
    'D-E1', 'D-E2', 'D-E3',
    'D-I2', 'D-I3',
    'E-I1'
);
