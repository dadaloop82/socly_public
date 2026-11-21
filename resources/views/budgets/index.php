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
 * Budget — list of estimates by financial year.
 *
 * @var list<array<string,mixed>> $budgets
 * @var list<array<string,mixed>> $drafts
 * @var list<int> $years
 * @var int $default_year
 * @var list<array{year:int,income:float,can_generate:bool}> $rendiconto_years
 * @var float $runts_limit
 * @var array<string,mixed> $annual_report
 * @var int $model_d_year
 * @var list<int> $model_d_years
 * @var array<string,mixed>|null $model_d_report
 * @var string $default_tab
 * @var \Socly\Services\CurrencyService $currency
 */
$canManage = can('treasury.manage');
$drafts = is_array($drafts ?? null) ? $drafts : [];
$rendicontoYears = is_array($rendiconto_years ?? null) ? $rendiconto_years : [];
$runtsLimit = (float) ($runts_limit ?? 0);
$annualReport = is_array($annual_report ?? null) ? $annual_report : [];
$annualYear = (int) ($annualReport['year'] ?? 0);
$annualAvailableYears = is_array($annualReport['available_years'] ?? null) ? $annualReport['available_years'] : [];
if ($annualAvailableYears === [] && $annualYear > 0) {
    $annualAvailableYears = [$annualYear];
}
$annualBudget = is_array($annualReport['budget'] ?? null) ? $annualReport['budget'] : null;
$annualPlanned = is_array($annualReport['planned'] ?? null) ? $annualReport['planned'] : ['expense' => 0.0, 'income' => 0.0];
$annualActual = is_array($annualReport['actual'] ?? null) ? $annualReport['actual'] : ['expense' => 0.0, 'income' => 0.0];
$annualCategories = is_array($annualReport['categories'] ?? null) ? $annualReport['categories'] : ['expense' => [], 'income' => []];
$annualCharts = is_array($annualReport['charts'] ?? null) ? $annualReport['charts'] : [];
$annualHistory = is_array($annualReport['history'] ?? null) ? $annualReport['history'] : [];
$annualRuntsSections = is_array($annualReport['runts_sections'] ?? null) ? $annualReport['runts_sections'] : [];
$annualAccounts = is_array($annualReport['accounts'] ?? null) ? $annualReport['accounts'] : [];
$modelDYears = is_array($model_d_years ?? null) ? $model_d_years : [];
$modelDYear = (int) ($model_d_year ?? 0);
$modelDReport = is_array($model_d_report ?? null) ? $model_d_report : null;
$budgetDefaultTab = in_array((string) ($default_tab ?? ''), ['preventivo', 'consuntivo', 'model_d', 'bilancio'], true)
    ? (string) $default_tab
    : 'preventivo';
$modelDSections = [];
if ($modelDReport !== null) {
    foreach (($modelDReport['sections'] ?? []) as $section) {
        if (is_array($section)) {
            $modelDSections[] = $section;
        }
    }
    if (is_array($modelDReport['investments'] ?? null)) {
        $modelDSections[] = $modelDReport['investments'];
    }
}
$annualYear = $annualYear > 0 ? $annualYear : (int) ($annualAvailableYears[0] ?? date('Y'));
$annualCategoriesExpense = is_array($annualCategories['expense'] ?? null) ? $annualCategories['expense'] : [];
$annualCategoriesIncome = is_array($annualCategories['income'] ?? null) ? $annualCategories['income'] : [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('budget.title')) ?></h1>
        <p class="page-lede"><?= e(__('budget.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn" href="<?= e(url('/rendiconto')) ?>">
            <?= e(__('budget.model_d_button')) ?>
        </a>
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.title')) ?></a>
    </div>
</div>

<div class="dashboard-tabs" data-budget-tabs data-default-tab="<?= e($budgetDefaultTab) ?>">
    <div class="dashboard-tablist-wrap">
        <div class="dashboard-tablist" role="tablist" aria-label="<?= e(__('budget.title')) ?>">
            <button type="button" class="dashboard-tab" role="tab" data-budget-tab="preventivo"
                aria-controls="budget-panel-preventivo" aria-selected="true" tabindex="0">
                <?= e(__('budget.tab_preventivo')) ?>
            </button>
            <button type="button" class="dashboard-tab" role="tab" data-budget-tab="consuntivo"
                aria-controls="budget-panel-consuntivo" aria-selected="false" tabindex="-1">
                <?= e(__('budget.tab_consuntivo')) ?>
            </button>
            <button type="button" class="dashboard-tab" role="tab" data-budget-tab="model_d"
                aria-controls="budget-panel-model_d" aria-selected="false" tabindex="-1">
                <?= e(__('budget.tab_model_d')) ?>
            </button>
            <button type="button" class="dashboard-tab" role="tab" data-budget-tab="bilancio"
                aria-controls="budget-panel-bilancio" aria-selected="false" tabindex="-1">
                <?= e(__('budget.tab_annual')) ?>
            </button>
        </div>
    </div>

    <section class="dashboard-panel" id="budget-panel-preventivo" role="tabpanel" data-budget-panel="preventivo">

<?php if ($budgets === []): ?>
    <div class="panel budget-empty">
        <strong><?= e(__('budget.empty_title')) ?></strong>
        <p class="muted"><?= e(__('budget.empty_text')) ?></p>
    </div>
<?php else: ?>
    <div class="budget-list">
        <?php foreach ($budgets as $row): ?>
            <?php
            $budgetId = (int) ($row['id'] ?? 0);
            $isApproved = (string) ($row['status'] ?? '') === 'approved';
            ?>
            <article class="panel budget-card">
                <header class="budget-card-head">
                    <div>
                        <h2 class="budget-card-year"><?= e((string) ($row['fiscal_year'] ?? '')) ?></h2>
                        <p class="budget-card-title muted"><?= e((string) (($row['title'] ?? '') !== '' ? $row['title'] : __('budget.card_title_default'))) ?></p>
                    </div>
                    <span class="budget-badge <?= $isApproved ? 'is-approved' : 'is-draft' ?>">
                        <?= e($isApproved ? __('budget.status_approved') : __('budget.status_draft')) ?>
                    </span>
                </header>
                <dl class="budget-card-kpis">
                    <div>
                        <dt><?= e(__('budget.planned_expense')) ?></dt>
                        <dd><?= e($currency->format((float) ($row['planned_expense'] ?? 0))) ?></dd>
                    </div>
                    <div>
                        <dt><?= e(__('budget.planned_income')) ?></dt>
                        <dd><?= e($currency->format((float) ($row['planned_income'] ?? 0))) ?></dd>
                    </div>
                    <div>
                        <dt><?= e(__('budget.planned_need')) ?></dt>
                        <dd><?= e($currency->format((float) ($row['planned_need'] ?? 0))) ?></dd>
                    </div>
                </dl>
                <footer class="budget-card-actions">
                    <a class="btn btn-sm" href="<?= e(url('/budgets/' . $budgetId)) ?>"><?= e(__('budget.open')) ?></a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $budgetId . '/compare')) ?>"><?= e(__('budget.compare')) ?></a>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $budgetId . '/rendiconto')) ?>" target="_blank" rel="noopener"><?= e(__('budget.rendiconto')) ?></a>
                </footer>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canManage && $years !== [] && $drafts === []): ?>
    <section class="panel budget-create">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('budget.create_title')) ?></h2>
                <p class="section-lede"><?= e(__('budget.create_lede')) ?></p>
            </div>
        </header>
        <form method="post" action="<?= e(url('/budgets')) ?>" class="budget-create-form" data-leave-guard enctype="multipart/form-data" data-budget-create>
            <?= csrf_field() ?>
            <div class="budget-create-row">
                <?= field('select', [
                    'name' => 'fiscal_year',
                    'label' => (string) __('budget.fiscal_year'),
                    'value' => (string) $default_year,
                    'context' => 'setup',
                    'include_empty' => false,
                    'options' => array_map(
                        static fn (int $year): array => ['value' => (string) $year, 'label' => (string) $year],
                        $years
                    ),
                ]) ?>
                <?= field('text', [
                    'name' => 'title',
                    'label' => (string) __('budget.document_title') . ' (' . __('setup.optional_short') . ')',
                    'value' => '',
                    'context' => 'setup',
                    'maxlength' => 160,
                    'placeholder' => (string) __('budget.document_title_placeholder'),
                ]) ?>
                <?= field('text', [
                    'name' => 'coefficient',
                    'label' => (string) __('budget.coefficient'),
                    'value' => '0',
                    'context' => 'setup',
                    'hint' => (string) __('budget.coefficient_hint'),
                ]) ?>
            </div>
            <fieldset class="budget-template-picker">
                <legend class="setup-field-label"><?= e(__('budget.template_legend')) ?></legend>
                <div class="budget-template-grid">
                    <?php
                    $templates = [
                        'previous_budget' => ['title' => 'budget.tpl_previous_budget', 'lede' => 'budget.tpl_previous_budget_lede'],
                        'previous_actual' => ['title' => 'budget.tpl_previous_actual', 'lede' => 'budget.tpl_previous_actual_lede'],
                        'public_grant_model' => ['title' => 'budget.tpl_grant', 'lede' => 'budget.tpl_grant_lede'],
                        'csv_import' => ['title' => 'budget.tpl_csv', 'lede' => 'budget.tpl_csv_lede'],
                        'empty' => ['title' => 'budget.tpl_empty', 'lede' => 'budget.tpl_empty_lede'],
                    ];
                    $first = true;
                    ?>
                    <?php foreach ($templates as $key => $tpl): ?>
                        <label class="budget-template-option">
                            <input type="radio" name="template" value="<?= e($key) ?>" <?= $first ? 'checked' : '' ?>>
                            <span class="budget-template-body">
                                <strong><?= e(__($tpl['title'])) ?></strong>
                                <span class="muted"><?= e(__($tpl['lede'])) ?></span>
                            </span>
                        </label>
                        <?php $first = false; ?>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="budget-csv-field" data-budget-csv-field>
                <span class="field-label sf-field__label"><?= e(__('budget.csv_file')) ?></span>
                <div class="doc-dropzone budget-dropzone" data-budget-dropzone role="button" tabindex="0">
                    <span class="field-icon doc-dropzone-icon" data-icon="upload" aria-hidden="true"></span>
                    <div class="doc-dropzone-text">
                        <span class="doc-dropzone-title"><?= e(__('budget.csv_drop')) ?></span>
                        <span class="doc-dropzone-meta" data-budget-file-name><?= e(__('budget.csv_none')) ?></span>
                    </div>
                    <?= field('file', [
                        'label' => '',
                        'name' => 'csv_file',
                        'as_button' => false,
                        'input_class' => 'visually-hidden',
                        'accept' => '.csv,text/csv,text/plain',
                        'attrs' => ['data-budget-csv-input' => true],
                    ]) ?>
                </div>
                <p class="setup-hint muted"><?= e(__('budget.csv_hint')) ?></p>
            </div>
            <div class="actions form-actions form-actions-end">
                <button class="btn" type="submit"><?= e(__('budget.create_submit')) ?></button>
            </div>
        </form>
    </section>
<?php elseif ($canManage): ?>
    <?php
    $hasOpenDraft = $drafts !== [];
    $blockedTitle = $hasOpenDraft ? __('budget.create_blocked_draft_title') : __('budget.create_blocked_title');
    $blockedText = $hasOpenDraft ? __('budget.create_blocked_draft_text') : __('budget.create_blocked_text');
    $blockedBudgets = $hasOpenDraft ? $drafts : $budgets;
    ?>
    <section class="panel budget-create budget-create-blocked">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e($blockedTitle) ?></h2>
                <p class="section-lede"><?= e($blockedText) ?></p>
            </div>
        </header>
        <div class="budget-blocked-links">
            <?php foreach ($blockedBudgets as $row): ?>
                <?php
                $blockedId = (int) ($row['id'] ?? 0);
                $blockedApproved = (string) ($row['status'] ?? '') === 'approved';
                ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/' . $blockedId)) ?>">
                    <?= e((string) ($row['fiscal_year'] ?? '')) ?>
                    · <?= e($blockedApproved ? __('budget.status_approved') : __('budget.status_draft')) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

    </section>

    <section class="dashboard-panel" id="budget-panel-consuntivo" role="tabpanel" data-budget-panel="consuntivo" hidden>
        <p class="setup-hint muted"><?= e(__('runts.lede')) ?></p>
        <p class="setup-hint muted"><?= e(__('runts.official_wording_note')) ?></p>
        <p class="setup-hint muted"><?= e(__('runts.limit_hint', ['limit' => $currency->format($runtsLimit)])) ?></p>
        <?php if ($rendicontoYears === []): ?>
            <div class="panel">
                <div class="empty-state">
                    <strong><?= e(__('runts.empty_title')) ?></strong>
                    <p><?= e(__('runts.empty_text')) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="panel">
                <div class="table-wrap embedded">
                    <table class="data-table statistics-table">
                        <thead>
                        <tr>
                            <th><?= e(__('runts.year')) ?></th>
                            <th><?= e(__('runts.income')) ?></th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rendicontoYears as $row): ?>
                            <tr>
                                <td><strong><?= e((string) (int) $row['year']) ?></strong></td>
                                <td class="num"><?= e($currency->format((float) $row['income'])) ?></td>
                                <td>
                                    <?php if (!empty($row['can_generate'])): ?>
                                        <a class="btn btn-sm" href="<?= e(url('/rendiconto/' . (int) $row['year'])) ?>">
                                            <?= e(__('runts.open')) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="budget-badge is-draft"><?= e(__('runts.manual_title')) ?></span>
                                        <span class="muted"><?= e(__('runts.manual_text', [
                                            'income' => $currency->format((float) $row['income']),
                                            'limit' => $currency->format($runtsLimit),
                                        ])) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel" id="budget-panel-model_d" role="tabpanel" data-budget-panel="model_d" hidden>
        <p class="setup-hint muted"><?= e(__('budget.model_d_lede')) ?></p>
        <?php if ($modelDReport === null): ?>
            <div class="panel">
                <div class="empty-state">
                    <strong><?= e(__('runts.empty_title')) ?></strong>
                    <p><?= e(__('budget.model_d_empty')) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="panel budget-model-d-toolbar">
                <form class="filter-bar" method="get" action="<?= e(url('/budgets')) ?>">
                    <label class="field">
                        <span><?= e(__('runts.year')) ?></span>
                        <select name="model_d_year">
                            <?php foreach ($modelDYears as $year): ?>
                                <option value="<?= (int) $year ?>" <?= (int) $year === $modelDYear ? 'selected' : '' ?>>
                                    <?= (int) $year ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-sm" type="submit"><?= e(__('budget.model_d_show')) ?></button>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/rendiconto/' . $modelDYear)) ?>">
                        <?= e(__('runts.open')) ?>
                    </a>
                </form>
            </div>

            <?php foreach ($modelDSections as $modelDSection): ?>
                <?php
                $sectionLines = is_array($modelDSection['lines'] ?? null) ? $modelDSection['lines'] : [];
                $sectionCounts = is_array($modelDSection['line_counts'] ?? null) ? $modelDSection['line_counts'] : [];
                $sectionTotals = is_array($modelDSection['totals'] ?? null) ? $modelDSection['totals'] : [];
                $sectionTotalsCounts = is_array($modelDSection['counts'] ?? null) ? $modelDSection['counts'] : [];
                ?>
                <section class="panel budget-compare-section">
                    <header class="budget-compare-head">
                        <h2 class="section-title"><?= e((string) ($modelDSection['label'] ?? '')) ?></h2>
                        <span class="budget-compare-totals muted">
                            <?= e(__('runts.expenses')) ?> <?= e($currency->format((float) ($sectionTotals['expense'] ?? 0))) ?>
                            · <?= e(__('runts.incomes')) ?> <?= e($currency->format((float) ($sectionTotals['income'] ?? 0))) ?>
                        </span>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="budget-compare-table">
                            <thead>
                            <tr>
                                <th><?= e(__('budget.model_d_items')) ?></th>
                                <th class="budget-th-amount"><?= e(__('budget.model_d_amount')) ?></th>
                                <th class="budget-th-amount"><?= e(__('budget.model_d_movements')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (['expense' => 'runts.expenses', 'income' => 'runts.incomes'] as $direction => $heading): ?>
                                <?php $officialLines = is_array($modelDSection[$direction] ?? null) ? $modelDSection[$direction] : []; ?>
                                <tr class="budget-model-d-group">
                                    <td colspan="3"><?= e(__($heading)) ?></td>
                                </tr>
                                <?php foreach ($officialLines as $line => $label): ?>
                                    <?php
                                    $amount = (float) ($sectionLines[$direction][$line] ?? 0);
                                    $count = (int) ($sectionCounts[$direction][$line] ?? 0);
                                    ?>
                                    <tr class="clickable-row"
                                        data-href="<?= e(ledger_url([
                                            'runts_line' => (string) $line,
                                            'year' => $modelDYear,
                                        ])) ?>"
                                        tabindex="0" role="link" title="<?= e(__('budget.open_movements')) ?>">
                                        <td><?= e((string) $label) ?></td>
                                        <td class="num"><?= e($currency->format($amount)) ?></td>
                                        <td class="num"><?= $count ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="budget-model-d-total">
                                    <td><?= e(__('runts.total_' . $direction)) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($sectionTotals[$direction] ?? 0))) ?></td>
                                    <td class="num"><?= (int) ($sectionTotalsCounts[$direction] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="budget-kpis">
                <div class="budget-kpi">
                    <span class="budget-kpi-label"><?= e(__('runts.total_expense')) ?></span>
                    <strong><?= e($currency->format((float) ($modelDReport['total_expense'] ?? 0))) ?></strong>
                </div>
                <div class="budget-kpi">
                    <span class="budget-kpi-label"><?= e(__('runts.total_income')) ?></span>
                    <strong><?= e($currency->format((float) ($modelDReport['total_income'] ?? 0))) ?></strong>
                </div>
                <div class="budget-kpi">
                    <span class="budget-kpi-label"><?= e(__('runts.balance')) ?></span>
                    <strong><?= e($currency->format((float) ($modelDReport['balance'] ?? 0))) ?></strong>
                </div>
            </div>
            <?php if ((float) ($modelDReport['internal'] ?? 0) > 0): ?>
                <p class="setup-hint muted"><?= e(__('runts.internal', [
                    'amount' => $currency->format((float) $modelDReport['internal']),
                ])) ?></p>
            <?php endif; ?>
            <?php if (($modelDReport['unclassified'] ?? []) !== []): ?>
                <section class="panel budget-compare-section">
                    <header class="budget-compare-head">
                        <div>
                            <h2 class="section-title"><?= e(__('runts.unclassified_title')) ?></h2>
                            <p class="section-lede"><?= e(__('runts.unclassified_text')) ?></p>
                        </div>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="budget-compare-table">
                            <tbody>
                            <?php foreach ($modelDReport['unclassified'] as $unclassified): ?>
                                <?php $category = trim((string) ($unclassified['category'] ?? '')); ?>
                                <tr class="clickable-row"
                                    data-href="<?= e(ledger_url([
                                        'category' => $category !== '' ? $category : '__none__',
                                        'direction' => (string) ($unclassified['direction'] ?? ''),
                                        'year' => $modelDYear,
                                    ])) ?>"
                                    tabindex="0" role="link" title="<?= e(__('budget.open_movements')) ?>">
                                    <td><?= e($category !== '' ? $category : __('treasury.category_other')) ?></td>
                                    <td class="budget-th-amount"><?= e($currency->format((float) ($unclassified['amount'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel" id="budget-panel-bilancio" role="tabpanel" data-budget-panel="bilancio" hidden>
        <div class="panel">
            <div class="budget-model-d-toolbar">
                <form class="filter-bar" method="get" action="<?= e(url('/budgets')) ?>">
                    <input type="hidden" name="tab" value="bilancio">
                    <label class="field">
                        <span><?= e(__('runts.year')) ?></span>
                        <select name="year">
                            <?php foreach ($annualAvailableYears as $optionYear): ?>
                                <option value="<?= (int) $optionYear ?>" <?= (int) $optionYear === $annualYear ? 'selected' : '' ?>>
                                    <?= (int) $optionYear ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn-sm" type="submit"><?= e(__('budget.annual_show')) ?></button>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/budgets/annual/' . $annualYear . '/pdf')) ?>" target="_blank" rel="noopener">
                        <?= e(__('budget.annual_export_pdf')) ?>
                    </a>
                </form>
            </div>
            <p class="section-lede"><?= e(__('budget.annual_lede')) ?></p>
        </div>

        <div class="stats">
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_income')) ?></div>
                <div class="value"><?= e($currency->format((float) ($annualActual['income'] ?? 0))) ?></div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_expense')) ?></div>
                <div class="value"><?= e($currency->format((float) ($annualActual['expense'] ?? 0))) ?></div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_result')) ?></div>
                <div class="value"><?= e($currency->format((float) ($annualReport['result'] ?? 0))) ?></div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_coverage')) ?></div>
                <div class="value"><?= e(number_format((float) ($annualReport['coverage'] ?? 0), 1, ',', '.')) ?>%</div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_fees')) ?></div>
                <div class="value"><?= e($currency->format((float) ($annualReport['fees'] ?? 0))) ?></div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_opening')) ?></div>
                <div class="value"><?= e($currency->format((float) ($annualReport['opening_balance'] ?? 0))) ?></div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
            <div class="stat">
                <div class="label"><?= e(__('budget.annual_closing')) ?></div>
                <div class="value"><?= e($currency->format((float) ($annualReport['closing_balance'] ?? 0))) ?></div>
                <div class="stat-hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></div>
            </div>
        </div>

        <div class="panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('budget.annual_planned_vs_actual')) ?></h2>
                    <p class="section-lede"><?= e(($annualBudget['title'] ?? '') !== '' ? (string) $annualBudget['title'] : __('budget.card_title_default')) ?></p>
                </div>
            </header>
            <div class="table-wrap embedded">
                <table class="data-table statistics-table">
                    <thead>
                    <tr>
                        <th><?= e(__('runts.year')) ?></th>
                        <th><?= e(__('budget.planned_expense')) ?></th>
                        <th><?= e(__('budget.actual_expense')) ?></th>
                        <th><?= e(__('budget.planned_income')) ?></th>
                        <th><?= e(__('budget.actual_income')) ?></th>
                        <th><?= e(__('budget.annual_result')) ?></th>
                        <th><?= e(__('budget.annual_coverage')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><strong><?= e((string) $annualYear) ?></strong></td>
                        <td class="num"><?= e($currency->format((float) ($annualPlanned['expense'] ?? 0))) ?></td>
                        <td class="num"><?= e($currency->format((float) ($annualActual['expense'] ?? 0))) ?></td>
                        <td class="num"><?= e($currency->format((float) ($annualPlanned['income'] ?? 0))) ?></td>
                        <td class="num"><?= e($currency->format((float) ($annualActual['income'] ?? 0))) ?></td>
                        <td class="num"><?= e($currency->format((float) ($annualReport['result'] ?? 0))) ?></td>
                        <td class="num"><?= e(number_format((float) ($annualReport['coverage'] ?? 0), 1, ',', '.')) ?>%</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid-2" data-budget-annual-charts='<?= e(json_encode($annualCharts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>' data-budget-annual-chart-i18n='<?= e(json_encode(["currency" => $currency->display(), "income" => __('budget.annual_income'), "expense" => __('budget.annual_expense')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_flow')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></p>
                    </div>
                </header>
                <div class="chart-panel">
                    <canvas id="chart-budget-annual-flow" height="220"></canvas>
                </div>
            </div>
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_categories')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.direction_expense')) ?></p>
                    </div>
                </header>
                <div class="chart-panel">
                    <canvas id="chart-budget-annual-expense" height="220"></canvas>
                </div>
            </div>
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_categories_income')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.direction_income')) ?></p>
                    </div>
                </header>
                <div class="chart-panel">
                    <canvas id="chart-budget-annual-income" height="220"></canvas>
                </div>
            </div>
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_monthly')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></p>
                    </div>
                </header>
                <div class="chart-panel">
                    <canvas id="chart-budget-annual-monthly" height="220"></canvas>
                </div>
            </div>
        </div>

        <div class="grid-2">
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_categories')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.direction_expense')) ?></p>
                    </div>
                </header>
                <?php if ($annualCategoriesExpense === []): ?>
                    <div class="empty-state compact">—</div>
                <?php else: ?>
                    <div class="table-wrap embedded">
                        <table class="data-table statistics-table">
                            <thead>
                            <tr>
                                <th><?= e(__('budget.category_label')) ?></th>
                                <th><?= e(__('budget.amount')) ?></th>
                                <th>%</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($annualCategoriesExpense as $cat): ?>
                                <?php $catKey = trim((string) ($cat['key'] ?? '')); ?>
                                <tr class="clickable-row"
                                    data-href="<?= e(ledger_url([
                                        'category' => $catKey !== '' ? $catKey : '__none__',
                                        'direction' => 'expense',
                                        'year' => $annualYear,
                                    ])) ?>"
                                    tabindex="0" role="link" title="<?= e(__('budget.open_movements')) ?>">
                                    <td><?= e((string) ($cat['label'] ?? '')) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($cat['amount'] ?? 0))) ?></td>
                                    <td class="num"><?= e(number_format((float) ($cat['percent'] ?? 0), 1, ',', '.')) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_categories_income')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.direction_income')) ?></p>
                    </div>
                </header>
                <?php if ($annualCategoriesIncome === []): ?>
                    <div class="empty-state compact">—</div>
                <?php else: ?>
                    <div class="table-wrap embedded">
                        <table class="data-table statistics-table">
                            <thead>
                            <tr>
                                <th><?= e(__('budget.category_label')) ?></th>
                                <th><?= e(__('budget.amount')) ?></th>
                                <th>%</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($annualCategoriesIncome as $cat): ?>
                                <?php $catKey = trim((string) ($cat['key'] ?? '')); ?>
                                <tr class="clickable-row"
                                    data-href="<?= e(ledger_url([
                                        'category' => $catKey !== '' ? $catKey : '__none__',
                                        'direction' => 'income',
                                        'year' => $annualYear,
                                    ])) ?>"
                                    tabindex="0" role="link" title="<?= e(__('budget.open_movements')) ?>">
                                    <td><?= e((string) ($cat['label'] ?? '')) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($cat['amount'] ?? 0))) ?></td>
                                    <td class="num"><?= e(number_format((float) ($cat['percent'] ?? 0), 1, ',', '.')) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-2">
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_runts_summary')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></p>
                    </div>
                </header>
                <?php if ($annualRuntsSections === []): ?>
                    <div class="empty-state compact">—</div>
                <?php else: ?>
                    <div class="table-wrap embedded">
                        <table class="data-table statistics-table">
                            <thead>
                            <tr>
                                <th><?= e(__('setup.runts_field_section')) ?></th>
                                <th><?= e(__('budget.annual_expense')) ?></th>
                                <th><?= e(__('budget.annual_income')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($annualRuntsSections as $section): ?>
                                <tr>
                                    <td><strong><?= e((string) ($section['label'] ?? $section['letter'] ?? '')) ?></strong></td>
                                    <td class="num"><?= e($currency->format((float) ($section['expense'] ?? 0))) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($section['income'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('budget.annual_accounts')) ?></h2>
                        <p class="section-lede"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></p>
                    </div>
                </header>
                <?php if ($annualAccounts === []): ?>
                    <div class="empty-state compact">—</div>
                <?php else: ?>
                    <div class="table-wrap embedded">
                        <table class="data-table statistics-table">
                            <thead>
                            <tr>
                                <th><?= e(__('treasury.account')) ?></th>
                                <th><?= e(__('budget.annual_income')) ?></th>
                                <th><?= e(__('budget.annual_expense')) ?></th>
                                <th><?= e(__('budget.transfer_in')) ?></th>
                                <th><?= e(__('budget.transfer_out')) ?></th>
                                <th><?= e(__('treasury.balance')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($annualAccounts as $account): ?>
                                <tr>
                                    <td><?= e((string) ($account['label'] ?? $account['key'] ?? '')) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($account['income'] ?? 0))) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($account['expense'] ?? 0))) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($account['transfer_in'] ?? 0))) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($account['transfer_out'] ?? 0))) ?></td>
                                    <td class="num"><?= e($currency->format((float) ($account['balance'] ?? 0))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('budget.annual_history')) ?></h2>
                    <p class="section-lede"><?= e(__('budget.annual_year_hint', ['year' => (string) $annualYear])) ?></p>
                </div>
            </header>
            <?php if ($annualHistory === []): ?>
                <div class="empty-state compact">—</div>
            <?php else: ?>
                <div class="table-wrap embedded">
                    <table class="data-table statistics-table">
                        <thead>
                        <tr>
                            <th><?= e(__('runts.year')) ?></th>
                            <th><?= e(__('budget.planned_expense')) ?></th>
                            <th><?= e(__('budget.actual_expense')) ?></th>
                            <th><?= e(__('budget.planned_income')) ?></th>
                            <th><?= e(__('budget.actual_income')) ?></th>
                            <th><?= e(__('budget.annual_result')) ?></th>
                            <th><?= e(__('budget.annual_coverage')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($annualHistory as $row): ?>
                            <?php $historyYear = (int) ($row['year'] ?? 0); ?>
                            <tr class="clickable-row"
                                data-href="<?= e(ledger_url(['year' => $historyYear])) ?>"
                                tabindex="0" role="link"
                                title="<?= e(__('budget.open_year_movements', ['year' => (string) $historyYear])) ?>">
                                <td><strong><?= e((string) $historyYear) ?></strong></td>
                                <td class="num"><?= e($currency->format((float) ($row['planned_expense'] ?? 0))) ?></td>
                                <td class="num"><?= e($currency->format((float) ($row['actual_expense'] ?? 0))) ?></td>
                                <td class="num"><?= e($currency->format((float) ($row['planned_income'] ?? 0))) ?></td>
                                <td class="num"><?= e($currency->format((float) ($row['actual_income'] ?? 0))) ?></td>
                                <td class="num"><?= e($currency->format((float) ($row['actual_income'] ?? 0) - (float) ($row['actual_expense'] ?? 0))) ?></td>
                                <td class="num"><?= e(number_format((float) ($row['coverage'] ?? 0), 1, ',', '.')) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script src="<?= e(asset('js/chart.umd.min.js')) ?>"></script>

