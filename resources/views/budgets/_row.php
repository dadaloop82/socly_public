<?php
/**
 * Inline budget row (also rendered with $sectionKey = "__KEY__" inside the JS template).
 *
 * @var array<string,mixed>|null $row
 * @var string $sectionKey
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 */
$row = is_array($row ?? null) ? $row : null;
$rowId = (int) ($row['id'] ?? 0);
$direction = (string) ($row['direction'] ?? 'expense') === 'income' ? 'income' : 'expense';
$category = (string) ($row['category'] ?? '');
$amount = $row !== null ? number_format((float) ($row['amount'] ?? 0), 2, ',', '') : '';
?>
<tr class="budget-row" data-budget-row>
    <td class="budget-cell-label">
        <input type="hidden" name="item_id[]" value="<?= e((string) $rowId) ?>">
        <input type="hidden" name="item_section[]" value="<?= e($sectionKey) ?>" data-budget-item-section>
        <input type="hidden" name="item_notes[]" value="<?= e((string) ($row['notes'] ?? '')) ?>">
        <input
            type="text"
            class="budget-input"
            name="item_label[]"
            value="<?= e((string) ($row['label'] ?? '')) ?>"
            maxlength="190"
            placeholder="<?= e(__('budget.item_label_placeholder')) ?>"
            aria-label="<?= e(__('budget.item_label')) ?>"
        >
    </td>
    <td class="budget-cell-category">
        <select class="budget-select" name="item_category[]" aria-label="<?= e(__('treasury.category')) ?>">
            <option value=""><?= e(__('budget.category_none')) ?></option>
            <?php foreach ($categories as $option): ?>
                <?php $optionKey = (string) ($option['key'] ?? ''); ?>
                <option value="<?= e($optionKey) ?>" <?= $optionKey === $category ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? $optionKey)) ?></option>
            <?php endforeach; ?>
        </select>
    </td>
    <td class="budget-cell-direction">
        <select class="budget-select" name="item_direction[]" data-budget-direction aria-label="<?= e(__('budget.direction')) ?>">
            <option value="expense" <?= $direction === 'expense' ? 'selected' : '' ?>><?= e(__('budget.direction_expense')) ?></option>
            <option value="income" <?= $direction === 'income' ? 'selected' : '' ?>><?= e(__('budget.direction_income')) ?></option>
        </select>
    </td>
    <td class="budget-cell-amount">
        <div class="budget-amount-wrap">
            <input
                type="text"
                inputmode="decimal"
                class="budget-input budget-amount"
                name="item_amount[]"
                value="<?= e($amount) ?>"
                data-budget-amount
                aria-label="<?= e(__('budget.amount')) ?>"
                placeholder="0,00"
            >
            <span class="budget-amount-currency" aria-hidden="true"><?= e($currencySymbol ?? '') ?></span>
        </div>
    </td>
    <td class="budget-cell-actions">
        <button type="button" class="budget-remove" data-budget-row-remove aria-label="<?= e(__('budget.remove_item')) ?>" title="<?= e(__('budget.remove_item')) ?>">×</button>
    </td>
</tr>
