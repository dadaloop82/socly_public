<?php
/**
 * Import members — step 5: final report of the run.
 *
 * @var array<string,mixed> $report
 */
$errors = is_array($report['errors'] ?? null) ? $report['errors'] : [];
$createdTypes = is_array($report['created_types'] ?? null) ? $report['created_types'] : [];
$createdFields = is_array($report['created_fields'] ?? null) ? $report['created_fields'] : [];
$failed = (int) ($report['failed'] ?? 0);
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.import.report_title')) ?></h1>
        <p class="page-lede"><?= e(__('members.import.report_lede', ['run' => (string) (int) ($report['run_id'] ?? 0)])) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/members')) ?>"><?= e(__('members.import.back_members')) ?></a>
        <a class="btn" href="<?= e(url('/members/import')) ?>"><?= e(__('members.import.again')) ?></a>
    </div>
</div>

<?= view_partial('members/import/steps', ['current' => 5]) ?>

<div class="stats import-stats">
    <div class="stat">
        <div class="label"><?= e(__('members.import.report_created')) ?></div>
        <div class="value"><?= (int) ($report['created'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.report_updated')) ?></div>
        <div class="value"><?= (int) ($report['updated'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.report_skipped')) ?></div>
        <div class="value"><?= (int) ($report['skipped'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.report_failed')) ?></div>
        <div class="value"><?= $failed ?></div>
    </div>
</div>

<?php if ($createdTypes !== [] || $createdFields !== []): ?>
    <section class="panel import-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('members.import.report_created_refs')) ?></h2>
            </div>
        </header>
        <ul class="import-created-refs">
            <?php foreach ($createdTypes as $name): ?>
                <li><?= e(__('members.import.report_created_type', ['name' => (string) $name])) ?></li>
            <?php endforeach; ?>
            <?php foreach ($createdFields as $name): ?>
                <li><?= e(__('members.import.report_created_field', ['name' => (string) $name])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.import.report_errors_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.import.report_errors_lede')) ?></p>
        </div>
        <?php if ($failed > 0): ?>
            <div class="actions">
                <?= page_action_icon([
                    'icon' => 'sheet',
                    'label' => (string) __('members.import.report_download'),
                    'href' => url('/members/import/errors'),
                ]) ?>
            </div>
        <?php endif; ?>
    </header>
    <?php if ($errors === []): ?>
        <p class="muted"><?= e(__('members.import.report_no_errors')) ?></p>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table class="import-preview-table">
                <thead>
                <tr>
                    <th><?= e(__('members.import.col_line')) ?></th>
                    <th><?= e(__('members.import.col_member')) ?></th>
                    <th><?= e(__('members.import.col_errors')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($errors as $error): ?>
                    <tr>
                        <td class="import-line"><?= (int) ($error['line'] ?? 0) ?></td>
                        <td><?= e((string) ($error['member'] ?? '')) ?></td>
                        <td><?= e((string) ($error['message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<form method="post" action="<?= e(url('/members/import/cancel')) ?>" class="import-reset-form">
    <?= csrf_field() ?>
    <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('members.import.reset')) ?></button>
</form>
