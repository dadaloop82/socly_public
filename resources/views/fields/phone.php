<?php
/**
 * International phone — wraps the canonical phone control.
 */
use Socly\Support\Fields;

$modifier = trim(($modifier ?? '') . ($input_class !== '' ? ' ' . $input_class : ''));
echo view_partial('fields/_shell_open', array_merge(get_defined_vars(), ['modifier' => $modifier]));
echo view_partial('partials/phone_field', [
    'name' => $name,
    'value' => is_string($value) ? $value : '',
    'required' => $required,
    'id' => $id,
    'class' => trim('sf-field__phone ' . $input_class),
    'default_dial' => (string) ($default_dial ?? '39'),
]);
echo view_partial('fields/_shell_close', get_defined_vars());
