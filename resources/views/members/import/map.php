<?php
/**
 * Import members — step 2: mapping CSV columns → program fields.
 *
 * @var array{headers:list<string>,rows:list<array{line:int,cells:list<string>}>,has_header:bool,delimiter:string,encoding:string} $table
 * @var list<array{index:int,header:string,suggested:string,sample:string,kind:string,filled:int,rows:int}> $columns
 * @var list<array{label:string,options:list<array{value:string,label:string}>}> $targetGroups
 * @var array<int,string> $map
 * @var list<array<string,mixed>> $types
 * @var list<array<string,mixed>> $periods
 * @var string $sourceName
 * @var bool $profileApplied
 */
use Socly\Support\MemberFieldTypes;

$map = is_array($map ?? null) ? $map : [];
$typeOptions = [];
foreach ($types as $type) {
    $typeOptions[] = [
        'value' => (string) (int) $type['id'],
        'label' => localized($type['name_json'] ?? ''),
    ];
}
$periodOptions = [];
foreach ($periods as $period) {
    $periodOptions[] = [
        'value' => (string) (int) $period['id'],
        'label' => (string) $period['label'] . ' · ' . format_date((string) $period['starts_on']) . ' → ' . format_date((string) $period['ends_on']),
    ];
}
$statusOptions = [];
foreach ($statuses as $status) {
    $statusOptions[] = ['value' => $status, 'label' => (string) __('members.status_' . $status)];
}
$kindLabels = [
    'date' => 'members.import.kind_date',
    'datetime' => 'members.import.kind_datetime',
    'email' => 'members.import.kind_email',
    'fiscal_code' => 'members.import.kind_fiscal_code',
    'phone' => 'members.import.kind_phone',
    'amount' => 'members.import.kind_amount',
    'number' => 'members.import.kind_number',
    'word' => 'members.import.kind_word',
    'text' => 'members.import.kind_text',
];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.import.step_map_title')) ?></h1>
        <p class="page-lede"><?= e(__('members.import.step_map_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/members/import')) ?>"><?= e(__('members.import.change_file')) ?></a>
    </div>
</div>

<?= view_partial('members/import/steps', ['current' => 2]) ?>

<div class="alert alert-success import-loaded">
    <strong><?= e(__('members.import.map_loaded', [
        'file' => $sourceName,
        'rows' => (string) count($table['rows']),
        'columns' => (string) count($table['headers']),
    ])) ?></strong>
    <span class="import-loaded-hint"><?= e(__('members.import.map_loaded_hint')) ?></span>
</div>

<?php if (!empty($profileApplied)): ?>
    <p class="alert alert-info import-profile-hint"><?= e(__('members.import.map_profile_applied')) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(url('/members/import/header')) ?>" class="import-header-form">
    <?= csrf_field() ?>
    <input type="hidden" name="has_header" value="0">
    <label class="import-flag">
        <input type="checkbox" name="has_header" value="1" <?= !empty($table['has_header']) ? 'checked' : '' ?>>
        <span><?= e(__('members.import.map_header_label')) ?></span>
    </label>
    <button class="btn btn-sm btn-ghost" type="submit"><?= e(__('members.import.map_header_apply')) ?></button>
    <span class="muted import-header-hint"><?= e(__('members.import.map_header_hint')) ?></span>
</form>

<details class="import-raw" open>
    <summary><?= e(__('members.import.map_preview_title')) ?></summary>
    <div class="table-wrap embedded">
        <table class="import-raw-table">
            <thead>
            <tr>
                <th><?= e(__('members.import.col_line')) ?></th>
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
    <p class="muted import-raw-hint"><?= e(__('members.import.map_preview_hint')) ?></p>
</details>

<p class="import-meta muted">
    <?= e(__('members.import.meta', [
        'file' => $sourceName,
        'rows' => (string) count($table['rows']),
        'delimiter' => $table['delimiter'],
        'encoding' => $table['encoding'],
    ])) ?>
</p>

<form method="post" action="<?= e(url('/members/import/map')) ?>" class="import-map-form" data-import-map-form>
<?= csrf_field() ?>

<section class="panel import-panel">
    <header class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.import.map_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.import.map_lede')) ?></p>
        </div>
    </header>
    <div class="table-wrap embedded import-map-wrap">
        <table class="import-map-table">
            <thead>
            <tr>
                <th><?= e(__('members.import.col_source')) ?></th>
                <th><?= e(__('members.import.col_sample')) ?></th>
                <th><?= e(__('members.import.col_target')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($columns as $column): ?>
                <?php
                $index = (int) $column['index'];
                $currentTarget = (string) ($map[$index] ?? $column['suggested']);
                $groups = $targetGroups;
                $groups[] = [
                    'label' => (string) __('members.import.group_new_field'),
                    'options' => [[
                        'value' => 'new:' . MemberFieldTypes::slugifyKey((string) $column['header']),
                        'label' => (string) __('members.import.target_new_field', ['field' => (string) $column['header']]),
                    ]],
                ];
                $kindKey = $kindLabels[(string) $column['kind']] ?? '';
                ?>
                <tr>
                    <td>
                        <strong><?= e((string) $column['header']) ?></strong>
                        <div class="import-col-meta muted">
                            <?php if ($kindKey !== ''): ?>
                                <span class="badge badge-muted"><?= e(__($kindKey)) ?></span>
                            <?php endif; ?>
                            <span><?= e(__('members.import.col_filled', [
                                'filled' => (string) (int) $column['filled'],
                                'rows' => (string) (int) $column['rows'],
                            ])) ?></span>
                        </div>
                    </td>
                    <td class="import-col-sample muted"><?= e((string) $column['sample']) ?></td>
                    <td>
                        <?= field('select', [
                            'name' => 'map[' . $index . ']',
                            'id' => 'import-map-' . $index,
                            'label' => '',
                            'value' => $currentTarget === '__ignore' ? '' : $currentTarget,
                            'context' => 'setup',
                            'include_empty' => true,
                            'empty_label' => (string) __('members.import.target_ignore'),
                            'groups' => $groups,
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
            <h2 class="section-title"><?= e(__('members.import.options_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.import.options_lede')) ?></p>
        </div>
    </header>
    <div class="import-options-grid">
        <?= field('select', [
            'name' => 'period_id',
            'label' => (string) __('members.import.opt_period'),
            'value' => (string) ($options['period_id'] ?? ''),
            'context' => 'setup',
            'include_empty' => true,
            'empty_label' => (string) __('members.import.opt_period_none'),
            'options' => $periodOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'default_type_id',
            'label' => (string) __('members.import.opt_default_type'),
            'value' => (string) ($options['default_type_id'] ?? ''),
            'context' => 'setup',
            'include_empty' => true,
            'empty_label' => (string) __('members.import.opt_type_none'),
            'options' => $typeOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'status',
            'label' => (string) __('members.import.opt_status'),
            'value' => (string) ($options['status'] ?? 'active'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => $statusOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'update_strategy',
            'label' => (string) __('members.import.opt_strategy'),
            'value' => (string) ($options['update_strategy'] ?? 'fill_empty'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => [
                ['value' => 'fill_empty', 'label' => (string) __('members.import.opt_strategy_fill')],
                ['value' => 'overwrite', 'label' => (string) __('members.import.opt_strategy_overwrite')],
                ['value' => 'keep', 'label' => (string) __('members.import.opt_strategy_keep')],
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'duplicate_policy',
            'label' => (string) __('members.import.opt_duplicates'),
            'value' => (string) ($options['duplicate_policy'] ?? 'first'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => [
                ['value' => 'first', 'label' => (string) __('members.import.opt_duplicates_first')],
                ['value' => 'last', 'label' => (string) __('members.import.opt_duplicates_last')],
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'default_payment_method',
            'label' => (string) __('members.import.opt_default_method'),
            'value' => (string) ($options['default_payment_method'] ?? 'cash'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => [
                ['value' => 'cash', 'label' => (string) __('treasury.method_cash')],
                ['value' => 'bank', 'label' => (string) __('treasury.method_bank')],
                ['value' => 'other', 'label' => (string) __('treasury.method_other')],
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'treasury_date_source',
            'label' => (string) __('members.import.opt_treasury_date'),
            'value' => (string) ($options['treasury_date_source'] ?? 'payment'),
            'context' => 'setup',
            'include_empty' => false,
            'options' => [
                ['value' => 'payment', 'label' => (string) __('members.import.opt_treasury_date_payment')],
                ['value' => 'registration', 'label' => (string) __('members.import.opt_treasury_date_registration')],
            ],
        ]) ?>
        <?= field('select', [
            'name' => 'accept_consents',
            'label' => (string) __('members.import.opt_consents_label'),
            'value' => (string) ($options['accept_consents'] ?? '1') === '1' ? '1' : '0',
            'context' => 'setup',
            'include_empty' => false,
            'options' => [
                ['value' => '1', 'label' => (string) __('members.import.opt_consents_yes')],
                ['value' => '0', 'label' => (string) __('members.import.opt_consents_no')],
            ],
        ]) ?>
    </div>
    <div class="import-flags">
        <?php
        $flagFields = [
            'update_existing' => 'members.import.opt_update_existing',
            'import_payments' => 'members.import.opt_import_payments',
            'register_treasury' => 'members.import.opt_register_treasury',
            'create_cards' => 'members.import.opt_cards',
        ];
        ?>
        <?php foreach ($flagFields as $flagName => $flagLabel): ?>
            <label class="import-flag">
                <input type="checkbox" name="<?= e($flagName) ?>" value="1" <?= (string) ($options[$flagName] ?? '0') === '1' ? 'checked' : '' ?>>
                <span><?= e(__($flagLabel)) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="alert alert-warn import-map-warning" data-import-map-warning data-template="<?= e(__('members.import.map_duplicate_warning')) ?>" hidden></p>
    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/members/import')) ?>"><?= e(__('common.cancel')) ?></a>
        <button class="btn" type="submit" data-import-map-submit><?= e(__('members.import.preview_submit')) ?></button>
    </div>
</section>
</form>
