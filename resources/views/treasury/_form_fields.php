<?php
/**
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{label:string,keys:list<string>}>|null $category_groups
 * @var list<array{key:string,directions:string,default:string}>|null $movement_kinds
 * @var list<array{label:string,keys:list<string>}>|null $payment_method_groups
 * @var list<array> $members
 * @var list<string> $beneficiaries
 * @var array{auto_from_payments?:bool}|null $config
 * @var \Socly\Services\CurrencyService $currency
 */
$selectedCategory = (string) ($values['category'] ?? 'membership_fee');
$showNewCategory = $selectedCategory === '__new__';
$today = date('Y-m-d');
$selectedDirection = (string) ($values['direction'] ?? 'income');
$selectedKind = (string) ($values['movement_kind'] ?? 'operating');
$movementKinds = $movement_kinds ?? \Socly\Services\TreasuryService::movementKindMeta();
$categoryGroups = $category_groups ?? [];
$methodGroups = $payment_method_groups ?? [];
$categoryMap = [];
foreach ($categories as $cat) {
    $categoryMap[(string) $cat['key']] = (string) $cat['label'];
}
$groupedCategoryKeys = [];
foreach ($categoryGroups as $group) {
    foreach ($group['keys'] as $key) {
        $groupedCategoryKeys[$key] = true;
    }
}
$customCategories = array_values(array_filter(
    $categories,
    static fn (array $cat): bool => empty($cat['builtin']) || !isset($groupedCategoryKeys[(string) $cat['key']])
));
$kindMetaJson = json_encode($movementKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$baseCurrency = $currency->code();
$selectedAmountCurrency = strtoupper(trim((string) ($values['amount_currency'] ?? $baseCurrency)));
if (!in_array($selectedAmountCurrency, $currency->supportedCodes(), true)) {
    $selectedAmountCurrency = $baseCurrency;
}
$displayAmount = (string) ($values['amount'] ?? '');
if (isset($values['amount_entered']) && $values['amount_entered'] !== '' && $values['amount_entered'] !== null) {
    $displayAmount = (string) $values['amount_entered'];
}
$memberInvolved = !empty($values['member_involved']) || trim((string) ($values['member_id'] ?? '')) !== '';
$hasAttachment = !empty($values['attachment_path']) && empty($values['detach_invoice_pdf']);
$attachmentName = $hasAttachment ? basename((string) $values['attachment_path']) : '';
$attachmentPreviewUrl = trim((string) ($values['attachment_preview_url'] ?? ''));
$documentEditUrl = trim((string) ($values['document_edit_url'] ?? ''));
$fileReady = $hasAttachment;
?>
<div class="treasury-type-row grid-3" data-treasury-kind-meta='<?= e((string) $kindMetaJson) ?>'>
    <div>
        <label><?= e(__('treasury.movement_kind')) ?></label>
        <select name="movement_kind" data-treasury-kind>
            <?php foreach ($movementKinds as $kind): ?>
                <?php $key = (string) ($kind['key'] ?? 'operating'); ?>
                <option
                    value="<?= e($key) ?>"
                    data-directions="<?= e((string) ($kind['directions'] ?? 'both')) ?>"
                    data-default-direction="<?= e((string) ($kind['default'] ?? 'income')) ?>"
                    <?= $selectedKind === $key ? 'selected' : '' ?>
                ><?= e(__('treasury.kind_' . $key)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div data-treasury-sign-wrap>
        <label><?= e(__('treasury.direction_sign')) ?></label>
        <select data-treasury-sign>
            <option value="income" <?= $selectedDirection === 'income' ? 'selected' : '' ?>><?= e(__('treasury.direction_sign_income')) ?></option>
            <option value="expense" <?= $selectedDirection === 'expense' ? 'selected' : '' ?>><?= e(__('treasury.direction_sign_expense')) ?></option>
        </select>
    </div>
    <input type="hidden" name="direction" value="<?= e($selectedDirection) ?>" data-treasury-direction required>
</div>
<div class="grid-3">
    <div>
        <label><?= e(__('treasury.amount')) ?> *</label>
        <input type="text" name="amount" inputmode="decimal" value="<?= e($displayAmount) ?>" required placeholder="0,00" data-treasury-amount>
    </div>
    <div>
        <label><?= e(__('treasury.amount_currency')) ?></label>
        <select name="amount_currency" data-treasury-amount-currency>
            <?php foreach ($currency->supportedCodes() as $code): ?>
                <option value="<?= e($code) ?>" <?= $selectedAmountCurrency === $code ? 'selected' : '' ?>>
                    <?= e(__('currency.' . strtolower($code))) ?> (<?= e($code) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="muted treasury-currency-hint" data-treasury-currency-hint hidden
           data-template="<?= e(__('treasury.currency_convert_hint', ['base' => $baseCurrency])) ?>"></p>
    </div>
    <div>
        <label><?= e(__('treasury.operation_date')) ?> *</label>
        <input type="date" name="movement_date" value="<?= e((string) ($values['movement_date'] ?? $today)) ?>" required>
    </div>
</div>
<div class="grid-3">
    <div data-treasury-category-wrap>
        <label><?= e(__('treasury.category')) ?></label>
        <select name="category" data-treasury-category>
            <?php if ($categoryGroups !== []): ?>
                <?php foreach ($categoryGroups as $group): ?>
                    <optgroup label="<?= e((string) $group['label']) ?>">
                        <?php foreach ($group['keys'] as $key): ?>
                            <?php if (!isset($categoryMap[$key])) { continue; } ?>
                            <option value="<?= e($key) ?>" <?= $selectedCategory === $key ? 'selected' : '' ?>><?= e($categoryMap[$key]) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <?php if ($customCategories !== []): ?>
                    <optgroup label="<?= e(__('treasury.cat_group_custom')) ?>">
                        <?php foreach ($customCategories as $cat): ?>
                            <?php if (($cat['key'] ?? '') === 'other') { continue; } ?>
                            <option value="<?= e((string) $cat['key']) ?>" <?= $selectedCategory === (string) $cat['key'] ? 'selected' : '' ?>><?= e((string) $cat['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <optgroup label="<?= e(__('treasury.cat_group_other')) ?>">
                    <?php if (isset($categoryMap['other'])): ?>
                        <option value="other" <?= $selectedCategory === 'other' ? 'selected' : '' ?>><?= e($categoryMap['other']) ?></option>
                    <?php endif; ?>
                    <option value="__new__" <?= $showNewCategory ? 'selected' : '' ?>><?= e(__('treasury.category_new')) ?></option>
                </optgroup>
            <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['key']) ?>" <?= $selectedCategory === $cat['key'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option>
                <?php endforeach; ?>
                <option value="__new__" <?= $showNewCategory ? 'selected' : '' ?>><?= e(__('treasury.category_new')) ?></option>
            <?php endif; ?>
        </select>
        <div class="doc-new-category" data-treasury-new-category <?= $showNewCategory ? '' : 'hidden' ?>>
            <label><?= e(__('treasury.category_new_label')) ?></label>
            <input
                type="text"
                name="new_category"
                value="<?= e((string) ($values['new_category'] ?? '')) ?>"
                maxlength="80"
                placeholder="<?= e(__('treasury.category_new_placeholder')) ?>"
                data-treasury-new-category-input
                <?= $showNewCategory ? 'required' : '' ?>
            >
        </div>
    </div>
    <div>
        <label><?= e(__('treasury.method')) ?></label>
        <select name="payment_method" data-treasury-method>
            <?php
            $selectedMethod = \Socly\Services\TreasuryService::normalizePaymentMethod(
                (string) ($values['payment_method'] ?? 'cash')
            );
            ?>
            <?php if ($methodGroups !== []): ?>
                <?php foreach ($methodGroups as $group): ?>
                    <optgroup label="<?= e((string) $group['label']) ?>">
                        <?php foreach ($group['keys'] as $method): ?>
                            <option value="<?= e($method) ?>" <?= $selectedMethod === $method ? 'selected' : '' ?>><?= e(__('treasury.method_' . $method)) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach (\Socly\Services\TreasuryService::METHODS as $method): ?>
                    <option value="<?= e($method) ?>" <?= $selectedMethod === $method ? 'selected' : '' ?>><?= e(__('treasury.method_' . $method)) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>
    <div>
        <label><?= e(__('treasury.description')) ?></label>
        <input type="text" name="description" value="<?= e((string) ($values['description'] ?? '')) ?>" maxlength="500">
    </div>
</div>
<label class="checkbox-row checkbox-row-lg">
    <input type="checkbox" name="member_involved" value="1" data-treasury-member-toggle <?= $memberInvolved ? 'checked' : '' ?>>
    <span><?= e(__('treasury.member_involved')) ?></span>
</label>
<div data-treasury-member-fields <?= $memberInvolved ? '' : 'hidden' ?>>
    <label><?= e(__('treasury.member')) ?></label>
    <select name="member_id" data-treasury-member-select>
        <option value=""><?= e(__('treasury.member_select')) ?></option>
        <?php foreach ($members as $member): ?>
            <option value="<?= (int) $member['id'] ?>" <?= (string) ($values['member_id'] ?? '') === (string) $member['id'] ? 'selected' : '' ?>>
                <?= e(trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''))) ?>
                <?php if (!empty($member['member_number'])): ?> (#<?= e((string) $member['member_number']) ?>)<?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>
<div data-treasury-expense-fields>
    <label class="checkbox-row checkbox-row-lg">
        <input type="checkbox" name="invoice_payment" value="1" data-treasury-invoice-toggle <?= !empty($values['invoice_payment']) ? 'checked' : '' ?>>
        <span><?= e(__('treasury.invoice_payment')) ?></span>
    </label>
    <div class="grid-3" data-treasury-invoice-fields>
        <div>
            <label><?= e(__('treasury.invoice_number')) ?></label>
            <input type="text" name="invoice_number" value="<?= e((string) ($values['invoice_number'] ?? '')) ?>" maxlength="120">
        </div>
        <div>
            <label><?= e(__('treasury.invoice_date')) ?></label>
            <input type="date" name="invoice_date" value="<?= e((string) ($values['invoice_date'] ?? '')) ?>">
        </div>
        <div>
            <label><?= e(__('treasury.invoice_due_date')) ?></label>
            <input type="date" name="invoice_due_date" value="<?= e((string) ($values['invoice_due_date'] ?? '')) ?>">
        </div>
    </div>
    <div data-treasury-invoice-fields>
        <label><?= e(__('treasury.beneficiary')) ?></label>
        <input type="text" name="beneficiary" value="<?= e((string) ($values['beneficiary'] ?? '')) ?>" maxlength="190" list="treasury-beneficiaries">
        <datalist id="treasury-beneficiaries">
            <?php foreach ($beneficiaries as $beneficiary): ?>
                <option value="<?= e($beneficiary) ?>">
            <?php endforeach; ?>
        </datalist>
    </div>
    <div data-treasury-invoice-fields>
        <div class="doc-file-field<?= $fileReady ? ' is-uploaded' : '' ?>" data-treasury-doc-field>
            <label><?= e(__('treasury.upload_document')) ?></label>
            <div class="doc-file-row">
                <label class="btn btn-ghost file-btn">
                    <span data-treasury-doc-pick-label><?= e($fileReady ? __('treasury.document_change') : __('treasury.document_choose')) ?></span>
                    <input
                        type="file"
                        name="invoice_pdf"
                        accept="application/pdf,.pdf"
                        data-treasury-doc-input
                    >
                </label>
                <div class="doc-file-meta">
                    <strong class="doc-file-name" data-treasury-doc-name <?= $attachmentName === '' ? 'hidden' : '' ?>><?= e($attachmentName) ?></strong>
                    <span class="doc-file-status muted" data-treasury-doc-status>
                        <?= e($fileReady ? __('treasury.document_attached') : __('documents.upload_idle')) ?>
                    </span>
                    <div class="doc-file-preview" data-treasury-doc-preview <?= ($fileReady && $attachmentPreviewUrl !== '') ? '' : 'hidden' ?>>
                        <?php if ($fileReady && $attachmentPreviewUrl !== ''): ?>
                            <iframe src="<?= e($attachmentPreviewUrl) ?>#toolbar=0" title="<?= e(__('treasury.upload_document')) ?>" loading="lazy"></iframe>
                        <?php endif; ?>
                    </div>
                    <span class="doc-file-hint muted"><?= e(__('documents.upload_hint', ['max' => upload_max_mb()])) ?></span>
                    <?php if ($documentEditUrl !== ''): ?>
                        <a class="doc-file-archive-link" href="<?= e($documentEditUrl) ?>" target="_blank" rel="noopener"><?= e(__('treasury.document_in_archive')) ?></a>
                    <?php endif; ?>
                </div>
                <?php if ($fileReady || !empty($values['attachment_path'])): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-treasury-doc-detach><?= e(__('treasury.document_detach')) ?></button>
                    <input type="hidden" name="detach_invoice_pdf" value="" data-treasury-doc-detach-input>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($config['auto_from_payments'])): ?>
    <p class="muted"><?= e(__('treasury.auto_hint')) ?></p>
<?php endif; ?>
