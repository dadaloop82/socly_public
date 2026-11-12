<?php
/**
 * Import first note — step 2: mapping CSV columns → first-note fields.
 *
 * @var array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>,has_header:bool,delimiter:string,encoding:string,delimiter_label:string} $table
 * @var list<array{index:int,header:string,suggested:string,sample:string,kind:string,filled:int,rows:int}> $columns
 * @var list<array{label:string,options:list<array{value:string,label:string}>}> $targetGroups
 * @var array<int,string> $map
 * @var array<string,string> $options
 * @var list<array{key:string,label:string,active:bool}> $accounts
 * @var list<array{key:string,label:string,builtin:bool}> $categories
 * @var list<array{label:string,options:list<array{value:string,label:string}>}> $methodGroups
 * @var string $sourceName
 */
$map = is_array($map ?? null) ? $map : [];
$options = is_array($options ?? null) ? $options : [];
$accountOptions = [];
foreach (($accounts ?? []) as $account) {
    if (empty($account['active'])) {
        continue;
    }
    $accountOptions[(string) $account['key']] = (string) $account['label'];
}
$categoryOptions = [];
foreach (($categories ?? []) as $category) {
    $categoryOptions[(string) $category['key']] = (string) $category['label'];
}
$methodGroups = is_array($methodGroups ?? null) ? $methodGroups : [];
$kindLabels = [
    'date' => 'treasury.import.kind_date',
    'datetime' => 'treasury.import.kind_datetime',
    'amount' => 'treasury.import.kind_amount',
    'number' => 'treasury.import.kind_number',
    'word' => 'treasury.import.kind_word',
    'text' => 'treasury.import.kind_text',
];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.import.step_map_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.import.step_map_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury/import')) ?>"><?= e(__('treasury.import.change_file')) ?></a>
    </div>
</div>

<?= view_partial('treasury/import/steps', ['current' => 2]) ?>

<div class="alert alert-success import-loaded">
    <strong><?= e(__('treasury.import.map_loaded', [
        'file' => $sourceName,
        'rows' => (string) count($table['rows']),
        'columns' => (string) count($table['headers']),
    ])) ?></strong>
    <span class="import-loaded-hint"><?= e(__('treasury.import.map_loaded_hint')) ?></span>
</div>

<form method="post" action="<?= e(url('/treasury/import/header')) ?>" class="import-header-form">
    <?= csrf_field() ?>
    <input type="hidden" name="has_header" value="0">
    <label class="import-flag">
        <input type="checkbox" name="has_header" value="1" <?= !empty($table['has_header']) ? 'checked' : '' ?>>
        <span><?= e(__('treasury.import.map_header_label')) ?></span>
    </label>
    <button class="btn btn-sm btn-ghost" type="submit"><?= e(__('treasury.import.map_header_apply')) ?></button>
    <span class="muted import-header-hint"><?= e(__('treasury.import.map_header_hint')) ?></span>
</form>

<details class="import-raw" open>
    <summary><?= e(__('treasury.import.map_preview_title')) ?></summary>
    <div class="table-wrap embedded">
        <table class="import-raw-table">
            <thead>
            <tr>
                <th><?= e(__('treasury.import.col_line')) ?></th>
                <?php foreach ($table['headers'] as $header): ?>
                    <th><?= e((string) $header) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($table['rows'], 0, 3) as $rawRow): ?>
                <tr>
                    <td class="import-line"><?= (int) ($rawRow['line'] ?? 0) ?></td>
                    <?php foreach ($rawRow['cells'] as $cell): ?>
                        <td><?= e(mb_strimwidth((string) $cell, 0, 40, '…')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted import-raw-hint"><?= e(__('treasury.import.map_preview_hint')) ?></p>
</details>

<p class="import-meta muted">
    <?= e(__('treasury.import.meta', [
        'file' => $sourceName,
        'rows' => (string) count($table['rows']),
        'delimiter' => (string) ($table['delimiter_label'] ?? $table['delimiter']),
        'encoding' => (string) $table['encoding'],
    ])) ?>
</p>

<form method="post" action="<?= e(url('/treasury/import/map')) ?>" class="import-map-form" data-import-map-form>
<?= csrf_field() ?>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.import.map_title')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.import.map_lede')) ?></p>
        </div>
    </header>
    <div class="table-wrap embedded import-map-wrap">
        <table class="import-map-table">
            <thead>
            <tr>
                <th><?= e(__('treasury.import.col_source')) ?></th>
                <th><?= e(__('treasury.import.col_sample')) ?></th>
                <th><?= e(__('treasury.import.col_target')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($columns as $column): ?>
                <?php
                $index = (int) $column['index'];
                $currentTarget = (string) ($map[$index] ?? $column['suggested']);
                $kindKey = $kindLabels[(string) $column['kind']] ?? '';
                ?>
                <tr>
                    <td>
                        <strong><?= e((string) $column['header']) ?></strong>
                        <div class="import-col-meta muted">
                            <?php if ($kindKey !== ''): ?>
                                <span class="badge badge-muted"><?= e(__($kindKey)) ?></span>
                            <?php endif; ?>
                            <span><?= e(__('treasury.import.col_filled', [
                                'filled' => (string) (int) $column['filled'],
                                'rows' => (string) (int) $column['rows'],
                            ])) ?></span>
                        </div>
                    </td>
                    <td class="import-col-sample muted"><?= e((string) $column['sample']) ?></td>
                    <td>
                        <?= field('select', [
                            'name' => 'map[' . $index . ']',
                            'id' => 'treasury-import-map-' . $index,
                            'label' => '',
                            'value' => $currentTarget,
                            'context' => 'setup',
                            'include_empty' => true,
                            'empty_label' => (string) __('treasury.import.target_ignore'),
                            'groups' => $targetGroups,
                            'attrs' => ['data-import-map-select' => true],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('treasury.import.options_title')) ?></h2>
            <p class="section-lede"><?= e(__('treasury.import.options_lede')) ?></p>
        </div>
    </header>
    <div class="import-options-grid">
        <?= field('select', [
            'name' => 'default_direction',
            'label' => (string) __('treasury.import.opt_default_direction'),
            'value' => (string) ($options['default_direction'] ?? 'expense'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => [
                ['value' => 'expense', 'label' => (string) __('treasury.direction_sign_expense')],
                ['value' => 'income', 'label' => (string) __('treasury.direction_sign_income')],
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'default_account',
            'label' => (string) __('treasury.import.opt_default_account'),
            'value' => (string) ($options['default_account'] ?? ''),
            'context' => 'setup',
            'include_empty' => true,
            'empty_label' => (string) __('treasury.import.opt_no_default'),
            'options' => $accountOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'default_category',
            'label' => (string) __('treasury.import.opt_default_category'),
            'value' => (string) ($options['default_category'] ?? 'other'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => $categoryOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'default_payment_method',
            'label' => (string) __('treasury.import.opt_default_method'),
            'value' => (string) ($options['default_payment_method'] ?? ''),
            'context' => 'setup',
            'include_empty' => true,
            'empty_label' => (string) __('treasury.import.opt_no_default'),
            'groups' => $methodGroups,
        ]) ?>
    </div>
    <div class="import-flags">
        <label class="import-flag">
            <input type="checkbox" name="approve" value="1" <?= (string) ($options['approve'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span><?= e(__('treasury.import.opt_approve')) ?></span>
        </label>
        <label class="import-flag">
            <input type="checkbox" name="skip_duplicates" value="1" <?= (string) ($options['skip_duplicates'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span><?= e(__('treasury.import.opt_skip_duplicates')) ?></span>
        </label>
    </div>
    <p class="alert alert-warn import-map-warning" data-import-map-warning data-template="<?= e(__('treasury.import.map_duplicate_warning')) ?>" hidden></p>
    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/treasury/import')) ?>"><?= e(__('common.cancel')) ?></a>
        <button class="btn" type="submit" data-import-map-submit><?= e(__('treasury.import.normalize_next')) ?></button>
    </div>
</section>
</form>
