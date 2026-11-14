<?php
/**
 * Managed positions of the cash book: loans (member financing) and security deposits
 * (SIAE, municipality, rent contract…). Same layout as the supplier register, but grouped by
 * counterparty with the two flows of the category and the amount still open.
 *
 * @var string $kind 'loan'|'deposit'
 * @var string $category Treasury category key (member_loan / security_deposit)
 * @var list<array{name:string,member_id:int,movements:int,income:float,expense:float,net:float,last_date:string,last_date_formatted:string}> $positions
 * @var array{count:int,movements:int,income:float,expense:float} $position_totals
 * @var string $search_query
 * @var string $sort
 * @var \Socly\Services\CurrencyService $currency
 */
$positions = is_array($positions ?? null) ? $positions : [];
$totals = is_array($position_totals ?? null)
    ? $position_totals
    : ['count' => 0, 'movements' => 0, 'income' => 0.0, 'expense' => 0.0];
$isLoan = ($kind ?? '') === 'loan';
$category = (string) ($category ?? ($isLoan ? 'member_loan' : 'security_deposit'));
$query = (string) ($search_query ?? '');
$sortKey = (string) ($sort ?? 'volume') === 'name' ? 'name' : 'volume';
$key = $isLoan ? 'loans' : 'deposits';
$listPath = '/treasury/' . ($isLoan ? 'loans' : 'deposits');
$ledgerUrl = static function (array $row) use ($category): string {
    // Free-text counterparties drill down to their own movements. Member-linked ones have no
    // beneficiary text: the ledger opens on the whole category (the member name is not a ledger
    // filter), which is still the useful next screen.
    $params = ['category' => $category];
    if ((int) ($row['member_id'] ?? 0) < 1) {
        $params['beneficiary'] = (string) ($row['name'] ?? '');
    }
    return url('/treasury?' . http_build_query($params));
};
$resetUrl = static function () use ($listPath, $query, $sortKey): string {
    $params = array_filter(['q' => $query, 'sort' => $sortKey === 'volume' ? '' : $sortKey]);
    return url($listPath) . ($params === [] ? '' : '?' . http_build_query($params));
};
$createUrl = static function (string $direction) use ($category): string {
    return url('/treasury/create?' . http_build_query([
        'category' => $category,
        'direction' => $direction,
    ]));
};
$flowPrimary = $isLoan ? 'income' : 'expense';
$flowSecondary = $isLoan ? 'expense' : 'income';
$canManage = can('treasury.manage');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.' . $key . '_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.' . $key . '_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.suppliers_back')) ?></a>
        <?php if ($canManage): ?>
            <a class="btn" href="<?= e($createUrl($isLoan ? 'income' : 'expense')) ?>">
                <?= e(__('treasury.' . $key . '_new')) ?>
            </a>
            <a class="btn btn-ghost" href="<?= e($createUrl($isLoan ? 'expense' : 'income')) ?>">
                <?= e(__('treasury.' . $key . '_' . ($isLoan ? 'repay' : 'refund'))) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="panel treasury-overview suppliers-overview">
    <div class="treasury-overview-head">
        <div class="treasury-overview-balance">
            <span class="treasury-overview-label"><?= e(__('treasury.' . $key . '_count_label')) ?></span>
            <strong class="treasury-overview-value"><?= e((string) (int) $totals['count']) ?></strong>
        </div>
        <div class="treasury-overview-flows">
            <span class="<?= $flowPrimary === 'income' ? 'is-income' : 'is-expense' ?>">
                <span aria-hidden="true"><?= $flowPrimary === 'income' ? '↓' : '↑' ?></span>
                <?= e($currency->format((float) $totals[$flowPrimary])) ?>
            </span>
            <span class="<?= $flowSecondary === 'income' ? 'is-income' : 'is-expense' ?>">
                <span aria-hidden="true"><?= $flowSecondary === 'income' ? '↓' : '↑' ?></span>
                <?= e($currency->format((float) $totals[$flowSecondary])) ?>
            </span>
        </div>
    </div>
    <p class="setup-hint muted"><?= e(__('treasury.' . $key . '_overview_hint', [
        'movements' => (string) (int) $totals['movements'],
    ])) ?></p>
</section>

<form class="panel filter-bar suppliers-filter" method="get" action="<?= e(url($listPath)) ?>" role="search">
    <?= field('search', [
        'name' => 'q',
        'id' => 'positions-q',
        'label' => (string) __('treasury.' . $key . '_search'),
        'value' => $query,
        'context' => 'filter',
        'placeholder' => (string) __('treasury.' . $key . '_search_placeholder'),
        'maxlength' => 120,
        'attrs' => ['enterkeyhint' => 'search', 'autocomplete' => 'off'],
    ]) ?>
    <?= field('select', [
        'name' => 'sort',
        'id' => 'positions-sort',
        'label' => (string) __('treasury.suppliers_sort'),
        'value' => $sortKey,
        'context' => 'filter',
        'include_empty' => false,
        'options' => [
            'volume' => (string) __('treasury.suppliers_sort_volume'),
            'name' => (string) __('treasury.suppliers_sort_name'),
        ],
    ]) ?>
    <div class="suppliers-filter-actions">
        <button class="btn btn-sm" type="submit"><?= e(__('treasury.suppliers_search_action')) ?></button>
        <?php if ($query !== ''): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($resetUrl()) ?>"><?= e(__('treasury.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>

<div class="panel">
    <?php if ($positions === []): ?>
        <div class="empty-state">
            <strong><?= e($query !== '' ? __('treasury.' . $key . '_empty_search') : __('treasury.' . $key . '_empty')) ?></strong>
            <?php if ($query !== ''): ?>
                <p class="muted"><?= e(__('treasury.suppliers_empty_search_hint', ['q' => $query])) ?></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table class="suppliers-table">
                <thead>
                <tr>
                    <th><?= e(__('treasury.counterparty_short')) ?></th>
                    <th class="is-num"><?= e(__('treasury.suppliers_movements')) ?></th>
                    <th class="is-num"><?= e(__('treasury.' . $key . '_' . ($isLoan ? 'received' : 'paid'))) ?></th>
                    <th class="is-num"><?= e(__('treasury.' . $key . '_' . ($isLoan ? 'repaid' : 'refunded'))) ?></th>
                    <th class="is-num"><?= e(__('treasury.' . $key . '_' . ($isLoan ? 'outstanding' : 'held'))) ?></th>
                    <th><?= e(__('treasury.suppliers_last')) ?></th>
                    <th><span class="visually-hidden"><?= e(__('treasury.actions')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($positions as $row): ?>
                    <?php
                    $income = (float) ($row['income'] ?? 0);
                    $expense = (float) ($row['expense'] ?? 0);
                    $open = $isLoan ? $income - $expense : $expense - $income;
                    $primary = $isLoan ? $income : $expense;
                    $secondary = $isLoan ? $expense : $income;
                    ?>
                    <tr>
                        <td><a class="suppliers-name" href="<?= e($ledgerUrl($row)) ?>"><?= e((string) ($row['name'] ?? '')) ?></a></td>
                        <td class="is-num"><?= e((string) (int) ($row['movements'] ?? 0)) ?></td>
                        <td class="is-num is-income"><?= $primary > 0 ? e($currency->format($primary)) : '—' ?></td>
                        <td class="is-num is-expense"><?= $secondary > 0 ? e($currency->format($secondary)) : '—' ?></td>
                        <td class="is-num<?= $open < 0 ? ' is-negative' : '' ?>"><?= e($currency->format($open)) ?></td>
                        <td><?= e((string) ($row['last_date_formatted'] ?? '')) ?></td>
                        <td class="suppliers-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e($ledgerUrl($row)) ?>"><?= e(__('treasury.suppliers_filter')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

