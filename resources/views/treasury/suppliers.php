<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/**
 * “Gestione fornitori”: the free-text counterparties of the register (suppliers, bodies, people)
 * with how much money came in and went out for each of them. The action links straight to the
 * ledger filtered on that supplier, so the single movements stay one click away.
 *
 * @var list<array{name:string,movements:int,income:float,expense:float,net:float,last_date:string,last_date_formatted:string}> $suppliers
 * @var array{suppliers:int,movements:int,income:float,expense:float} $supplier_totals
 * @var string $search_query
 * @var string $sort
 * @var \Socly\Services\CurrencyService $currency
 */
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$totals = is_array($supplier_totals ?? null) ? $supplier_totals : ['suppliers' => 0, 'movements' => 0, 'income' => 0, 'expense' => 0];
$query = (string) ($search_query ?? '');
$sortKey = (string) ($sort ?? 'volume') === 'name' ? 'name' : 'volume';
$ledgerUrl = static fn (string $name): string => url('/treasury?' . http_build_query(['beneficiary' => $name]));
$resetUrl = static function () use ($query, $sortKey): string {
    $params = array_filter(['q' => $query, 'sort' => $sortKey === 'volume' ? '' : $sortKey]);
    return url('/treasury/suppliers' . ($params === [] ? '' : '?' . http_build_query($params)));
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.suppliers_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.suppliers_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.suppliers_back')) ?></a>
    </div>
</div>

<section class="panel treasury-overview suppliers-overview">
    <div class="treasury-overview-head">
        <div class="treasury-overview-balance">
            <span class="treasury-overview-label"><?= e(__('treasury.suppliers_count_label')) ?></span>
            <strong class="treasury-overview-value"><?= e((string) (int) $totals['suppliers']) ?></strong>
        </div>
        <div class="treasury-overview-flows">
            <span class="is-income"><span aria-hidden="true">↓</span> <?= e($currency->format((float) $totals['income'])) ?></span>
            <span class="is-expense"><span aria-hidden="true">↑</span> <?= e($currency->format((float) $totals['expense'])) ?></span>
        </div>
    </div>
    <p class="setup-hint muted"><?= e(__('treasury.suppliers_overview_hint', [
        'movements' => (string) (int) $totals['movements'],
    ])) ?></p>
</section>

<form class="panel filter-bar suppliers-filter" method="get" action="<?= e(url('/treasury/suppliers')) ?>" role="search">
    <?= field('search', [
        'name' => 'q',
        'id' => 'suppliers-q',
        'label' => (string) __('treasury.suppliers_search'),
        'value' => $query,
        'context' => 'filter',
        'placeholder' => (string) __('treasury.suppliers_search_placeholder'),
        'maxlength' => 120,
        'attrs' => ['enterkeyhint' => 'search', 'autocomplete' => 'off'],
    ]) ?>
    <?= field('select', [
        'name' => 'sort',
        'id' => 'suppliers-sort',
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
    <?php if ($suppliers === []): ?>
        <div class="empty-state">
            <strong><?= e($query !== '' ? __('treasury.suppliers_empty_search') : __('treasury.suppliers_empty')) ?></strong>
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
                    <th class="is-num"><?= e(__('treasury.income')) ?></th>
                    <th class="is-num"><?= e(__('treasury.expense')) ?></th>
                    <th class="is-num"><?= e(__('treasury.suppliers_net')) ?></th>
                    <th><?= e(__('treasury.suppliers_last')) ?></th>
                    <th><span class="visually-hidden"><?= e(__('treasury.actions')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($suppliers as $supplier): ?>
                    <?php
                    $name = (string) ($supplier['name'] ?? '');
                    $income = (float) ($supplier['income'] ?? 0);
                    $expense = (float) ($supplier['expense'] ?? 0);
                    $net = (float) ($supplier['net'] ?? 0);
                    ?>
                    <tr>
                        <td><a class="suppliers-name" href="<?= e($ledgerUrl($name)) ?>"><?= e($name) ?></a></td>
                        <td class="is-num"><?= e((string) (int) ($supplier['movements'] ?? 0)) ?></td>
                        <td class="is-num is-income"><?= $income > 0 ? e($currency->format($income)) : '—' ?></td>
                        <td class="is-num is-expense"><?= $expense > 0 ? e($currency->format($expense)) : '—' ?></td>
                        <td class="is-num<?= $net < 0 ? ' is-negative' : '' ?>"><?= e($currency->format($net)) ?></td>
                        <td><?= e((string) ($supplier['last_date_formatted'] ?? '')) ?></td>
                        <td class="suppliers-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e($ledgerUrl($name)) ?>"><?= e(__('treasury.suppliers_filter')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
