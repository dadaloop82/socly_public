<?php
/** @var list<array> $rows */
/** @var string $assocName */
/** @var string $assocLegalCode */
/** @var string $assocLegalLabel */
$assocName = (string) ($assocName ?? 'SOCLY');
$roleLabel = static function (array $row): string {
    $custom = trim((string) ($row['custom_label'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    return __((string) ($row['label_key'] ?? ('association.role_' . ($row['role_key'] ?? ''))));
};
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <title><?= e(__('org.export_officers')) ?> · <?= e($assocName) ?></title>
    <style>
        body { font-family: Georgia, "Times New Roman", serif; color: #111; margin: 2rem; }
        h1 { font-size: 1.4rem; margin: 0 0 0.25rem; }
        .meta { color: #444; margin-bottom: 1.5rem; font-size: 0.95rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 0.45rem 0.55rem; text-align: left; font-size: 0.92rem; }
        th { background: #f3f3f3; }
        .actions { margin-bottom: 1rem; }
        @media print {
            .actions { display: none; }
            body { margin: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()"><?= e(__('org.export_pdf')) ?></button>
        <a href="<?= e(url('/org')) ?>"><?= e(__('common.back')) ?></a>
    </div>
    <h1><?= e(__('org.export_officers')) ?></h1>
    <p class="meta">
        <?= e($assocName) ?>
        <?php if ($assocLegalCode !== ''): ?>
            · <?= e($assocLegalCode) ?><?= $assocLegalLabel !== '' ? ' — ' . e($assocLegalLabel) : '' ?>
        <?php endif; ?>
        · <?= e(format_date(date('Y-m-d'))) ?>
    </p>
    <table>
        <thead>
            <tr>
                <th><?= e(__('org.mandate')) ?></th>
                <th><?= e(__('setup.field_first_name')) ?></th>
                <th><?= e(__('setup.field_last_name')) ?></th>
                <th><?= e(__('setup.field_fiscal_code')) ?></th>
                <th><?= e(__('setup.field_appointed_at')) ?></th>
                <th><?= e(__('setup.field_mandate_ends_at')) ?></th>
                <th><?= e(__('org.appointment_minutes')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($roleLabel($row)) ?></td>
                    <td><?= e((string) ($row['first_name'] ?? '')) ?></td>
                    <td><?= e((string) ($row['last_name'] ?? '')) ?></td>
                    <td><?= e((string) ($row['fiscal_code'] ?? '')) ?></td>
                    <td><?= e(format_date($row['appointed_at'] ?? null) ?: '—') ?></td>
                    <td><?= e(format_date($row['mandate_ends_at'] ?? null) ?: '—') ?></td>
                    <td><?= e((string) ($row['appointment_minutes'] ?? '') ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
