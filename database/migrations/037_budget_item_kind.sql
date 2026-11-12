-- Nature of the estimate item ("type"), separated by the accounting sign `direction`: an investment expense
-- remains an expense, a restricted contribution remains an income.
SET @kind_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'budget_items' AND column_name = 'kind'
);

SET @kind_sql := IF(
    @kind_exists = 0,
    'ALTER TABLE budget_items ADD COLUMN kind VARCHAR(24) NOT NULL DEFAULT '''' AFTER direction',
    'SELECT 1'
);

PREPARE kind_stmt FROM @kind_sql;
EXECUTE kind_stmt;
DEALLOCATE PREPARE kind_stmt;

-- Existing entries inherit the type from the already saved sign.
UPDATE budget_items SET kind = direction WHERE kind = '' OR kind IS NULL;
