<?php
/**
 * Budget — list of estimates by financial year.
 *
 * @var list<array<string,mixed>> $budgets
 * @var list<array<string,mixed>> $drafts
 * @var list<int> $years
 * @var int $default_year
 * @var list<array{year:int,income:float,can_generate:bool}> $rendiconto_years
 * @var float $runts_limit
 * @var array<string,mixed> $statistics
 * @var \Socly\Services\CurrencyService $currency
 */
$canManage = can('treasury.manage');
$drafts = is_array($drafts ?? null) ? $drafts : [];
$rendicontoYears = is_array($rendiconto_years ?? null) ? $rendiconto_years : [];
$runtsLimit = (float) ($runts_limit ?? 0);
$statistics = is_array($statistics ?? null) ? $statistics : [];
$statsYears = is_array($statistics['years'] ?? null) ? $statistics['years'] : [];
$latestYear = (int) ($statistics['latest_year'] ?? 0);
$latestStats = [];
foreach ($statsYears as $statsRow) {
    if ((int) ($statsRow['year'] ?? 0) === $latestYear) {
        $latestStats = $statsRow;
    }
}
$topExpense = is_array($statistics['categories']['expense'] ?? null) ? $statistics['categories']['expense'] : [];
$topIncome = is_array($statistics['categories']['income'] ?? null) ? $statistics['categories']['income'] : [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('budget.title')) ?></h1>
        <p class="page-lede"><?= e(__('budget.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.title')) ?></a>
    </div>
</div>

<div class="dashboard-tabs" data-budget-tabs data-default-tab="preventivo">
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
            <button type="button" class="dashboard-tab" role="tab" data-budget-tab="statistiche"
                aria-controls="budget-panel-statistiche" aria-selected="false" tabindex="-1">
                <?= e(__('budget.tab_statistics')) ?>
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
                                <td><?= e($currency->format((float) $row['income'])) ?></td>
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

    <section class="dashboard-panel" id="budget-panel-statistiche" role="tabpanel" data-budget-panel="statistiche" hidden>
        <?php if ($statsYears === []): ?>
            <div class="panel">
                <div class="empty-state">
                    <strong><?= e(__('budget.stats_empty')) ?></strong>
                    <p><?= e(__('budget.stats_empty_text')) ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="stats">
                <div class="stat">
                    <div class="label"><?= e(__('budget.stats_coverage')) ?></div>
                    <div class="value"><?= e(number_format((float) ($latestStats['coverage'] ?? 0), 1, ',', '.')) ?>%</div>
                    <div class="stat-hint"><?= e(__('budget.stats_year', ['year' => (string) $latestYear])) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('budget.stats_need')) ?></div>
                    <div class="value"><?= e($currency->format((float) ($latestStats['need'] ?? 0))) ?></div>
                    <div class="stat-hint"><?= e(__('budget.stats_year', ['year' => (string) $latestYear])) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('budget.stats_fees')) ?></div>
                    <div class="value"><?= e($currency->format((float) ($statistics['fees'] ?? 0))) ?></div>
                    <div class="stat-hint"><?= e(__('budget.stats_fees_share', [
                        'percent' => number_format((float) ($statistics['fees_share'] ?? 0), 1, ',', '.'),
                    ])) ?></div>
                </div>
            </div>

            <div class="panel">
                <div class="table-wrap embedded">
                    <table class="data-table statistics-table">
                        <thead>
                        <tr>
                            <th><?= e(__('runts.year')) ?></th>
                            <th><?= e(__('budget.planned_expense')) ?></th>
                            <th><?= e(__('budget.actual_expense')) ?></th>
                            <th><?= e(__('budget.planned_income')) ?></th>
                            <th><?= e(__('budget.actual_income')) ?></th>
                            <th><?= e(__('budget.stats_coverage')) ?></th>
                            <th><?= e(__('budget.stats_need')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($statsYears as $row): ?>
                            <tr>
                                <td><strong><?= e((string) (int) $row['year']) ?></strong></td>
                                <td><?= e($currency->format((float) $row['planned_expense'])) ?></td>
                                <td><?= e($currency->format((float) $row['actual_expense'])) ?></td>
                                <td><?= e($currency->format((float) $row['planned_income'])) ?></td>
                                <td><?= e($currency->format((float) $row['actual_income'])) ?></td>
                                <td><?= e(number_format((float) $row['coverage'], 1, ',', '.')) ?>%</td>
                                <td><?= e($currency->format((float) $row['need'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid-2">
                <div class="panel">
                    <header class="panel-header">
                        <div>
                            <h2 class="section-title"><?= e(__('budget.stats_top_expense')) ?></h2>
                            <p class="section-lede"><?= e(__('budget.stats_year', ['year' => (string) $latestYear])) ?></p>
                        </div>
                    </header>
                    <?php if ($topExpense === []): ?>
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
                                <?php foreach ($topExpense as $cat): ?>
                                    <tr>
                                        <td><?= e((string) $cat['label']) ?></td>
                                        <td><?= e($currency->format((float) $cat['amount'])) ?></td>
                                        <td><?= e(number_format((float) $cat['percent'], 1, ',', '.')) ?>%</td>
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
                            <h2 class="section-title"><?= e(__('budget.stats_top_income')) ?></h2>
                            <p class="section-lede"><?= e(__('budget.stats_year', ['year' => (string) $latestYear])) ?></p>
                        </div>
                    </header>
                    <?php if ($topIncome === []): ?>
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
                                <?php foreach ($topIncome as $cat): ?>
                                    <tr>
                                        <td><?= e((string) $cat['label']) ?></td>
                                        <td><?= e($currency->format((float) $cat['amount'])) ?></td>
                                        <td><?= e(number_format((float) $cat['percent'], 1, ',', '.')) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>

