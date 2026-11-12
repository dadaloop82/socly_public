<?php
/**
 * CSV import step indicator (1 upload … 5 report).
 *
 * @var int $current
 */
$current = (int) ($current ?? 1);
$steps = [
    1 => 'members.import.step_upload',
    2 => 'members.import.step_map',
    3 => 'members.import.step_preview',
    4 => 'members.import.step_references',
    5 => 'members.import.step_report',
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
