-- Natura della posta di preventivo ("tipo"), separata dal segno contabile `direction`:
-- una spesa d'investimento resta una spesa, un contributo vincolato resta un'entrata.
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

-- Le voci esistenti ereditano il tipo dal segno già salvato.
UPDATE budget_items SET kind = direction WHERE kind = '' OR kind IS NULL;
