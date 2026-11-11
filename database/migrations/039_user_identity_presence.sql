-- Identità dell'operatore: nome, cognome (per pallini/notifiche di presenza) e, in alternativa,
-- il collegamento al socio di riferimento (stesso schema usato dalle cariche sociali).
SET @fn_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'first_name');
SET @fn_sql := IF(@fn_exists = 0,
    'ALTER TABLE users ADD COLUMN first_name VARCHAR(80) NULL AFTER name',
    'SELECT 1');
PREPARE fn_stmt FROM @fn_sql; EXECUTE fn_stmt; DEALLOCATE PREPARE fn_stmt;

SET @ln_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_name');
SET @ln_sql := IF(@ln_exists = 0,
    'ALTER TABLE users ADD COLUMN last_name VARCHAR(80) NULL AFTER first_name',
    'SELECT 1');
PREPARE ln_stmt FROM @ln_sql; EXECUTE ln_stmt; DEALLOCATE PREPARE ln_stmt;

SET @mid_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'member_id');
SET @mid_sql := IF(@mid_exists = 0,
    'ALTER TABLE users ADD COLUMN member_id INT UNSIGNED NULL AFTER last_name, ADD INDEX idx_users_member (member_id)',
    'SELECT 1');
PREPARE mid_stmt FROM @mid_sql; EXECUTE mid_stmt; DEALLOCATE PREPARE mid_stmt;

-- Presenza: una riga per sessione aperta. `ended_at` valorizzato al logout, `last_seen` aggiornato
-- dal polling del client: oltre 5 minuti senza heartbeat l'utente è mostrato come inattivo.
CREATE TABLE IF NOT EXISTS user_presence (
    session_id VARCHAR(128) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    connected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (session_id),
    KEY idx_user_presence_user (user_id),
    KEY idx_user_presence_seen (last_seen),
    CONSTRAINT fk_user_presence_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
