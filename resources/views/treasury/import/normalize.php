<?php
/**
 * Import first note — step 3: normalise the values found in the CSV
 * (for example “Credito” → “Credit card”).
 *
 * @var list<array{field:string,label:string,values:list<array{index:int,raw:string,count:int,suggested:string,options:list<array{value:string,label:string}>}>}> $fields
 * @var array<string,array<string,string>> $valueMap
 * @var list<array{value:string,label:string}> $memberOptions
 * @var string $sourceName
 */
$fields = is_array($fields ?? null) ? $fields : [];
$valueMap = is_array($valueMap ?? null) ? $valueMap : [];
$memberOptions = is_array($memberOptions ?? null) ? $memberOptions : [];
$memberLabels = [];
foreach ($memberOptions as $option) {
    $memberLabels[(string) ($option['value'] ?? '')] = (string) ($option['label'] ?? '');
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('treasury.import.step_normalize_title')) ?></h1>
        <p class="page-lede"><?= e(__('treasury.import.step_normalize_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/treasury/import/map')) ?>"><?= e(__('treasury.import.back_map')) ?></a>
    </div>
</div>

<?= view_partial('treasury/import/steps', ['current' => 3]) ?>

<?php if ($fields === []): ?>
    <section class="panel import-panel">
        <p class="import-meta muted"><?= e(__('treasury.import.normalize_no_values')) ?></p>
        <div class="actions form-actions form-actions-end">
            <a class="btn btn-ghost" href="<?= e(url('/treasury/import/map')) ?>"><?= e(__('treasury.import.back_map')) ?></a>
            <a class="btn" href="<?= e(url('/treasury/import/preview')) ?>"><?= e(__('treasury.import.normalize_continue')) ?></a>
        </div>
    </section>
<?php else: ?>
    <p class="import-meta muted"><?= e(__('treasury.import.normalize_intro')) ?></p>

    <form method="post" action="<?= e(url('/treasury/import/normalize')) ?>">
        <?= csrf_field() ?>
        <?php foreach ($fields as $field): ?>
            <section class="panel import-panel">
                <header class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e((string) $field['label']) ?></h2>
                        <p class="section-lede"><?= e(__('treasury.import.normalize_field_lede')) ?></p>
                    </div>
                </header>
                <div class="table-wrap embedded">
                    <table class="import-map-table">
                        <thead>
                        <tr>
                            <th><?= e(__('treasury.import.normalize_csv_value')) ?></th>
                            <th><?= e(__('treasury.import.normalize_rows')) ?></th>
                            <th><?= e(__('treasury.import.normalize_import_as')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($field['values'] as $value): ?>
                            <?php
                            $fieldKey = (string) $field['field'];
                            $raw = (string) $value['raw'];
                            $current = (string) ($valueMap[$fieldKey][$raw] ?? $value['suggested']);
                            ?>
                            <tr>
                                <td><strong><?= e($raw) ?></strong></td>
                                <td><span class="badge badge-muted"><?= (int) $value['count'] ?></span></td>
                                <td>
                                    <input type="hidden" name="value_raw[<?= e($fieldKey) ?>][<?= (int) $value['index'] ?>]" value="<?= e($raw) ?>">
                                    <?php if ($fieldKey === 'member'): ?>
                                        <div class="sf-field">
                                            <input class="sf-field__control" type="text"
                                                   name="value_map[member][<?= (int) $value['index'] ?>]"
                                                   id="treasury-normalize-member-<?= (int) $value['index'] ?>"
                                                   list="treasury-import-members"
                                                   value="<?= e($memberLabels[$current] ?? '') ?>"
                                                   placeholder="<?= e(__('treasury.import.normalize_member_placeholder')) ?>">
                                        </div>
                                        <p class="sf-field__hint muted"><?= e(__('treasury.import.normalize_member_hint')) ?></p>
                                    <?php else: ?>
                                        <?= field('select', [
                                            'name' => 'value_map[' . $fieldKey . '][' . (int) $value['index'] . ']',
                                            'id' => 'treasury-normalize-' . $fieldKey . '-' . (int) $value['index'],
                                            'label' => '',
                                            'value' => $current,
                                            'context' => 'setup',
                                            'include_empty' => false,
                                            'options' => $value['options'],
                                        ]) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <datalist id="treasury-import-members">
            <?php foreach ($memberOptions as $option): ?>
                <option value="<?= e((string) ($option['label'] ?? '')) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <div class="actions form-actions form-actions-end">
            <a class="btn btn-ghost" href="<?= e(url('/treasury/import/map')) ?>"><?= e(__('treasury.import.back_map')) ?></a>
            <button class="btn" type="submit"><?= e(__('treasury.import.normalize_submit')) ?></button>
        </div>
    </form>
<?php endif; ?>
