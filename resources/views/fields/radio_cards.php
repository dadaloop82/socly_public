<?php
/**
 * Choice cards (radio) — setup locale / payment / enrollment style.
 *
 * options: list of {value, label, icon?: string(html), description?: string}
 */
use Socly\Support\Fields;

$rawOptions = is_array($options ?? null) ? $options : [];
$normalized = [];
foreach ($rawOptions as $k => $opt) {
    if (is_array($opt)) {
        $normalized[] = [
            'value' => (string) ($opt['value'] ?? ''),
            'label' => (string) ($opt['label'] ?? ''),
            'icon' => (string) ($opt['icon'] ?? ''),
            'description' => (string) ($opt['description'] ?? ''),
        ];
    } else {
        $normalized[] = [
            'value' => (string) $k,
            'label' => (string) $opt,
            'icon' => '',
            'description' => '',
        ];
    }
}
$current = is_string($value) ? $value : (is_scalar($value ?? null) ? (string) $value : '');
$cardClass = trim('tessera-choice-cards sf-field__choice-cards ' . (string) ($cards_class ?? ''));
$modifier = trim(($modifier ?? '') . ' sf-field--choice-cards');
echo view_partial('fields/_shell_open', array_merge(get_defined_vars(), [
    'modifier' => $modifier,
    // Label already rendered; avoid double label on each card.
]));
?>
<div class="<?= e($cardClass) ?>" role="radiogroup"<?= $label !== '' ? ' aria-label="' . e($label) . '"' : '' ?>>
    <?php foreach ($normalized as $i => $opt): ?>
        <?php
        $selected = ((string) $opt['value'] === $current);
        $rAttrs = array_merge([
            'type' => 'radio',
            'name' => $name,
            'value' => $opt['value'],
            'checked' => $selected,
            'required' => $required,
            'disabled' => $disabled,
        ], $attrs);
        ?>
        <label class="tessera-choice-card<?= $selected ? ' is-selected' : '' ?>">
            <input<?= Fields::attrs($rAttrs) ?>>
            <?php if ($opt['icon'] !== ''): ?>
                <span class="tessera-choice-card-icon"><?= $opt['icon'] ?></span>
            <?php endif; ?>
            <span class="tessera-choice-card-title"><?= e($opt['label']) ?></span>
            <?php if ($opt['description'] !== ''): ?>
                <span class="tessera-choice-card-desc muted"><?= e($opt['description']) ?></span>
            <?php endif; ?>
        </label>
    <?php endforeach; ?>
</div>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
