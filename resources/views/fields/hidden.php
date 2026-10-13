<?php
use Socly\Support\Fields;

$attrs = array_merge([
    'type' => 'hidden',
    'name' => $name,
    'id' => $id !== '' ? $id : null,
    'value' => is_string($value) ? $value : (is_scalar($value ?? null) ? (string) $value : ''),
], $attrs);
?>
<input<?= Fields::attrs($attrs) ?>>
