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

$attrs = array_merge([
    'data-email-input' => true,
    'autocomplete' => $autocomplete !== '' ? $autocomplete : 'email',
], is_array($attrs ?? null) ? $attrs : []);
if ($placeholder === '') {
    $placeholder = __('members.email_placeholder');
}
if ($input_class === '') {
    $input_class = 'input-emphasis';
}
$input_type = 'email';
echo view_partial('fields/text', array_merge(get_defined_vars(), [
    'input_type' => 'email',
    'attrs' => $attrs,
    'placeholder' => $placeholder,
    'input_class' => $input_class,
]));
