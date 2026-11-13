<?php
/** @var array{movements:list<array>,balance:float,income:float,expense:float} $ledger */
/** @var list<array{key:string,label:string,items:list<array>}> $movement_groups */
/** @var array{auto_from_payments?:bool} $config */
/** @var list<array> $members */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<array{label:string,keys:list<string>}> $category_groups */
/** @var list<array{label:string,options:list<array{value:string,label:string}>}>|null $bulk_category_groups */
/** @var list<array{key:string,label:string,active:bool}> $accounts */
/** @var list<array{key:string,label:string,active:bool,income:float,expense:float,transfer_in:float,transfer_out:float,balance:float}> $account_balances */
/** @var list<array{label:string,keys:list<string>}> $payment_method_groups */
/** @var string $default_category */
/** @var string $search_query */
/** @var list<string> $beneficiaries */
/** @var bool $hasAnyMovements */
/** @var \Socly\Services\CurrencyService $currency */
$canManage = can('treasury.manage');
$sort = (string) ($ledger_sort ?? 'date_desc');
$filterPeriod = (string) ($filters['period'] ?? '');
$filterDirection = (string) ($filters['direction'] ?? '');
$filterCategory = (string) ($filters['category'] ?? '');
$filterAccount = (string) ($filters['account'] ?? '');
$advancedCount = (int) ($advanced_filters_count ?? 0);
$filterMonth = (string) ($filters['month'] ?? '');
$filterYear = (string) ($filters['year'] ?? '');
$filterBudget = (string) ($filters['budget_item_id'] ?? '');
$filterRunts = (string) ($filters['runts_line'] ?? '');
$filterApproval = (string) ($filters['approval'] ?? '');
$hasActiveFilters = trim((string) ($search_query ?? '')) !== ''
    || $filterPeriod !== ''
    || $filterDirection !== ''
    || $filterCategory !== ''
    || $filterAccount !== ''
    || $filterMonth !== ''
    || $filterYear !== ''
    || $filterBudget !== ''
    || $filterRunts !== ''
    || $filterApproval !== '';
// Pager: keep search, filters and sort when moving between pages (cost centre and Model D line too:
// the drill-down from budgets opens the ledger already filtered).
$pagerQuery = array_filter([
    'q' => (string) ($search_query ?? ''),
    'period' => $filterPeriod,
    'direction' => $filterDirection,
    'category' => $filterCategory,
    'account' => $filterAccount,
    'approval' => $filterApproval,
    'month' => $filterMonth,
    'year' => $filterYear,
    'budget_item_id' => $filterBudget,
    'runts_line' => $filterRunts,
    'sort' => $sort,
    'group' => !empty($group_by_category) ? '1' : '0',
], static fn ($value): bool => (string) $value !== '');
$currentPage = (int) ($page ?? 1);
$totalPages = (int) ($pages ?? 1);
$pageUrl = static fn (int $p): string => url('/treasury?' . http_build_query(array_merge($pagerQuery, ['page' => $p])));
// “ret”: the ledger state (filters, sort, page) handed to the create/edit pages, so “Back”, a retry
// after a validation error and the redirect after a save come back to the same view.
$listQuery = $pagerQuery;
if ($currentPage > 1) {
    $listQuery['page'] = (string) $currentPage;
}
$listPath = '/treasury' . ($listQuery === [] ? '' : '?' . http_build_query($listQuery));
$retSuffix = '?ret=' . rawurlencode($listPath);
$pageWindow = $totalPages > 1 ? range(max(1, $currentPage - 2), min($totalPages, $currentPage + 2)) : [];
// Sortable column headers: the ledger keeps the order in a single `sort` key (`<column>_asc|_desc`),
// so a click on the active column flips the direction and a click on another column starts over.
$sortUrl = static function (string $next) use ($pagerQuery): string {
    $params = $pagerQuery;
    $params['sort'] = $next;
    unset($params['page']);

    return url('/treasury') . '?' . http_build_query($params);
};
$sortHeader = static function (string $column, string $labelKey, string $firstDirection = 'asc') use ($sort, $sortUrl): string {
    $active = str_starts_with($sort, $column . '_');
    $direction = $active ? substr($sort, strlen($column) + 1) : '';
    $next = $active
        ? $column . ($direction === 'asc' ? '_desc' : '_asc')
        : $column . ($firstDirection === 'desc' ? '_desc' : '_asc');
    $icon = $active ? ($direction === 'asc' ? '↑' : '↓') : '↕';
    $hint = $active
        ? (string) __($direction === 'asc' ? 'treasury.sort_current_asc' : 'treasury.sort_current_desc')
        : (string) __('treasury.sort_hint');

    return '<th class="th-sort' . ($active ? ' is-sorted' : '') . '">'
        . '<a href="' . e($sortUrl($next)) . '" title="' . e($hint) . '"'
        . ' aria-label="' . e(__($labelKey) . ' — ' . $hint) . '">'
        . e(__($labelKey)) . ' <span class="th-sort-icon" aria-hidden="true">' . $icon . '</span></a></th>';
};
$accountFilterOptions = [];
foreach (($accounts ?? []) as $accountOption) {
    $accountFilterOptions[(string) ($accountOption['key'] ?? '')] = (string) ($accountOption['label'] ?? '');
}
// Keeps the current filters (and the grouping) when jumping from a wallet card to the filtered ledger.
$ledgerParams = [];
$ledgerParams['group'] = !empty($group_by_category) ? '1' : '0';
if (trim((string) ($search_query ?? '')) !== '') {
    $ledgerParams['q'] = (string) $search_query;
}
if ($filterDirection !== '') {
    $ledgerParams['direction'] = $filterDirection;
}
if ($filterCategory !== '') {
    $ledgerParams['category'] = $filterCategory;
}
if ($filterPeriod !== '') {
    $ledgerParams['period'] = $filterPeriod;
}
if ($filterMonth !== '') {
    $ledgerParams['month'] = $filterMonth;
}
if ($filterYear !== '') {
    $ledgerParams['year'] = $filterYear;
}
if ($sort !== 'date_desc') {
    $ledgerParams['sort'] = $sort;
}
$accountFilterUrl = static function (string $accountKey) use ($ledgerParams): string {
    $params = $ledgerParams;
    if ($accountKey !== '') {
        $params['account'] = $accountKey;
    }
    return url('/treasury') . ($params !== [] ? '?' . http_build_query($params) : '');
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if ($canManage): ?>
            <a class="btn<?= empty($hasAnyMovements) ? ' is-first-cta' : '' ?>" href="<?= e(url('/treasury/create') . $retSuffix) ?>"><?= e(__('treasury.add_movement')) ?></a>
            <a class="btn-action-icon" href="<?= e(url('/treasury/import')) ?>" aria-label="<?= e(__('treasury.csv_import_button')) ?>" title="<?= e(__('treasury.csv_import_button')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3l5 5h-3v6h-4V8H7l5-5zM5 19h14v2H5v-2z"/></svg>
            </a>
        <?php endif; ?>
        <a class="btn-action-icon" href="<?= e(url('/treasury/export')) ?>" aria-label="<?= e(__('treasury.csv_export_button')) ?>" title="<?= e(__('treasury.csv_export_button')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 21l-5-5h3v-6h4v6h3l-5 5zM5 3h14v2H5V3z"/></svg>
        </a>
    </div>
</div>

<section class="panel treasury-overview">
    <div class="treasury-overview-head">
        <div class="treasury-overview-balance">
            <span class="treasury-overview-label"><?= e(__('treasury.balance')) ?></span>
            <strong class="treasury-overview-value<?= (float) $ledger['balance'] < 0 ? ' is-negative' : '' ?>" data-treasury-kpi="balance"><?= e($currency->format((float) $ledger['balance'])) ?></strong>
        </div>
        <div class="treasury-overview-flows">
            <span class="is-income" data-treasury-kpi="income"><span aria-hidden="true">↓</span> <?= e($currency->format((float) $ledger['income'])) ?></span>
            <span class="is-expense" data-treasury-kpi="expense"><span aria-hidden="true">↑</span> <?= e($currency->format((float) $ledger['expense'])) ?></span>
        </div>
        <div class="treasury-overview-actions">
            <?php if ($canManage): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets')) ?>"><?= e(__('budget.title')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($account_balances)): ?>
        <div class="treasury-wallets-grid is-compact" data-treasury-wallets>
            <?php foreach ($account_balances as $wallet): ?>
                <?php
                $walletKey = (string) ($wallet['key'] ?? '');
                $walletBalance = (float) ($wallet['balance'] ?? 0);
                $walletActive = !empty($wallet['active']);
                $walletSelected = $walletKey !== '' && $filterAccount === $walletKey;
                ?>
                <a class="treasury-wallet<?= $walletSelected ? ' is-active' : '' ?><?= $walletActive ? '' : ' is-inactive' ?>"
                   href="<?= e($accountFilterUrl($walletSelected ? '' : $walletKey)) ?>"
                   title="<?= e($walletSelected ? __('treasury.filter_reset') : __('treasury.accounts_filter_hint')) ?>">
                    <span class="treasury-wallet-label">
                        <?= e((string) ($wallet['label'] ?? '')) ?>
                        <?php if (!$walletActive): ?>
                            <span class="treasury-wallet-flag"><?= e(__('treasury.account_inactive_suffix')) ?></span>
                        <?php endif; ?>
                    </span>
                    <strong class="treasury-wallet-balance<?= $walletBalance < 0 ? ' is-negative' : '' ?>"><?= e($currency->format($walletBalance)) ?></strong>
                    <span class="treasury-wallet-meta">
                        <span class="is-income">+ <?= e($currency->format((float) ($wallet['income'] ?? 0))) ?></span>
                        <span class="is-expense">− <?= e($currency->format((float) ($wallet['expense'] ?? 0))) ?></span>
                        <?php if ((float) ($wallet['transfer_in'] ?? 0) !== 0.0 || (float) ($wallet['transfer_out'] ?? 0) !== 0.0): ?>
                            <span class="is-transfer">↔ <?= e($currency->format((float) ($wallet['transfer_in'] ?? 0) - (float) ($wallet['transfer_out'] ?? 0))) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($hasAnyMovements)): ?>
<?php
$categoryFilterOptions = [];
foreach ($categories as $categoryOption) {
    $categoryFilterOptions[(string) ($categoryOption['key'] ?? '')] = (string) ($categoryOption['label'] ?? '');
}
// Month/year of the operation date: two extra visible filters for the most common reading
// (“March 2025”), next to the relative period presets.
$monthFilterOptions = [];
for ($monthIndex = 1; $monthIndex <= 12; $monthIndex++) {
    $monthFilterOptions[(string) $monthIndex] = (string) __('common.month_' . $monthIndex);
}
$yearFilterOptions = [];
foreach (($filter_years ?? []) as $yearValue) {
    $yearFilterOptions[(string) (int) $yearValue] = (string) (int) $yearValue;
}
if ($filterYear !== '' && !isset($yearFilterOptions[$filterYear])) {
    $yearFilterOptions = [$filterYear => $filterYear] + $yearFilterOptions;
}
?>
<form class="panel filter-bar members-filter treasury-filter" method="get" action="<?= e(url('/treasury')) ?>" role="search" data-treasury-filter>
    <div class="members-filter-row treasury-filter-row">
        <div class="members-filter-q">
            <?= field('search', [
                'name' => 'q',
                'id' => 'treasury-q',
                'label' => (string) __('treasury.search'),
                'value' => (string) ($search_query ?? ''),
                'context' => 'filter',
                'placeholder' => (string) __('treasury.search_placeholder'),
                'maxlength' => 120,
                'attrs' => ['enterkeyhint' => 'search', 'autocomplete' => 'off'],
            ]) ?>
        </div>
        <?= field('select', [
            'name' => 'period',
            'id' => 'treasury-period',
            'label' => (string) __('treasury.filter_period'),
            'value' => $filterPeriod,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.period_all'),
            'options' => [
                'month' => (string) __('treasury.period_month'),
                'year' => (string) __('treasury.period_year'),
                'days30' => (string) __('treasury.period_days30'),
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'direction',
            'id' => 'treasury-direction',
            'label' => (string) __('treasury.filter_direction'),
            'value' => $filterDirection,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.direction_all'),
            'options' => [
                'income' => (string) __('treasury.income'),
                'expense' => (string) __('treasury.expense'),
                'transfer' => (string) __('treasury.direction_transfer'),
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'month',
            'id' => 'treasury-month',
            'label' => (string) __('treasury.filter_month'),
            'value' => $filterMonth,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_month_all'),
            'options' => $monthFilterOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'year',
            'id' => 'treasury-year',
            'label' => (string) __('treasury.filter_year'),
            'value' => $filterYear,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_year_all'),
            'options' => $yearFilterOptions,
        ]) ?>
        <div class="treasury-filter-tools">
            <button type="button" class="btn btn-ghost btn-sm treasury-filter-toggle" data-treasury-advanced-toggle
                    aria-controls="treasury-advanced-filters" aria-expanded="<?= $advancedCount > 0 ? 'true' : 'false' ?>">
                <?= e(__('treasury.filters_button')) ?>
                <?php if ($advancedCount > 0): ?><span class="treasury-filter-count"><?= $advancedCount ?></span><?php endif; ?>
            </button>
            <div class="treasury-group-toggle">
                <?= field('hidden', ['name' => 'group', 'value' => '0']) ?>
                <?= field('checkbox', [
                    'name' => 'group',
                    'id' => 'treasury-group',
                    'label' => (string) __('treasury.group_toggle'),
                    'value' => !empty($group_by_category) ? '1' : '',
                    'check_value' => '1',
                    'context' => 'inline',
                    'label_class' => 'treasury-group-toggle-text',
                ]) ?>
            </div>
            <?php if ($hasActiveFilters): ?>
                <a class="btn btn-ghost btn-sm treasury-filter-reset" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.filter_reset')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="treasury-advanced<?= $advancedCount > 0 ? ' is-open' : '' ?>" id="treasury-advanced-filters" data-treasury-advanced <?= $advancedCount > 0 ? '' : 'hidden' ?>>
        <div class="treasury-advanced-grid">
        <?php
        $categoryFilterOptions = $categoryFilterOptions ?? [];
        $categoryFilterOptions['__none__'] = (string) __('treasury.filter_none');
        ?>
        <?= field('select', [
            'name' => 'category',
            'id' => 'treasury-category-filter',
            'label' => (string) __('treasury.filter_category'),
            'value' => $filterCategory,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_all'),
            'options' => $categoryFilterOptions,
        ]) ?>
        <?php
        $runtsFilterGroups = is_array($runts_filter_groups ?? null) ? $runts_filter_groups : [];
        $runtsNoneGroup = [['label' => (string) __('treasury.filter_none_group'), 'options' => [['value' => '__none__', 'label' => (string) __('treasury.filter_none')]]]];
        $runtsGroupsForFilter = array_merge([], $runtsFilterGroups, $runtsNoneGroup);
        ?>
        <?= field('select', [
            'name' => 'runts_line',
            'id' => 'treasury-runts-filter',
            'label' => (string) __('runts.line_label'),
            'value' => (string) ($filters['runts_line'] ?? ''),
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_all'),
            'groups' => $runtsGroupsForFilter,
            'options' => [],
        ]) ?>
        <?php
        // Cost centre: grouped like the “Voce del bilancio consuntivo (Modello D)” filter — one
        // optgroup per budget and section, the lines inside.
        $budgetFilterGroups = is_array($budget_filter_groups ?? null) ? $budget_filter_groups : [];
        $budgetNoneGroup = [['label' => (string) __('treasury.filter_none_group'), 'options' => [['value' => '__none__', 'label' => (string) __('treasury.filter_none')]]]];
        $budgetGroupsForFilter = array_merge([], $budgetFilterGroups, $budgetNoneGroup);
        ?>
        <?= field('select', [
            'name' => 'budget_item_id',
            'id' => 'treasury-budget-filter',
            'label' => (string) __('treasury.budget_item'),
            'value' => (string) ($filters['budget_item_id'] ?? ''),
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_all'),
            'groups' => $budgetGroupsForFilter,
            'options' => [],
        ]) ?>
        <?= field('select', [
            'name' => 'account',
            'id' => 'treasury-account-filter',
            'label' => (string) __('treasury.filter_account'),
            'value' => $filterAccount,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_all'),
            'options' => $accountFilterOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'sort',
            'id' => 'treasury-sort',
            'label' => (string) __('treasury.sort_by'),
            'value' => $sort,
            'context' => 'filter',
            'include_empty' => false,
            'groups' => [
                [
                    'label' => (string) __('treasury.sort_group_date'),
                    'options' => [
                        ['value' => 'date_desc', 'label' => (string) __('treasury.sort_date_desc')],
                        ['value' => 'date_asc', 'label' => (string) __('treasury.sort_date_asc')],
                        ['value' => 'created_desc', 'label' => (string) __('treasury.sort_created_desc')],
                        ['value' => 'invoice_desc', 'label' => (string) __('treasury.sort_invoice_desc')],
                    ],
                ],
                [
                    'label' => (string) __('treasury.sort_group_columns'),
                    'options' => [
                        ['value' => 'description_asc', 'label' => (string) __('treasury.sort_description_asc')],
                        ['value' => 'description_desc', 'label' => (string) __('treasury.sort_description_desc')],
                        ['value' => 'method_asc', 'label' => (string) __('treasury.sort_method_asc')],
                        ['value' => 'method_desc', 'label' => (string) __('treasury.sort_method_desc')],
                        ['value' => 'income_desc', 'label' => (string) __('treasury.sort_income_desc')],
                        ['value' => 'income_asc', 'label' => (string) __('treasury.sort_income_asc')],
                        ['value' => 'expense_desc', 'label' => (string) __('treasury.sort_expense_desc')],
                        ['value' => 'expense_asc', 'label' => (string) __('treasury.sort_expense_asc')],
                    ],
                ],
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'approval',
            'id' => 'treasury-approval',
            'label' => (string) __('treasury.filter_approval'),
            'include_empty' => false,
            'value' => (string) ($filters['approval'] ?? ''),
            'options' => [
                ['value' => '', 'label' => (string) __('treasury.approval_all')],
                ['value' => 'pending', 'label' => (string) __('treasury.approval_pending')],
                ['value' => 'approved', 'label' => (string) __('treasury.approval_approved')],
            ],
            'context' => 'filter',
        ]) ?>
        </div>
    </div>
</form>
<?php endif; ?>

<?= view_partial('treasury/_similar_probe', [
    'probe' => $similar_probe ?? null,
    'ret_path' => $listPath,
    'back_path' => '',
    'dismiss_url' => url($listPath),
]) ?>

<div class="panel" data-treasury-list>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.ledger')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.ledger_lede')) ?></p>
        </div>
    </div>
    <?php $recurringRules = is_array($recurring_rules ?? null) ? $recurring_rules : []; ?>
    <?php if ($recurringRules !== []): ?>
        <div class="treasury-recurring" data-treasury-recurring-list>
            <h3 class="treasury-recurring-title"><?= e(__('treasury.recurring_title')) ?></h3>
            <div class="table-wrap embedded">
                <table class="data-table statistics-table">
                    <thead>
                    <tr>
                        <th><?= e(__('treasury.description')) ?></th>
                        <th><?= e(__('treasury.amount')) ?></th>
                        <th><?= e(__('treasury.recurring_frequency')) ?></th>
                        <th><?= e(__('treasury.recurring_next')) ?></th>
                        <th><?= e(__('treasury.recurring_status')) ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recurringRules as $rule): ?>
                        <?php
                        $ruleId = (int) ($rule['id'] ?? 0);
                        $ruleActive = !empty($rule['is_active']);
                        $ruleFreq = (string) ($rule['frequency'] ?? 'monthly');
                        $ruleLabel = trim((string) ($rule['description'] ?? ''));
                        if ($ruleLabel === '') {
                            $ruleLabel = (string) __('treasury.recurring_default_description');
                        }
                        ?>
                        <tr>
                            <td>
                                <?= e($ruleLabel) ?>
                                <?php if (!empty($rule['amount_variable'])): ?>
                                    <span class="badge badge-muted"><?= e(__('treasury.recurring_variable_badge')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= e($currency->format((float) ($rule['amount'] ?? 0))) ?></td>
                            <td><?= e(__('treasury.recurring_freq_' . $ruleFreq)) ?></td>
                            <td><?= e(format_date($rule['next_run_date'] ?? null) ?: '—') ?></td>
                            <td>
                                <span class="badge <?= $ruleActive ? 'badge-ok' : 'badge-muted' ?>">
                                    <?= e($ruleActive ? __('treasury.recurring_active') : __('treasury.recurring_paused')) ?>
                                </span>
                            </td>
                            <td class="doc-row-actions">
                                <?php if ($canManage): ?>
                                    <?= row_actions([
                                        [
                                            'icon' => $ruleActive ? 'toggle' : 'renew',
                                            'label' => $ruleActive ? __('treasury.recurring_pause') : __('treasury.recurring_resume'),
                                            'form_action' => url('/treasury/recurring/' . $ruleId . '/toggle'),
                                        ],
                                        [
                                            'icon' => 'delete',
                                            'label' => __('treasury.recurring_delete'),
                                            'danger' => true,
                                            'form_action' => url('/treasury/recurring/' . $ruleId . '/delete'),
                                            'form_attrs' => ['data-confirm' => __('treasury.recurring_delete_confirm')],
                                        ],
                                    ]) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php $pendingCount = (int) ($pending_count ?? 0); ?>
    <?php if ($pendingCount > 0): ?>
        <div class="alert alert-warn treasury-pending-banner" role="status" data-treasury-pending-banner>
            <span><?= str_replace(':count', '<strong data-treasury-pending-count>' . $pendingCount . '</strong>', e(__('treasury.pending_banner', ['count' => ':count']))) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($canManage): ?>
        <div class="members-bulk-bar" data-treasury-bulk-bar aria-hidden="true"
             data-currency-display="<?= e($currency->display()) ?>"
             data-msg-total-income="<?= e(__('treasury.bulk_total_income')) ?>"
             data-msg-total-expense="<?= e(__('treasury.bulk_total_expense')) ?>"
             data-msg-total-net="<?= e(__('treasury.bulk_total_net')) ?>"
             data-msg-total-transfers="<?= e(__('treasury.bulk_total_transfers')) ?>">
            <div class="members-bulk-bar-inner">
                <span class="members-bulk-count" data-treasury-bulk-count>0</span>
                <span class="muted"><?= e(__('treasury.bulk_selected')) ?></span>
                <span class="treasury-bulk-total" data-treasury-bulk-total hidden></span>
                <div class="members-bulk-actions">
                    <button type="button" class="btn btn-sm btn-ghost" data-treasury-bulk-action="approve"><?= e(__('treasury.bulk_approve')) ?></button>
                    <button type="button" class="btn btn-sm btn-ghost" data-treasury-bulk-action="unapprove"><?= e(__('treasury.bulk_unapprove')) ?></button>
                    <span class="treasury-bulk-divider" aria-hidden="true"></span>
                    <label class="treasury-transfer-pick">
                        <span class="visually-hidden"><?= e(__('treasury.transfer_target')) ?></span>
                        <select class="sf-field__control treasury-transfer-select" name="target_account" data-treasury-transfer-target>
                            <option value=""><?= e(__('treasury.transfer_target')) ?></option>
                            <?php foreach (($accounts ?? []) as $accountOption): ?><?php if (empty($accountOption['active'])) { continue; } ?><option value="<?= e((string) $accountOption['key']) ?>"><?= e((string) $accountOption['label']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button type="button" class="btn btn-sm" data-treasury-bulk-action="transfer" disabled>
                        <span class="treasury-transfer-arrow" aria-hidden="true">↔</span>
                        <span><?= e(__('treasury.transfer_bulk')) ?></span>
                    </button>
                    <span class="muted treasury-transfer-hint" data-treasury-transfer-hint hidden></span>
                    <span class="treasury-bulk-divider" aria-hidden="true"></span>
                    <button type="button" class="btn btn-sm btn-ghost" data-treasury-bulk-action="edit">
                        <?= row_action_icon_svg('edit') ?>
                        <span><?= e(__('treasury.bulk_edit')) ?></span>
                    </button>
                    <span class="treasury-bulk-divider" aria-hidden="true"></span>
                    <button type="button" class="btn btn-sm btn-ghost is-danger" data-treasury-bulk-action="delete" data-confirm="<?= e(__('treasury.bulk_delete_confirm')) ?>">
                        <?= row_action_icon_svg('delete') ?>
                        <span><?= e(__('treasury.bulk_delete')) ?></span>
                    </button>
                </div>
            </div>
        </div>
        <form method="post" action="<?= e(url('/treasury/bulk')) ?>" data-treasury-bulk-form hidden
              data-msg-transfer-confirm="<?= e(__('treasury.transfer_confirm')) ?>"
              data-msg-transfer-target="<?= e(__('treasury.transfer_target_required')) ?>"
              data-msg-transfer-mixed="<?= e(__('treasury.transfer_mixed')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="" data-treasury-bulk-action-input>
            <input type="hidden" name="target_account" value="" data-treasury-transfer-hidden>
            <span data-treasury-bulk-id-host></span>
        </form>
        <?php
        // Bulk classification sheet: the same fields the operator would set one by one, each with a
        // “keep” default so only the chosen ones overwrite the current value.
        $bulkAccountOptions = [];
        foreach (($accounts ?? []) as $accountOption) {
            if (empty($accountOption['active'])) {
                continue;
            }
            $bulkAccountOptions[(string) $accountOption['key']] = (string) $accountOption['label'];
        }
        // Categories keep the same macro-groups as the movement sheet (income / expense / financial /
        // custom / other), taken from the single source of truth the service already exposes; the
        // “create a new category” entry is excluded because it makes no sense in bulk.
        $bulkCategoryGroups = is_array($bulk_category_groups ?? null) ? $bulk_category_groups : [];
        $bulkNoneGroup = [['label' => (string) __('treasury.filter_none_group'), 'options' => [['value' => '__none__', 'label' => (string) __('treasury.filter_none')]]]];
        $bulkRuntsGroups = array_merge(is_array($runts_filter_groups ?? null) ? $runts_filter_groups : [], $bulkNoneGroup);
        $bulkBudgetGroups = array_merge(is_array($budget_filter_groups ?? null) ? $budget_filter_groups : [], $bulkNoneGroup);
        // Payment methods keep the same groups as the movement form; there is no “clear” entry
        // because the column always holds a value (cash is implied by the cash wallet).
        $bulkMethodGroups = [];
        foreach (is_array($payment_method_groups ?? null) ? $payment_method_groups : [] as $methodGroup) {
            $methodOptions = [];
            foreach ((array) ($methodGroup['keys'] ?? []) as $methodKey) {
                $methodOptions[] = [
                    'value' => (string) $methodKey,
                    'label' => (string) __('treasury.method_' . $methodKey),
                ];
            }
            if ($methodOptions !== []) {
                $bulkMethodGroups[] = ['label' => (string) ($methodGroup['label'] ?? ''), 'options' => $methodOptions];
            }
        }
        ?>
        <dialog
            class="member-bulk-email-dialog"
            data-treasury-bulk-edit-dialog
            data-msg-summary="<?= e(__('treasury.bulk_edit_summary', ['count' => ':count'])) ?>"
            data-msg-account="<?= e(__('treasury.account')) ?>"
            data-msg-category="<?= e(__('treasury.category')) ?>"
            data-msg-method="<?= e(__('treasury.payment_method_label')) ?>"
            data-msg-runts="<?= e(__('runts.line_label')) ?>"
            data-msg-budget="<?= e(__('treasury.budget_item')) ?>"
            data-msg-description="<?= e(__('treasury.description')) ?>"
            data-msg-notes="<?= e(__('treasury.notes')) ?>"
            data-msg-confirm="<?= e(__('treasury.bulk_edit_confirm', ['changes' => ':changes', 'count' => ':count'])) ?>"
            data-msg-none="<?= e(__('treasury.bulk_edit_none')) ?>"
        >
            <div class="member-leave-shell treasury-bulk-edit-shell">
                <div class="treasury-bulk-edit-head">
                    <h3 class="section-title"><?= e(__('treasury.bulk_edit_title')) ?></h3>
                    <p class="muted" data-treasury-bulk-edit-summary></p>
                    <p class="muted"><?= e(__('treasury.bulk_edit_lede')) ?></p>
                </div>
                <div class="treasury-bulk-edit-grid">
                    <?= field('select', [
                        'name' => '',
                        'label' => (string) __('treasury.account'),
                        'include_empty' => true,
                        'empty_label' => (string) __('treasury.bulk_edit_keep'),
                        'options' => $bulkAccountOptions,
                        'attrs' => ['data-treasury-bulk-edit-account' => true],
                    ]) ?>
                    <?= field('select', [
                        'name' => '',
                        'label' => (string) __('treasury.category'),
                        'include_empty' => true,
                        'empty_label' => (string) __('treasury.bulk_edit_keep'),
                        'groups' => $bulkCategoryGroups,
                        'options' => [],
                        'attrs' => ['data-treasury-bulk-edit-category' => true],
                    ]) ?>
                    <?= field('select', [
                        'name' => '',
                        'label' => (string) __('treasury.payment_method_label'),
                        'include_empty' => true,
                        'empty_label' => (string) __('treasury.bulk_edit_keep'),
                        'groups' => $bulkMethodGroups,
                        'options' => [],
                        'attrs' => ['data-treasury-bulk-edit-method' => true],
                    ]) ?>
                    <?= field('select', [
                        'name' => '',
                        'label' => (string) __('runts.line_label'),
                        'include_empty' => true,
                        'empty_label' => (string) __('treasury.bulk_edit_keep'),
                        'groups' => $bulkRuntsGroups,
                        'options' => [],
                        'attrs' => ['data-treasury-bulk-edit-runts' => true],
                    ]) ?>
                    <?= field('select', [
                        'name' => '',
                        'label' => (string) __('treasury.budget_item'),
                        'include_empty' => true,
                        'empty_label' => (string) __('treasury.bulk_edit_keep'),
                        'groups' => $bulkBudgetGroups,
                        'options' => [],
                        'attrs' => ['data-treasury-bulk-edit-budget' => true],
                    ]) ?>
                </div>
                <div class="treasury-bulk-edit-optional">
                    <?= field('checkbox', [
                        'name' => '',
                        'label' => (string) __('treasury.bulk_edit_description_replace'),
                        'label_class' => 'checkbox-row-lg',
                        'checked' => false,
                        'attrs' => ['data-treasury-bulk-edit-description-toggle' => true],
                    ]) ?>
                    <div class="treasury-bulk-edit-optional-body" data-treasury-bulk-edit-description-block hidden>
                        <?= field('textarea', [
                            'name' => '',
                            'label' => (string) __('treasury.description'),
                            'rows' => 2,
                            'maxlength' => 500,
                            'attrs' => ['data-treasury-bulk-edit-description' => true],
                        ]) ?>
                    </div>
                </div>
                <div class="treasury-bulk-edit-optional">
                    <?= field('checkbox', [
                        'name' => '',
                        'label' => (string) __('treasury.bulk_edit_notes_replace'),
                        'label_class' => 'checkbox-row-lg',
                        'checked' => false,
                        'attrs' => ['data-treasury-bulk-edit-notes-toggle' => true],
                    ]) ?>
                    <div class="treasury-bulk-edit-optional-body" data-treasury-bulk-edit-notes-block hidden>
                        <?= field('textarea', [
                            'name' => '',
                            'label' => (string) __('treasury.notes'),
                            'rows' => 2,
                            'maxlength' => 2000,
                            'attrs' => ['data-treasury-bulk-edit-notes' => true],
                        ]) ?>
                    </div>
                </div>
                <div class="member-leave-actions">
                    <button type="button" class="btn btn-ghost" data-treasury-bulk-edit-cancel><?= e(__('common.cancel')) ?></button>
                    <button type="button" class="btn" data-treasury-bulk-edit-apply><?= e(__('treasury.bulk_edit_apply')) ?></button>
                </div>
            </div>
        </dialog>
    <?php endif; ?>
    <?php if ($movement_groups === []): ?>
        <div class="empty-state">
            <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
                <strong><?= e(__('treasury.search_empty_title')) ?></strong>
                <?= e(__('treasury.search_empty_text', ['q' => (string) $search_query])) ?>
            <?php else: ?>
                <strong><?= e(__('treasury.empty_title')) ?></strong>
                <?= e(__('treasury.empty_text')) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="doc-archive">
            <?php foreach ($movement_groups as $group): ?>
                <section class="doc-archive-group">
                    <header class="doc-archive-group-head">
                        <h3 class="doc-archive-group-title"><?= e((string) $group['label']) ?></h3>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="treasury-ledger">
                            <thead>
                            <tr>
                                <?php if ($canManage): ?>
                                    <th class="treasury-col-check">
                                        <input
                                            type="checkbox"
                                            data-treasury-select-all
                                            aria-label="<?= e(__('treasury.select_all')) ?>"
                                        >
                                    </th>
                                <?php endif; ?>
                                <?= $sortHeader('date', 'treasury.operation_date') ?>
                                <?= $sortHeader('description', 'treasury.description') ?>
                                <?= $sortHeader('method', 'treasury.method') ?>
                                <th><?= e(__('treasury.details')) ?></th>
                                <?= $sortHeader('income', 'treasury.income', 'desc') ?>
                                <?= $sortHeader('expense', 'treasury.expense', 'desc') ?>
                                <th><?= e(__('treasury.actions')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['items'] as $row): ?>
                                <?php
                                $isIncome = ($row['direction'] ?? '') === 'income';
                                $isTransfer = ($row['direction'] ?? '') === 'transfer';
                                $amount = (float) ($row['amount'] ?? 0);
                                $memberLabel = trim((string) (($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')));
                                $desc = trim((string) ($row['description'] ?? ''));
                                $rowNotes = trim((string) ($row['notes'] ?? ''));
                                $invoiceTitle = trim((string) ($row['invoice_title'] ?? ''));
                                $documentTypeKey = (string) ($row['document_type'] ?? '');
                                $documentTypeLabel = '';
                                if ($documentTypeKey !== '') {
                                    $documentTypeLabel = (string) __('treasury.document_type_' . $documentTypeKey);
                                    if ($documentTypeLabel === 'treasury.document_type_' . $documentTypeKey) {
                                        $documentTypeLabel = $documentTypeKey;
                                    }
                                }
                                if ($desc === '' && $invoiceTitle !== '') {
                                    $desc = $invoiceTitle;
                                }
                                if ($desc === '' && $isTransfer) {
                                    $desc = (string) __('treasury.kind_internal_transfer');
                                }
                                if ($desc === '' && $memberLabel !== '') {
                                    $desc = $memberLabel;
                                }
                                $rowId = (int) ($row['id'] ?? 0);
                                $isPending = trim((string) ($row['approved_at'] ?? '')) === '';
                                $isSuperseded = trim((string) ($row['superseded_at'] ?? '')) !== '';
                                $supersededNote = trim((string) ($row['superseded_note'] ?? ''));
                                $supersededAccountLabel = '';
                                $supersededAccountRaw = trim((string) ($row['superseded_account'] ?? ''));
                                if ($supersededAccountRaw !== '') {
                                    // Custom wallets carry their own name: never show the slug.
                                    $supersededAccountLabel = treasury_account_label($supersededAccountRaw);
                                }
                                $editUrl = $canManage ? url('/treasury/' . $rowId . '/edit') . $retSuffix : '';
                                $accountKey = \Socly\Services\TreasuryService::normalizeAccount((string) ($row['account'] ?? ''));
                                $accountLabel = $accountKey !== '' ? treasury_account_label($accountKey) : '';
                                $methodRaw = trim((string) ($row['payment_method'] ?? ''));
                                $methodKey = $methodRaw !== '' ? \Socly\Services\TreasuryService::normalizePaymentMethod($methodRaw) : '';
                                $methodLabel = $methodKey !== '' ? (string) __('treasury.method_' . $methodKey) : '';
                                if ($methodKey !== '' && $methodLabel === 'treasury.method_' . $methodKey) {
                                    $methodLabel = $methodKey;
                                }
                                // Wallet first, method second: cash (and internal transfers) need no method.
                                $methodCell = $accountLabel !== '' ? $accountLabel : $methodLabel;
                                if ($accountLabel !== '' && $methodLabel !== '' && $methodKey !== 'cash') {
                                    $methodCell .= ' · ' . $methodLabel;
                                }
                                $transferLabel = '';
                                $transferRaw = trim((string) ($row['transfer_account'] ?? ''));
                                if ($isTransfer && $transferRaw !== '') {
                                    $transferLabel = treasury_account_label($transferRaw);
                                }
                                ?>
                                <tr
                                    class="treasury-row treasury-row-<?= $isTransfer ? 'transfer' : ($isIncome ? 'income' : 'expense') ?><?= $canManage && !$isSuperseded ? ' doc-row-editable' : '' ?><?= $isSuperseded ? ' is-superseded' : '' ?>"
                                    data-treasury-row="<?= $rowId ?>" data-treasury-approved="<?= $isPending ? '0' : '1' ?>" data-treasury-account="<?= e($accountKey) ?>"
                                    data-treasury-amount="<?= e(number_format($amount, 2, '.', '')) ?>"
                                    data-treasury-direction="<?= $isTransfer ? 'transfer' : ($isIncome ? 'income' : 'expense') ?>"
                                    <?php if ($canManage && !$isSuperseded): ?>
                                        data-href="<?= e($editUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('treasury.edit') . ': ' . ($desc !== '' ? $desc : (string) $rowId)) ?>"
                                    <?php endif; ?>
                                >
                                    <?php if ($canManage): ?>
                                        <td class="treasury-col-check" data-stop-row-nav>
                                            <?php if (!$isSuperseded): ?>
                                                <?= field('checkbox', [
                                                    'name' => 'treasury_pick_' . $rowId,
                                                    'check_value' => (string) $rowId,
                                                    'label' => '',
                                                    'context' => 'inline',
                                                    'attrs' => ['data-treasury-select' => true],
                                                ]) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <?= e(format_date($row['movement_date'] ?? null) ?: '—') ?>
                                        <?php if ($isPending): ?>
                                            <span class="badge badge-warn treasury-pending-badge"><?= e(__('treasury.pending')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e($desc !== '' ? $desc : '—') ?>
                                        <?php if ($rowNotes !== ''): ?>
                                            <div class="muted treasury-row-notes" title="<?= e($rowNotes) ?>">
                                                <?= e(__('treasury.notes')) ?>: <?= e(mb_strimwidth($rowNotes, 0, 120, '…')) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isSuperseded): ?>
                                            <span class="badge badge-muted treasury-superseded-badge">
                                                <?= e($supersededAccountLabel !== ''
                                                    ? __('treasury.superseded_badge_to', ['account' => $supersededAccountLabel])
                                                    : __('treasury.superseded_badge')) ?>
                                            </span>
                                            <?php if ($supersededNote !== ''): ?>
                                                <div class="muted treasury-superseded-note"><?= e($supersededNote) ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php $originAccount = trim((string) ($row['transfer_origin_account'] ?? '')); ?>
                                        <?php if ($originAccount !== ''): ?>
                                            <?php $originLabel = treasury_account_label($originAccount); ?>
                                            <span class="badge badge-ok treasury-superseded-badge"><?= e(__('treasury.transfer_origin_badge')) ?></span>
                                            <div class="muted treasury-superseded-note"><?= e(__('treasury.transfer_origin_note', [
                                                'account' => $originLabel,
                                                'date' => format_date($row['transfer_origin_date'] ?? null) ?: (string) ($row['transfer_origin_date'] ?? ''),
                                            ])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isTransfer && $transferLabel !== ''): ?>
                                            <?= e($methodCell) ?> → <?= e($transferLabel) ?>
                                        <?php else: ?>
                                            <?= e($methodCell) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($memberLabel !== ''): ?><div><?= e($memberLabel) ?></div><?php endif; ?>
                                        <?php if (!empty($row['beneficiary'])): ?><div><?= e(__('treasury.supplier')) ?>: <?= e((string) $row['beneficiary']) ?></div><?php endif; ?>
                                        <?php if ($invoiceTitle !== '' && $invoiceTitle !== $desc): ?><div><?= e(__('treasury.invoice_title')) ?>: <?= e($invoiceTitle) ?></div><?php endif; ?>
                                        <?php if ($documentTypeLabel !== ''): ?><div><?= e(__('treasury.document_type')) ?>: <?= e($documentTypeLabel) ?></div><?php endif; ?>
                                        <?php if (!empty($row['invoice_number'])): ?><div><?= e(__('treasury.doc_number')) ?>: <?= e((string) $row['invoice_number']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['invoice_date'])): ?><div><?= e(__('treasury.doc_date')) ?>: <?= e(format_date($row['invoice_date']) ?: (string) $row['invoice_date']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['budget_item_label'])): ?><div><?= e(__('treasury.budget_item')) ?>: <?= e((string) $row['budget_item_label']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['invoice_due_date'])): ?><div><?= e(__('treasury.invoice_due_date')) ?>: <?= e(format_date($row['invoice_due_date']) ?: (string) $row['invoice_due_date']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['amount_entered']) && !empty($row['amount_currency'])): ?><div><?= e(__('treasury.amount_original')) ?>: <?= e(number_format((float) $row['amount_entered'], 2, ',', '.')) ?> <?= e((string) $row['amount_currency']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['attachment_path'])): ?><div><a href="<?= e(url('/treasury/' . $rowId . '/attachment')) ?>" target="_blank" rel="noopener"><?= e(__('treasury.upload_document')) ?></a></div><?php endif; ?>
                                        <?php if (empty($row['member_id']) && empty($row['beneficiary']) && empty($row['invoice_number']) && empty($row['invoice_title']) && empty($row['budget_item_label']) && empty($row['invoice_date']) && empty($row['attachment_path']) && $documentTypeLabel === ''): ?><?= e(__('treasury.none')) ?><?php endif; ?>
                                    </td>
                                    <td class="<?= $isTransfer ? 'amount-transfer' : 'amount-income' ?>"><?= $isIncome || $isTransfer ? ($isTransfer ? '↔ ' : '') . e($currency->format($amount)) : '—' ?></td>
                                    <td class="amount-expense"><?= $isIncome || $isTransfer ? '—' : e($currency->format($amount)) ?></td>
                                    <td class="doc-row-actions" data-stop-row-nav>
                                        <?= row_actions([
                                            [
                                                'icon' => 'check',
                                                'label' => __('treasury.approve'),
                                                'show' => $canManage && $isPending,
                                                'form_action' => url('/treasury/' . $rowId . '/approve'),
                                                'form_attrs' => [
                                                    'data-treasury-approve' => true,
                                                    'data-treasury-id' => (string) $rowId,
                                                ],
                                            ],
                                            [
                                                'icon' => 'file',
                                                'label' => __('treasury.upload_document'),
                                                'show' => !empty($row['attachment_path']),
                                                'href' => !empty($row['attachment_path']) ? url('/treasury/' . $rowId . '/attachment') : '',
                                                'target' => '_blank',
                                                'rel' => 'noopener',
                                            ],
                                            [
                                                'icon' => 'edit',
                                                'label' => __('treasury.edit'),
                                                'show' => $canManage,
                                                'href' => $editUrl,
                                            ],
                                        ]) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($totalPages > 1): ?>
        <nav class="list-pager" aria-label="<?= e(__('common.pager_aria')) ?>">
            <?php if ($currentPage > 1): ?>
                <a class="list-pager-step" href="<?= e($pageUrl($currentPage - 1)) ?>" rel="prev">← <?= e(__('common.pager_prev')) ?></a>
            <?php else: ?>
                <span class="list-pager-step is-disabled">← <?= e(__('common.pager_prev')) ?></span>
            <?php endif; ?>
            <div class="list-pager-pages">
                <?php $previousPage = 0; foreach ($pageWindow as $pageNumber): ?>
                    <?php if ($previousPage > 0 && $pageNumber > $previousPage + 1): ?>
                        <span class="list-pager-gap" aria-hidden="true">…</span>
                    <?php endif; ?>
                    <a class="list-pager-page<?= $pageNumber === $currentPage ? ' is-current' : '' ?>"
                       href="<?= e($pageUrl($pageNumber)) ?>"
                       <?= $pageNumber === $currentPage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                    <?php $previousPage = $pageNumber; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($currentPage < $totalPages): ?>
                <a class="list-pager-step" href="<?= e($pageUrl($currentPage + 1)) ?>" rel="next"><?= e(__('common.pager_next')) ?> →</a>
            <?php else: ?>
                <span class="list-pager-step is-disabled"><?= e(__('common.pager_next')) ?> →</span>
            <?php endif; ?>
            <span class="muted list-pager-info"><?= e(__('common.pager_info', ['page' => (string) $currentPage, 'pages' => (string) $totalPages])) ?></span>
        </nav>
    <?php endif; ?>
</div>
