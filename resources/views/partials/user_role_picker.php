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
 * Operator role picker: choosing a role pre-fills the permission checkboxes
 * (the fine-grained selection stays editable right after).
 *
 * @var string|null $selected
 * @var string $wrapperClass
 */
$selected = \Socly\Services\UserService::normalizeRole((string) ($selected ?? '')) ?? '';
$roleOptions = [[
    'value' => '',
    'label' => (string) __('users.role_custom'),
]];
foreach (\Socly\Services\UserService::roleTemplates() as $tpl) {
    $roleOptions[] = [
        'value' => (string) $tpl['key'],
        'label' => (string) __((string) $tpl['label_key']),
        'attrs' => [
            'data-role-description' => (string) __((string) $tpl['description_key']),
            'data-role-permissions' => (string) json_encode(array_values($tpl['permissions']), JSON_UNESCAPED_UNICODE),
        ],
    ];
}
?>
<div class="user-role-picker<?= !empty($wrapperClass) ? ' ' . e((string) $wrapperClass) : '' ?>">
    <?= field('select', [
        'name' => 'role',
        'label' => (string) __('users.role'),
        'value' => $selected,
        'context' => 'setup',
        'options' => $roleOptions,
        'include_empty' => false,
        'attrs' => ['data-user-role' => true],
        'hint' => (string) __('users.role_hint'),
    ]) ?>
    <p class="user-role-description muted" data-user-role-description<?= $selected === '' ? ' hidden' : '' ?>></p>
</div>