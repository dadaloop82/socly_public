<?php
use Socly\Support\Fields;

$icon = $icon !== '' ? $icon : 'hash';
if ($placeholder === '') {
    $placeholder = __('members.cf_hint');
}
$showGenerate = !isset($show_generate) || (bool) $show_generate;
$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'type' => 'text',
    'value' => is_string($value) ? $value : '',
    'class' => trim('sf-field__control ' . $input_class),
    'placeholder' => $placeholder,
    'autocomplete' => 'off',
    'maxlength' => '16',
    'size' => isset($size) ? (string) (int) $size : '18',
    'pattern' => (string) ($pattern ?? '[A-Za-z0-9]{16}'),
    'data-fiscal-code' => true,
    'data-incomplete' => __('members.cf_incomplete'),
    'data-gender-other' => __('members.cf_gender_other'),
    'required' => $required,
    'disabled' => $disabled,
], $attrs);

echo view_partial('fields/_shell_open', get_defined_vars());
?>
<div class="cf-row sf-field__cf-row">
    <input<?= Fields::attrs($attrs) ?>>
    <?php if ($showGenerate): ?>
        <button type="button" class="btn btn-ghost" data-cf-generate>
            <span class="field-icon" data-icon="spark" aria-hidden="true"></span>
            <?= e(__('members.cf_generate')) ?>
        </button>
    <?php endif; ?>
</div>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
