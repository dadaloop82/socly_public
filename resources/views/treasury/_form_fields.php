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

$movementKindOptions = [];
foreach ($movementKinds as $kind) {
    $key = (string) ($kind['key'] ?? 'operating');
    $movementKindOptions[] = [
        'value' => $key,
        'label' => (string) __('treasury.kind_' . $key),
        'attrs' => [
            'data-directions' => (string) ($kind['directions'] ?? 'both'),
            'data-default-direction' => (string) ($kind['default'] ?? 'income'),
        ],
    ];
}

$amountCurrencyOptions = [];
foreach ($currency->supportedCodes() as $code) {
    $amountCurrencyOptions[] = [
        'value' => $code,
        'label' => (string) __('currency.' . strtolower($code)) . ' (' . $code . ')',
    ];
}

$treasuryCategoryGroups = [];
$treasuryCategoryFlat = [];
if ($categoryGroups !== []) {
    foreach ($categoryGroups as $group) {
        $opts = [];
        foreach ($group['keys'] as $key) {
            if ($key === 'other' || !isset($categoryMap[$key])) {
                continue;
            }
            $opts[] = ['value' => (string) $key, 'label' => $categoryMap[$key]];
        }
        if ($opts !== []) {
            $treasuryCategoryGroups[] = ['label' => (string) $group['label'], 'options' => $opts];
        }
    }
    if ($customCategories !== []) {
        $opts = [];
        foreach ($customCategories as $cat) {
            if (($cat['key'] ?? '') === 'other') {
                continue;
            }
            $opts[] = ['value' => (string) $cat['key'], 'label' => (string) $cat['label']];
        }
        if ($opts !== []) {
            $treasuryCategoryGroups[] = ['label' => (string) __('treasury.cat_group_custom'), 'options' => $opts];
        }
    }
    $otherOpts = [];
    if (isset($categoryMap['other'])) {
        $otherOpts[] = ['value' => 'other', 'label' => $categoryMap['other']];
    }
    $otherOpts[] = ['value' => '__new__', 'label' => (string) __('treasury.category_new')];
    $treasuryCategoryGroups[] = ['label' => (string) __('treasury.cat_group_other'), 'options' => $otherOpts];
} else {
    foreach ($categories as $cat) {
        $treasuryCategoryFlat[] = ['value' => (string) $cat['key'], 'label' => (string) $cat['label']];
    }
    $treasuryCategoryFlat[] = ['value' => '__new__', 'label' => (string) __('treasury.category_new')];
}

$selectedMethod = \Socly\Services\TreasuryService::normalizePaymentMethod(
    (string) ($values['payment_method'] ?? 'cash')
);
$paymentMethodGroups = [];
$paymentMethodFlat = [];
if ($methodGroups !== []) {
    foreach ($methodGroups as $group) {
        $opts = [];
        foreach ($group['keys'] as $method) {
            $opts[] = ['value' => (string) $method, 'label' => (string) __('treasury.method_' . $method)];
        }
        $paymentMethodGroups[] = ['label' => (string) $group['label'], 'options' => $opts];
    }
} else {
    foreach (\Socly\Services\TreasuryService::METHODS as $method) {
        $paymentMethodFlat[] = ['value' => (string) $method, 'label' => (string) __('treasury.method_' . $method)];
    }
}

$treasuryMemberOptions = [];
foreach ($members as $member) {
    $label = trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''));
    if (!empty($member['member_number'])) {
        $label .= ' (#' . (string) $member['member_number'] . ')';
    }
    $treasuryMemberOptions[] = [
        'value' => (string) (int) $member['id'],
        'label' => $label,
    ];
}
?>
<div class="treasury-type-row grid-3" data-treasury-kind-meta='<?= e((string) $kindMetaJson) ?>'>
    <?= field('select', [
        'name' => 'movement_kind',
        'label' => (string) __('treasury.movement_kind'),
        'value' => $selectedKind,
        'include_empty' => false,
        'options' => $movementKindOptions,
        'attrs' => ['data-treasury-kind' => true],
    ]) ?>
    <div data-treasury-sign-wrap>
        <?= field('select', [
            'name' => '',
            'label' => (string) __('treasury.direction_sign'),
            'value' => $selectedDirection,
            'include_empty' => false,
            'options' => [
                ['value' => 'income', 'label' => (string) __('treasury.direction_sign_income')],
                ['value' => 'expense', 'label' => (string) __('treasury.direction_sign_expense')],
            ],
            'attrs' => ['data-treasury-sign' => true],
        ]) ?>
    </div>
    <?= field('hidden', [
        'name' => 'direction',
        'value' => $selectedDirection,
        'required' => true,
        'attrs' => ['data-treasury-direction' => true],
    ]) ?>
</div>
<div class="grid-3">
    <?= field('text', [
        'name' => 'amount',
        'label' => (string) __('treasury.amount'),
        'value' => $displayAmount,
        'required' => true,
        'placeholder' => '0,00',
        'inputmode' => 'decimal',
        'attrs' => ['data-treasury-amount' => true],
    ]) ?>
    <div>
        <?= field('select', [
            'name' => 'amount_currency',
            'label' => (string) __('treasury.amount_currency'),
            'value' => $selectedAmountCurrency,
            'include_empty' => false,
            'options' => $amountCurrencyOptions,
            'attrs' => ['data-treasury-amount-currency' => true],
        ]) ?>
        <p class="muted treasury-currency-hint" data-treasury-currency-hint hidden
           data-template="<?= e(__('treasury.currency_convert_hint', ['base' => $baseCurrency])) ?>"></p>
    </div>
    <?= field('date', [
        'name' => 'movement_date',
        'label' => (string) __('treasury.operation_date'),
        'value' => (string) ($values['movement_date'] ?? $today),
        'required' => true,
    ]) ?>
</div>
<div class="grid-3">
    <div data-treasury-category-wrap>
        <?= field('select', [
            'name' => 'category',
            'label' => (string) __('treasury.category'),
            'value' => $selectedCategory,
            'include_empty' => false,
            'groups' => $treasuryCategoryGroups !== [] ? $treasuryCategoryGroups : null,
            'options' => $treasuryCategoryGroups === [] ? $treasuryCategoryFlat : [],
            'attrs' => ['data-treasury-category' => true],
        ]) ?>
        <div class="doc-new-category" data-treasury-new-category <?= $showNewCategory ? '' : 'hidden' ?>>
            <?= field('text', [
                'name' => 'new_category',
                'label' => (string) __('treasury.category_new_label'),
                'value' => (string) ($values['new_category'] ?? ''),
                'maxlength' => 80,
                'placeholder' => (string) __('treasury.category_new_placeholder'),
                'required' => $showNewCategory,
                'attrs' => ['data-treasury-new-category-input' => true],
            ]) ?>
        </div>
    </div>
    <?= field('select', [
        'name' => 'payment_method',
        'label' => (string) __('treasury.method'),
        'value' => $selectedMethod,
        'include_empty' => false,
        'groups' => $paymentMethodGroups !== [] ? $paymentMethodGroups : null,
        'options' => $paymentMethodGroups === [] ? $paymentMethodFlat : [],
        'attrs' => ['data-treasury-method' => true],
    ]) ?>
    <?= field('text', [
        'name' => 'description',
        'label' => (string) __('treasury.description'),
        'value' => (string) ($values['description'] ?? ''),
        'maxlength' => 500,
    ]) ?>
</div>
<?= field('checkbox', [
    'name' => 'member_involved',
    'label' => (string) __('treasury.member_involved'),
    'checked' => $memberInvolved,
    'label_class' => 'checkbox-row-lg',
    'attrs' => ['data-treasury-member-toggle' => true],
]) ?>
<div data-treasury-member-fields <?= $memberInvolved ? '' : 'hidden' ?>>
    <?= field('select', [
        'name' => 'member_id',
        'label' => (string) __('treasury.member'),
        'value' => (string) ($values['member_id'] ?? ''),
        'empty_label' => (string) __('treasury.member_select'),
        'options' => $treasuryMemberOptions,
        'attrs' => ['data-treasury-member-select' => true],
    ]) ?>
</div>
<div data-treasury-expense-fields>
    <div class="treasury-invoice-block">
        <?= field('checkbox', [
            'name' => 'invoice_payment',
            'label' => (string) __('treasury.invoice_payment'),
            'checked' => !empty($values['invoice_payment']),
            'label_class' => 'checkbox-row-lg',
            'attrs' => ['data-treasury-invoice-toggle' => true],
        ]) ?>
        <div class="treasury-invoice-fields" data-treasury-invoice-fields>
            <p class="section-lede" style="margin:0 0 0.65rem"><?= e(__('treasury.invoice_block_lede')) ?></p>
            <?= field('text', [
                'name' => 'invoice_title',
                'label' => (string) __('treasury.invoice_title'),
                'value' => (string) ($values['invoice_title'] ?? ''),
                'maxlength' => 190,
                'placeholder' => (string) __('treasury.invoice_title_placeholder'),
            ]) ?>
            <div class="grid-3">
                <?= field('text', [
                    'name' => 'invoice_number',
                    'label' => (string) __('treasury.invoice_number'),
                    'value' => (string) ($values['invoice_number'] ?? ''),
                    'maxlength' => 120,
                ]) ?>
                <?= field('date', [
                    'name' => 'invoice_date',
                    'label' => (string) __('treasury.invoice_date'),
                    'value' => (string) ($values['invoice_date'] ?? ''),
                ]) ?>
                <?= field('date', [
                    'name' => 'invoice_due_date',
                    'label' => (string) __('treasury.invoice_due_date'),
                    'value' => (string) ($values['invoice_due_date'] ?? ''),
                ]) ?>
            </div>
            <?= field('text', [
                'name' => 'beneficiary',
                'label' => (string) __('treasury.supplier'),
                'value' => (string) ($values['beneficiary'] ?? ''),
                'maxlength' => 190,
                'placeholder' => (string) __('treasury.supplier_placeholder'),
                'attrs' => ['list' => 'treasury-beneficiaries'],
            ]) ?>
            <datalist id="treasury-beneficiaries">
                <?php foreach ($beneficiaries as $beneficiary): ?>
                    <option value="<?= e($beneficiary) ?>">
                <?php endforeach; ?>
            </datalist>
            <div class="doc-file-field<?= $fileReady ? ' is-uploaded' : '' ?>" data-treasury-doc-field>
                <div class="doc-file-row">
                    <?= field('file', [
                        'label' => (string) __('treasury.upload_document'),
                        'name' => 'invoice_pdf',
                        'button_label' => $fileReady ? (string) __('treasury.document_change') : (string) __('treasury.document_choose'),
                        'accept' => 'application/pdf,.pdf',
                        'as_button' => true,
                        'button_class' => 'btn btn-ghost file-btn sf-field__file-btn',
                        'attrs' => ['data-treasury-doc-input' => true],
                        'pick_label_attrs' => ['data-treasury-doc-pick-label' => true],
                    ]) ?>
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
                        <?= field('hidden', [
                            'name' => 'detach_invoice_pdf',
                            'value' => '',
                            'attrs' => ['data-treasury-doc-detach-input' => true],
                        ]) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if (!empty($config['auto_from_payments'])): ?>
    <p class="muted"><?= e(__('treasury.auto_hint')) ?></p>
<?php endif; ?>
