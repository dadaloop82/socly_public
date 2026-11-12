<?php
/**
 * Import first note — step 4: dry-run preview (nothing is written until confirmation).
 * Errors, duplicates and recurring series can be adjusted here: the corrections are saved in the
 * wizard state and re-evaluated, the first note is only touched by the confirmation form.
 *
 * @var array{stats:array{total:int,create:int,skip:int,error:int,ignore:int},rows:list<array<string,mixed>>,recurring:list<array<string,mixed>>,shown:int,total_rows:int} $analysis
 * @var string $sourceName
 * @var \Socly\Services\CurrencyService $currency
 * @var list<array{key:string,label:string,active:bool}> $accounts
 */
$stats = is_array($analysis['stats'] ?? null) ? $analysis['stats'] : ['total' => 0, 'create' => 0, 'skip' => 0, 'error' => 0, 'ignore' => 0];
$rows = is_array($analysis['rows'] ?? null) ? $analysis['rows'] : [];
$series = is_array($analysis['recurring'] ?? null) ? $analysis['recurring'] : [];
$shown = (int) ($analysis['shown'] ?? count($rows));
$totalRows = (int) ($analysis['total_rows'] ?? $stats['total']);
$createCount = (int) ($stats['create'] ?? 0);
$errorCount = (int) ($stats['error'] ?? 0);
$skipCount = (int) ($stats['skip'] ?? 0);
$accountOptions = [];
foreach (($accounts ?? []) as $account) {
    if (!empty($account['active'])) {
        $accountOptions[(string) $account['key']] = (string) $account['label'];
    }
}
$directionOptions = [
    'expense' => 'treasury.direction_sign_expense',
    'income' => 'treasury.direction_sign_income',
    'transfer' => 'treasury.direction_sign_transfer',
];
$documentTypeOptions = [];
foreach (\Socly\Services\TreasuryService::DOCUMENT_TYPES as $documentTypeKey) {
    $documentTypeOptions[(string) $documentTypeKey] = (string) __('treasury.document_type_' . $documentTypeKey);
}
$actionBadge = static function (string $action): array {
    return match ($action) {
        'create' => ['badge-ok', 'treasury.import.action_create'],
        'skip' => ['badge-muted', 'treasury.import.action_skip'],
        'ignore' => ['badge-muted', 'treasury.import.action_ignore'],
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
$hasChoices = $errorCount > 0 || $skipCount > 0 || $series !== [];
foreach ($rows as $row) {
    if (($row['errors'] ?? []) !== [] || !empty($row['fixed']) || !empty($row['ignored'])
        || is_array($row['duplicate'] ?? null)) {
        $hasChoices = true;
        break;
    }
}
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
        <div class="value"><?= $skipCount ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.stat_ignore')) ?></div>
        <div class="value"><?= (int) ($stats['ignore'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.stat_error')) ?></div>
        <div class="value"><?= $errorCount ?></div>
    </div>
</div>

<?php if ($series !== []): ?>
    <section class="panel import-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('treasury.import.recurring_title')) ?></h2>
                <p class="section-lede"><?= e(__('treasury.import.recurring_lede')) ?></p>
            </div>
        </header>
        <ul class="import-recurring-list">
            <?php foreach ($series as $meta): ?>
                <?php $seriesKey = (string) ($meta['key'] ?? ''); ?>
                <?php if ($seriesKey === '') {
                    continue;
                } ?>
                <li class="import-recurring-item">
                    <input type="hidden" name="recurring_keys[]" value="<?= e($seriesKey) ?>">
                    <label class="import-flag">
                        <input type="checkbox" name="recurring[<?= e($seriesKey) ?>]" value="1" <?= !empty($meta['enabled']) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e((string) ($meta['label'] ?? '')) ?></strong>
                            <span class="muted"><?= e(__((string) ($meta['frequency'] ?? '') === 'weekly'
                                ? 'treasury.import.recurring_pattern_weekly'
                                : 'treasury.import.recurring_pattern', [
                                'frequency' => (string) ($meta['frequency_label'] ?? ''),
                                'day' => (string) (int) ($meta['day'] ?? 1),
                                'count' => (string) (int) ($meta['count'] ?? 0),
                                'from' => format_date((string) ($meta['from'] ?? '')) ?: (string) ($meta['from'] ?? ''),
                                'to' => format_date((string) ($meta['to'] ?? '')) ?: (string) ($meta['to'] ?? ''),
                            ])) ?></span>
                            <?php if (!empty($meta['variable'])): ?>
                                <span class="badge badge-muted"><?= e(__('treasury.import.recurring_variable')) ?></span>
                            <?php endif; ?>
                        </span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="muted import-recurring-hint"><?= e(__('treasury.import.recurring_created_note', ['rules' => (string) count($series)])) ?></p>
    </section>
<?php endif; ?>

<form method="post" action="<?= e(url('/treasury/import/rows')) ?>" class="import-adjust-form">
    <?= csrf_field() ?>
    <section class="panel import-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('treasury.import.preview_title')) ?></h2>
                <p class="section-lede"><?= e(__('treasury.import.preview_lede', [
                    'file' => $sourceName,
                    'rows' => (string) $totalRows,
                ])) ?></p>
                <p class="muted import-order-hint"><?= e(__('treasury.import.preview_order_hint')) ?></p>
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
                    $line = (int) ($row['line'] ?? 0);
                    $duplicate = is_array($row['duplicate'] ?? null) ? $row['duplicate'] : null;
                    $included = !empty($duplicate['included']);
                    $fixed = !empty($row['fixed']);
                    $ignored = $action === 'ignore';
                    $editable = $errors !== [] || $fixed;
                    $edit = is_array($row['edit'] ?? null) ? $row['edit'] : [];
                    $recurringKey = (string) ($row['recurring_key'] ?? '');
                    ?>
                    <tr class="<?= $ignored ? 'is-ignored' : '' ?>">
                        <td class="import-line"><?= $line ?></td>
                        <td><?= e(format_date((string) ($row['date'] ?? '')) ?: '—') ?></td>
                        <td><?= e($directionLabel((string) ($row['direction'] ?? ''))) ?></td>
                        <td class="num"><?= e($currency->format((float) ($row['amount'] ?? 0))) ?></td>
                        <td><?= e((string) ($row['category'] ?? '')) ?></td>
                        <td>
                            <?= e((string) ($row['account'] ?? '')) ?>
                            <?php if ((string) ($row['transfer_account'] ?? '') !== ''): ?>
                                → <?= e((string) $row['transfer_account']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="hidden" name="row_present[<?= $line ?>]" value="1">
                            <span class="badge <?= e($badgeClass) ?>"><?= e(__($badgeKey)) ?></span>
                            <?php if ($fixed): ?>
                                <span class="badge badge-warn"><?= e(__('treasury.import.fix_applied')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((string) ($row['description'] ?? '') !== ''): ?>
                                <div><?= e((string) $row['description']) ?></div>
                            <?php endif; ?>
                            <?php if ((string) ($row['notes'] ?? '') !== ''): ?>
                                <div class="muted"><?= e(__('treasury.notes')) ?>: <?= e((string) $row['notes']) ?></div>
                            <?php endif; ?>
                            <?php if ((string) ($row['member'] ?? '') !== ''): ?>
                                <div class="muted"><?= e(__('treasury.member')) ?>: <?= e((string) $row['member']) ?></div>
                            <?php endif; ?>
                            <?php if ((string) ($row['runts_line'] ?? '') !== ''): ?>
                                <div class="muted"><?= e(__('treasury.csv_runts_line')) ?>: <?= e((string) $row['runts_line']) ?></div>
                            <?php endif; ?>
                            <?php if ((string) ($row['budget_item'] ?? '') !== ''): ?>
                                <div class="muted"><?= e(__('treasury.csv_budget_item')) ?>: <?= e((string) $row['budget_item']) ?></div>
                            <?php endif; ?>
                            <?php if ((string) ($row['document_type'] ?? '') !== ''): ?>
                                <div class="muted"><?= e(__('treasury.document_type')) ?>: <?= e((string) $row['document_type']) ?></div>
                            <?php endif; ?>
                            <?php if ((string) ($row['invoice_number'] ?? '') !== '' || (string) ($row['invoice_date'] ?? '') !== ''): ?>
                                <div class="muted">
                                    <?= e(__('treasury.doc_number')) ?>:
                                    <?= e((string) ($row['invoice_number'] ?? '—')) ?>
                                    <?php if ((string) ($row['invoice_date'] ?? '') !== ''): ?>
                                        · <?= e(__('treasury.doc_date')) ?>: <?= e(format_date((string) $row['invoice_date']) ?: (string) $row['invoice_date']) ?>
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
                            <?php if ($duplicate !== null): ?>
                                <label class="import-flag import-duplicate-flag">
                                    <input type="checkbox" name="include[<?= $line ?>]" value="1" <?= $included ? 'checked' : '' ?>>
                                    <span><?= e(__('treasury.import.duplicate_include')) ?></span>
                                </label>
                            <?php endif; ?>
                            <?php if ($errors !== [] || $ignored): ?>
                                <label class="import-flag import-duplicate-flag">
                                    <input type="checkbox" name="ignore[<?= $line ?>]" value="1" <?= $ignored ? 'checked' : '' ?>>
                                    <span><?= e(__('treasury.import.fix_ignore')) ?></span>
                                </label>
                            <?php endif; ?>
                            <?php if ($recurringKey !== ''): ?>
                                <div class="import-recurring-badge">
                                    <span class="badge badge-muted"><?= e(__('treasury.import.recurring_row_badge')) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($editable): ?>
                                <details class="import-row-fix" <?= $errors !== [] || $fixed ? 'open' : '' ?>>
                                    <summary><?= e(__('treasury.import.fix_open')) ?></summary>
                                    <div class="import-row-fix-grid">
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_date')) ?></span>
                                            <input class="sf-field__control" type="text" name="fix[<?= $line ?>][movement_date]"
                                                   value="<?= e((string) ($edit['movement_date'] ?? '')) ?>"
                                                   placeholder="AAAA-MM-GG">
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_direction')) ?></span>
                                            <select class="sf-field__control" name="fix[<?= $line ?>][direction]">
                                                <option value=""><?= e(__('treasury.import.fix_keep_file')) ?></option>
                                                <?php foreach ($directionOptions as $directionValue => $directionKey): ?>
                                                    <option value="<?= e($directionValue) ?>" <?= (string) ($edit['direction'] ?? '') === $directionValue ? 'selected' : '' ?>><?= e(__($directionKey)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_amount')) ?></span>
                                            <input class="sf-field__control" type="text" inputmode="decimal" name="fix[<?= $line ?>][amount]"
                                                   value="<?= e((string) ($edit['amount'] ?? '')) ?>">
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_account')) ?></span>
                                            <select class="sf-field__control" name="fix[<?= $line ?>][account]">
                                                <option value=""><?= e(__('treasury.import.fix_keep_file')) ?></option>
                                                <?php foreach ($accountOptions as $accountKey => $accountLabel): ?>
                                                    <option value="<?= e($accountKey) ?>" <?= (string) ($edit['account'] ?? '') === $accountKey ? 'selected' : '' ?>><?= e($accountLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_transfer_account')) ?></span>
                                            <select class="sf-field__control" name="fix[<?= $line ?>][transfer_account]">
                                                <option value=""><?= e(__('treasury.import.fix_keep_file')) ?></option>
                                                <?php foreach ($accountOptions as $accountKey => $accountLabel): ?>
                                                    <option value="<?= e($accountKey) ?>" <?= (string) ($edit['transfer_account'] ?? '') === $accountKey ? 'selected' : '' ?>><?= e($accountLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_description')) ?></span>
                                            <input class="sf-field__control" type="text" name="fix[<?= $line ?>][description]"
                                                   value="<?= e((string) ($edit['description'] ?? '')) ?>">
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_document_type')) ?></span>
                                            <select class="sf-field__control" name="fix[<?= $line ?>][document_type]">
                                                <option value=""><?= e(__('treasury.import.fix_keep_file')) ?></option>
                                                <?php foreach ($documentTypeOptions as $typeValue => $typeLabel): ?>
                                                    <option value="<?= e($typeValue) ?>" <?= (string) ($edit['document_type'] ?? '') === $typeValue ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_invoice_number')) ?></span>
                                            <input class="sf-field__control" type="text" name="fix[<?= $line ?>][invoice_number]"
                                                   value="<?= e((string) ($edit['invoice_number'] ?? '')) ?>">
                                        </label>
                                        <label class="import-fix-field">
                                            <span><?= e(__('treasury.import.fix_field_invoice_date')) ?></span>
                                            <input class="sf-field__control" type="text" name="fix[<?= $line ?>][invoice_date]"
                                                   value="<?= e((string) ($edit['invoice_date'] ?? '')) ?>"
                                                   placeholder="AAAA-MM-GG">
                                        </label>
                                    </div>
                                    <p class="muted import-row-fix-hint"><?= e(__('treasury.import.fix_hint')) ?></p>
                                    <?php if ($fixed): ?>
                                        <label class="import-flag">
                                            <input type="checkbox" name="fix_reset[<?= $line ?>]" value="1">
                                            <span><?= e(__('treasury.import.fix_reset')) ?></span>
                                        </label>
                                    <?php endif; ?>
                                </details>
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
        <?php if ($hasChoices): ?>
            <div class="actions form-actions form-actions-end">
                <span class="muted import-adjust-hint"><?= e(__('treasury.import.fix_submit_hint')) ?></span>
                <button class="btn btn-ghost" type="submit"><?= e(__('treasury.import.fix_submit')) ?></button>
            </div>
        <?php endif; ?>
    </section>
</form>

<div class="actions form-actions form-actions-end import-commit-actions">
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

