<?php
/**
 * Cash book register - new/modify movement (dedicated page, like "New member").
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
// Only a resubmission of the same form (after an error) can reuse the entered values: a `_old` left over from
// another screen must not empty the tab being edited.
if ($old !== [] && ($movementId > 0 ? !isset($old['direction']) : !array_key_exists('amount', $old))) {
    $old = [];
}
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
        'runts_line' => (string) ($movement['runts_line'] ?? ''),
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
        'document_type' => (string) ($movement['document_type'] ?? ''),
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
        'document_type' => '',
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
      <?php if (!empty($isEdit)): ?><?= version_attrs('treasury', ['id' => (int) ($movement['id'] ?? 0), 'row_version' => (int) ($movement_version ?? ($movement['row_version'] ?? 0))]) ?><?php endif; ?>
      data-treasury-form data-leave-guard data-confirm-template="<?= e($isEdit ? __('treasury.confirm_summary_edit') : __('treasury.confirm_summary')) ?>"
      data-base-currency="<?= e($currency->code()) ?>"
      data-account-balances-url="<?= e(url('/treasury/accounts/balances')) ?>"
      data-msg-account-option="<?= e(__('treasury.account_option_balance')) ?>"
      data-msg-doc-loaded="<?= e(__('treasury.document_loaded')) ?>"
      data-msg-doc-idle="<?= e(__('documents.upload_idle')) ?>"
      data-msg-doc-change="<?= e(__('treasury.document_change')) ?>"
      data-similar-url="<?= e(url('/treasury/similar')) ?>"
      data-original-category="<?= e((string) ($movement['category'] ?? '')) ?>"
      data-original-runts-line="<?= e((string) ($movement['runts_line'] ?? '')) ?>"
      data-original-budget-item="<?= e((string) (int) ($movement['budget_item_id'] ?? 0)) ?>"
      data-similar-apply-url="<?= e(url('/treasury/similar/apply')) ?>"
      data-msg-similar-title="<?= e(__('treasury.similar_title', ['count' => ':count'])) ?>"
      data-msg-similar-hint="<?= e(__('treasury.similar_hint')) ?>"
      data-msg-similar-changes="<?= e(__('treasury.similar_changes')) ?>"
      data-msg-similar-apply="<?= e(__('treasury.similar_apply')) ?>"
      data-msg-similar-dismiss="<?= e(__('treasury.similar_dismiss')) ?>"
      data-msg-similar-done="<?= e(__('treasury.similar_applied', ['count' => ':count'])) ?>"
      data-msg-similar-skipped="<?= e(__('treasury.similar_skipped', ['count' => ':count'])) ?>"
      data-max-upload-bytes="<?= (int) upload_limit_bytes() ?>"
      data-msg-upload-too-large="<?= e(__('documents.upload_too_large', ['max' => upload_max_mb()])) ?>">
    <?= csrf_field() ?>
    <?php if (!empty($isEdit)): ?>
        <?= view_partial('partials/record_lock_notice', [
            'entity' => 'treasury',
            'row' => $movement ?? null,
            'recordId' => (int) ($movement['id'] ?? 0),
            'version' => (int) ($movement_version ?? ($movement['row_version'] ?? 0)),
            'conflict' => $movement_conflict ?? null,
        ]) ?>
    <?php endif; ?>
    <?php require __DIR__ . '/_form_fields.php'; ?>
    <div class="form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e($submitLabel) ?></button>
    </div>
</form>
