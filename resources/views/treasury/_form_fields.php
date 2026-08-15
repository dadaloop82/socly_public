<?php
/**
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array> $members
 * @var array{auto_from_payments?:bool}|null $config
 */
$selectedCategory = (string) ($values['category'] ?? 'membership_fee');
$showNewCategory = $selectedCategory === '__new__';
$today = date('Y-m-d');
?>
<div class="grid-3">
    <div>
        <label><?= e(__('treasury.direction')) ?> *</label>
        <select name="direction" required>
            <option value="income" <?= ($values['direction'] ?? '') === 'income' ? 'selected' : '' ?>><?= e(__('treasury.direction_income')) ?></option>
            <option value="expense" <?= ($values['direction'] ?? '') === 'expense' ? 'selected' : '' ?>><?= e(__('treasury.direction_expense')) ?></option>
        </select>
    </div>
    <div>
        <label><?= e(__('treasury.amount')) ?> *</label>
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
            <option value="">—</option>
            <?php foreach ($members as $member): ?>
                <option value="<?= (int) $member['id'] ?>" <?= (string) ($values['member_id'] ?? '') === (string) $member['id'] ? 'selected' : '' ?>>
                    <?= e(trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''))) ?>
                    <?php if (!empty($member['member_number'])): ?> (#<?= e((string) $member['member_number']) ?>)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div>
    <label><?= e(__('treasury.description')) ?></label>
    <input type="text" name="description" value="<?= e((string) ($values['description'] ?? '')) ?>" maxlength="500">
</div>
<?php if (!empty($config['auto_from_payments'])): ?>
    <p class="muted"><?= e(__('treasury.auto_hint')) ?></p>
<?php endif; ?>
