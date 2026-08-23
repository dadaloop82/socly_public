-- Link junior/minor members to a guardian (parent/tutor) member record.
ALTER TABLE members
    ADD COLUMN guardian_member_id INT UNSIGNED NULL AFTER member_type_id;

ALTER TABLE members
    ADD CONSTRAINT fk_member_guardian
        FOREIGN KEY (guardian_member_id) REFERENCES members(id) ON DELETE SET NULL;
