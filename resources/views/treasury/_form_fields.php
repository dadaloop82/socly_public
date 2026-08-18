<?php
/**
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array> $members
 * @var list<string> $beneficiaries
 * @var array{auto_from_payments?:bool}|null $config
 * @var \Socly\Services\CurrencyService $currency
 */
$selectedCategory = (string) ($values['category'] ?? 'membership_fee');
$showNewCategory = $selectedCategory === '__new__';
$today = date('Y-m-d');
?>
<div class="grid-3">
    <div>
        <label><?= e(__('treasury.direction')) ?> *</label>
        <select name="direction" required data-treasury-direction>
            <option value="income" <?= ($values['direction'] ?? '') === 'income' ? 'selected' : '' ?>><?= e(__('treasury.direction_income')) ?></option>
            <option value="expense" <?= ($values['direction'] ?? '') === 'expense' ? 'selected' : '' ?>><?= e(__('treasury.direction_expense')) ?></option>
        </select>
    </div>
    <div>
        <label><?= e(__('treasury.amount')) ?> (<?= e($currency->code()) ?>) *</label>
        <input type="text" name="amount" inputmode="decimal" value="<?= e((string) ($values['amount'] ?? '')) ?>" required placeholder="0,00">
    </div>
    <div>
        <label><?= e(__('treasury.date')) ?> *</label>
        <input type="date" name="movement_date" value="<?= e((string) ($values['movement_date'] ?? $today)) ?>" required>
    </div>
</div>
<div class="grid-3">
    <div data-treasury-category-wrap>
        <label><?= e(__('treasury.category')) ?></label>
        <select name="category" data-treasury-category>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['key']) ?>" <?= $selectedCategory === $cat['key'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option>
            <?php endforeach; ?>
            <option value="__new__" <?= $showNewCategory ? 'selected' : '' ?>><?= e(__('treasury.category_new')) ?></option>
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
        <select name="payment_method">
            <?php foreach (\Socly\Services\TreasuryService::METHODS as $method): ?>
                <option value="<?= e($method) ?>" <?= ($values['payment_method'] ?? 'cash') === $method ? 'selected' : '' ?>><?= e(__('treasury.method_' . $method)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label><?= e(__('treasury.member')) ?></label>
        <select name="member_id">
            <option value=""><?= e(__('treasury.none')) ?></option>
            <?php foreach ($members as $member): ?>
                <option value="<?= (int) $member['id'] ?>" <?= (string) ($values['member_id'] ?? '') === (string) $member['id'] ? 'selected' : '' ?>>
                    <?= e(trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''))) ?>
                    <?php if (!empty($member['member_number'])): ?> (#<?= e((string) $member['member_number']) ?>)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="grid-3" data-treasury-expense-fields>
    <label class="checkbox-row">
        <input type="checkbox" name="invoice_payment" value="1" data-treasury-invoice-toggle <?= !empty($values['invoice_payment']) ? 'checked' : '' ?>>
        <span><?= e(__('treasury.invoice_payment')) ?></span>
    </label>
    <div data-treasury-invoice-fields>
        <label><?= e(__('treasury.invoice_number')) ?></label>
        <input type="text" name="invoice_number" value="<?= e((string) ($values['invoice_number'] ?? '')) ?>" maxlength="120">
    </div>
    <div>
        <label><?= e(__('treasury.beneficiary')) ?></label>
        <input type="text" name="beneficiary" value="<?= e((string) ($values['beneficiary'] ?? '')) ?>" maxlength="190" list="treasury-beneficiaries">
        <datalist id="treasury-beneficiaries">
            <?php foreach ($beneficiaries as $beneficiary): ?>
                <option value="<?= e($beneficiary) ?>">
            <?php endforeach; ?>
        </datalist>
    </div>
</div>
<div data-treasury-expense-fields data-treasury-invoice-fields>
    <label><?= e(__('treasury.invoice_pdf')) ?></label>
    <input type="file" name="invoice_pdf" accept="application/pdf,.pdf">
    <p class="muted"><?= e(__('documents.upload_hint', ['max' => upload_max_mb()])) ?></p>
    <?php if (!empty($values['attachment_path'])): ?>
        <p class="muted"><?= e(__('treasury.invoice_pdf_attached')) ?></p>
    <?php endif; ?>
</div>
<div>
    <label><?= e(__('treasury.description')) ?></label>
    <input type="text" name="description" value="<?= e((string) ($values['description'] ?? '')) ?>" maxlength="500">
</div>
<?php if (!empty($config['auto_from_payments'])): ?>
    <p class="muted"><?= e(__('treasury.auto_hint')) ?></p>
<?php endif; ?>
