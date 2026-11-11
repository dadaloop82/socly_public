<?php
/**
 * Import CSV della prima nota: upload → anteprima con mappatura automatica → conferma.
 *
 * @var array{headers:list<string>,map:array<string,string>,unmapped:list<string>,rows:list<array<string,string>>,total:int}|null $preview
 * @var array<string,string> $fields
 */
$preview = is_array($preview ?? null) ? $preview : null;
$fields = is_array($fields ?? null) ? $fields : [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.csv_import_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.csv_import_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury/import/template')) ?>"><?= e(__('treasury.csv_template_download')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/treasury')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>

<section class="panel">
    <form method="post" action="<?= e(url('/treasury/import/upload')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?= field('file', [
            'name' => 'csv_file',
            'label' => (string) __('treasury.csv_file'),
            'accept' => '.csv,text/csv,text/plain',
            'button_label' => (string) __('treasury.csv_choose'),
            'as_button' => true,
            'button_class' => 'btn btn-ghost file-btn',
            'required' => true,
        ]) ?>
        <p class="setup-hint muted"><?= e(__('treasury.csv_upload_hint')) ?></p>
        <div class="actions form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('treasury.csv_preview')) ?></button>
        </div>
    </form>
</section>

<?php if ($preview !== null): ?>
    <section class="panel">
        <header class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__('treasury.csv_preview_title', ['count' => (string) (int) $preview['total']])) ?></h2>
                <p class="section-lede"><?= e(__('treasury.csv_preview_lede')) ?></p>
            </div>
        </header>
        <div class="table-wrap embedded">
            <table class="data-table statistics-table">
                <thead>
                <tr>
                    <th><?= e(__('treasury.csv_column')) ?></th>
                    <th><?= e(__('treasury.csv_mapped_to')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['headers'] as $header): ?>
                    <tr>
                        <td><?= e($header) ?></td>
                        <td>
                            <?php if (isset($preview['map'][$header])): ?>
                                <span class="badge badge-ok"><?= e($fields[$preview['map'][$header]] ?? $preview['map'][$header]) ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted"><?= e(__('treasury.csv_ignored')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (($preview['rows'] ?? []) !== []): ?>
            <div class="table-wrap embedded">
                <table class="data-table statistics-table">
                    <thead>
                    <tr>
                        <?php foreach ($preview['headers'] as $header): ?>
                            <th><?= e($header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($preview['headers'] as $header): ?>
                                <td><?= e((string) ($row[$header] ?? '')) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="actions form-actions form-actions-end">
            <form method="post" action="<?= e(url('/treasury/import/cancel')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-ghost" type="submit"><?= e(__('common.cancel')) ?></button>
            </form>
            <form method="post" action="<?= e(url('/treasury/import/commit')) ?>">
                <?= csrf_field() ?>
                <button class="btn" type="submit"><?= e(__('treasury.csv_import_confirm')) ?></button>
            </form>
        </div>
    </section>
<?php endif; ?>
