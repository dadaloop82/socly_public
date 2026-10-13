<?php
/**
 * Password with show/hide — delegates to canonical password_input partial,
 * wrapped in sf-field for consistent spacing when a label is provided externally.
 */
$props = [
    'name' => $name,
    'id' => $id !== '' ? $id : null,
    'label' => $label,
    'required' => $required,
    'value' => is_string($value) ? $value : '',
    'placeholder' => $placeholder,
    'autocomplete' => $autocomplete !== '' ? $autocomplete : 'current-password',
    'minlength' => $minlength ?? null,
    'wrapper_class' => trim('sf-field password-field ' . $wrapper_class . ($context === 'setup' ? ' setup-field' : '')),
    'input_attrs' => (string) ($input_attrs ?? ''),
    'hint_attrs' => (string) ($hint_attrs ?? ''),
];
if ($hint !== '' && ($props['hint_attrs'] ?? '') === '') {
    // password_input supports optional hint via hint_attrs on a <p>; keep simple echo after.
}
echo view_partial('partials/password_input', $props);
if ($hint !== ''): ?>
    <p class="muted sf-field__hint"><?= e($hint) ?></p>
<?php endif; ?>
