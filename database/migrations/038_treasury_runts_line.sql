-- RUNTS (DM 18 aprile 2020): voce del Rendiconto per cassa (Modello D) a cui appartiene
-- il movimento di prima cassa, in aggiunta al capitolo interno del preventivo.
SET @runts_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'runts_line'
);

SET @runts_sql := IF(
    @runts_exists = 0,
    'ALTER TABLE treasury_movements ADD COLUMN runts_line VARCHAR(48) NULL AFTER category, ADD INDEX idx_treasury_runts_line (runts_line)',
    'SELECT 1'
);

PREPARE runts_stmt FROM @runts_sql;
EXECUTE runts_stmt;
DEALLOCATE PREPARE runts_stmt;
