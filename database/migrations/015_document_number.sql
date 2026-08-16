ALTER TABLE association_documents
    ADD COLUMN document_number VARCHAR(80) NULL AFTER title,
    ADD INDEX idx_doc_number (document_number);
