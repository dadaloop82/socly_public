<?php
/**
 * Bilancio — elenco preventivi per anno sociale.
 *
 * @var list<array<string,mixed>> $budgets
 * @var list<array<string,mixed>> $drafts
 * @var list<int> $years
 * @var int $default_year
 * @var \Socly\Services\CurrencyService $currency
 */
$canManage = can('treasury.manage');
$drafts = is_array($drafts ?? null) ? $drafts : [];
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

