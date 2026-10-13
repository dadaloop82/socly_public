<?php
/**
 * Birth place with suggest + optional foreign toggle.
 * Delegates to canonical geo_birth_place; wraps with sf-field when label provided separately.
 */
$payload = [
    'name' => $name,
    'value' => is_string($value) ? $value : '',
    'required' => $required,
    'id' => $id,
    'class' => trim($input_class . ' ' . $wrapper_class),
    'foreign_toggle' => !isset($foreign_toggle) || (bool) $foreign_toggle,
    'foreign_inline' => !empty($foreign_inline),
    'with_scope' => $with_scope ?? null,
    'flag_name' => (string) ($flag_name ?? 'address_foreign'),
    'foreign_label' => (string) ($foreign_label ?? __('members.birth_foreign')),
];
// When caller passes label, use shell; geo_birth_place already has its own label from setup.field_birth_place.
// Prefer override via $label_key / custom: if label set and use_shell, wrap.
$useShell = !empty($use_shell);
if ($useShell && $label !== '') {
    echo view_partial('fields/_shell_open', get_defined_vars());
    $payload['id'] = $id;
    // Suppress inner label by passing empty - geo_birth_place always shows setup label.
    // For member form we use geo partial directly with its label.
}
echo view_partial('partials/geo_birth_place', $payload);
if ($useShell && $label !== '') {
    echo view_partial('fields/_shell_close', get_defined_vars());
}
