/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

ALTER TABLE treasury_movements
    ADD COLUMN invoice_payment TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method,
    ADD COLUMN invoice_number VARCHAR(120) NULL AFTER invoice_payment,
    ADD COLUMN beneficiary VARCHAR(190) NULL AFTER invoice_number,
    ADD COLUMN document_id INT UNSIGNED NULL AFTER attachment_path,
    ADD INDEX idx_treasury_beneficiary (beneficiary),
    ADD INDEX idx_treasury_document (document_id),
    ADD CONSTRAINT fk_treasury_document FOREIGN KEY (document_id) REFERENCES association_documents(id) ON DELETE SET NULL;
