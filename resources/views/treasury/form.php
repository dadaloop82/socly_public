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
 * Cash book register - new/modify movement (dedicated page, like "New member").
 *
 * @var bool $isEdit
 * @var array<string,mixed>|null $movement
 * @var string $action
 * @var string $default_category
 * @var string $default_direction
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{key:string,label:string}> $accounts
 * @var list<array> $members
 * @var array{auto_from_payments?:bool} $config
 * @var list<string> $beneficiaries
 * @var list<array{label:string,options:list<array{value:string,label:string,direction:string,category:string}>}>|null $budget_items
 * @var array<string,mixed>|null $pattern_profile
 * @var \Socly\Services\CurrencyService $currency
 */
$movementId = $isEdit ? (int) ($movement['id'] ?? 0) : 0;
// The card was just saved: show a persistent status next to the action button (the top flash
// disappears after a few seconds, and the operator is usually scrolled to the bottom).
$justSaved = !empty($just_saved ?? false);
// Embedded quick edit (add-on dialog): bare layout, no page actions and only the save button.
$embed = !empty($embed ?? false);
// “Copia movimento”: the form opens pre-filled like an edit page, but saves a new movement.
// Statement “add missing” also passes a synthetic movement (no id) — keep the “new” title.
$isCopy = !$isEdit && is_array($movement) && (int) ($movement['id'] ?? 0) > 0;
$old = old_input();
// Only a resubmission of the same form (after an error) can reuse the entered values: a `_old` left over from
// another screen must not empty the tab being edited.
if ($old !== [] && ($movementId > 0 ? !isset($old['direction']) : !array_key_exists('amount', $old))) {
    $old = [];
}
if ($old !== []) {
    $values = $old;
} elseif ($movement !== null) {
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
        'notes' => (string) ($movement['notes'] ?? ''),
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
        'direction' => (string) ($default_direction ?? 'income'),
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
        'notes' => '',
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
$values['attachment_name'] = trim((string) ($attachment_name ?? ''));

$pageTitle = $isCopy
    ? __('treasury.copy_movement')
    : ($isEdit ? __('treasury.edit') : __('treasury.add_movement'));
$pageLede = $isEdit ? __('treasury.edit_lede') : __('treasury.add_lede');
$submitLabel = $isEdit ? __('treasury.update') : __('treasury.submit');
$adjacent = is_array($adjacent ?? null) ? $adjacent : ['prev' => null, 'next' => null];
$adjacentPrev = is_array($adjacent['prev'] ?? null) ? $adjacent['prev'] : null;
$adjacentNext = is_array($adjacent['next'] ?? null) ? $adjacent['next'] : null;
$adjacentLabel = static function (array $entry, string $key): string {
    $date = format_date((string) ($entry['date'] ?? ''));

    return (string) __($key, ['date' => $date !== '' ? $date : (string) ($entry['date'] ?? '')]);
};
// Ledger state to come back to: “Back”, the prev/next arrows and the redirect after the save keep
// the filters, the sort and the page the operator was reading.
$returnPath = (string) ($return_path ?? '/treasury');
$returnUrl = (string) ($return_url ?? url('/treasury'));
$retSuffix = $returnPath === '/treasury' ? '' : '?ret=' . rawurlencode($returnPath);
// Prev/next stay inside the dialog: they must keep the bare layout and the ledger return path.
$navSuffix = $embed
    ? '?embed=1' . ($returnPath === '/treasury' ? '' : '&ret=' . rawurlencode($returnPath))
    : $retSuffix;
$patternProfile = is_array($pattern_profile ?? null) ? $pattern_profile : [];
$patternProfileJson = (string) json_encode(
    $patternProfile,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($patternProfileJson === '') {
    $patternProfileJson = '{}';
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-lede"><?= e($pageLede) ?></p>
    </div>
    <div class="actions">
        <?php if (!$embed): ?>
        <?php if ($isEdit): ?>
            <div class="record-nav" role="group" aria-label="<?= e(__('treasury.nav_label')) ?>">
                <?php if ($adjacentPrev !== null && (int) ($adjacentPrev['id'] ?? 0) > 0): ?>
                    <a class="btn btn-ghost btn-sm record-nav-btn" href="<?= e(url('/treasury/' . (int) $adjacentPrev['id'] . '/edit') . $navSuffix) ?>"
                       title="<?= e($adjacentLabel($adjacentPrev, 'treasury.nav_prev_title')) ?>"
                       aria-label="<?= e($adjacentLabel($adjacentPrev, 'treasury.nav_prev_title')) ?>">‹</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm record-nav-btn" aria-disabled="true"
                          title="<?= e(__('treasury.nav_prev_none')) ?>">‹</span>
                <?php endif; ?>
                <?php if ($adjacentNext !== null && (int) ($adjacentNext['id'] ?? 0) > 0): ?>
                    <a class="btn btn-ghost btn-sm record-nav-btn" href="<?= e(url('/treasury/' . (int) $adjacentNext['id'] . '/edit') . $navSuffix) ?>"
                       title="<?= e($adjacentLabel($adjacentNext, 'treasury.nav_next_title')) ?>"
                       aria-label="<?= e($adjacentLabel($adjacentNext, 'treasury.nav_next_title')) ?>">›</a>
                <?php else: ?>
                    <span class="btn btn-ghost btn-sm record-nav-btn" aria-disabled="true"
                          title="<?= e(__('treasury.nav_next_none')) ?>">›</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e($returnUrl) ?>"><?= e(__('common.back')) ?></a>
        <?php endif; ?>
    </div>
</div>

<?php
// “Similar movements” proposal (after an update that changed the classification): rendered on the
// card so saving never throws the operator back to the ledger.
$probe = is_array($similar_probe ?? null) ? $similar_probe : null;
if ($probe !== null && $movementId > 0) {
    echo view_partial('treasury/_similar_probe', [
        'probe' => $probe,
        'ret_path' => $returnPath,
        'back_path' => '/treasury/' . $movementId . '/edit',
        'dismiss_url' => url('/treasury/' . $movementId . '/edit') . $navSuffix,
    ]);
}
?>
<form id="treasury-form" class="panel treasury-movement-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data"
      <?php if (!empty($isEdit)): ?><?= version_attrs('treasury', ['id' => (int) ($movement['id'] ?? 0), 'row_version' => (int) ($movement_version ?? ($movement['row_version'] ?? 0))]) ?><?php endif; ?>
      data-treasury-form data-leave-guard
      <?php if (!$isEdit && !$embed): ?>data-confirm-template="<?= e(__('treasury.confirm_summary')) ?>"<?php endif; ?>
      data-base-currency="<?= e($currency->code()) ?>"
      data-account-balances-url="<?= e(url('/treasury/accounts/balances')) ?>"
      data-budget-items-url="<?= e(url('/treasury/budget-items')) ?>"
      data-msg-account-option="<?= e(__('treasury.account_option_balance')) ?>"
      data-msg-doc-loaded="<?= e(__('treasury.document_loaded')) ?>"
      data-msg-doc-idle="<?= e(__('documents.upload_idle')) ?>"
      data-msg-doc-change="<?= e(__('treasury.document_change')) ?>"
      data-similar-url="<?= e(url('/treasury/similar')) ?>"
      data-original-category="<?= e((string) ($movement['category'] ?? '')) ?>"
      data-original-runts-line="<?= e((string) ($movement['runts_line'] ?? '')) ?>"
      data-original-budget-item="<?= e((string) (int) ($movement['budget_item_id'] ?? 0)) ?>"
      data-similar-apply-url="<?= e(url('/treasury/similar/apply')) ?>"
      data-is-edit="<?= $isEdit ? '1' : '0' ?>"
      data-pattern-url="<?= e(url('/treasury/pattern')) ?>"
      data-pattern-ack-url="<?= e(url('/treasury/pattern/ack')) ?>"
      data-pattern-profile="<?= e($patternProfileJson) ?>"
      data-msg-pattern-typical="<?= e(__('treasury.pattern_typical')) ?>"
      data-msg-pattern-samples="<?= e(__('treasury.pattern_samples')) ?>"
      data-msg-pattern-apply="<?= e(__('treasury.pattern_apply')) ?>"
      data-msg-pattern-ack="<?= e(__('treasury.pattern_ack')) ?>"
      data-msg-pattern-acknowledged="<?= e(__('treasury.pattern_acknowledged')) ?>"
      data-msg-pattern-confirm="<?= e(__('treasury.pattern_confirm')) ?>"
      data-msg-pattern-warning="<?= e(__('treasury.pattern_warning')) ?>"
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
    <?php if ($embed): ?>
        <?= field('hidden', ['name' => 'embed', 'value' => '1']) ?>
    <?php endif; ?>
    <?php if (!empty($isEdit)): ?>
        <?= field('hidden', ['name' => 'record_id', 'value' => (string) $movementId]) ?>
        <?= view_partial('partials/record_lock_notice', [
            'entity' => 'treasury',
            'row' => $movement ?? null,
            'recordId' => (int) ($movement['id'] ?? 0),
            'version' => (int) ($movement_version ?? ($movement['row_version'] ?? 0)),
            'conflict' => $movement_conflict ?? null,
            'activity' => $movement_activity ?? null,
        ]) ?>
        <?php
        $formAnomalies = is_array($movement_anomalies ?? null) ? $movement_anomalies : [];
        $formAnomalyLabels = [];
        foreach ($formAnomalies as $formAnomaly) {
            if (!is_array($formAnomaly)) {
                continue;
            }
            $label = trim((string) ($formAnomaly['message'] ?? ''));
            if ($label !== '') {
                $formAnomalyLabels[] = $label;
            }
        }
        $formAnomalyLabels = array_values(array_unique($formAnomalyLabels));
        ?>
        <?php if ($formAnomalyLabels !== []): ?>
            <div class="setup-hint muted treasury-anomaly-notice" role="status">
                <strong><?= e(__('treasury.anomaly_aria')) ?></strong>
                <ul class="treasury-form-anomaly-list">
                    <?php foreach ($formAnomalyLabels as $formAnomalyLabel): ?>
                        <li><?= e($formAnomalyLabel) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/_form_fields.php'; ?>
    <?= field('hidden', ['name' => 'ret', 'value' => $returnPath]) ?>
    <div class="form-actions form-actions-end">
        <?php if ($justSaved): ?>
            <p class="treasury-save-status" role="status" data-treasury-save-status>
                <span aria-hidden="true">✓</span> <?= e(__('treasury.updated')) ?>
            </p>
        <?php endif; ?>
        <?php if (!$embed): ?>
            <a class="btn btn-ghost" href="<?= e($returnUrl) ?>"><?= e(__('common.back')) ?></a>
        <?php endif; ?>
        <button class="btn" type="submit"><?= e($submitLabel) ?></button>
    </div>
    <?php if ($isEdit): ?>
        <?= view_partial('partials/record_trail', ['entity' => 'treasury', 'id' => (int) ($movement['id'] ?? 0)]) ?>
    <?php endif; ?>
</form>
<?php if ($isEdit && !$embed): ?>
    <section class="panel treasury-danger-zone">
        <div>
            <h2 class="section-title"><?= e(__('treasury.delete_title')) ?></h2>
            <p class="muted"><?= e(__('treasury.delete_hint')) ?></p>
        </div>
        <form method="post" action="<?= e(url('/treasury/' . $movementId . '/delete')) ?>"
              data-confirm="<?= e(__('treasury.delete_confirm')) ?>" data-confirm-danger="1">
            <?= csrf_field() ?>
            <?= field('hidden', ['name' => 'ret', 'value' => $returnPath]) ?>
            <button class="btn btn-ghost is-danger" type="submit"><?= e(__('treasury.delete')) ?></button>
        </form>
    </section>
<?php endif; ?>
