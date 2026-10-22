<?php
/**
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{label:string,keys:list<string>}>|null $category_groups
 * @var list<array{key:string,label:string}>|null $accounts
 * @var list<array{label:string,keys:list<string>}>|null $payment_method_groups
 * @var list<array{key:string,label:string,active:bool,balance:float}>|null $account_balances
 * @var list<array> $members
 * @var list<string> $beneficiaries
 * @var list<array{label:string,options:list<array{value:string,label:string,direction:string,category:string}>}>|null $budget_items
 * @var array{auto_from_payments?:bool}|null $config
 * @var \Socly\Services\CurrencyService $currency
 */
$selectedCategory = (string) ($values['category'] ?? 'membership_fee');
$showNewCategory = $selectedCategory === '__new__';
$today = date('Y-m-d');
$selectedDirection = (string) ($values['direction'] ?? 'income');
$isTransfer = $selectedDirection === 'transfer';
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
$baseCurrency = $currency->code();
$displayAmount = (string) ($values['amount'] ?? '');
if (isset($values['amount_entered']) && $values['amount_entered'] !== '' && $values['amount_entered'] !== null) {
    $displayAmount = (string) $values['amount_entered'];
}
$memberInvolved = !empty($values['member_involved']) || trim((string) ($values['member_id'] ?? '')) !== '';
$hasAttachment = !empty($values['attachment_path']) && empty($values['detach_invoice_pdf']);
$attachmentName = $hasAttachment ? basename((string) $values['attachment_path']) : '';
$attachmentPreviewUrl = trim((string) ($values['attachment_preview_url'] ?? ''));
$attachmentIsImage = $hasAttachment
    && preg_match('/\.(?:jpe?g|png|webp)$/i', (string) ($values['attachment_path'] ?? '')) === 1;
$documentEditUrl = trim((string) ($values['document_edit_url'] ?? ''));
$fileReady = $hasAttachment;

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

$selectedMethod = trim((string) ($values['payment_method'] ?? ''));
if ($selectedMethod !== '') {
    $selectedMethod = \Socly\Services\TreasuryService::normalizePaymentMethod($selectedMethod);
}
$selectedAccount = \Socly\Services\TreasuryService::normalizeAccount((string) ($values['account'] ?? ''));
$selectedTransferAccount = \Socly\Services\TreasuryService::normalizeAccount((string) ($values['transfer_account'] ?? ''));

// Il saldo di ogni conto viaggia dentro l'etichetta dell'option («Banca: 1.234,56 €»).
$accountBalanceLabels = [];
foreach (($account_balances ?? []) as $balanceRow) {
    $balanceKey = (string) ($balanceRow['key'] ?? '');
    if ($balanceKey !== '') {
        $accountBalanceLabels[$balanceKey] = (string) $currency->format((float) ($balanceRow['balance'] ?? 0));
    }
}
$accountLabelWithBalance = static function (string $label, string $accountKey) use ($accountBalanceLabels): string {
    $amount = $accountBalanceLabels[$accountKey] ?? '';
    return $amount !== ''
        ? (string) __('treasury.account_option_balance', ['label' => $label, 'amount' => $amount])
        : $label;
};

$accountOptions = [];
// Only active wallets are selectable; the ones already saved on this movement stay
// visible (and valid) even when they have been deactivated meanwhile.
foreach (($accounts ?? []) as $accountOption) {
    $accountKey = (string) ($accountOption['key'] ?? '');
    if ($accountKey === '') {
        continue;
    }
    $accountActive = !empty($accountOption['active']);
    if (!$accountActive && $accountKey !== $selectedAccount && $accountKey !== $selectedTransferAccount) {
        continue;
    }
    $plainLabel = (string) ($accountOption['label'] ?? $accountKey);
    if (!$accountActive) {
        $plainLabel .= ' (' . (string) __('treasury.account_inactive_suffix') . ')';
    }
    $accountOptions[] = [
        'value' => $accountKey,
        'label' => $accountLabelWithBalance($plainLabel, $accountKey),
        'attrs' => ['data-label' => $plainLabel],
    ];
}
if ($accountOptions === []) {
    foreach (\Socly\Services\TreasuryService::BUILTIN_ACCOUNTS as $accountKey) {
        $plainLabel = (string) __('treasury.account_' . $accountKey);
        $accountOptions[] = [
            'value' => $accountKey,
            'label' => $accountLabelWithBalance($plainLabel, $accountKey),
            'attrs' => ['data-label' => $plainLabel],
        ];
    }
}
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
<?= field('radio_cards', [
    'name' => 'direction',
    'label' => (string) __('treasury.direction_sign'),
    'value' => $selectedDirection,
    'required' => true,
    'wrapper_class' => 'treasury-direction-field',
    'cards_class' => 'treasury-direction-cards',
    'options' => [
        [
            'value' => 'income',
            'label' => (string) __('treasury.direction_sign_income'),
            'card_class' => 'is-income',
            'icon' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M12 3.5 5.5 11h4v9.5h5V11h4z"/></svg>',
        ],
        [
            'value' => 'expense',
            'label' => (string) __('treasury.direction_sign_expense'),
            'card_class' => 'is-expense',
            'icon' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M12 20.5 18.5 13h-4V3.5h-5V13h-4z"/></svg>',
        ],
        [
            'value' => 'transfer',
            'label' => (string) __('treasury.direction_sign_transfer'),
            'card_class' => 'is-transfer',
            'icon' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M3 6h12V3l5 4-5 4V8H3V6zM21 15H9v-3l-5 4 5 4v-3h12v-2z"/></svg>',
        ],
    ],
    'attrs' => ['data-treasury-direction' => true],
]) ?>
<?= field('hidden', [
    'name' => 'amount_currency',
    'value' => $baseCurrency,
]) ?>
<div class="treasury-form-row treasury-form-row--economy">
    <div class="treasury-amount-field">
        <?= field('text', [
            'name' => 'amount',
            'label' => (string) __('treasury.amount'),
            'value' => $displayAmount,
            'required' => true,
            'placeholder' => '0,00',
            'attrs' => ['data-treasury-amount' => true, 'inputmode' => 'decimal'],
        ]) ?>
    </div>
    <?= field('date', [
        'name' => 'movement_date',
        'label' => (string) __('treasury.operation_date'),
        'value' => (string) ($values['movement_date'] ?? $today),
        'required' => true,
    ]) ?>
    <div data-treasury-account-wrap>
        <?= field('select', [
            'name' => 'account',
            'label' => (string) ($isTransfer ? __('treasury.transfer_from') : __('treasury.account')),
            'value' => $selectedAccount,
            'required' => true,
            'include_empty' => true,
            'empty_label' => (string) __('treasury.account_select'),
            'options' => $accountOptions,
            'attrs' => [
                'data-treasury-account' => true,
                'data-label-default' => (string) __('treasury.account'),
                'data-label-transfer' => (string) __('treasury.transfer_from'),
            ],
        ]) ?>
    </div>
    <div data-treasury-method-wrap>
        <?= field('select', [
            'name' => 'payment_method',
            'label' => (string) __('treasury.payment_method_label'),
            'value' => $selectedMethod,
            'include_empty' => true,
            'empty_label' => (string) __('treasury.method_unspecified'),
            'groups' => $paymentMethodGroups !== [] ? $paymentMethodGroups : null,
            'options' => $paymentMethodGroups === [] ? $paymentMethodFlat : [],
            'attrs' => ['data-treasury-method' => true],
        ]) ?>
    </div>
    <div data-treasury-transfer-fields <?= $isTransfer ? '' : 'hidden' ?>>
        <?= field('select', [
            'name' => 'transfer_account',
            'label' => (string) __('treasury.transfer_to'),
            'value' => $selectedTransferAccount,
            'required' => $isTransfer,
            'include_empty' => true,
            'empty_label' => (string) __('treasury.account_select'),
            'options' => $accountOptions,
            'attrs' => ['data-treasury-transfer-account' => true],
        ]) ?>
        <p class="treasury-transfer-notice" data-treasury-transfer-notice hidden><?= e(__('treasury.transfer_same_account')) ?></p>
    </div>
</div>
<?php
// Uscita + "Pagamento fattura": the invoice data live inside the block (complete and
// self-contained). Without the flag the paper invoice details stay in the doc row.
$invoiceActive = $selectedDirection === 'expense' && !empty($values['invoice_payment']);
?>
<div data-treasury-expense-fields <?= $selectedDirection === 'expense' ? '' : 'hidden' ?>>
    <div class="treasury-form-row treasury-form-row--single">
        <div class="treasury-invoice-block">
            <?= field('checkbox', [
                'name' => 'invoice_payment',
                'label' => (string) __('treasury.invoice_payment'),
                'hint' => (string) __('treasury.invoice_payment_hint'),
                'checked' => !empty($values['invoice_payment']),
                'label_class' => 'checkbox-row-lg',
                'attrs' => ['data-treasury-invoice-toggle' => true],
            ]) ?>
            <div class="treasury-invoice-fields" data-treasury-invoice-fields <?= $invoiceActive ? '' : 'hidden' ?>>
                <?= field('text', [
                    'name' => 'invoice_number',
                    'label' => (string) __('treasury.invoice_number'),
                    'value' => (string) ($values['invoice_number'] ?? ''),
                    'maxlength' => 120,
                    'placeholder' => (string) __('treasury.doc_number_placeholder'),
                ]) ?>
                <?= field('date', [
                    'name' => 'invoice_date',
                    'label' => (string) __('treasury.invoice_date'),
                    'value' => (string) ($values['invoice_date'] ?? ''),
                ]) ?>
                <?= field('text', [
                    'name' => 'invoice_title',
                    'label' => (string) __('treasury.invoice_title'),
                    'value' => (string) ($values['invoice_title'] ?? ''),
                    'maxlength' => 190,
                    'placeholder' => (string) __('treasury.invoice_title_placeholder'),
                ]) ?>
                <?= field('date', [
                    'name' => 'invoice_due_date',
                    'label' => (string) __('treasury.invoice_due_date'),
                    'value' => (string) ($values['invoice_due_date'] ?? ''),
                ]) ?>
            </div>
        </div>
    </div>
</div>
<div class="treasury-form-row treasury-form-row--docs" data-treasury-docs-row <?= $isTransfer ? 'hidden' : '' ?>>
    <div data-treasury-category-wrap <?= $isTransfer ? 'hidden' : '' ?>>
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
        <?php
        $budgetItemGroups = [];
        foreach (($budget_items ?? []) as $budgetGroup) {
            $budgetOptions = [];
            foreach (($budgetGroup['options'] ?? []) as $budgetOption) {
                $budgetOptions[] = [
                    'value' => (string) ($budgetOption['value'] ?? ''),
                    'label' => (string) ($budgetOption['label'] ?? ''),
                    'attrs' => [
                        'data-category' => (string) ($budgetOption['category'] ?? ''),
                        'data-direction' => (string) ($budgetOption['direction'] ?? ''),
                    ],
                ];
            }
            if ($budgetOptions !== []) {
                $budgetItemGroups[] = ['label' => (string) ($budgetGroup['label'] ?? ''), 'options' => $budgetOptions];
            }
        }
        ?>
        <?php if ($budgetItemGroups !== []): ?>
            <div class="budget-impute-field" data-treasury-budget-field>
                <?= field('select', [
                    'name' => 'budget_item_id',
                    'label' => (string) __('treasury.budget_item'),
                    'value' => (string) ($values['budget_item_id'] ?? ''),
                    'empty_label' => (string) __('treasury.budget_item_none'),
                    'groups' => $budgetItemGroups,
                    'hint' => (string) __('treasury.budget_item_hint'),
                    'attrs' => ['data-treasury-budget-item' => true],
                ]) ?>
            </div>
        <?php endif; ?>
    </div>
    <?php // Invoice number/date live only inside the invoice block (no duplicates). ?>
</div>
<div class="treasury-form-row treasury-form-row--single">
    <?= field('textarea', [
        'name' => 'description',
        'label' => (string) __('treasury.description'),
        'value' => (string) ($values['description'] ?? ''),
        'maxlength' => 500,
        'rows' => 2,
    ]) ?>
</div>
<div class="treasury-form-row treasury-form-row--single">
    <div class="treasury-soggetto-fields">
        <?= field('checkbox', [
            'name' => 'member_involved',
            'label' => (string) __('treasury.member_involved'),
            'hint' => (string) __('treasury.member_involved_hint'),
            'checked' => $memberInvolved,
            'label_class' => 'checkbox-row-lg',
            'attrs' => ['data-treasury-member-toggle' => true],
        ]) ?>
        <div data-treasury-beneficiary-wrap <?= $memberInvolved ? 'hidden' : '' ?>
             data-msg-suggest="<?= e(__('treasury.counterparty_suggest')) ?>">
            <?= field('text', [
                'name' => 'beneficiary',
                'label' => (string) __('treasury.counterparty'),
                'value' => (string) ($values['beneficiary'] ?? ''),
                'maxlength' => 190,
                'placeholder' => (string) __('treasury.supplier_placeholder'),
                'attrs' => [
                    'list' => 'treasury-beneficiaries',
                    'data-treasury-beneficiary-input' => true,
                ],
            ]) ?>
            <datalist id="treasury-beneficiaries">
                <?php foreach ($beneficiaries as $beneficiary): ?>
                    <option value="<?= e($beneficiary) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
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
    </div>
</div>
<div class="treasury-form-row treasury-form-row--single">
    <div class="treasury-attach-fields">
        <p class="section-lede treasury-attach-lede"><?= e(__('treasury.attachment_lede')) ?></p>
            <div class="doc-file-field<?= $fileReady ? ' is-uploaded' : '' ?>" data-treasury-doc-field>
                <div class="doc-file-row">
                    <?= field('file', [
                        'label' => (string) __('treasury.upload_document'),
                        'name' => 'invoice_pdf',
                        'button_label' => $fileReady ? (string) __('treasury.document_change') : (string) __('treasury.document_choose'),
                        'accept' => 'application/pdf,image/jpeg,image/png,image/webp',
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
                        <div class="doc-file-preview<?= $attachmentIsImage ? ' is-image' : '' ?>" data-treasury-doc-preview <?= ($fileReady && $attachmentPreviewUrl !== '') ? '' : 'hidden' ?>>
                            <?php if ($fileReady && $attachmentPreviewUrl !== ''): ?>
                                <?php if ($attachmentIsImage): ?>
                                    <img src="<?= e($attachmentPreviewUrl) ?>" alt="<?= e(__('treasury.upload_document')) ?>" loading="lazy">
                                <?php else: ?>
                                    <iframe src="<?= e($attachmentPreviewUrl) ?>#toolbar=0" title="<?= e(__('treasury.upload_document')) ?>" loading="lazy"></iframe>
                                <?php endif; ?>
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
<?php if (!empty($config['auto_from_payments'])): ?>
    <p class="muted"><?= e(__('treasury.auto_hint')) ?></p>
<?php endif; ?>
