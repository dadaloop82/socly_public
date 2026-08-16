<?php
/** @var array{movements:list<array>,balance:float,income:float,expense:float} $ledger */
/** @var list<array{key:string,label:string,items:list<array>}> $movement_groups */
/** @var array{auto_from_payments?:bool} $config */
/** @var list<array> $members */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var string $default_category */
/** @var string $search_query */
/** @var list<string> $beneficiaries */
/** @var \Socly\Services\CurrencyService $currency */
$old = old_input();
$values = $old !== [] ? $old : [
    'direction' => 'income',
    'amount' => '',
    'movement_date' => date('Y-m-d'),
    'category' => (string) ($default_category ?? 'membership_fee'),
    'payment_method' => 'cash',
    'member_id' => '',
    'description' => '',
    'new_category' => '',
    'invoice_payment' => '',
    'invoice_number' => '',
    'beneficiary' => '',
];
$canManage = can('treasury.manage');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.lede')) ?></p>
    </div>
</div>

<div class="stats">
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

<?php if ($canManage): ?>
<form class="panel" method="post" action="<?= e(url('/treasury')) ?>" enctype="multipart/form-data" data-treasury-form data-leave-guard
      data-confirm-template="<?= e(__('treasury.confirm_summary')) ?>">
    <?= csrf_field() ?>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.add_movement')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.add_lede')) ?></p>
        </div>
    </div>
    <?php require __DIR__ . '/_form_fields.php'; ?>
    <div class="form-actions form-actions-end">
        <button class="btn" type="submit"><?= e(__('treasury.submit')) ?></button>
    </div>
</form>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.ledger')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.ledger_lede')) ?></p>
        </div>
        <form class="doc-archive-search" method="get" action="<?= e(url('/treasury')) ?>" role="search">
            <label class="visually-hidden" for="treasury-q"><?= e(__('treasury.search')) ?></label>
            <input
                id="treasury-q"
                type="search"
                name="q"
                value="<?= e((string) ($search_query ?? '')) ?>"
                placeholder="<?= e(__('treasury.search_placeholder')) ?>"
                maxlength="120"
                autocomplete="off"
            >
            <button class="btn btn-sm" type="submit"><?= e(__('treasury.search')) ?></button>
            <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.search_clear')) ?></a>
            <?php endif; ?>
        </form>
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
                        <span class="doc-archive-group-count muted"><?= e((string) count($group['items'])) ?></span>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="treasury-ledger">
                            <thead>
                            <tr>
                                <th><?= e(__('treasury.date')) ?></th>
                                <th><?= e(__('treasury.description')) ?></th>
                                <th><?= e(__('treasury.method')) ?></th>
                                <th><?= e(__('treasury.details')) ?></th>
                                <th><?= e(__('treasury.created')) ?></th>
                                <th><?= e(__('treasury.income')) ?></th>
                                <th><?= e(__('treasury.expense')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['items'] as $row): ?>
                                <?php
                                $isIncome = ($row['direction'] ?? '') === 'income';
                                $amount = (float) ($row['amount'] ?? 0);
                                $memberLabel = trim((string) (($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '')));
                                $desc = trim((string) ($row['description'] ?? ''));
                                if ($desc === '' && $memberLabel !== '') {
                                    $desc = $memberLabel;
                                }
                                $rowId = (int) ($row['id'] ?? 0);
                                $editUrl = $canManage ? url('/treasury/' . $rowId . '/edit') : '';
                                $methodKey = (string) ($row['payment_method'] ?? 'cash');
                                $methodLabel = __('treasury.method_' . $methodKey);
                                if ($methodLabel === 'treasury.method_' . $methodKey) {
                                    $methodLabel = $methodKey;
                                }
                                ?>
                                <tr
                                    class="treasury-row treasury-row-<?= $isIncome ? 'income' : 'expense' ?><?= $canManage ? ' doc-row-editable' : '' ?>"
                                    <?php if ($canManage): ?>
                                        data-href="<?= e($editUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('treasury.edit') . ': ' . ($desc !== '' ? $desc : (string) $rowId)) ?>"
                                    <?php endif; ?>
                                >
                                    <td><?= e(format_date($row['movement_date'] ?? null) ?: '—') ?></td>
                                    <td><?= e($desc !== '' ? $desc : '—') ?></td>
                                    <td><?= e($methodLabel) ?></td>
                                    <td>
                                        <?php if ($memberLabel !== ''): ?><div><?= e($memberLabel) ?></div><?php endif; ?>
                                        <?php if (!empty($row['beneficiary'])): ?><div><?= e(__('treasury.beneficiary')) ?>: <?= e((string) $row['beneficiary']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['invoice_number'])): ?><div><?= e(__('treasury.invoice_number')) ?>: <?= e((string) $row['invoice_number']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['attachment_path'])): ?><div><a href="<?= e(url('/treasury/' . $rowId . '/attachment')) ?>" target="_blank" rel="noopener"><?= e(__('treasury.invoice_pdf')) ?></a></div><?php endif; ?>
                                        <?php if (empty($row['member_id']) && empty($row['beneficiary']) && empty($row['invoice_number']) && empty($row['attachment_path'])): ?><?= e(__('treasury.none')) ?><?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= e(format_datetime($row['created_at'] ?? null) ?: '—') ?></div>
                                        <div class="muted"><?= e((string) ($row['creator_name'] ?? __('treasury.none'))) ?></div>
                                    </td>
                                    <td class="amount-income"><?= $isIncome ? e($currency->format($amount)) : '—' ?></td>
                                    <td class="amount-expense"><?= !$isIncome ? e($currency->format($amount)) : '—' ?></td>
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
