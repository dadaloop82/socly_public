<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
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
 * “Gestione controparti”: the free-text counterparties of the register (suppliers, bodies, people)
 * with how much money came in and went out for each of them. A counterparty whose name matches a
 * member profile gets the “Socio” badge (linked to the card when unambiguous), and the party filter
 * narrows the list to members or to non-members. The action opens the counterparty's movements in a
 * dialog (bare iframe, same pattern as the add-on movement editor), so the list stays behind it; the
 * plain link still opens the ledger filtered on that counterparty.
 *
 * @var list<array{name:string,is_member:bool,member_id:int,movements:int,income:float,expense:float,net:float,last_date:string,last_date_formatted:string}> $suppliers
 * @var array{suppliers:int,members:int,movements:int,income:float,expense:float} $supplier_totals
 * @var list<array{canonical:string,names:list<string>,movements:int,reason:string}> $merge_suggestions
 * @var string $search_query
 * @var string $sort
 * @var string $party
 * @var \Socly\Services\CurrencyService $currency
 */
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$totals = is_array($supplier_totals ?? null) ? $supplier_totals : ['suppliers' => 0, 'members' => 0, 'movements' => 0, 'income' => 0, 'expense' => 0];
$mergeSuggestions = is_array($merge_suggestions ?? null) ? $merge_suggestions : [];
$query = (string) ($search_query ?? '');
$sortRaw = (string) ($sort ?? 'movements');
$sortKey = in_array($sortRaw, ['movements', 'volume', 'name'], true) ? $sortRaw : 'movements';
$partyKey = in_array((string) ($party ?? ''), ['members', 'others'], true) ? (string) $party : '';
$canMerge = can('treasury.manage');
$ledgerUrl = static fn (string $name): string => url('/treasury?' . http_build_query(['beneficiary' => $name]));
$supplierMovementsUrl = static fn (string $name): string => url('/treasury/supplier-movements?' . http_build_query(['name' => $name]));
$resetUrl = static function () use ($query, $sortKey, $partyKey): string {
    $params = array_filter([
        'q' => $query,
        'sort' => $sortKey === 'movements' ? '' : $sortKey,
        'party' => $partyKey,
    ]);
    return url('/treasury/suppliers' . ($params === [] ? '' : '?' . http_build_query($params)));
};
$reasonLabel = static function (string $reason): string {
    return match ($reason) {
        'spelling' => (string) __('treasury.suppliers_merge_reason_spelling'),
        'same_tokens' => (string) __('treasury.suppliers_merge_reason_same_tokens'),
        'same_core' => (string) __('treasury.suppliers_merge_reason_same_core'),
        'contained' => (string) __('treasury.suppliers_merge_reason_contained'),
        'typo' => (string) __('treasury.suppliers_merge_reason_typo'),
        default => (string) __('treasury.suppliers_merge_reason_similar'),
    };
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
            <?php if ((int) ($totals['members'] ?? 0) > 0): ?>
                <span class="muted suppliers-members-count"><?= e(__('treasury.suppliers_members_count', ['count' => (string) (int) $totals['members']])) ?></span>
            <?php endif; ?>
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

<form class="panel filter-bar suppliers-filter" method="get" action="<?= e(url('/treasury/suppliers')) ?>" role="search" data-live-filter>
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
            'movements' => (string) __('treasury.suppliers_sort_movements'),
            'volume' => (string) __('treasury.suppliers_sort_volume'),
            'name' => (string) __('treasury.suppliers_sort_name'),
        ],
    ]) ?>
    <?= field('select', [
        'name' => 'party',
        'id' => 'suppliers-party',
        'label' => (string) __('treasury.suppliers_party'),
        'value' => $partyKey,
        'context' => 'filter',
        'include_empty' => false,
        'options' => [
            '' => (string) __('treasury.suppliers_party_all'),
            'members' => (string) __('treasury.suppliers_party_members'),
            'others' => (string) __('treasury.suppliers_party_others'),
        ],
    ]) ?>
    <div class="suppliers-filter-actions">
        <button class="btn btn-sm" type="submit"><?= e(__('treasury.suppliers_search_action')) ?></button>
        <?php if ($query !== '' || $partyKey !== ''): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($resetUrl()) ?>"><?= e(__('treasury.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>

<?php if ($mergeSuggestions !== []): ?>
<section class="panel suppliers-merge" data-suppliers-merge>
    <header class="suppliers-merge-head">
        <div>
            <h2 class="suppliers-merge-title"><?= e(__('treasury.suppliers_merge_title')) ?></h2>
            <p class="muted suppliers-merge-lede"><?= e(__('treasury.suppliers_merge_lede')) ?></p>
        </div>
        <span class="badge"><?= e((string) count($mergeSuggestions)) ?></span>
    </header>
    <ul class="suppliers-merge-list">
        <?php foreach ($mergeSuggestions as $suggestion): ?>
            <?php
            $canonical = (string) ($suggestion['canonical'] ?? '');
            $names = array_values(array_filter(array_map('strval', (array) ($suggestion['names'] ?? []))));
            if ($canonical === '' || count($names) < 2) {
                continue;
            }
            $aliases = array_values(array_filter($names, static fn (string $name): bool => mb_strtolower($name) !== mb_strtolower($canonical)));
            ?>
            <li class="suppliers-merge-item">
                <div class="suppliers-merge-body">
                    <strong class="suppliers-merge-canonical"><?= e($canonical) ?></strong>
                    <span class="muted"><?= e(__('treasury.suppliers_merge_also', [
                        'names' => implode(' · ', $aliases),
                    ])) ?></span>
                    <span class="suppliers-merge-meta muted">
                        <?= e(__('treasury.suppliers_merge_meta', [
                            'count' => (string) (int) ($suggestion['movements'] ?? 0),
                            'reason' => $reasonLabel((string) ($suggestion['reason'] ?? '')),
                        ])) ?>
                    </span>
                </div>
                <?php if ($canMerge): ?>
                    <form method="post" action="<?= e(url('/treasury/suppliers/merge')) ?>" class="suppliers-merge-form"
                        data-confirm="<?= e(__('treasury.suppliers_merge_confirm', [
                            'name' => $canonical,
                            'count' => (string) count($aliases),
                        ])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="canonical" value="<?= e($canonical) ?>">
                        <?php foreach ($names as $fromName): ?>
                            <input type="hidden" name="from[]" value="<?= e($fromName) ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm"><?= e(__('treasury.suppliers_merge_action', [
                            'name' => $canonical,
                        ])) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<div class="panel">
    <?php if ($suppliers === []): ?>
        <div class="empty-state">
            <strong><?= e($query !== ''
                ? __('treasury.suppliers_empty_search')
                : ($partyKey !== '' ? __('treasury.suppliers_empty_party') : __('treasury.suppliers_empty'))) ?></strong>
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
                    $isMember = !empty($supplier['is_member']);
                    $memberId = (int) ($supplier['member_id'] ?? 0);
                    $income = (float) ($supplier['income'] ?? 0);
                    $expense = (float) ($supplier['expense'] ?? 0);
                    $net = (float) ($supplier['net'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <a class="suppliers-name" href="<?= e($ledgerUrl($name)) ?>"
                               data-supplier-movements
                               data-supplier-movements-url="<?= e($supplierMovementsUrl($name)) ?>"
                               data-supplier-movements-name="<?= e($name) ?>"><?= e($name) ?></a>
                            <?php if ($isMember): ?>
                                <?php $memberHint = (string) __('treasury.suppliers_member_badge_hint'); ?>
                                <?php if ($memberId > 0): ?>
                                    <a class="badge suppliers-member-badge" href="<?= e(url('/members/' . $memberId)) ?>" title="<?= e($memberHint) ?>"><?= e(__('treasury.suppliers_member_badge')) ?></a>
                                <?php else: ?>
                                    <span class="badge suppliers-member-badge" title="<?= e($memberHint) ?>"><?= e(__('treasury.suppliers_member_badge')) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="is-num"><?= e((string) (int) ($supplier['movements'] ?? 0)) ?></td>
                        <td class="is-num is-income"><?= $income > 0 ? e($currency->format($income)) : '—' ?></td>
                        <td class="is-num is-expense"><?= $expense > 0 ? e($currency->format($expense)) : '—' ?></td>
                        <td class="is-num<?= $net < 0 ? ' is-negative' : '' ?>"><?= e($currency->format($net)) ?></td>
                        <td><?= e((string) ($supplier['last_date_formatted'] ?? '')) ?></td>
                        <td class="suppliers-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e($ledgerUrl($name)) ?>"
                               data-supplier-movements
                               data-supplier-movements-url="<?= e($supplierMovementsUrl($name)) ?>"
                               data-supplier-movements-name="<?= e($name) ?>"><?= e(__('treasury.suppliers_filter')) ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php /* “Filtra i movimenti” dialog: the bare list runs in the iframe, the counterparty page stays behind. */ ?>
<dialog class="supplier-movements-dialog" data-supplier-movements-dialog
    data-msg-title="<?= e(__('treasury.supplier_movements_title', ['name' => ':name'])) ?>">
    <div class="supplier-movements-shell">
        <div class="supplier-movements-dialog-head">
            <div class="supplier-movements-dialog-lead">
                <button type="button" class="btn btn-ghost btn-sm" data-supplier-movements-back hidden>‹ <?= e(__('treasury.supplier_movements_back')) ?></button>
                <h2 class="supplier-movements-dialog-title" data-supplier-movements-title><?= e(__('treasury.suppliers_filter')) ?></h2>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" data-supplier-movements-close><?= e(__('common.close')) ?></button>
        </div>
        <iframe class="supplier-movements-frame" data-supplier-movements-frame
            title="<?= e(__('treasury.suppliers_filter')) ?>" loading="lazy"></iframe>
    </div>
</dialog>

