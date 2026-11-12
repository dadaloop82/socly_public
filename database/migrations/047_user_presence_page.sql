-- Presence, part 3: the page the operator is on, so a click on their badge opens exactly that screen
-- even when they are not editing a versioned record (lists, dashboard, settings, …). Only the path and
-- the hash are stored (never the query string), and the link is rebuilt server-side behind the same
-- permission/component flag as the page itself.
SET @page_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'user_presence' AND column_name = 'page_path'
);

SET @page_sql := IF(
    @page_exists = 0,
    'ALTER TABLE user_presence ADD COLUMN page_path VARCHAR(255) NULL AFTER editing_value',
    'SELECT 1'
);

PREPARE page_stmt FROM @page_sql;
EXECUTE page_stmt;
DEALLOCATE PREPARE page_stmt;
