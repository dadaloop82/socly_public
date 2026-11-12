-- Operator Roles: The role is a stable label (president, treasurer, secretariat, board, volunteer) that
-- accompanies the granular permissions already present in user_permissions. NULL = custom role / unassigned.
ALTER TABLE users
    ADD COLUMN role VARCHAR(40) NULL AFTER locale,
    ADD INDEX idx_users_role (role);
