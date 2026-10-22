<?php
/**
 * Controllo budget — preventivo vs consuntivo con semafori di scostamento.
 *
 * @var array<string,mixed> $comparison
 * @var \Socly\Services\CurrencyService $currency
 */
$budget = is_array($comparison['budget'] ?? null) ? $comparison['budget'] : [];
$budgetId = (int) ($budget['id'] ?? 0);
$sections = is_array($comparison['sections'] ?? null) ? $comparison['sections'] : [];
$totals = is_array($comparison['totals'] ?? null) ? $comparison['totals'] : [];
$alerts = is_array($comparison['alerts'] ?? null) ? $comparison['alerts'] : [];
$unallocated = is_array($comparison['unallocated'] ?? null) ? $comparison['unallocated'] : [];
$statusLabels = [
    'ok' => __('budget.status_ok'),
    'warning' => __('budget.status_warning'),
    'over' => __('budget.status_over'),
];
$percentLabel = static fn (float $value): string => number_format($value, 0, ',', '.') . '%';
?>
<div class="page-header">
    <div class="titles">
        <p class="budget-eyebrow"><?= e(__('budget.compare_eyebrow')) ?></p>
        <h1 class="page-title"><?= e(__('budget.compare_title', ['year' => (string) ($budget['fiscal_year'] ?? '')])) ?></h1>
        <p class="page-lede"><?= e(__('budget.compare_lede')) ?></p>
    </div>
    <div class="actions budget-header-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $budgetId)) ?>"><?= e(__('budget.open')) ?></a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $budgetId . '/rendiconto')) ?>" target="_blank" rel="noopener"><?= e(__('budget.rendiconto_export')) ?></a>
    </div>
</div>

<?php if ($alerts !== []): ?>
    <div class="budget-alerts" role="alert">
        <strong><?= e(__('budget.over_title')) ?></strong>
        <ul>
            <?php foreach ($alerts as $alert): ?>
                <li>
                    <?= e(__('budget.over_line', [
                        'label' => (string) $alert['label'],
                        'amount' => $currency->format((float) $alert['over']),
                        'percent' => $percentLabel((float) $alert['percent']),
                    ])) ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="budget-alerts-note"><?= e(__('budget.over_note')) ?></p>
    </div>
<?php endif; ?>

<div class="budget-kpis">
    <div class="budget-kpi">
        <span class="budget-kpi-label"><?= e(__('budget.planned_expense')) ?></span>
        <strong><?= e($currency->format((float) ($totals['planned_expense'] ?? 0))) ?></strong>
    </div>
    <div class="budget-kpi">
        <span class="budget-kpi-label"><?= e(__('budget.actual_expense')) ?></span>
        <strong><?= e($currency->format((float) ($totals['actual_expense'] ?? 0))) ?></strong>
        <span class="budget-kpi-meta"><?= e($percentLabel((float) ($totals['expense_percent'] ?? 0))) ?></span>
    </div>
    <div class="budget-kpi">
        <span class="budget-kpi-label"><?= e(__('budget.planned_income')) ?></span>
        <strong><?= e($currency->format((float) ($totals['planned_income'] ?? 0))) ?></strong>
    </div>
    <div class="budget-kpi">
        <span class="budget-kpi-label"><?= e(__('budget.actual_income')) ?></span>
        <strong><?= e($currency->format((float) ($totals['actual_income'] ?? 0))) ?></strong>
    </div>
    <div class="budget-kpi budget-kpi-need">
        <span class="budget-kpi-label"><?= e(__('budget.need_residual')) ?></span>
        <strong><?= e($currency->format((float) ($totals['planned_need'] ?? 0) - (float) ($totals['actual_need'] ?? 0))) ?></strong>
        <span class="budget-kpi-meta"><?= e(__('budget.need_planned_meta', ['amount' => $currency->format((float) ($totals['planned_need'] ?? 0))])) ?></span>
    </div>
</div>

<?php foreach ($sections as $section): ?>
    <section class="panel budget-compare-section">
        <header class="budget-compare-head">
            <h2 class="section-title"><?= e((string) ($section['title'] ?? '')) ?></h2>
            <span class="budget-compare-totals muted">
                <?= e(__('budget.section_totals', [
                    'planned' => $currency->format((float) ($section['planned_need'] ?? 0)),
                    'actual' => $currency->format((float) ($section['actual_need'] ?? 0)),
                ])) ?>
            </span>
        </header>
        <div class="table-wrap embedded">
            <table class="budget-compare-table">
                <thead>
                <tr>
                    <th><?= e(__('budget.item_label')) ?></th>
                    <th class="budget-th-amount"><?= e(__('budget.planned')) ?></th>
                    <th class="budget-th-amount"><?= e(__('budget.actual')) ?></th>
                    <th class="budget-th-amount"><?= e(__('budget.residual')) ?></th>
                    <th class="budget-th-progress"><?= e(__('budget.progress')) ?></th>
                    <th><?= e(__('budget.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($section['items'] as $item): ?>
                    <?php
                    $status = (string) $item['status'];
                    $percent = (float) $item['percent'];
                    $barWidth = max(0.0, min(100.0, $percent));
                    ?>
                    <tr class="budget-compare-row is-<?= e($status) ?>">
                        <td>
                            <span class="budget-compare-label"><?= e((string) $item['label']) ?></span>
                            <?php if ((string) ($item['category'] ?? '') !== ''): ?>
                                <span class="budget-compare-category muted"><?= e($item['category']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="budget-th-amount"><?= e($currency->format((float) $item['planned'])) ?></td>
                        <td class="budget-th-amount"><?= e($currency->format((float) $item['actual'])) ?></td>
                        <td class="budget-th-amount<?= (float) $item['residual'] < 0 ? ' is-negative' : '' ?>"><?= e($currency->format((float) $item['residual'])) ?></td>
                        <td class="budget-th-progress">
                            <div class="budget-progress" data-status="<?= e($status) ?>" style="--pct: <?= e(number_format($barWidth, 1, '.', '')) ?>">
                                <span class="budget-progress-track"><span class="budget-progress-bar"></span></span>
                                <span class="budget-progress-label"><?= e($percentLabel($percent)) ?></span>
                            </div>
                        </td>
                        <td><span class="budget-status <?= e($status) ?>"><?= e((string) ($statusLabels[$status] ?? $status)) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($section['items'] === []): ?>
                    <tr><td colspan="6" class="muted"><?= e(__('budget.section_empty')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($unallocated !== []): ?>
    <section class="panel budget-compare-section budget-unallocated">
        <header class="budget-compare-head">
            <h2 class="section-title"><?= e(__('budget.unallocated_title', ['year' => (string) ($budget['fiscal_year'] ?? '')])) ?></h2>
            <p class="section-lede"><?= e(__('budget.unallocated_lede')) ?></p>
        </header>
        <div class="table-wrap embedded">
            <table class="budget-compare-table">
                <thead>
                <tr>
                    <th><?= e(__('treasury.category')) ?></th>
                    <th><?= e(__('budget.direction')) ?></th>
                    <th class="budget-th-amount"><?= e(__('budget.movements')) ?></th>
                    <th class="budget-th-amount"><?= e(__('budget.actual')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($unallocated as $row): ?>
                    <tr>
                        <td><?= e((string) $row['label']) ?></td>
                        <td><?= e((string) $row['direction'] === 'income' ? __('budget.direction_income') : __('budget.direction_expense')) ?></td>
                        <td class="budget-th-amount"><?= e((string) $row['movements']) ?></td>
                        <td class="budget-th-amount"><?= e($currency->format((float) $row['total'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="setup-hint muted"><?= e(__('budget.unallocated_hint')) ?></p>
    </section>
<?php endif; ?>

<div class="budget-compare-footer">
    <p class="muted">
        <?= e(__('budget.reconcile_note', [
            'allocated' => $currency->format((float) ($totals['actual_expense_all'] ?? 0)),
            'unallocated' => $currency->format((float) ($totals['unallocated_expense'] ?? 0)),
        ])) ?>
    </p>
</div>

