-- “The current values are correct”: a per-movement, per-field acknowledgement that silences a
-- classification anomaly until the current value or the typical value changes again.
CREATE TABLE IF NOT EXISTS treasury_classification_acknowledgements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_id INT UNSIGNED NOT NULL,
    field VARCHAR(32) NOT NULL,
    current_value VARCHAR(190) NOT NULL DEFAULT '',
    typical_value VARCHAR(190) NOT NULL DEFAULT '',
    user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tca_movement_field (movement_id, field),
    KEY idx_tca_user (user_id),
    CONSTRAINT fk_tca_movement FOREIGN KEY (movement_id) REFERENCES treasury_movements (id) ON DELETE CASCADE,
    CONSTRAINT fk_tca_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
