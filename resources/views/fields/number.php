<?php
$input_type = 'number';
$inputmode = $inputmode ?? 'decimal';
echo view_partial('fields/text', array_merge(get_defined_vars(), [
    'input_type' => 'number',
    'inputmode' => $inputmode,
]));
