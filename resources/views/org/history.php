<?php
/** @var list<array> $history */
/** @var bool $canEdit */
$canEdit = !empty($canEdit);
$roleLabel = static function (array $row): string {
    $custom = trim((string) ($row['custom_label'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }
    return __((string) ($row['label_key'] ?? ('association.role_' . ($row['role_key'] ?? ''))));
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('org.history_title')) ?></h1>
        <p class="page-lede"><?= e(__('org.history_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/org')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>

<div class="panel">
    <?php if ($history === []): ?>
        <div class="empty-state">
            <strong><?= e(__('org.history_empty')) ?></strong>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                    <tr>
                        <th><?= e(__('org.mandate')) ?></th>
                        <th><?= e(__('setup.field_first_name')) ?></th>
                        <th><?= e(__('setup.field_last_name')) ?></th>
                        <th><?= e(__('setup.field_fiscal_code')) ?></th>
                        <th><?= e(__('setup.field_appointed_at')) ?></th>
                        <th><?= e(__('setup.field_mandate_ends_at')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr>
                            <td><?= e($roleLabel($row)) ?></td>
                            <td><?= e((string) ($row['first_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['last_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['fiscal_code'] ?? '')) ?></td>
                            <td><?= e(format_date($row['appointed_at'] ?? null) ?: '—') ?></td>
                            <td><?= e(format_date($row['mandate_ends_at'] ?? null) ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
