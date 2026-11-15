/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

-- Configurable member-form field steps (wizard panels) + field→step mapping

CREATE TABLE IF NOT EXISTS member_form_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(64) NOT NULL,
    title_json JSON NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_member_form_steps_key (`key`),
    INDEX idx_member_form_steps_sort (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE member_field_definitions
    ADD COLUMN form_step VARCHAR(64) NOT NULL DEFAULT 'profile' AFTER sort_order;

CREATE INDEX idx_mfd_form_step ON member_field_definitions (form_step, sort_order, id);

INSERT INTO member_form_steps (`key`, title_json, sort_order)
SELECT 'profile', JSON_OBJECT(
    'it', 'Anagrafica',
    'de', 'Stammdaten',
    'en', 'Profile'
), 10
WHERE NOT EXISTS (SELECT 1 FROM member_form_steps WHERE `key` = 'profile');

UPDATE member_field_definitions
SET form_step = CASE
    WHEN field_type = 'checkbox' OR `key` IN ('privacy_ack', 'statute_ack') THEN 'acknowledgements'
    ELSE 'profile'
END;
