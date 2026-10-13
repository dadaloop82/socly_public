<?php
use Socly\Support\Fields;

$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'type' => 'file',
    'class' => trim('sf-field__control sf-field__control--file ' . $input_class),
    'accept' => isset($accept) ? (string) $accept : null,
    'required' => $required,
    'disabled' => $disabled,
    'multiple' => !empty($multiple),
], $attrs);

$buttonLabel = (string) ($button_label ?? __('common.choose_file'));
$hiddenInput = !isset($as_button) || (bool) $as_button;
$buttonClass = trim((string) ($button_class ?? 'btn btn-sm btn-ghost file-btn sf-field__file-btn'));
$pickLabelAttrs = is_array($pick_label_attrs ?? null) ? $pick_label_attrs : [];

echo view_partial('fields/_shell_open', get_defined_vars());
if ($hiddenInput): ?>
    <label class="<?= e($buttonClass) ?>">
        <?php if ($icon !== ''): ?>
            <span class="field-icon" data-icon="<?= e($icon !== '' ? $icon : 'upload') ?>" aria-hidden="true"></span>
        <?php elseif ($pickLabelAttrs === []): ?>
            <span class="field-icon" data-icon="upload" aria-hidden="true"></span>
        <?php endif; ?>
        <?php if ($pickLabelAttrs !== []): ?>
            <span<?= Fields::attrs($pickLabelAttrs) ?>><?= e($buttonLabel) ?></span>
        <?php else: ?>
            <?= e($buttonLabel) ?>
        <?php endif; ?>
        <input<?= Fields::attrs(array_merge($attrs, ['hidden' => true])) ?>>
    </label>
<?php else: ?>
    <input<?= Fields::attrs($attrs) ?>>
<?php endif; ?>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
