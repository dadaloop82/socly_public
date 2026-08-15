-- Identity fields for association people (needed for codice fiscale)

ALTER TABLE association_people
    ADD COLUMN birth_date DATE NULL AFTER fiscal_code,
    ADD COLUMN gender VARCHAR(1) NULL AFTER birth_date,
    ADD COLUMN birth_place VARCHAR(120) NULL AFTER gender;
