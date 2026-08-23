<?php
/** @var array{name:string,fiscal_code:string,address:string} $association */
/** @var list<array<string,mixed>> $rows */
/** @var string $generated_at */
/** @var int $total */
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('members.registry_title')) ?> — <?= e($association['name'] ?: 'SOCLY') ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.45 "Segoe UI", system-ui, sans-serif; color: #111; }
        .sheet { max-width: 1100px; margin: 0 auto; }
        .toolbar { display: flex; gap: 0.75rem; margin-bottom: 1rem; }
        .toolbar button { border: 1px solid #ccc; background: #f7f7f7; border-radius: 8px; padding: 0.45rem 0.85rem; cursor: pointer; font: inherit; }
        header { border-bottom: 2px solid #111; padding-bottom: 0.75rem; margin-bottom: 1rem; }
        header h1 { margin: 0 0 0.35rem; font-size: 1.2rem; text-transform: uppercase; letter-spacing: 0.04em; }
        header p { margin: 0.15rem 0; color: #444; }
        h2 { margin: 0 0 0.75rem; font-size: 1rem; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #999; padding: 0.35rem 0.45rem; vertical-align: top; }
        th { background: #efefef; text-align: left; }
        .meta { margin-top: 1rem; color: #666; font-size: 12px; }
        .legal { margin-top: 1rem; font-size: 11px; color: #555; }
        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="toolbar">
        <button type="button" onclick="window.print()"><?= e(__('members.registry_print')) ?></button>
        <button type="button" onclick="window.close()"><?= e(__('common.cancel')) ?></button>
    </div>
    <header>
        <h1><?= e(__('members.registry_title')) ?></h1>
        <p><strong><?= e($association['name'] ?: '—') ?></strong></p>
        <?php if ($association['address'] !== ''): ?><p><?= e($association['address']) ?></p><?php endif; ?>
        <?php if ($association['fiscal_code'] !== ''): ?><p><?= e(__('members.enrollment_form_fiscal')) ?>: <?= e($association['fiscal_code']) ?></p><?php endif; ?>
    </header>
    <h2><?= e(__('members.registry_subtitle', ['count' => (string) $total])) ?></h2>
    <table>
        <thead>
        <tr>
            <th><?= e(__('members.registry_col_progressive')) ?></th>
            <th><?= e(__('members.member_number')) ?></th>
            <th><?= e(__('members.registry_col_name')) ?></th>
            <th><?= e(__('members.registry_col_fiscal')) ?></th>
            <th><?= e(__('members.registry_col_applied')) ?></th>
            <th><?= e(__('members.registry_col_admitted')) ?></th>
            <th><?= e(__('members.type')) ?></th>
            <th><?= e(__('members.status')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= (int) $row['progressive'] ?></td>
                <td><?= e($row['member_number']) ?></td>
                <td><?= e(trim($row['last_name'] . ' ' . $row['first_name'])) ?></td>
                <td><?= e($row['fiscal_code'] !== '' ? $row['fiscal_code'] : '—') ?></td>
                <td><?= e($row['applied_at'] ?: '—') ?></td>
                <td><?= e($row['admitted_at'] ?: '—') ?></td>
                <td><?= e($row['type_label']) ?></td>
                <td><?= e($row['status_label']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="meta"><?= e(__('members.registry_generated', ['date' => $generated_at])) ?></p>
    <p class="legal"><?= e(__('members.registry_legal_note')) ?></p>
</div>
</body>
</html>
