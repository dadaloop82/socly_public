-- Link org-chart persons to member records (active members only at assign time).

ALTER TABLE association_people
    ADD COLUMN member_id INT UNSIGNED NULL AFTER role_key;

ALTER TABLE association_people
    ADD INDEX idx_association_people_member (member_id);

ALTER TABLE association_people
    ADD CONSTRAINT fk_association_people_member
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL;
