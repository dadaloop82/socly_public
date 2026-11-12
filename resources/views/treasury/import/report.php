<?php
/**
 * Import first note — step 4: final report of the run.
 *
 * @var array<string,mixed> $report
 */
$errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
$newCategories = is_array($report['new_categories'] ?? null) ? $report['new_categories'] : [];
$failed = (int) ($report['failed'] ?? 0);
$sourceName = trim((string) ($report['source_name'] ?? ''));
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.import.report_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.import.report_lede', [
            'file' => $sourceName !== '' ? $sourceName : '—',
            'date' => format_datetime((string) ($report['at'] ?? '')) ?: (string) ($report['at'] ?? ''),
        ])) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('treasury.import.back_treasury')) ?></a>
        <a class="btn" href="<?= e(url('/treasury/import')) ?>"><?= e(__('treasury.import.again')) ?></a>
    </div>
</div>

<?= view_partial('treasury/import/steps', ['current' => 5]) ?>

<div class="stats import-stats">
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.report_created')) ?></div>
        <div class="value"><?= (int) ($report['created'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.report_skipped')) ?></div>
        <div class="value"><?= (int) ($report['skipped'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('treasury.import.report_failed')) ?></div>
        <div class="value"><?= $failed ?></div>
    </div>
</div>

<?php if ($newCategories !== []): ?>
    <section class="panel import-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('treasury.import.report_new_categories')) ?></h2>
            </div>
        </header>
        <ul class="import-created-refs">
            <?php foreach ($newCategories as $name): ?>
                <li><?= e((string) $name) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.import.report_errors_title')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.import.report_errors_lede')) ?></p>
        </div>
        <?php if ($failed > 0): ?>
            <div class="actions">
                <?= page_action_icon([
                    'icon' => 'download',
                    'label' => (string) __('treasury.import.report_download'),
                    'href' => url('/treasury/import/errors'),
                ]) ?>
            </div>
        <?php endif; ?>
    </header>
    <?php if ($errors === []): ?>
        <p class="muted"><?= e(__('treasury.import.report_no_errors')) ?></p>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table class="import-preview-table">
                <thead>
                <tr>
                    <th><?= e(__('treasury.import.col_line')) ?></th>
                    <th><?= e(__('treasury.csv_description')) ?></th>
                    <th><?= e(__('treasury.import.col_notes')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($errors as $error): ?>
                    <tr>
                        <td class="import-line"><?= (int) ($error['line'] ?? 0) ?></td>
                        <td><?= e((string) ($error['description'] ?? '')) ?></td>
                        <td><?= e((string) ($error['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<form method="post" action="<?= e(url('/treasury/import/cancel')) ?>" class="import-reset-form">
    <?= csrf_field() ?>
    <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('treasury.import.reset')) ?></button>
</form>
