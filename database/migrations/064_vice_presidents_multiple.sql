-- More than one vice president is allowed: association_people stores one row per office, and the
-- role must not be flagged as unique. Some early installations carried is_unique = 1.
UPDATE association_roles
SET is_unique = 0
WHERE `key` = 'vice_president' AND is_unique <> 0;