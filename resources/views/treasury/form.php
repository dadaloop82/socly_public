<?php
/**
 * Prima cassa — nuovo/modifica movimento (pagina dedicata, come “Nuovo socio”).
 *
 * @var bool $isEdit
 * @var array<string,mixed>|null $movement
 * @var string $action
 * @var string $default_category
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{key:string,label:string}> $accounts
 * @var list<array> $members
 * @var array{auto_from_payments?:bool} $config
 * @var list<string> $beneficiaries
 * @var list<array{label:string,options:list<array{value:string,label:string,direction:string,category:string}>}>|null $budget_items
 * @var \Socly\Services\CurrencyService $currency
 */
$movementId = $isEdit ? (int) ($movement['id'] ?? 0) : 0;
$old = old_input();
if ($old !== []) {
    $values = $old;
} elseif ($isEdit && $movement !== null) {
    $values = [
        'direction' => (string) ($movement['direction'] ?? 'income'),
        'amount' => (string) ($movement['amount'] ?? ''),
        'amount_entered' => (string) ($movement['amount_entered'] ?? ''),
        'amount_currency' => (string) ($movement['amount_currency'] ?? ''),
        'movement_date' => (string) ($movement['movement_date'] ?? date('Y-m-d')),
        'category' => (string) ($movement['category'] ?? 'membership_fee'),
        'budget_item_id' => (string) ($movement['budget_item_id'] ?? ''),
        'payment_method' => (string) ($movement['payment_method'] ?? ''),
        'account' => (string) ($movement['account'] ?? ''),
        'transfer_account' => (string) ($movement['transfer_account'] ?? ''),
        'member_id' => (string) ($movement['member_id'] ?? ''),
        'member_involved' => !empty($movement['member_id']) ? '1' : '',
        'description' => (string) ($movement['description'] ?? ''),
        'new_category' => '',
        'invoice_payment' => (string) ($movement['invoice_payment'] ?? ''),
        'invoice_number' => (string) ($movement['invoice_number'] ?? ''),
        'invoice_date' => (string) ($movement['invoice_date'] ?? ''),
        'invoice_due_date' => (string) ($movement['invoice_due_date'] ?? ''),
        'invoice_title' => (string) ($movement['invoice_title'] ?? ''),
        'beneficiary' => (string) ($movement['beneficiary'] ?? ''),
        'attachment_path' => (string) ($movement['attachment_path'] ?? ''),
    ];
} else {
    $values = [
        'direction' => 'income',
        'amount' => '',
        'amount_entered' => '',
        'amount_currency' => '',
        'movement_date' => date('Y-m-d'),
        'category' => (string) ($default_category ?? 'membership_fee'),
        'budget_item_id' => '',
        'payment_method' => '',
        'account' => '',
        'transfer_account' => '',
        'member_id' => '',
        'member_involved' => '',
        'description' => '',
        'new_category' => '',
        'invoice_payment' => '',
        'invoice_number' => '',
        'invoice_date' => '',
        'invoice_due_date' => '',
        'invoice_title' => '',
        'beneficiary' => '',
        'attachment_path' => '',
    ];
}
$values['attachment_preview_url'] = $isEdit && $movementId > 0
    ? url('/treasury/' . $movementId . '/attachment')
    : '';
$values['document_edit_url'] = $isEdit && !empty($movement['document_id'])
    ? url('/documents/' . (int) $movement['document_id'])
    : '';

$pageTitle = $isEdit ? __('treasury.edit') : __('treasury.add_movement');
$pageLede = $isEdit ? __('treasury.edit_lede') : __('treasury.add_lede');
$submitLabel = $isEdit ? __('treasury.update') : __('treasury.submit');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-lede"><?= e($pageLede) ?></p>
        <?php if ($isEdit): ?>
            <?php
            $auditCreated = format_datetime($movement['created_at'] ?? null);
            $auditUpdated = format_datetime($movement['updated_at'] ?? null);
            $auditUser = trim((string) ($movement['creator_name'] ?? ''));
            ?>
            <?php if ($auditCreated !== '' || $auditUpdated !== ''): ?>
                <p class="muted treasury-audit">
                    <?php if ($auditCreated !== ''): ?>
                        <?= e(__('treasury.audit_created', [
                            'date' => $auditCreated,
                            'user' => $auditUser !== '' ? $auditUser : (string) __('treasury.none'),
                        ])) ?>
                    <?php endif; ?>
                    <?php if ($auditUpdated !== '' && $auditUpdated !== $auditCreated): ?>
                        · <?= e(__('treasury.audit_updated', ['date' => $auditUpdated])) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<form id="treasury-form" class="panel treasury-movement-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data"
      data-treasury-form data-leave-guard data-confirm-template="<?= e(__('treasury.confirm_summary')) ?>"
      data-base-currency="<?= e($currency->code()) ?>"
      data-account-balances-url="<?= e(url('/treasury/accounts/balances')) ?>"
      data-msg-account-option="<?= e(__('treasury.account_option_balance')) ?>"
      data-msg-doc-loaded="<?= e(__('treasury.document_loaded')) ?>"
      data-msg-doc-idle="<?= e(__('documents.upload_idle')) ?>"
      data-msg-doc-change="<?= e(__('treasury.document_change')) ?>"
      data-max-upload-bytes="<?= (int) upload_limit_bytes() ?>"
      data-msg-upload-too-large="<?= e(__('documents.upload_too_large', ['max' => upload_max_mb()])) ?>">
    <?= csrf_field() ?>
    <?php require __DIR__ . '/_form_fields.php'; ?>
    <div class="form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e($submitLabel) ?></button>
    </div>
</form>
