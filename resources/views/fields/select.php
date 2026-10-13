<?php
use Socly\Support\Fields;

/** @var list<array{value:string,label:string,selected?:bool,disabled?:bool,attrs?:array}>|array<string,string> $options */
$rawOptions = is_array($options ?? null) ? $options : [];
$normalized = [];
$isList = array_is_list($rawOptions);
foreach ($rawOptions as $k => $opt) {
    if (is_array($opt)) {
        $normalized[] = [
            'value' => (string) ($opt['value'] ?? ''),
            'label' => (string) ($opt['label'] ?? $opt['value'] ?? ''),
            'disabled' => !empty($opt['disabled']),
            'attrs' => is_array($opt['attrs'] ?? null) ? $opt['attrs'] : [],
        ];
        continue;
    }
    if ($isList) {
        $normalized[] = ['value' => (string) $opt, 'label' => (string) $opt, 'disabled' => false, 'attrs' => []];
    } else {
        $normalized[] = ['value' => (string) $k, 'label' => (string) $opt, 'disabled' => false, 'attrs' => []];
    }
}
$emptyLabel = array_key_exists('empty_label', get_defined_vars()) ? (string) $empty_label : '—';
$includeEmpty = !isset($include_empty) || (bool) $include_empty;
$current = is_string($value) ? $value : (is_scalar($value ?? null) ? (string) $value : '');

$attrs = array_merge([
    'id' => $id !== '' ? $id : null,
    'name' => $name !== '' ? $name : null,
    'class' => trim('sf-field__control ' . $input_class),
    'required' => $required,
    'disabled' => $disabled,
], $attrs);

/** @var list<array{label:string,options:list<array<string,mixed>>}> $optionGroups */
$optionGroups = [];
if (!empty($groups) && is_array($groups)) {
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $groupOptions = [];
        foreach (($group['options'] ?? []) as $opt) {
            if (is_array($opt)) {
                $groupOptions[] = [
                    'value' => (string) ($opt['value'] ?? ''),
                    'label' => (string) ($opt['label'] ?? $opt['value'] ?? ''),
                    'disabled' => !empty($opt['disabled']),
                    'attrs' => is_array($opt['attrs'] ?? null) ? $opt['attrs'] : [],
                ];
                continue;
            }
            $groupOptions[] = [
                'value' => (string) $opt,
                'label' => (string) $opt,
                'disabled' => false,
                'attrs' => [],
            ];
        }
        $optionGroups[] = [
            'label' => (string) ($group['label'] ?? ''),
            'options' => $groupOptions,
        ];
    }
}

/** @var list<array{value:string,label:string,disabled?:bool,attrs?:array}> $trailingOptions */
$trailingOptions = [];
if (!empty($trailing_options) && is_array($trailing_options)) {
    foreach ($trailing_options as $k => $opt) {
        if (is_array($opt)) {
            $trailingOptions[] = [
                'value' => (string) ($opt['value'] ?? ''),
                'label' => (string) ($opt['label'] ?? $opt['value'] ?? ''),
                'disabled' => !empty($opt['disabled']),
                'attrs' => is_array($opt['attrs'] ?? null) ? $opt['attrs'] : [],
            ];
            continue;
        }
        if (array_is_list($trailing_options)) {
            $trailingOptions[] = ['value' => (string) $opt, 'label' => (string) $opt, 'disabled' => false, 'attrs' => []];
        } else {
            $trailingOptions[] = ['value' => (string) $k, 'label' => (string) $opt, 'disabled' => false, 'attrs' => []];
        }
    }
}

$modifier = trim((string) ($modifier ?? ''));
echo view_partial('fields/_shell_open', array_merge(get_defined_vars(), ['modifier' => $modifier]));
?>
<select<?= Fields::attrs($attrs) ?>>
    <?php if ($includeEmpty): ?>
        <option value=""><?= e($emptyLabel) ?></option>
    <?php endif; ?>
    <?php if ($optionGroups !== []): ?>
        <?php foreach ($optionGroups as $group): ?>
            <optgroup label="<?= e($group['label']) ?>">
                <?php foreach ($group['options'] as $opt): ?>
                    <option
                        value="<?= e($opt['value']) ?>"
                        <?= ((string) $opt['value'] === $current) ? 'selected' : '' ?>
                        <?= !empty($opt['disabled']) ? 'disabled' : '' ?>
                        <?= Fields::attrs($opt['attrs']) ?>
                    ><?= e($opt['label']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
        <?php foreach ($trailingOptions as $opt): ?>
            <option
                value="<?= e($opt['value']) ?>"
                <?= ((string) $opt['value'] === $current) ? 'selected' : '' ?>
                <?= !empty($opt['disabled']) ? 'disabled' : '' ?>
                <?= Fields::attrs($opt['attrs']) ?>
            ><?= e($opt['label']) ?></option>
        <?php endforeach; ?>
    <?php else: ?>
        <?php foreach ($normalized as $opt): ?>
            <option
                value="<?= e($opt['value']) ?>"
                <?= ((string) $opt['value'] === $current) ? 'selected' : '' ?>
                <?= !empty($opt['disabled']) ? 'disabled' : '' ?>
                <?= Fields::attrs($opt['attrs']) ?>
            ><?= e($opt['label']) ?></option>
        <?php endforeach; ?>
    <?php endif; ?>
</select>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
