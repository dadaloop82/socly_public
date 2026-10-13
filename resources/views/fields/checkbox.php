<?php
use Socly\Support\Fields;

$checkValue = (string) ($check_value ?? '1');
$current = is_string($value) ? $value : (is_scalar($value ?? null) ? (string) $value : '');
$checked = !empty($checked)
    || $current === '1'
    || $current === 'on'
    || $current === 'yes'
    || $current === $checkValue;

$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'type' => 'checkbox',
    'value' => $checkValue,
    'class' => trim('sf-field__control sf-field__control--check ' . $input_class),
    'checked' => $checked,
    'required' => $required,
    'disabled' => $disabled,
], $attrs);

// Bare mode: only the <input> (for custom card/label chrome that wraps the control).
if (!empty($bare)) {
    echo '<input' . Fields::attrs($attrs) . '>';
    return;
}

$modifier = trim(($modifier ?? '') . ' sf-field--checkbox');
$wrapperClass = Fields::wrapperClass($context, $wrapper_class, $modifier);
$wrapAttrs = ['class' => $wrapperClass, 'data-sf-field' => 'checkbox'];
if ($data_field !== '') {
    $wrapAttrs['data-field'] = $data_field;
}
if (!empty($wrap_attrs) && is_array($wrap_attrs)) {
    $wrapAttrs = array_merge($wrapAttrs, $wrap_attrs);
}
?>
<div<?= Fields::attrs($wrapAttrs) ?>>
    <label class="<?= e(trim('checkbox-row field-label sf-field__check-label ' . (string) ($label_class ?? ''))) ?>"<?= $id !== '' ? ' for="' . e($id) . '"' : '' ?>>
        <input<?= Fields::attrs($attrs) ?>>
        <span><?= e($label) ?><?= $required ? ' *' : '' ?></span>
    </label>
    <?php if ($hint !== ''): ?>
        <p class="muted sf-field__hint"><?= e($hint) ?></p>
    <?php endif; ?>
</div>
