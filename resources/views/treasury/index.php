<?php
/** @var array{movements:list<array>,balance:float,income:float,expense:float} $ledger */
/** @var list<array{key:string,label:string,items:list<array>}> $movement_groups */
/** @var array{auto_from_payments?:bool} $config */
/** @var list<array> $members */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<array{label:string,keys:list<string>}> $category_groups */
/** @var list<array{key:string,directions:string,default:string}> $movement_kinds */
/** @var list<array{label:string,keys:list<string>}> $payment_method_groups */
/** @var string $default_category */
/** @var string $search_query */
/** @var list<string> $beneficiaries */
/** @var bool $hasAnyMovements */
/** @var \Socly\Services\CurrencyService $currency */
$old = old_input();
$values = $old !== [] ? $old : [
    'direction' => 'income',
    'movement_kind' => 'operating',
    'amount' => '',
    'amount_currency' => '',
    'member_involved' => '',
    'movement_date' => date('Y-m-d'),
    'category' => (string) ($default_category ?? 'membership_fee'),
    'payment_method' => 'cash',
    'member_id' => '',
    'description' => '',
    'new_category' => '',
    'invoice_payment' => '',
    'invoice_number' => '',
    'invoice_date' => '',
    'invoice_due_date' => '',
    'beneficiary' => '',
];
$canManage = can('treasury.manage');
$formOpen = $old !== [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.lede')) ?></p>
    </div>
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

<?php if (!empty($hasAnyMovements)): ?>
<form class="panel filter-bar treasury-filter" method="get" action="<?= e(url('/treasury')) ?>" role="search">
    <div class="panel-header treasury-filter-head">
        <div>
            <h2 class="section-title"><?= e(__('treasury.search')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.search_lede')) ?></p>
        </div>
        <button class="btn btn-sm treasury-filter-submit-desktop" type="submit"><?= e(__('treasury.search')) ?></button>
    </div>
    <div class="treasury-filter-q">
        <label class="treasury-filter-q-label" for="treasury-q"><?= e(__('treasury.search')) ?></label>
        <div class="treasury-filter-q-row">
            <input
                id="treasury-q"
                type="search"
                name="q"
                value="<?= e((string) ($search_query ?? '')) ?>"
                placeholder="<?= e(__('treasury.search_placeholder')) ?>"
                maxlength="120"
                autocomplete="off"
                enterkeyhint="search"
            >
            <button class="btn btn-sm treasury-filter-submit-mobile" type="submit"><?= e(__('treasury.search')) ?></button>
            <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.search_clear')) ?></a>
            <?php endif; ?>
        </div>
    </div>
</form>
<?php endif; ?>

<?php if ($canManage): ?>
<details class="panel treasury-form-panel" data-treasury-form-panel <?= $formOpen ? 'open' : '' ?>>
    <summary class="treasury-form-summary">
        <span class="treasury-form-summary-text">
            <span class="section-title"><?= e(__('treasury.add_movement')) ?></span>
            <span class="section-lede"><?= e(__('treasury.add_lede')) ?></span>
        </span>
        <span class="treasury-form-chevron" aria-hidden="true"></span>
    </summary>
    <form class="treasury-form-body" method="post" action="<?= e(url('/treasury')) ?>" enctype="multipart/form-data" data-treasury-form data-leave-guard
          data-confirm-template="<?= e(__('treasury.confirm_summary')) ?>"
          data-base-currency="<?= e($currency->code()) ?>"
          data-msg-doc-loaded="<?= e(__('treasury.document_loaded')) ?>"
          data-msg-doc-idle="<?= e(__('documents.upload_idle')) ?>"
          data-msg-doc-change="<?= e(__('treasury.document_change')) ?>"
          data-max-upload-bytes="<?= (int) upload_limit_bytes() ?>"
          data-msg-upload-too-large="<?= e(__('documents.upload_too_large', ['max' => upload_max_mb()])) ?>">
        <?= csrf_field() ?>
        <?php require __DIR__ . '/_form_fields.php'; ?>
        <div class="form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('treasury.submit')) ?></button>
        </div>
    </form>
</details>
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
                        <span class="doc-archive-group-count muted"><?= e((string) count($group['items'])) ?></span>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="treasury-ledger">
                            <thead>
                            <tr>
                                <th><?= e(__('treasury.operation_date')) ?></th>
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
                                $methodKey = \Socly\Services\TreasuryService::normalizePaymentMethod((string) ($row['payment_method'] ?? 'cash'));
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
                                        <?php if (!empty($row['invoice_date'])): ?><div><?= e(__('treasury.invoice_date')) ?>: <?= e(format_date($row['invoice_date']) ?: (string) $row['invoice_date']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['invoice_due_date'])): ?><div><?= e(__('treasury.invoice_due_date')) ?>: <?= e(format_date($row['invoice_due_date']) ?: (string) $row['invoice_due_date']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['amount_entered']) && !empty($row['amount_currency'])): ?><div><?= e(__('treasury.amount_original')) ?>: <?= e(number_format((float) $row['amount_entered'], 2, ',', '.')) ?> <?= e((string) $row['amount_currency']) ?></div><?php endif; ?>
                                        <?php if (!empty($row['attachment_path'])): ?><div><a href="<?= e(url('/treasury/' . $rowId . '/attachment')) ?>" target="_blank" rel="noopener"><?= e(__('treasury.upload_document')) ?></a></div><?php endif; ?>
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
