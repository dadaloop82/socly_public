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
$category = (string) ($row['category'] ?? '');
$categoryKeys = array_map(static fn (array $option): string => (string) ($option['key'] ?? ''), $categories);
// Etichette arrivate da import CSV in un'altra lingua: se riconosciamo la categoria di sistema
// usiamo la chiave canonica, così il nome compare tradotto nella lingua dell'utente.
if ($category !== '' && !in_array($category, $categoryKeys, true)) {
    try {
        $resolved = app(\Socly\Services\TreasuryService::class)->resolveCategoryFromLabel($category);
        if ($resolved !== null && in_array($resolved, $categoryKeys, true)) {
            $category = $resolved;
        }
    } catch (\Throwable) {
        // nessuna mappatura: resta il testo salvato
    }
}
$showUnknownCategory = $category !== '' && !in_array($category, $categoryKeys, true);
$categoryIsBuiltin = in_array($category, \Socly\Services\TreasuryService::BUILTIN_CATEGORIES, true);
$kind = \Socly\Services\BudgetService::normalizeKind(
    (string) ($row['kind'] ?? ''),
    (string) ($row['direction'] ?? 'expense')
);
$amount = $row !== null ? number_format((float) ($row['amount'] ?? 0), 2, ',', '.') : '';
?>
<tr class="budget-row" data-budget-row>
    <td class="budget-cell-category">
        <div class="budget-category-row">
        <?php if ($category !== '' && !$categoryIsBuiltin): ?>
            <button type="button" class="budget-category-rename" data-budget-category-rename="<?= e($category) ?>"
                    aria-label="<?= e(__('budget.category_rename')) ?>" title="<?= e(__('budget.category_rename')) ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
                </svg>
            </button>
        <?php endif; ?>
        <input type="hidden" name="item_id[]" value="<?= e((string) $rowId) ?>">
        <input type="hidden" name="item_section[]" value="<?= e($sectionKey) ?>" data-budget-item-section>
        <input type="hidden" name="item_notes[]" value="<?= e((string) ($row['notes'] ?? '')) ?>">
        <select class="budget-select" name="item_category[]" data-budget-category aria-label="<?= e(__('budget.category_label')) ?>">
            <option value=""><?= e(__('budget.category_none')) ?></option>
            <?php $categoryGroups = is_array($category_groups ?? null) ? $category_groups : []; ?>
            <?php if ($categoryGroups !== []): ?>
                <?php foreach ($categoryGroups as $group): ?>
                    <?php $groupOptions = is_array($group['options'] ?? null) ? $group['options'] : []; ?>
                    <?php if ($groupOptions === []) {
                        continue;
                    } ?>
                    <optgroup label="<?= e((string) ($group['label'] ?? '')) ?>">
                        <?php foreach ($groupOptions as $option): ?>
                            <?php $optionKey = (string) ($option['value'] ?? ''); ?>
                            <option value="<?= e($optionKey) ?>" <?= $optionKey === $category ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? $optionKey)) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($categories as $option): ?>
                    <?php $optionKey = (string) ($option['key'] ?? ''); ?>
                    <option value="<?= e($optionKey) ?>" <?= $optionKey === $category ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? $optionKey)) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($showUnknownCategory): ?>
                <option value="<?= e($category) ?>" selected><?= e($category) ?></option>
            <?php endif; ?>
            <option value="__new__"><?= e(__('treasury.category_new')) ?></option>
        </select>
        <input
            type="text"
            class="budget-input budget-new-category"
            name="item_new_category[]"
            value=""
            maxlength="80"
            placeholder="<?= e(__('treasury.category_new_placeholder')) ?>"
            aria-label="<?= e(__('treasury.category_new_label')) ?>"
            data-budget-new-category
            hidden
        >
        </div>
    </td>
    <td class="budget-cell-label">
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
    <td class="budget-cell-kind">
        <select class="budget-select" name="item_kind[]" data-budget-kind aria-label="<?= e(__('budget.direction')) ?>">
            <?php foreach (\Socly\Services\BudgetService::KINDS as $kindKey): ?>
                <option value="<?= e($kindKey) ?>"
                    data-sign="<?= e(\Socly\Services\BudgetService::kindDirection($kindKey)) ?>"
                    <?= $kind === $kindKey ? 'selected' : '' ?>>
                    <?= e(\Socly\Services\BudgetService::kindLabel($kindKey)) ?>
                </option>
            <?php endforeach; ?>
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
