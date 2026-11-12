<?php
/**
 * Import members — step 4: creation/mapping of missing reference data.
 *
 * @var array<string,mixed> $analysis
 * @var list<array<string,mixed>> $types
 * @var list<array<string,mixed>> $periods
 * @var list<array{key:string,label:string}> $formSteps
 * @var list<array{value:string,label:string}> $fieldTypes
 */
$gaps = is_array($analysis['gaps'] ?? null) ? $analysis['gaps'] : ['types' => [], 'fields' => [], 'methods' => [], 'statuses' => []];
$stats = is_array($analysis['stats'] ?? null) ? $analysis['stats'] : [];
$options = is_array($analysis['options'] ?? null) ? $analysis['options'] : [];
$hasGaps = $gaps['types'] !== [] || $gaps['fields'] !== [] || $gaps['methods'] !== [] || $gaps['statuses'] !== [];
$periodMissing = (int) ($options['period_id'] ?? 0) <= 0 && $periods === [];
$typeOptions = [['value' => 'create', 'label' => (string) __('members.import.ref_type_create')]];
foreach ($types as $type) {
    $typeOptions[] = [
        'value' => 'map:' . (int) $type['id'],
        'label' => (string) __('members.import.ref_type_use', ['name' => localized($type['name_json'] ?? '')]),
    ];
}
$typeOptions[] = ['value' => 'skip', 'label' => (string) __('members.import.ref_type_skip')];
$statusOptions = [];
foreach (\Socly\Services\MemberService::memberStatuses() as $status) {
    $statusOptions[] = ['value' => $status, 'label' => (string) __('members.status_' . $status)];
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.import.step_references_title')) ?></h1>
        <p class="page-lede"><?= e(__('members.import.step_references_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/members/import/preview')) ?>"><?= e(__('members.import.back_preview')) ?></a>
    </div>
</div>

<?= view_partial('members/import/steps', ['current' => 4]) ?>

<div class="stats import-stats">
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_create')) ?></div>
        <div class="value"><?= (int) ($stats['create'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_update')) ?></div>
        <div class="value"><?= (int) ($stats['update'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_skip')) ?></div>
        <div class="value"><?= (int) ($stats['skip'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.import.stat_error')) ?></div>
        <div class="value"><?= (int) ($stats['error'] ?? 0) ?></div>
    </div>
</div>

<form
    method="post"
    action="<?= e(url('/members/import/commit')) ?>"
    class="import-references-form"
    data-import-commit
    data-import-confirm="<?= e(__('members.import.confirm_commit')) ?>"
    data-import-total="<?= (int) (($stats['create'] ?? 0) + ($stats['update'] ?? 0)) ?>"
>
    <?= csrf_field() ?>

    <?php if (!$hasGaps && !$periodMissing): ?>
        <div class="alert alert-success"><?= e(__('members.import.ref_none')) ?></div>
    <?php endif; ?>

    <?php if ($gaps['types'] !== []): ?>
        <section class="panel import-panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.import.ref_types_title')) ?></h2>
                    <p class="section-lede"><?= e(__('members.import.ref_types_lede')) ?></p>
                </div>
            </header>
            <p class="alert alert-info import-ref-note"><?= e(__('members.import.ref_cards_note')) ?></p>
            <?php foreach ($gaps['types'] as $slug => $gap): ?>
                <div class="import-ref-card">
                    <p class="import-ref-title">
                        <strong><?= e((string) $gap['label']) ?></strong>
                        <span class="muted"><?= e(__('members.import.ref_rows', ['count' => (string) (int) $gap['count']])) ?></span>
                    </p>
                    <div class="import-ref-grid">
                        <?= field('select', [
                            'name' => 'types[' . $slug . '][action]',
                            'label' => (string) __('members.import.ref_action'),
                            'value' => 'create',
                            'context' => 'setup',
                            'include_empty' => false,
                            'options' => $typeOptions,
                        ]) ?>
                        <?= field('text', [
                            'name' => 'types[' . $slug . '][name_it]',
                            'label' => (string) __('members.import.ref_name_it'),
                            'value' => (string) $gap['suggested_it'],
                            'context' => 'setup',
                            'maxlength' => 120,
                            'attrs' => ['data-import-name-source' => true],
                        ]) ?>
                        <?= field('text', [
                            'name' => 'types[' . $slug . '][price]',
                            'label' => (string) __('members.import.ref_price'),
                            'value' => '',
                            'context' => 'setup',
                            'placeholder' => '0,00',
                        ]) ?>
                    </div>
                    <details class="import-ref-translations" data-import-translations>
                        <summary><?= e(__('members.import.ref_translations_toggle')) ?></summary>
                        <div class="import-ref-grid">
                            <?= field('text', [
                                'name' => 'types[' . $slug . '][name_de]',
                                'label' => (string) __('members.import.ref_name_de'),
                                'value' => (string) $gap['suggested_de'],
                                'context' => 'setup',
                                'maxlength' => 120,
                                'attrs' => ['data-import-translation' => true],
                            ]) ?>
                            <?= field('text', [
                                'name' => 'types[' . $slug . '][name_en]',
                                'label' => (string) __('members.import.ref_name_en'),
                                'value' => (string) $gap['suggested_en'],
                                'context' => 'setup',
                                'maxlength' => 120,
                                'attrs' => ['data-import-translation' => true],
                            ]) ?>
                        </div>
                    </details>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($gaps['fields'] !== []): ?>
        <section class="panel import-panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.import.ref_fields_title')) ?></h2>
                    <p class="section-lede"><?= e(__('members.import.ref_fields_lede')) ?></p>
                </div>
            </header>
            <?php foreach ($gaps['fields'] as $slug => $gap): ?>
                <div class="import-ref-card">
                    <p class="import-ref-title">
                        <strong><?= e((string) $gap['header']) ?></strong>
                        <span class="muted"><?= e(__('members.import.ref_rows', ['count' => (string) (int) $gap['count']])) ?></span>
                    </p>
                    <div class="import-ref-grid">
                        <?= field('select', [
                            'name' => 'fields[' . $slug . '][action]',
                            'label' => (string) __('members.import.ref_action'),
                            'value' => 'create',
                            'context' => 'setup',
                            'include_empty' => false,
                            'options' => [
                                ['value' => 'create', 'label' => (string) __('members.import.ref_field_create')],
                                ['value' => 'skip', 'label' => (string) __('members.import.ref_field_skip')],
                            ],
                        ]) ?>
                        <?= field('text', [
                            'name' => 'fields[' . $slug . '][label_it]',
                            'label' => (string) __('members.import.ref_name_it'),
                            'value' => (string) $gap['label'],
                            'context' => 'setup',
                            'maxlength' => 120,
                        ]) ?>
                        <?= field('select', [
                            'name' => 'fields[' . $slug . '][field_type]',
                            'label' => (string) __('members.import.ref_field_type'),
                            'value' => 'text',
                            'context' => 'setup',
                            'include_empty' => false,
                            'options' => $fieldTypes,
                        ]) ?>
                        <?= field('select', [
                            'name' => 'fields[' . $slug . '][form_step]',
                            'label' => (string) __('members.import.ref_field_step'),
                            'value' => (string) ($formSteps[0]['key'] ?? 'profile'),
                            'context' => 'setup',
                            'include_empty' => false,
                            'options' => array_map(static fn (array $step): array => [
                                'value' => (string) $step['key'],
                                'label' => (string) $step['label'],
                            ], $formSteps),
                        ]) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($periodMissing): ?>
        <section class="panel import-panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.import.ref_period_title')) ?></h2>
                    <p class="section-lede"><?= e(__('members.import.ref_period_lede')) ?></p>
                </div>
            </header>
            <div class="import-ref-grid">
                <?= field('date', [
                    'name' => 'period[starts_on]',
                    'label' => (string) __('members.import.ref_period_start'),
                    'value' => date('Y') . '-01-01',
                    'context' => 'setup',
                ]) ?>
                <?= field('date', [
                    'name' => 'period[ends_on]',
                    'label' => (string) __('members.import.ref_period_end'),
                    'value' => date('Y') . '-12-31',
                    'context' => 'setup',
                ]) ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($gaps['methods'] !== []): ?>
        <section class="panel import-panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.import.ref_methods_title')) ?></h2>
                    <p class="section-lede"><?= e(__('members.import.ref_methods_lede')) ?></p>
                </div>
            </header>
            <?php foreach ($gaps['methods'] as $slug => $gap): ?>
                <div class="import-ref-grid import-ref-row">
                    <p class="import-ref-title">
                        <strong><?= e((string) $gap['label']) ?></strong>
                        <span class="muted"><?= e(__('members.import.ref_rows', ['count' => (string) (int) $gap['count']])) ?></span>
                    </p>
                    <p class="import-ref-options">
                        <?php foreach (['cash', 'bank', 'other', 'skip'] as $index => $choice): ?>
                            <label class="import-flag">
                                <input type="radio" name="methods[<?= e((string) $slug) ?>][action]" value="<?= e($choice) ?>" <?= $index === 0 ? 'checked' : '' ?>>
                                <span><?= e($choice === 'skip' ? __('members.import.ref_method_skip') : __('treasury.method_' . $choice)) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($gaps['statuses'] !== []): ?>
        <section class="panel import-panel">
            <header class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.import.ref_statuses_title')) ?></h2>
                    <p class="section-lede"><?= e(__('members.import.ref_statuses_lede')) ?></p>
                </div>
            </header>
            <?php foreach ($gaps['statuses'] as $slug => $gap): ?>
                <div class="import-ref-card">
                    <p class="import-ref-title">
                        <strong><?= e((string) $gap['label']) ?></strong>
                        <span class="muted"><?= e(__('members.import.ref_rows', ['count' => (string) (int) $gap['count']])) ?></span>
                    </p>
                    <?= field('select', [
                        'name' => 'statuses[' . $slug . '][status]',
                        'label' => (string) __('members.import.ref_status_target'),
                        'value' => 'active',
                        'context' => 'setup',
                        'include_empty' => false,
                        'options' => $statusOptions,
                    ]) ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="alert alert-info">
        <?= e((string) ($options['accept_consents'] === '1'
            ? __('members.import.ref_consents_on')
            : __('members.import.ref_consents_off'))) ?>
    </div>

    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/members/import/preview')) ?>"><?= e(__('members.import.back_preview')) ?></a>
        <button class="btn" type="submit" data-import-commit-submit data-running-label="<?= e(__('members.import.commit_running')) ?>">
            <?= e(__('members.import.commit_submit')) ?>
        </button>
    </div>
</form>

<div class="import-progress" data-import-progress hidden>
    <div class="import-progress-card" role="status" aria-live="polite">
        <span class="import-progress-spinner" aria-hidden="true"></span>
        <strong class="import-progress-title"><?= e(__('members.import.in_progress_title')) ?></strong>
        <p class="import-progress-text">
            <?= e(__('members.import.in_progress_text', [
                'count' => (string) (int) (($stats['create'] ?? 0) + ($stats['update'] ?? 0)),
            ])) ?>
        </p>
        <p class="import-progress-hint"><?= e(__('members.import.in_progress_hint')) ?></p>
    </div>
</div>
