<?php
use Socly\Support\Fields;

/** @var list<array{value:string,label:string}>|array<string,string> $options */
$rawOptions = is_array($options ?? null) ? $options : [];
$normalized = [];
$isList = array_is_list($rawOptions);
foreach ($rawOptions as $k => $opt) {
    if (is_array($opt)) {
        $normalized[] = [
            'value' => (string) ($opt['value'] ?? ''),
            'label' => (string) ($opt['label'] ?? ''),
        ];
    } elseif ($isList) {
        $normalized[] = ['value' => (string) $opt, 'label' => (string) $opt];
    } else {
        $normalized[] = ['value' => (string) $k, 'label' => (string) $opt];
    }
}
$current = is_string($value) ? $value : (is_scalar($value ?? null) ? (string) $value : '');
$modifier = trim(($modifier ?? '') . ' sf-field--radio');
echo view_partial('fields/_shell_open', array_merge(get_defined_vars(), ['modifier' => $modifier]));
?>
<div class="sf-field__radio-list" role="radiogroup"<?= $label !== '' ? ' aria-label="' . e($label) . '"' : '' ?>>
    <?php foreach ($normalized as $i => $opt): ?>
        <?php
        $oid = $id !== '' ? $id . '-' . $i : Fields::defaultId($name . '-' . $opt['value']);
        $rAttrs = array_merge([
            'id' => $oid,
            'name' => $name,
            'type' => 'radio',
            'value' => $opt['value'],
            'class' => trim('sf-field__control sf-field__control--radio ' . $input_class),
            'checked' => ((string) $opt['value'] === $current),
            'required' => $required && $i === 0,
            'disabled' => $disabled,
        ], $attrs);
        ?>
        <label class="checkbox-row sf-field__radio-label" for="<?= e($oid) ?>">
            <input<?= Fields::attrs($rAttrs) ?>>
            <span><?= e($opt['label']) ?></span>
        </label>
    <?php endforeach; ?>
</div>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
