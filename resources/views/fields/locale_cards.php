<?php
/**
 * Locale picker as flag radio cards (login / setup / settings / users).
 *
 * options: list of {value, label, flag?: url, attrs?: array}
 * Or pass locales => ['it','de','en'] and labels are resolved automatically.
 */
use Socly\Support\Fields;

$rawOptions = is_array($options ?? null) ? $options : [];
if ($rawOptions === [] && !empty($locales) && is_array($locales)) {
    foreach ($locales as $code) {
        $code = (string) $code;
        $rawOptions[] = [
            'value' => $code,
            'label' => match ($code) {
                'it' => __('members.lang_it'),
                'de' => __('members.lang_de'),
                'en' => __('members.lang_en'),
                default => strtoupper($code),
            },
            'flag' => function_exists('locale_flag_url')
                ? locale_flag_url($code)
                : (function_exists('dial_flag_url')
                    ? dial_flag_url(match ($code) {
                        'it' => 'IT',
                        'de' => 'DE',
                        'en' => 'GB',
                        default => 'UN',
                    })
                    : ''),
        ];
    }
}

$normalized = [];
foreach ($rawOptions as $k => $opt) {
    if (is_array($opt)) {
        $normalized[] = [
            'value' => (string) ($opt['value'] ?? ''),
            'label' => (string) ($opt['label'] ?? ''),
            'flag' => (string) ($opt['flag'] ?? ''),
            'attrs' => is_array($opt['attrs'] ?? null) ? $opt['attrs'] : [],
            'label_attrs' => is_array($opt['label_attrs'] ?? null) ? $opt['label_attrs'] : [],
        ];
    } else {
        $normalized[] = [
            'value' => (string) $k,
            'label' => (string) $opt,
            'flag' => '',
            'attrs' => [],
            'label_attrs' => [],
        ];
    }
}

$current = is_string($value) ? $value : (is_scalar($value ?? null) ? (string) $value : '');
$gridClass = trim('setup-locale-grid sf-field__locale-cards ' . (string) ($grid_class ?? ''));
$cardClass = trim('setup-locale-card ' . (string) ($card_class ?? ''));
$inputAttrsBase = is_array($attrs ?? null) ? $attrs : [];

if ($label !== '') {
    echo view_partial('fields/_shell_open', array_merge(get_defined_vars(), [
        'modifier' => trim(($modifier ?? '') . ' sf-field--locale-cards'),
    ]));
} else {
    echo '<div class="' . e(Fields::wrapperClass($context, $wrapper_class, 'sf-field--locale-cards')) . '" data-sf-field="locale_cards">';
}
?>
<div class="<?= e($gridClass) ?>" role="radiogroup"<?= $label !== '' ? ' aria-label="' . e($label) . '"' : '' ?>>
    <?php foreach ($normalized as $i => $opt): ?>
        <?php
        $selected = ((string) $opt['value'] === $current);
        $rAttrs = array_merge([
            'type' => 'radio',
            'name' => $name,
            'value' => $opt['value'],
            'checked' => $selected,
            'required' => $required && $i === 0,
            'disabled' => $disabled,
        ], $inputAttrsBase, $opt['attrs']);
        ?>
        <label class="<?= e($cardClass) ?><?= $selected ? ' is-selected' : '' ?>">
            <input<?= Fields::attrs($rAttrs) ?>>
            <?php if ($opt['flag'] !== ''): ?>
                <img class="setup-locale-flag" src="<?= e($opt['flag']) ?>" alt="" width="28" height="20" loading="lazy">
            <?php endif; ?>
            <span class="setup-locale-label"<?= Fields::attrs($opt['label_attrs']) ?>><?= e($opt['label']) ?></span>
        </label>
    <?php endforeach; ?>
</div>
<?php
if ($label !== '') {
    echo view_partial('fields/_shell_close', get_defined_vars());
} else {
    echo '</div>';
}
