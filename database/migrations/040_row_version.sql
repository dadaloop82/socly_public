-- Concorrenza: versione di riga per le modifiche in contemporanea da più operatori.
-- `updateVersioned()` tocca la riga solo se la versione attesa coincide, così due salvataggi
-- simultanei non si sovrascrivono silenziosamente.
SET @t := 'members';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE members ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := 'treasury_movements';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE treasury_movements ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := 'budgets';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE budgets ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := 'budget_items';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE budget_items ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := 'association_documents';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE association_documents ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := 'deadline_items';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE deadline_items ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @t := 'association_people';
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'row_version');
SET @s := IF(@c = 0, 'ALTER TABLE association_people ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Presenza: cosa sta modificando ciascun operatore (“Mario Rossi sta modificando questa scheda”).
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_presence' AND column_name = 'editing_entity');
SET @s := IF(@c = 0, 'ALTER TABLE user_presence ADD COLUMN editing_entity VARCHAR(40) NULL, ADD COLUMN editing_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
