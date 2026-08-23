-- Pending approval status + admission date for official member register (RUNTS).
ALTER TABLE members
    MODIFY status ENUM('pending','active','suspended','expired','cancelled') NOT NULL DEFAULT 'pending';

ALTER TABLE members
    ADD COLUMN admitted_at DATE NULL AFTER status;

UPDATE members
SET admitted_at = DATE(created_at)
WHERE status = 'active' AND admitted_at IS NULL;
