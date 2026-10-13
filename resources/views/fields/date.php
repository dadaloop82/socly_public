<?php
use Socly\Support\Fields;

$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'type' => 'date',
    'value' => is_string($value) ? $value : '',
    'class' => trim('sf-field__control ' . $input_class),
    'required' => $required,
    'disabled' => $disabled,
    'readonly' => $readonly,
    'min' => isset($min) ? (string) $min : null,
    'max' => isset($max) ? (string) $max : null,
], $attrs);

echo view_partial('fields/_shell_open', get_defined_vars());
?>
<input<?= Fields::attrs($attrs) ?>>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
