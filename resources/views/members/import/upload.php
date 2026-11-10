<?php
/**
 * Import soci — step 1: caricamento del file CSV.
 *
 * @var array<string,mixed>|null $state
 * @var int $maxMegabytes
 */
$state = is_array($state ?? null) ? $state : null;
$maxMegabytes = (int) ($maxMegabytes ?? 5);
$membersUrl = url('/members');
$howSteps = [
    ['members.import.how_1_title', 'members.import.how_1_text'],
    ['members.import.how_2_title', 'members.import.how_2_text'],
    ['members.import.how_3_title', 'members.import.how_3_text'],
    ['members.import.how_4_title', 'members.import.how_4_text'],
];
$knowItems = [
    'members.import.know_formats',
    'members.import.know_size',
    'members.import.know_duplicates',
    'members.import.know_safe',
];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.import.title')) ?></h1>
        <p class="page-lede"><?= e(__('members.import.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e($membersUrl) ?>"><?= e(__('members.import.back_members')) ?></a>
    </div>
</div>

<?= view_partial('members/import/steps', ['current' => 1]) ?>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.import.upload_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.import.upload_lede')) ?></p>
        </div>
    </header>
    <form method="post" action="<?= e(url('/members/import/upload')) ?>" enctype="multipart/form-data" class="import-upload-form" data-import-file-field>
        <?= csrf_field() ?>
        <span class="field-label sf-field__label"><?= e(__('members.import.file_label')) ?></span>
        <div class="doc-dropzone import-dropzone" data-import-dropzone role="button" tabindex="0">
            <span class="field-icon doc-dropzone-icon" data-icon="upload" aria-hidden="true"></span>
            <div class="doc-dropzone-text">
                <span class="doc-dropzone-title"><?= e(__('members.import.file_drop')) ?></span>
                <span class="doc-dropzone-meta" data-import-file-name><?= e(__('members.import.file_none')) ?></span>
                <span class="doc-dropzone-meta" data-import-status hidden><?= e(__('members.import.analyzing')) ?></span>
            </div>
            <?= field('file', [
                'label' => '',
                'name' => 'csv_file',
                'as_button' => false,
                'input_class' => 'visually-hidden',
                'accept' => '.csv,.txt,.tsv,text/csv,text/plain',
                'attrs' => ['data-import-file-input' => true],
            ]) ?>
        </div>
        <p class="setup-hint muted"><?= e(__('members.import.file_hint', ['size' => (string) $maxMegabytes])) ?></p>
        <div class="actions form-actions form-actions-end">
            <a class="btn btn-ghost" href="<?= e($membersUrl) ?>"><?= e(__('common.cancel')) ?></a>
            <button class="btn" type="submit" data-import-submit><?= e(__('members.import.upload_submit')) ?></button>
        </div>
    </form>
</section>

<div class="import-help-grid">
    <section class="panel import-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('members.import.how_title')) ?></h2>
            </div>
        </header>
        <ol class="import-how">
            <?php foreach ($howSteps as $step): ?>
                <li>
                    <strong><?= e(__($step[0])) ?></strong>
                    <span><?= e(__($step[1])) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="panel import-panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('members.import.know_title')) ?></h2>
            </div>
        </header>
        <ul class="import-know">
            <?php foreach ($knowItems as $item): ?>
                <li><?= e(__($item, ['size' => (string) $maxMegabytes])) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<?php if ($state !== null && (string) ($state['source_name'] ?? '') !== ''): ?>
    <section class="panel import-panel">
        <p class="import-pending">
            <?= e(__('members.import.pending_file', ['name' => (string) $state['source_name']])) ?>
        </p>
        <div class="actions form-actions">
            <a class="btn" href="<?= e(url('/members/import/map')) ?>"><?= e(__('members.import.continue_mapping')) ?></a>
            <form method="post" action="<?= e(url('/members/import/cancel')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-ghost" type="submit"><?= e(__('members.import.reset')) ?></button>
            </form>
        </div>
    </section>
<?php endif; ?>
