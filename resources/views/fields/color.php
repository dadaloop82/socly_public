<?php
use Socly\Support\Fields;

$hexValue = is_string($value) && $value !== '' ? $value : '#0D6E66';
$setupBrand = ($variant ?? '') === 'setup_brand' || !empty($setup_brand);
$brandKey = (string) ($brand_key ?? ($name !== '' ? $name : 'primary'));

if ($setupBrand) {
    $cardClass = trim('setup-field setup-color-picker-card ' . $wrapper_class);
    $inputAttrs = array_merge([
        'name' => $name,
        'type' => 'color',
        'value' => $hexValue,
        'required' => $required,
        'disabled' => $disabled,
        'data-brand-color' => $brandKey,
        'class' => trim('sf-field__control sf-field__control--color ' . $input_class),
    ], $attrs);
    ?>
    <label class="<?= e($cardClass) ?>">
        <span><?= e($label) ?></span>
        <span class="setup-color-picker-control">
            <input<?= Fields::attrs($inputAttrs) ?>>
            <code><?= e(strtoupper($hexValue)) ?></code>
        </span>
    </label>
    <?php
    return;
}

$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'type' => 'color',
    'value' => $hexValue,
    'class' => trim('sf-field__control sf-field__control--color ' . $input_class),
    'required' => $required,
    'disabled' => $disabled,
], $attrs);

echo view_partial('fields/_shell_open', get_defined_vars());
?>
<input<?= Fields::attrs($attrs) ?>>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
