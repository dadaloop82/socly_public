<?php
/**
 * Bilancio preventivo — griglia inline editabile (sezioni + voci).
 *
 * @var array<string,mixed> $budget
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var \Socly\Services\CurrencyService $currency
 * @var bool $canManage
 */
$budgetId = (int) ($budget['id'] ?? 0);
$sections = is_array($budget['sections'] ?? null) ? $budget['sections'] : [];
$isApproved = (string) ($budget['status'] ?? '') === 'approved';
$approvedAt = trim((string) ($budget['approved_at'] ?? ''));
$approvedBy = trim((string) ($budget['approver_name'] ?? ''));
$budgetTitle = trim((string) ($budget['title'] ?? ''));
$currencySymbol = $currency->display();
$rowPartial = static fn (?array $row, string $sectionKey): string => view_partial('budgets/_row', [
    'row' => $row,
    'sectionKey' => $sectionKey,
    'categories' => $categories,
    'currencySymbol' => $currencySymbol,
]);
?>
<div class="page-header">
    <div class="titles">
        <p class="budget-eyebrow"><?= e(__('budget.preventivo_eyebrow')) ?></p>
        <h1 class="page-title"><?= e(__('budget.title_year', ['year' => (string) ($budget['fiscal_year'] ?? '')])) ?></h1>
        <p class="page-lede">
            <?= e($budgetTitle !== '' ? $budgetTitle : __('budget.card_title_default')) ?>
            <span class="budget-badge <?= $isApproved ? 'is-approved' : 'is-draft' ?>"><?= e($isApproved ? __('budget.status_approved') : __('budget.status_draft')) ?></span>
        </p>
        <?php if ($isApproved && $approvedAt !== ''): ?>
            <p class="setup-hint muted"><?= e(__('budget.approved_meta', ['date' => format_datetime($approvedAt), 'name' => $approvedBy])) ?></p>
        <?php endif; ?>
    </div>
    <div class="actions budget-header-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets')) ?>"><?= e(__('budget.all_budgets')) ?></a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $budgetId . '/compare')) ?>"><?= e(__('budget.compare')) ?></a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $budgetId . '/rendiconto')) ?>" target="_blank" rel="noopener"><?= e(__('budget.rendiconto_export')) ?></a>
        <?php if ($canManage && !$isApproved): ?>
            <form method="post" action="<?= e(url('/budgets/' . $budgetId . '/approve')) ?>" data-confirm="<?= e(__('budget.approve_confirm')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm" type="submit"><?= e(__('budget.approve')) ?></button>
            </form>
        <?php elseif ($canManage): ?>
            <form method="post" action="<?= e(url('/budgets/' . $budgetId . '/reopen')) ?>" data-confirm="<?= e(__('budget.reopen_confirm')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('budget.reopen')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>


<form
    method="post"
    action="<?= e(url('/budgets/' . $budgetId)) ?>"
    class="budget-grid-form"
    data-budget-grid
    data-currency-symbol="<?= e($currencySymbol) ?>"
    data-msg-remove-section="<?= e(__('budget.remove_section_confirm')) ?>"
>
    <?= csrf_field() ?>
    <div class="budget-grid">
        <?php foreach ($sections as $sectionIndex => $section): ?>
            <?php
            $sectionKey = 's' . $sectionIndex;
            $sectionId = (int) ($section['id'] ?? 0);
            $sectionItems = is_array($section['items'] ?? null) ? $section['items'] : [];
            ?>
            <section class="budget-section" data-budget-section data-section-key="<?= e($sectionKey) ?>">
                <header class="budget-section-head">
                    <span class="budget-section-index" aria-hidden="true"></span>
                    <input
                        type="text"
                        class="budget-section-title"
                        name="section_title[]"
                        value="<?= e((string) ($section['title'] ?? '')) ?>"
                        maxlength="190"
                        placeholder="<?= e(__('budget.section_title_placeholder')) ?>"
                        aria-label="<?= e(__('budget.section_title')) ?>"
                    >
                    <span class="budget-section-total">
                        <span data-budget-section-total="expense"><?= e($currency->format((float) ($section['planned_expense'] ?? 0))) ?></span>
                        <span class="budget-section-total-sep" aria-hidden="true">·</span>
                        <span data-budget-section-total="income"><?= e($currency->format((float) ($section['planned_income'] ?? 0))) ?></span>
                    </span>
                    <button type="button" class="budget-remove" data-budget-section-remove aria-label="<?= e(__('budget.remove_section')) ?>" title="<?= e(__('budget.remove_section')) ?>">×</button>
                </header>
                <input type="hidden" name="section_key[]" value="<?= e($sectionKey) ?>">
                <input type="hidden" name="section_id[]" value="<?= e((string) $sectionId) ?>">
                <div class="table-wrap embedded">
                    <table class="budget-table">
                        <thead>
                        <tr>
                            <th><?= e(__('budget.item_label')) ?></th>
                            <th><?= e(__('treasury.category')) ?></th>
                            <th><?= e(__('budget.direction')) ?></th>
                            <th class="budget-th-amount"><?= e(__('budget.amount')) ?></th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody data-budget-rows>
                        <?php foreach ($sectionItems as $item): ?>
                            <?= $rowPartial($item, $sectionKey) ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-ghost btn-sm budget-add-row" data-budget-add-row><?= e(__('budget.add_item')) ?></button>
                <template data-budget-row-template><?= $rowPartial(null, '__KEY__') ?></template>
            </section>
        <?php endforeach; ?>
    </div>

<?php if ($isApproved): ?>
    <p class="budget-locked-note"><?= e(__('budget.locked_note')) ?></p>
<?php endif; ?>

    <div class="budget-grid-tools">
        <button type="button" class="btn btn-ghost" data-budget-add-section><?= e(__('budget.add_section')) ?></button>
    </div>

    <template data-budget-section-template>
        <section class="budget-section" data-budget-section data-section-key="__KEY__">
            <header class="budget-section-head">
                <span class="budget-section-index" aria-hidden="true"></span>
                <input type="text" class="budget-section-title" name="section_title[]" value="" maxlength="190" placeholder="<?= e(__('budget.section_title_placeholder')) ?>" aria-label="<?= e(__('budget.section_title')) ?>">
                <span class="budget-section-total">
                    <span data-budget-section-total="expense"><?= e($currency->format(0)) ?></span>
                    <span class="budget-section-total-sep" aria-hidden="true">·</span>
                    <span data-budget-section-total="income"><?= e($currency->format(0)) ?></span>
                </span>
                <button type="button" class="budget-remove" data-budget-section-remove aria-label="<?= e(__('budget.remove_section')) ?>">×</button>
            </header>
            <input type="hidden" name="section_key[]" value="__KEY__">
            <input type="hidden" name="section_id[]" value="0">
            <div class="table-wrap embedded">
                <table class="budget-table">
                    <thead>
                    <tr>
                        <th><?= e(__('budget.item_label')) ?></th>
                        <th><?= e(__('treasury.category')) ?></th>
                        <th><?= e(__('budget.direction')) ?></th>
                        <th class="budget-th-amount"><?= e(__('budget.amount')) ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody data-budget-rows></tbody>
                </table>
            </div>
            <button type="button" class="btn btn-ghost btn-sm budget-add-row" data-budget-add-row><?= e(__('budget.add_item')) ?></button>
            <template data-budget-row-template><?= $rowPartial(null, '__KEY__') ?></template>
        </section>
    </template>

    <div class="budget-summary" data-budget-summary>
        <div class="budget-summary-kpis">
            <div class="budget-summary-item">
                <span><?= e(__('budget.planned_expense')) ?></span>
                <strong data-budget-grand="expense"><?= e($currency->format((float) ($budget['planned_expense'] ?? 0))) ?></strong>
            </div>
            <div class="budget-summary-item">
                <span><?= e(__('budget.planned_income')) ?></span>
                <strong data-budget-grand="income"><?= e($currency->format((float) ($budget['planned_income'] ?? 0))) ?></strong>
            </div>
            <div class="budget-summary-item budget-summary-need">
                <span><?= e(__('budget.planned_need')) ?></span>
                <strong data-budget-grand="need"><?= e($currency->format((float) ($budget['planned_need'] ?? 0))) ?></strong>
            </div>
        </div>
        <?php if ($canManage): ?>
            <div class="budget-summary-actions">
                <button class="btn" type="submit"><?= e(__('budget.save_draft')) ?></button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if ($canManage): ?>
    <div class="budget-danger">
        <form method="post" action="<?= e(url('/budgets/' . $budgetId . '/delete')) ?>" data-confirm="<?= e(__('budget.delete_confirm')) ?>" data-confirm-danger="1">
            <?= csrf_field() ?>
            <button class="btn btn-danger btn-sm" type="submit"><?= e(__('budget.delete')) ?></button>
        </form>
    </div>
<?php endif; ?>

