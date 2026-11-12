-- Kind of document behind the movement (invoice, receipt, contract, quittance, insurance premium, ...).
-- It is independent from `invoice_payment`: a receipt or a contract is useful on incoming entries too,
-- while a transfer has no document at all.
SET @doc_type_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'treasury_movements' AND column_name = 'document_type'
);

SET @doc_type_sql := IF(
    @doc_type_exists = 0,
    'ALTER TABLE treasury_movements ADD COLUMN document_type VARCHAR(24) NULL AFTER invoice_title',
    'SELECT 1'
);

PREPARE doc_type_stmt FROM @doc_type_sql;
EXECUTE doc_type_stmt;
DEALLOCATE PREPARE doc_type_stmt;
