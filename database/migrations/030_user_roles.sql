-- Ruoli operatore: il ruolo è un'etichetta stabile (presidente, tesoriere, segreteria,
-- consiglio, volontario) che accompagna i permessi granulari già presenti in
-- user_permissions. NULL = ruolo personalizzato / non assegnato.
ALTER TABLE users
    ADD COLUMN role VARCHAR(40) NULL AFTER locale,
    ADD INDEX idx_users_role (role);
