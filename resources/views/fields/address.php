<?php
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

/**
 * Full address block (city / postcode / province / street / house number).
 */
$payload = [
    'names' => is_array($names ?? null) ? $names : [
        'city' => $name !== '' ? $name : 'city',
        'postal_code' => (string) ($postal_name ?? 'postal_code'),
        'province' => (string) ($province_name ?? 'province'),
        'address' => (string) ($address_name ?? 'address'),
        'house_number' => (string) ($house_name ?? 'house_number'),
    ],
    'values' => is_array($values ?? null) ? $values : (is_array($value) ? $value : []),
    'required' => is_array($required_map ?? null) ? $required_map : [
        'city' => $required,
        'postal_code' => !empty($postal_required),
        'province' => !empty($province_required),
        'address' => !empty($address_required),
        'house_number' => !empty($house_required),
    ],
    'ids' => is_array($ids ?? null) ? $ids : [],
    'enabled' => is_array($enabled ?? null) ? $enabled : [],
    'show_hint' => !isset($show_hint) || (bool) $show_hint,
    'class' => trim('sf-field sf-field--address ' . $wrapper_class),
    'with_scope' => !isset($with_scope) || (bool) $with_scope,
    'layout' => (string) ($layout ?? 'rows'),
    'foreign_toggle' => !isset($foreign_toggle) || (bool) $foreign_toggle,
    'flag_name' => (string) ($flag_name ?? 'address_foreign'),
];
echo view_partial('partials/geo_address', $payload);
