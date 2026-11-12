<?php
/**
 * First-note CSV import step indicator (1 upload … 4 report).
 *
 * @var int $current
 */
$current = (int) ($current ?? 1);
$steps = [
    1 => 'treasury.import.step_upload',
    2 => 'treasury.import.step_map',
    3 => 'treasury.import.step_normalize',
    4 => 'treasury.import.step_preview',
    5 => 'treasury.import.step_report',
];
?>
<ol class="import-steps">
    <?php foreach ($steps as $index => $key): ?>
        <li class="import-step<?= $index === $current ? ' is-current' : '' ?><?= $index < $current ? ' is-done' : '' ?>">
            <span class="import-step-index"><?= (int) $index ?></span>
            <span class="import-step-label"><?= e(__($key)) ?></span>
        </li>
    <?php endforeach; ?>
</ol>
