<?php
/**
 * Import first note — step 4: dry-run preview (nothing is written until confirmation).
 *
 * @var array{stats:array{total:int,create:int,skip:int,error:int},rows:list<array<string,mixed>>,shown:int,total_rows:int} $analysis
 * @var string $sourceName
 * @var \Socly\Services\CurrencyService $currency
 */
$stats = is_array($analysis['stats'] ?? null) ? $analysis['stats'] : ['total' => 0, 'create' => 0, 'skip' => 0, 'error' => 0];
$rows = is_array($analysis['rows'] ?? null) ? $analysis['rows'] : [];
$shown = (int) ($analysis['shown'] ?? count($rows));
$totalRows = (int) ($analysis['total_rows'] ?? $stats['total']);
$createCount = (int) ($stats['create'] ?? 0);
$errorCount = (int) ($stats['error'] ?? 0);
$actionBadge = static function (string $action): array {
    return match ($action) {
        'create' => ['badge-ok', 'treasury.import.action_create'],
        'skip' => ['badge-muted', 'treasury.import.action_skip'],
        default => ['badge-due', 'treasury.import.action_error'],
    };
};
$directionLabel = static function (string $direction): string {
    return match ($direction) {
        'income' => (string) __('treasury.direction_sign_income'),
        'expense' => (string) __('treasury.direction_sign_expense'),
        default => (string) __('treasury.direction_sign_transfer'),
    };
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.import.step_preview_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.import.step_preview_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury/import/normalize')) ?>"><?= e(__('treasury.import.back_normalize')) ?></a>
        <?= page_action_icon([
            'icon' => 'sheet',
            'label' => (string) __('treasury.import.preview_download_report'),
            'href' => url('/treasury/import/check'),
        ]) ?>
    </div>
</div>

<?= view_partial('treasury/import/steps', ['current' => 4]) ?>

<p class="alert alert-info import-loaded">
    <strong><?= e(__('treasury.import.preview_dry_run_title')) ?></strong>
    <span class="import-loaded-hint"><?= e(__('treasury.import.preview_dry_run_lede')) ?></span>
</p>

<div class="stats import-stats">
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.stat_total')) ?></div>
        <div class="value"><?= (int) ($stats['total'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.stat_create')) ?></div>
        <div class="value"><?= $createCount ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.stat_skip')) ?></div>
        <div class="value"><?= (int) ($stats['skip'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.stat_error')) ?></div>
        <div class="value"><?= (int) ($stats['error'] ?? 0) ?></div>
    </div>
</div>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.import.preview_title')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.import.preview_lede', [
                'file' => $sourceName,
                'rows' => (string) $totalRows,
            ])) ?></p>
        </div>
    </header>
    <div class="table-wrap embedded">
        <table class="import-preview-table">
            <thead>
            <tr>
                <th><?= e(__('treasury.import.col_line')) ?></th>
                <th><?= e(__('treasury.csv_movement_date')) ?></th>
                <th><?= e(__('treasury.csv_direction')) ?></th>
                <th><?= e(__('treasury.csv_amount')) ?></th>
                <th><?= e(__('treasury.csv_category')) ?></th>
                <th><?= e(__('treasury.csv_account')) ?></th>
                <th><?= e(__('treasury.import.col_status')) ?></th>
                <th><?= e(__('treasury.import.col_notes')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $action = (string) ($row['action'] ?? 'error');
                [$badgeClass, $badgeKey] = $actionBadge($action);
                $errors = is_array($row['errors'] ?? null) ? $row['errors'] : [];
                $warnings = is_array($row['warnings'] ?? null) ? $row['warnings'] : [];
                ?>
                <tr>
                    <td class="import-line"><?= (int) ($row['line'] ?? 0) ?></td>
                    <td><?= e(format_date((string) ($row['date'] ?? '')) ?: '—') ?></td>
                    <td><?= e($directionLabel((string) ($row['direction'] ?? ''))) ?></td>
                    <td><?= e($currency->format((float) ($row['amount'] ?? 0))) ?></td>
                    <td><?= e((string) ($row['category'] ?? '')) ?></td>
                    <td>
                        <?= e((string) ($row['account'] ?? '')) ?>
                        <?php if ((string) ($row['transfer_account'] ?? '') !== ''): ?>
                            → <?= e((string) $row['transfer_account']) ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= e($badgeClass) ?>"><?= e(__($badgeKey)) ?></span></td>
                    <td>
                        <?php if ((string) ($row['description'] ?? '') !== ''): ?>
                            <div><?= e((string) $row['description']) ?></div>
                        <?php endif; ?>
                        <?php if ((string) ($row['member'] ?? '') !== ''): ?>
                            <div class="muted"><?= e(__('treasury.member')) ?>: <?= e((string) $row['member']) ?></div>
                        <?php endif; ?>
                        <?php if ((string) ($row['invoice_number'] ?? '') !== '' || (string) ($row['invoice_date'] ?? '') !== ''): ?>
                            <div class="muted">
                                <?= e(__('treasury.invoice_number')) ?>:
                                <?= e((string) ($row['invoice_number'] ?? '—')) ?>
                                <?php if ((string) ($row['invoice_date'] ?? '') !== ''): ?>
                                    · <?= e(__('treasury.invoice_date')) ?>: <?= e(format_date((string) $row['invoice_date']) ?: (string) $row['invoice_date']) ?>
                                <?php endif; ?>
                            </div>
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
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="8" class="muted import-preview-empty"><?= e(__('treasury.import.preview_empty')) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($shown < $totalRows): ?>
        <p class="muted import-raw-hint"><?= e(__('treasury.import.preview_more', [
            'shown' => (string) $shown,
            'total' => (string) $totalRows,
        ])) ?></p>
    <?php endif; ?>
    <?php if ($errorCount > 0): ?>
        <p class="alert alert-warn">
            <?= e(__('treasury.import.preview_errors_block', ['count' => (string) $errorCount])) ?>
        </p>
    <?php endif; ?>
    <?php if ($createCount === 0): ?>
        <p class="alert alert-warn"><?= e(__('treasury.import.preview_nothing')) ?></p>
    <?php endif; ?>
    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/treasury/import/normalize')) ?>"><?= e(__('treasury.import.back_normalize')) ?></a>
        <form method="post" action="<?= e(url('/treasury/import/commit')) ?>">
            <?= csrf_field() ?>
            <?php if ($errorCount > 0): ?>
                <label class="import-flag import-confirm-errors">
                    <input type="checkbox" name="confirm_errors" value="1" required>
                    <span><?= e(__('treasury.import.preview_confirm_errors', ['count' => (string) $errorCount])) ?></span>
                </label>
            <?php endif; ?>
            <button class="btn" type="submit" <?= $createCount === 0 ? 'disabled' : '' ?>><?= e(__('treasury.import.preview_confirm_import')) ?></button>
        </form>
    </div>
</section>
