-- Field-level presence, part 2: the value the operator is typing in the field they hold. It lets the
-- other operators see the draft live and keep that field locked until the colleague leaves it.
-- The value is only a short, transient preview: it is overwritten by every heartbeat and cleared when
-- the field is released (and by the daily housekeeping).
SET @value_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_presence' AND column_name = 'editing_value'
);

SET @value_sql := IF(
    @value_exists = 0,
    'ALTER TABLE user_presence ADD COLUMN editing_value VARCHAR(500) NULL AFTER editing_field',
    'SELECT 1'
);

PREPARE value_stmt FROM @value_sql;
EXECUTE value_stmt;
DEALLOCATE PREPARE value_stmt;
