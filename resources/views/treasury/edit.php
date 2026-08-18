<?php
/** @var array<string,mixed> $movement */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<array> $members */
/** @var array{auto_from_payments?:bool} $config */
/** @var list<string> $beneficiaries */
/** @var \Socly\Services\CurrencyService $currency */
$old = old_input();
$values = $old !== [] ? $old : [
    'direction' => (string) ($movement['direction'] ?? 'income'),
    'amount' => (string) ($movement['amount'] ?? ''),
    'movement_date' => (string) ($movement['movement_date'] ?? date('Y-m-d')),
    'category' => (string) ($movement['category'] ?? 'membership_fee'),
    'payment_method' => (string) ($movement['payment_method'] ?? 'cash'),
    'member_id' => (string) ($movement['member_id'] ?? ''),
    'description' => (string) ($movement['description'] ?? ''),
    'new_category' => '',
    'invoice_payment' => (string) ($movement['invoice_payment'] ?? ''),
    'invoice_number' => (string) ($movement['invoice_number'] ?? ''),
    'beneficiary' => (string) ($movement['beneficiary'] ?? ''),
    'attachment_path' => (string) ($movement['attachment_path'] ?? ''),
];
$movementId = (int) ($movement['id'] ?? 0);
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.edit')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.edit_lede')) ?></p>
    </div>
</div>

<form class="panel" method="post" action="<?= e(url('/treasury/' . $movementId)) ?>" enctype="multipart/form-data"
      data-treasury-form data-leave-guard data-confirm-template="<?= e(__('treasury.confirm_summary')) ?>"
      data-max-upload-bytes="<?= (int) upload_limit_bytes() ?>"
      data-msg-upload-too-large="<?= e(__('documents.upload_too_large', ['max' => upload_max_mb()])) ?>">
    <?= csrf_field() ?>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.edit')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.edit_lede')) ?></p>
        </div>
        <div class="actions">
            <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('common.back')) ?></a>
            <button class="btn" type="submit"><?= e(__('treasury.update')) ?></button>
        </div>
    </div>
    <?php require __DIR__ . '/_form_fields.php'; ?>
    <div class="form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e(__('treasury.update')) ?></button>
    </div>
</form>
