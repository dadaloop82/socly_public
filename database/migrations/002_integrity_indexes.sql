-- Performance and integrity indexes (lessons from legacy late-added indexes)

CREATE INDEX idx_members_status ON members (status);
CREATE INDEX idx_members_balance_due ON members (balance_due);
CREATE INDEX idx_members_type ON members (member_type_id);
CREATE INDEX idx_members_period ON members (membership_period_id);
CREATE INDEX idx_payments_member ON payments (member_id);
CREATE INDEX idx_payments_created ON payments (created_at);
CREATE INDEX idx_mfv_value_prefix ON member_field_values (field_definition_id, value(64));
CREATE INDEX idx_audit_entity ON audit_logs (entity_type, entity_id);
CREATE INDEX idx_audit_created ON audit_logs (created_at);
