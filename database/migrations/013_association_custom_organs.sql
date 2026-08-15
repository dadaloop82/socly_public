-- Custom org-chart organs (blocks below auditors)

ALTER TABLE association_roles
    ADD COLUMN custom_label VARCHAR(120) NULL AFTER label_key,
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;

UPDATE association_roles SET is_system = 1 WHERE `key` IN (
    'president', 'vice_president', 'secretary', 'treasurer', 'board', 'auditor'
);
