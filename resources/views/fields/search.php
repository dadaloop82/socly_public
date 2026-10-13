<?php
$input_type = 'search';
$autocomplete = $autocomplete !== '' ? $autocomplete : 'off';
echo view_partial('fields/text', array_merge(get_defined_vars(), [
    'input_type' => 'search',
    'autocomplete' => $autocomplete,
]));
