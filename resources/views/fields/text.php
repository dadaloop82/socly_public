<?php
/** @var array<string,mixed> Normalized via Fields::render */
use Socly\Support\Fields;

$inputType = (string) ($input_type ?? 'text');
$attrs = array_merge([
    'id' => $id !== '' ? $id : null,
    'name' => $name !== '' ? $name : null,
    'type' => $inputType,
    'value' => is_string($value) ? $value : '',
    'class' => trim('sf-field__control ' . $input_class),
    'placeholder' => $placeholder !== '' ? $placeholder : null,
    'autocomplete' => $autocomplete !== '' ? $autocomplete : null,
    'required' => $required,
    'disabled' => $disabled,
    'readonly' => $readonly,
    'maxlength' => isset($maxlength) ? (string) (int) $maxlength : null,
    'minlength' => isset($minlength) ? (string) (int) $minlength : null,
    'pattern' => isset($pattern) ? (string) $pattern : null,
    'inputmode' => isset($inputmode) ? (string) $inputmode : null,
    'min' => isset($min) ? (string) $min : null,
    'max' => isset($max) ? (string) $max : null,
    'step' => isset($step) ? (string) $step : null,
    'size' => isset($size) ? (string) (int) $size : null,
    'list' => isset($list) ? (string) $list : null,
], $attrs);

echo view_partial('fields/_shell_open', get_defined_vars());
?>
<input<?= Fields::attrs($attrs) ?>>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
