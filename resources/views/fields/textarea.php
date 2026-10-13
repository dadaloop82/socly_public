<?php
use Socly\Support\Fields;

$rows = isset($rows) ? max(1, (int) $rows) : 3;
$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'class' => trim('sf-field__control ' . $input_class),
    'rows' => (string) $rows,
    'placeholder' => $placeholder !== '' ? $placeholder : null,
    'required' => $required,
    'disabled' => $disabled,
    'readonly' => $readonly,
    'maxlength' => isset($maxlength) ? (string) (int) $maxlength : null,
], $attrs);
$text = is_string($value) ? $value : '';

if (!empty($bare)) {
    echo '<textarea' . Fields::attrs($attrs) . '>' . e($text) . '</textarea>';
    return;
}

echo view_partial('fields/_shell_open', get_defined_vars());
?>
<textarea<?= Fields::attrs($attrs) ?>><?= e($text) ?></textarea>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
