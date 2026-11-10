-- Tessere associative: numero, QR, emissione, validità e tipologia.
-- Nasce con l'import CSV (ID socio = ID tessera) e resta disponibile per la gestione tessere.

CREATE TABLE IF NOT EXISTS membership_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NULL,
    card_number VARCHAR(50) NOT NULL,
    qr_code VARCHAR(120) NULL,
    member_type_id INT UNSIGNED NULL,
    issued_on DATE NULL,
    expires_on DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_card_number (card_number),
    KEY idx_card_member (member_id),
    KEY idx_card_qr (qr_code),
    KEY idx_card_type (member_type_id),
    CONSTRAINT fk_card_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_card_type FOREIGN KEY (member_type_id) REFERENCES member_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
