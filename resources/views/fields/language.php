<?php
$current = is_string($value) ? $value : '';
if ($current === '') {
    $sessionLocale = (string) ($_SESSION['locale'] ?? '');
    $fallbackLocale = function_exists('app_locale') ? app_locale() : 'it';
    $current = in_array($sessionLocale, ['it', 'de', 'en'], true)
        ? $sessionLocale
        : (in_array($fallbackLocale, ['it', 'de', 'en'], true) ? $fallbackLocale : 'it');
}
$options = [
    ['value' => 'it', 'label' => __('members.lang_it')],
    ['value' => 'de', 'label' => __('members.lang_de')],
    ['value' => 'en', 'label' => __('members.lang_en')],
    ['value' => 'other', 'label' => __('members.lang_other')],
];
$attrs = array_merge(['data-language-input' => true], is_array($attrs ?? null) ? $attrs : []);
$icon = $icon !== '' ? $icon : 'globe';
$modifier = trim(($modifier ?? '') . ' field-block--language sf-field--language');

echo field('select', array_merge(get_defined_vars(), [
    'value' => $current,
    'options' => $options,
    'attrs' => $attrs,
    'icon' => $icon,
    'modifier' => $modifier,
]));
