-- Add-ons permission for upgraded installations.
--
-- `addons.manage` was introduced with the add-on layer (048), but the permissions
-- table is seeded only at install time: existing instances never received the row,
-- so the configuration entry and the /settings/addons page stayed hidden even for
-- administrators. Create it and grant it to whoever can already manage settings.

INSERT INTO permissions (`key`, description)
VALUES ('addons.manage', 'Install and manage paid add-ons')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO user_permissions (user_id, permission_id)
SELECT DISTINCT up.user_id, p_addon.id
FROM user_permissions up
INNER JOIN permissions p_settings
        ON p_settings.id = up.permission_id
       AND p_settings.`key` = 'settings.manage'
CROSS JOIN permissions p_addon
WHERE p_addon.`key` = 'addons.manage';
