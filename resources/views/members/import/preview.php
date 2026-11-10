<?php
/**
 * Import soci — step 3: anteprima e analisi (nuovi, aggiornamenti, errori).
 *
 * @var array<string,mixed> $analysis
 */
$stats = is_array($analysis['stats'] ?? null) ? $analysis['stats'] : [];
$rows = is_array($analysis['rows'] ?? null) ? $analysis['rows'] : [];
$gaps = is_array($analysis['gaps'] ?? null) ? $analysis['gaps'] : ['types' => [], 'fields' => [], 'methods' => [], 'statuses' => []];
$gapsCount = count($gaps['types']) + count($gaps['fields']) + count($gaps['methods']) + count($gaps['statuses']);
$actionBadge = static function (string $action): array {
    return match ($action) {
        'create' => ['badge-ok', 'members.import.action_create'],
        'update' => ['badge-warn', 'members.import.action_update'],
        'error' => ['badge-due', 'members.import.action_error'],
        default => ['badge-muted', 'members.import.action_skip'],
    };
};
$gapLabels = static function (array $gapsList, string $key): string {
    return implode(', ', array_map(static fn (array $gap): string => (string) ($gap[$key] ?? ''), $gapsList));
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.import.step_preview_title')) ?></h1>
        <p class="page-lede"><?= e(__('members.import.step_preview_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/members/import/map')) ?>"><?= e(__('members.import.back_map')) ?></a>
    </div>
</div>

<?= view_partial('members/import/steps', ['current' => 3]) ?>

<div class="stats import-stats">
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_total')) ?></div>
        <div class="value"><?= (int) ($stats['total'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_create')) ?></div>
        <div class="value"><?= (int) ($stats['create'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_update')) ?></div>
        <div class="value"><?= (int) ($stats['update'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_skip')) ?></div>
        <div class="value"><?= (int) ($stats['skip'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_error')) ?></div>
        <div class="value"><?= (int) ($stats['error'] ?? 0) ?></div>
    </div>
</div>

<?php if ($gapsCount > 0): ?>
    <div class="alert alert-warn import-gaps-alert">
        <strong><?= e(__('members.import.gaps_found', ['count' => (string) $gapsCount])) ?></strong>
        <ul>
            <?php if ($gaps['types'] !== []): ?>
                <li><?= e(__('members.import.gaps_types', ['count' => (string) count($gaps['types'])])) ?>:
                    <?= e($gapLabels($gaps['types'], 'label')) ?></li>
            <?php endif; ?>
            <?php if ($gaps['fields'] !== []): ?>
                <li><?= e(__('members.import.gaps_fields', ['count' => (string) count($gaps['fields'])])) ?>:
                    <?= e($gapLabels($gaps['fields'], 'header')) ?></li>
            <?php endif; ?>
            <?php if ($gaps['methods'] !== []): ?>
                <li><?= e(__('members.import.gaps_methods', ['count' => (string) count($gaps['methods'])])) ?>:
                    <?= e($gapLabels($gaps['methods'], 'label')) ?></li>
            <?php endif; ?>
            <?php if ($gaps['statuses'] !== []): ?>
                <li><?= e(__('members.import.gaps_statuses', ['count' => (string) count($gaps['statuses'])])) ?>:
                    <?= e($gapLabels($gaps['statuses'], 'label')) ?></li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.import.preview_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.import.preview_lede', [
                'shown' => (string) count($rows),
                'total' => (string) (int) ($analysis['rows_total'] ?? 0),
            ])) ?></p>
        </div>
    </header>
    <div class="table-wrap embedded">
        <table class="import-preview-table">
            <thead>
            <tr>
                <th><?= e(__('members.import.col_line')) ?></th>
                <th><?= e(__('members.import.col_action')) ?></th>
                <th><?= e(__('members.import.col_member')) ?></th>
                <th><?= e(__('members.import.col_details')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                [$badgeClass, $badgeLabel] = $actionBadge((string) ($row['action'] ?? 'skip'));
                $values = is_array($row['values'] ?? null) ? $row['values'] : [];
                $resolved = is_array($row['resolved'] ?? null) ? $row['resolved'] : [];
                $errors = is_array($row['errors'] ?? null) ? $row['errors'] : [];
                $warnings = is_array($row['warnings'] ?? null) ? $row['warnings'] : [];
                $diff = is_array($row['diff'] ?? null) ? $row['diff'] : [];
                ?>
                <tr>
                    <td class="import-line"><?= (int) ($row['line'] ?? 0) ?></td>
                    <td>
                        <span class="badge <?= e($badgeClass) ?>"><?= e(__($badgeLabel)) ?></span>
                        <?php if ((string) ($row['reason'] ?? '') !== ''): ?>
                            <div class="muted import-reason"><?= e((string) $row['reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e((string) ($row['member_label'] ?? '')) ?></strong>
                        <?php if ((int) ($row['member_id'] ?? 0) > 0): ?>
                            <div class="muted"><?= e(__('members.import.existing_member', ['id' => (string) (int) $row['member_id']])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="import-details muted">
                            <?php if ((string) ($resolved['member_number'] ?? '') !== ''): ?>
                                <span><?= e(__('members.import.target_member_number')) ?>:
                                    <strong><?= e((string) $resolved['member_number']) ?></strong></span>
                            <?php endif; ?>
                            <?php if ((string) ($values['sys:member_type_id'] ?? '') !== ''): ?>
                                <span><?= e(__('members.import.target_member_type')) ?>:
                                    <strong><?= e((string) $values['sys:member_type_id']) ?></strong></span>
                            <?php endif; ?>
                            <?php if (array_key_exists('sys:balance_due', $values)): ?>
                                <span><?= e(__('members.import.target_balance_due')) ?>:
                                    <strong><?= e((string) $values['sys:balance_due']) ?></strong></span>
                            <?php endif; ?>
                            <?php if (array_key_exists('sys:paid_total', $values)): ?>
                                <span><?= e(__('members.import.target_paid_total')) ?>:
                                    <strong><?= e((string) $values['sys:paid_total']) ?></strong></span>
                            <?php endif; ?>
                            <?php if ($diff !== []): ?>
                                <span><?= e(__('members.import.diff_count', ['count' => (string) count($diff)])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($diff !== []): ?>
                            <details class="import-diff">
                                <summary><?= e(__('members.import.diff_show')) ?></summary>
                                <ul>
                                    <?php foreach ($diff as $field => $change): ?>
                                        <li>
                                            <strong><?= e((string) $field) ?></strong>:
                                            <span class="muted"><?= e((string) ($change['old'] ?? '')) ?></span>
                                            → <span><?= e((string) ($change['new'] ?? '')) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                        <?php if ($errors !== []): ?>
                            <ul class="import-errors">
                                <?php foreach ($errors as $message): ?>
                                    <li><?= e((string) $message) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($warnings !== []): ?>
                            <ul class="import-warnings muted">
                                <?php foreach ($warnings as $message): ?>
                                    <li><?= e((string) $message) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/members/import/map')) ?>"><?= e(__('members.import.back_map')) ?></a>
        <a class="btn" href="<?= e(url('/members/import/references')) ?>"><?= e(__('members.import.references_submit')) ?></a>
    </div>
</section>
