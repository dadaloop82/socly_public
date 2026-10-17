<?php
/** @var array{movements:list<array>,balance:float,income:float,expense:float} $ledger */
/** @var list<array{key:string,label:string,items:list<array>}> $movement_groups */
/** @var array{auto_from_payments?:bool} $config */
/** @var list<array> $members */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<array{label:string,keys:list<string>}> $category_groups */
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
$hasActiveFilters = trim((string) ($search_query ?? '')) !== ''
    || $filterPeriod !== ''
    || $filterDirection !== ''
    || $filterCategory !== ''
    || $filterAccount !== '';
$accountFilterOptions = [];
foreach (($accounts ?? []) as $accountOption) {
    $accountFilterOptions[(string) ($accountOption['key'] ?? '')] = (string) ($accountOption['label'] ?? '');
}
// Keeps the current filters when jumping from a wallet card to the filtered ledger.
$ledgerParams = [];
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
if ($sort !== 'date_desc') {
    $ledgerParams['sort'] = $sort;
}
if (empty($group_by_category)) {
    $ledgerParams['group'] = '0';
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
    <?php if ($canManage): ?>
        <div class="actions">
            <a class="btn<?= empty($hasAnyMovements) ? ' is-first-cta' : '' ?>" href="<?= e(url('/treasury/create')) ?>"><?= e(__('treasury.add_movement')) ?></a>
        </div>
    <?php endif; ?>
</div>

<div class="stats stats-context-treasury">
    <div class="stat">
        <div class="label"><?= e(__('treasury.balance')) ?></div>
        <div class="value"><?= e($currency->format((float) $ledger['balance'])) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.income_total')) ?></div>
        <div class="value stat-positive"><?= e($currency->format((float) $ledger['income'])) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.expense_total')) ?></div>
        <div class="value stat-negative"><?= e($currency->format((float) $ledger['expense'])) ?></div>
    </div>
</div>

<?php if (!empty($account_balances)): ?>
    <section class="panel treasury-wallets" data-treasury-wallets>
        <header class="treasury-wallets-head">
            <h2 class="section-title"><?= e(__('treasury.accounts_overview')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.accounts_overview_lede')) ?></p>
        </header>
        <div class="treasury-wallets-grid">
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
                    <span class="treasury-wallet-label"><?= e((string) ($wallet['label'] ?? '')) ?></span>
                    <strong class="treasury-wallet-balance<?= $walletBalance < 0 ? ' is-negative' : '' ?>"><?= e($currency->format($walletBalance)) ?></strong>
                    <span class="treasury-wallet-meta">
                        <span class="is-income">+ <?= e($currency->format((float) ($wallet['income'] ?? 0))) ?></span>
                        <span class="is-expense">− <?= e($currency->format((float) ($wallet['expense'] ?? 0))) ?></span>
                        <?php if ((float) ($wallet['transfer_in'] ?? 0) !== 0.0 || (float) ($wallet['transfer_out'] ?? 0) !== 0.0): ?>
                            <span class="is-transfer">↔ <?= e($currency->format((float) ($wallet['transfer_in'] ?? 0) - (float) ($wallet['transfer_out'] ?? 0))) ?></span>
                        <?php endif; ?>
                        <?php if (!$walletActive): ?>
                            <span class="is-muted"><?= e(__('treasury.account_inactive_suffix')) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (!empty($hasAnyMovements)): ?>
<?php
$categoryFilterOptions = [];
foreach ($categories as $categoryOption) {
    $categoryFilterOptions[(string) ($categoryOption['key'] ?? '')] = (string) ($categoryOption['label'] ?? '');
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
            'name' => 'category',
            'id' => 'treasury-category-filter',
            'label' => (string) __('treasury.filter_category'),
            'value' => $filterCategory,
            'context' => 'filter',
            'empty_label' => (string) __('treasury.filter_all'),
            'options' => $categoryFilterOptions,
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
            'options' => [
                'date_desc' => (string) __('treasury.sort_date_desc'),
                'date_asc' => (string) __('treasury.sort_date_asc'),
                'created_desc' => (string) __('treasury.sort_created_desc'),
                'invoice_desc' => (string) __('treasury.sort_invoice_desc'),
            ],
        ]) ?>
        <?= field('hidden', ['name' => 'group', 'value' => '0']) ?>
        <?= field('checkbox', [
            'name' => 'group',
            'id' => 'treasury-group',
            'label' => (string) __('treasury.group_toggle'),
            'value' => !empty($group_by_category) ? '1' : '',
            'check_value' => '1',
            'context' => 'filter',
            'wrapper_class' => 'treasury-filter-group',
        ]) ?>
        <?php if ($hasActiveFilters): ?>
            <a class="btn btn-ghost btn-sm treasury-filter-reset" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.ledger')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.ledger_lede')) ?></p>
        </div>
    </div>
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
                                <th><?= e(__('treasury.operation_date')) ?></th>
                                <th><?= e(__('treasury.invoice_date')) ?></th>
                                <th><?= e(__('treasury.description')) ?></th>
                                <th><?= e(__('treasury.method')) ?></th>
                                <th><?= e(__('treasury.details')) ?></th>
                                <th><?= e(__('treasury.income')) ?></th>
                                <th><?= e(__('treasury.expense')) ?></th>
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
                                $invoiceTitle = trim((string) ($row['invoice_title'] ?? ''));
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
                                $editUrl = $canManage ? url('/treasury/' . $rowId . '/edit') : '';
                                $accountKey = \Socly\Services\TreasuryService::normalizeAccount((string) ($row['account'] ?? ''));
                                $accountLabel = $accountKey !== '' ? (string) __('treasury.account_' . $accountKey) : '';
                                if ($accountKey !== '' && $accountLabel === 'treasury.account_' . $accountKey) {
                                    $accountLabel = $accountKey;
                                }
                                $methodRaw = trim((string) ($row['payment_method'] ?? ''));
                                $methodKey = $methodRaw !== '' ? \Socly\Services\TreasuryService::normalizePaymentMethod($methodRaw) : '';
                                $methodLabel = $methodKey !== '' ? (string) __('treasury.method_' . $methodKey) : '';
                                if ($methodKey !== '' && $methodLabel === 'treasury.method_' . $methodKey) {
                                    $methodLabel = $methodKey;
                                }
                                // Wallet first, method second: cash (and giroconti) need no method.
                                $methodCell = $accountLabel !== '' ? $accountLabel : $methodLabel;
                                if ($accountLabel !== '' && $methodLabel !== '' && $methodKey !== 'cash') {
                                    $methodCell .= ' · ' . $methodLabel;
                                }
                                $transferLabel = '';
                                $transferRaw = trim((string) ($row['transfer_account'] ?? ''));
                                if ($isTransfer && $transferRaw !== '') {
                                    $transferKey = \Socly\Services\TreasuryService::normalizeAccount($transferRaw);
                                    $transferLabel = $transferKey !== '' ? (string) __('treasury.account_' . $transferKey) : $transferRaw;
                                    if ($transferKey !== '' && $transferLabel === 'treasury.account_' . $transferKey) {
                                        $transferLabel = $transferKey;
                                    }
                                }
                                ?>
                                <tr
                                    class="treasury-row treasury-row-<?= $isTransfer ? 'transfer' : ($isIncome ? 'income' : 'expense') ?><?= $canManage ? ' doc-row-editable' : '' ?>"
                                    <?php if ($canManage): ?>
                                        data-href="<?= e($editUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('treasury.edit') . ': ' . ($desc !== '' ? $desc : (string) $rowId)) ?>"
                                    <?php endif; ?>
                                >
                                    <td><?= e(format_date($row['movement_date'] ?? null) ?: '—') ?></td>
                                    <td><?= e(!empty($row['invoice_date']) ? (format_date($row['invoice_date']) ?: (string) $row['invoice_date']) : '—') ?></td>
                                    <td><?= e($desc !== '' ? $desc : '—') ?></td>
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
                                        <?php if (!empty($row['invoice_number'])): ?><div><?= e(__('treasury.invoice_number')) ?>: <?= e((string) $row['invoice_number']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['invoice_due_date'])): ?><div><?= e(__('treasury.invoice_due_date')) ?>: <?= e(format_date($row['invoice_due_date']) ?: (string) $row['invoice_due_date']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['amount_entered']) && !empty($row['amount_currency'])): ?><div><?= e(__('treasury.amount_original')) ?>: <?= e(number_format((float) $row['amount_entered'], 2, ',', '.')) ?> <?= e((string) $row['amount_currency']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['attachment_path'])): ?><div><a href="<?= e(url('/treasury/' . $rowId . '/attachment')) ?>" target="_blank" rel="noopener"><?= e(__('treasury.upload_document')) ?></a></div><?php endif; ?>
                                        <?php if (empty($row['member_id']) && empty($row['beneficiary']) && empty($row['invoice_number']) && empty($row['invoice_title']) && empty($row['attachment_path'])): ?><?= e(__('treasury.none')) ?><?php endif; ?>
                                    </td>
                                    <td class="<?= $isTransfer ? 'amount-transfer' : 'amount-income' ?>"><?= $isIncome || $isTransfer ? ($isTransfer ? '↔ ' : '') . e($currency->format($amount)) : '—' ?></td>
                                    <td class="amount-expense"><?= $isIncome || $isTransfer ? '—' : e($currency->format($amount)) ?></td>
                                    <td class="doc-row-actions" data-stop-row-nav>
                                        <?= row_actions([
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
</div>
