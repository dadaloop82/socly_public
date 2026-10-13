<?php
/**
 * Bare control (no label/shell) — for custom chrome that wraps the input.
 * Prefer typed modules when possible; use this only inside composite cards.
 */
use Socly\Support\Fields;

$inputType = (string) ($input_type ?? 'text');
$attrs = array_merge([
    'type' => $inputType,
    'id' => $id !== '' ? $id : null,
    'name' => $name !== '' ? $name : null,
    'value' => is_string($value) || is_numeric($value) ? (string) $value : null,
    'class' => $input_class !== '' ? $input_class : null,
    'required' => $required,
    'disabled' => $disabled,
    'readonly' => $readonly,
    'placeholder' => $placeholder !== '' ? $placeholder : null,
    'autocomplete' => $autocomplete !== '' ? $autocomplete : null,
], $attrs);

echo '<input' . Fields::attrs($attrs) . '>';
