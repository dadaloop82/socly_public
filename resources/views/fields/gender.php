<?php
$options = [
    ['value' => 'M', 'label' => __('members.gender_m')],
    ['value' => 'F', 'label' => __('members.gender_f')],
];
if (!empty($include_other)) {
    $options[] = ['value' => 'X', 'label' => __('members.gender_x')];
}
$attrs = array_merge(['data-gender-input' => true], is_array($attrs ?? null) ? $attrs : []);
$icon = $icon !== '' ? $icon : 'users';

echo field('select', array_merge(get_defined_vars(), [
    'options' => $options,
    'attrs' => $attrs,
    'icon' => $icon,
    'modifier' => 'field-block--gender sf-field--gender',
    'wrapper_class' => trim($wrapper_class . ' field-block--gender'),
    'empty_label' => (string) ($empty_label ?? '—'),
    'include_empty' => !isset($include_empty) || (bool) $include_empty,
]));
