-- Extended assignable roles for org chart (optional roles beyond statutory organs)

INSERT INTO association_roles (`key`, label_key, hierarchy_level, is_unique, requires_residence, requires_mandate, sort_order, is_system)
VALUES
    ('founding_member', 'association.role_founding_member', 200, 0, 0, 0, 200, 0),
    ('ordinary_member', 'association.role_ordinary_member', 201, 0, 0, 0, 201, 0),
    ('supporting_member', 'association.role_supporting_member', 202, 0, 0, 0, 202, 0),
    ('honorary_member', 'association.role_honorary_member', 203, 0, 0, 0, 203, 0),
    ('member_volunteer', 'association.role_member_volunteer', 210, 0, 0, 0, 210, 0),
    ('volunteer_coordinator', 'association.role_volunteer_coordinator', 211, 0, 0, 0, 211, 0),
    ('membership_manager', 'association.role_membership_manager', 212, 0, 0, 0, 212, 0),
    ('communications_manager', 'association.role_communications_manager', 213, 0, 0, 0, 213, 0),
    ('press_officer', 'association.role_press_officer', 214, 0, 0, 0, 214, 0),
    ('it_referent', 'association.role_it_referent', 215, 0, 0, 0, 215, 0),
    ('events_manager', 'association.role_events_manager', 216, 0, 0, 0, 216, 0),
    ('artistic_director', 'association.role_artistic_director', 217, 0, 0, 0, 217, 0),
    ('sports_director', 'association.role_sports_director', 218, 0, 0, 0, 218, 0),
    ('coach', 'association.role_coach', 219, 0, 0, 0, 219, 0),
    ('ombudsman', 'association.role_ombudsman', 220, 0, 0, 0, 220, 0),
    ('dpo', 'association.role_dpo', 221, 0, 0, 0, 221, 0),
    ('security_manager', 'association.role_security_manager', 222, 0, 0, 0, 222, 0)
ON DUPLICATE KEY UPDATE
    label_key = VALUES(label_key),
    hierarchy_level = VALUES(hierarchy_level),
    is_unique = VALUES(is_unique),
    requires_residence = VALUES(requires_residence),
    requires_mandate = VALUES(requires_mandate),
    sort_order = VALUES(sort_order);

UPDATE association_roles
SET label_key = 'association.role_board_director'
WHERE `key` = 'board';
