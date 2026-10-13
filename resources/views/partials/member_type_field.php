<?php
/**
 * Atomic member-type field (name / price / active) for setup membership editors.
 *
 * @var string $kind name|price|active|active_hidden
 * @var string $name
 * @var string $value
 * @var bool $required
 * @var bool $disabled
 * @var bool $checked
 * @var string $langCode for name fields (it|de|en)
 * @var string $currencyDisplay
 * @var string $typeNameRef for price data-type-name-ref
 * @var string $placeholder
 */
$kind = (string) ($kind ?? 'name');
$name = (string) ($name ?? '');
$value = (string) ($value ?? '');
$required = !empty($required);
$disabled = !empty($disabled);
$checked = !empty($checked);
$langCode = (string) ($langCode ?? '');
$currencyDisplay = (string) ($currencyDisplay ?? '€');
$typeNameRef = (string) ($typeNameRef ?? '');
$placeholder = (string) ($placeholder ?? '');

if ($kind === 'name') {
    $attrs = [];
    if ($langCode !== '') {
        $attrs['data-type-name-' . $langCode] = true;
    }
    echo field('text', [
        'name' => $name,
        'label' => '',
        'value' => $value,
        'required' => $required,
        'context' => 'inline',
        'wrapper_class' => 'sf-field--type-name-inline',
        'placeholder' => $placeholder,
        'attrs' => $attrs,
    ]);
    return;
}

if ($kind === 'price') {
    $priceAttrs = ['data-type-price' => true, 'inputmode' => 'decimal'];
    if ($typeNameRef !== '') {
        $priceAttrs['data-type-name-ref'] = $typeNameRef;
    }
    echo field('number', [
        'name' => $name,
        'label' => '',
        'value' => $value,
        'required' => $required,
        'context' => 'inline',
        'wrapper_class' => 'sf-field--price-inline',
        'step' => '0.01',
        'min' => 0,
        'placeholder' => $placeholder !== '' ? $placeholder : '0.00',
        'attrs' => $priceAttrs,
    ]);
    return;
}

if ($kind === 'active_hidden') {
    echo field('hidden', ['name' => $name, 'value' => '1']);
    return;
}

echo field('checkbox', [
    'name' => $name,
    'label' => (string) __('settings.is_active'),
    'checked' => $checked,
    'disabled' => $disabled,
    'context' => 'setup',
    'label_class' => 'checkbox-row setup-check setup-check-inline setup-check-prominent',
]);
