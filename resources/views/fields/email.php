<?php
$attrs = array_merge([
    'data-email-input' => true,
    'autocomplete' => $autocomplete !== '' ? $autocomplete : 'email',
], is_array($attrs ?? null) ? $attrs : []);
if ($placeholder === '') {
    $placeholder = __('members.email_placeholder');
}
if ($input_class === '') {
    $input_class = 'input-emphasis';
}
$input_type = 'email';
echo view_partial('fields/text', array_merge(get_defined_vars(), [
    'input_type' => 'email',
    'attrs' => $attrs,
    'placeholder' => $placeholder,
    'input_class' => $input_class,
]));
