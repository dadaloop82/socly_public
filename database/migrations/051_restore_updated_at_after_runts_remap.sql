-- Repair the “last modified” moment of the movements touched by migration 050.
--
-- 050 remapped the old Model D codes inside `treasury_movements`. The column
-- `updated_at` has ON UPDATE CURRENT_TIMESTAMP, so MySQL bumped it for every remapped row
-- even though no operator (and no audit row) was involved. The record trail then fell back
-- to the row timestamp and printed “Modified on …” without an author.
--
-- Restore `updated_at` from the last audit row of the movement (or from `created_at` when the
-- record has no audit history). Rows with a real audit entry at/after the migration time keep
-- their current timestamp, so a genuine modification is never overwritten.

SET @runts_remap_at := (
    SELECT ran_at FROM migrations
    WHERE migration = '050_runts_official_model_lines.sql'
    LIMIT 1
);

UPDATE treasury_movements m
LEFT JOIN (
    SELECT entity_id, MAX(created_at) AS last_at
    FROM audit_logs
    WHERE entity_type = 'treasury'
    GROUP BY entity_id
) a ON a.entity_id = m.id
SET m.updated_at = COALESCE(a.last_at, m.created_at)
WHERE @runts_remap_at IS NOT NULL
  AND m.updated_at IS NOT NULL
  AND m.updated_at >= DATE_SUB(@runts_remap_at, INTERVAL 10 MINUTE)
  AND m.updated_at > COALESCE(a.last_at, m.created_at);
