-- Document visibility, expiry, links; deadline recurrence; appointment minutes ref

ALTER TABLE association_documents
    ADD COLUMN visibility ENUM('reserved','internal','public') NOT NULL DEFAULT 'internal' AFTER status,
    ADD COLUMN expires_at DATE NULL AFTER document_date,
    ADD COLUMN member_id INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN sibling_document_id INT UNSIGNED NULL AFTER member_id,
    ADD INDEX idx_doc_visibility (visibility),
    ADD INDEX idx_doc_expires (expires_at),
    ADD INDEX idx_doc_member (member_id);

ALTER TABLE association_documents
    MODIFY COLUMN status ENUM('draft','pending_approval','approved','signed','archived','cancelled') NOT NULL DEFAULT 'draft';

ALTER TABLE association_documents
    ADD CONSTRAINT fk_doc_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL;

ALTER TABLE deadline_items
    ADD COLUMN recurrence ENUM('none','monthly','yearly') NOT NULL DEFAULT 'none' AFTER status,
    ADD COLUMN assignee_role VARCHAR(40) NULL AFTER member_id,
    ADD COLUMN notify_days VARCHAR(40) NULL AFTER notes;

ALTER TABLE association_people
    ADD COLUMN appointment_minutes VARCHAR(190) NULL AFTER mandate_ends_at;
