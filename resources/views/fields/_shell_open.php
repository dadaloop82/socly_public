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
 * Opens a standard field wrapper + optional label.
 *
 * Expects normalized props from Fields::normalizeProps plus:
 * @var string $modifier Extra BEM modifier classes on the wrapper
 * @var string $data_attrs Extra data-* already as HTML string (optional)
 */
use Socly\Support\Fields;

$type = (string) ($type ?? 'text');
$context = (string) ($context ?? 'default');
$modifier = trim((string) ($modifier ?? ''));
$wrapperClass = Fields::wrapperClass($context, (string) ($wrapper_class ?? ''), $modifier);
$id = (string) ($id ?? '');
$label = (string) ($label ?? '');
$labelClass = trim((string) ($label_class ?? ''));
$labelAttrs = is_array($label_attrs ?? null) ? $label_attrs : [];
$labelExtra = (string) ($label_extra ?? '');
$required = !empty($required);
$icon = (string) ($icon ?? '');
$dataField = (string) ($data_field ?? '');
$requiredFlag = isset($required_flag) ? (string) $required_flag : ($required ? '1' : '0');

$wrapAttrs = [
    'class' => $wrapperClass,
    'data-sf-field' => $type,
];
if ($dataField !== '') {
    $wrapAttrs['data-field'] = $dataField;
}
if ($required || $dataField !== '') {
    $wrapAttrs['data-field-required'] = $requiredFlag;
}
if (!empty($wrap_attrs) && is_array($wrap_attrs)) {
    $wrapAttrs = array_merge($wrapAttrs, $wrap_attrs);
}
?>
<div<?= Fields::attrs($wrapAttrs) ?>>
<?php if ($label !== '' || $labelExtra !== ''): ?>
    <label class="field-label sf-field__label<?= $labelClass !== '' ? ' ' . e($labelClass) : '' ?>"<?= $id !== '' ? ' for="' . e($id) . '"' : '' ?><?= Fields::attrs($labelAttrs) ?>>
        <?php if ($icon !== ''): ?>
            <span class="field-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
        <?php endif; ?>
        <?= e($label) ?><?= $required ? ' *' : '' ?>
        <?= $labelExtra ?>
    </label>
<?php endif; ?>
