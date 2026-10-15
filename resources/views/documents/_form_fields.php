<?php
/**
 * Shared document create/edit fields.
 *
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<string> $languages
 * @var int|string $upload_max_mb
 * @var bool $has_existing_file
 */
$today = date('Y-m-d');
$selectedCategory = (string) ($values['category'] ?? 'minutes');
$selectedLanguage = (string) ($values['language'] ?? '');
$showNewCategory = $selectedCategory === '__new__';
$hasExistingFile = !empty($has_existing_file);
$fileName = (string) ($values['uploaded_name'] ?? '');
$fileReady = $hasExistingFile || trim((string) ($values['uploaded_path'] ?? '')) !== '';

// Progressivo automatico per i verbali (libri sociali): "Verbale n. 01/2026".
$suggestNumbers = (!empty($suggest_number) && is_array($minutes_progressives ?? null))
    ? $minutes_progressives
    : [];
$numberPattern = (string) __('documents.minutes_number_pattern');
if (
    $suggestNumbers !== []
    && trim((string) ($values['document_number'] ?? '')) === ''
    && isset($suggestNumbers[$selectedCategory])
) {
    $values['document_number'] = sprintf($numberPattern, (int) $suggestNumbers[$selectedCategory], date('Y'));
}
$numberMapJson = json_encode($suggestNumbers, JSON_UNESCAPED_UNICODE) ?: '{}';

$documentLanguageOptions = array_map(
    static fn (string $lang): array => [
        'value' => $lang,
        'label' => (string) __('documents.language_' . $lang),
    ],
    $languages
);

$documentCategoryGroups = [];
if (!empty($category_groups) && is_array($category_groups)) {
    foreach ($category_groups as $group) {
        $opts = [];
        foreach (($group['options'] ?? []) as $cat) {
            $opts[] = ['value' => (string) $cat['key'], 'label' => (string) $cat['label']];
        }
        $documentCategoryGroups[] = [
            'label' => (string) ($group['label'] ?? ''),
            'options' => $opts,
        ];
    }
} else {
    $documentCategoryFlat = [];
    foreach ($categories as $cat) {
        $documentCategoryFlat[] = ['value' => (string) $cat['key'], 'label' => (string) $cat['label']];
    }
}
$documentCategoryNewOption = ['value' => '__new__', 'label' => (string) __('documents.category_new')];

$documentMemberOptions = [];
foreach (($members ?? []) as $member) {
    $documentMemberOptions[] = [
        'value' => (string) (int) $member['id'],
        'label' => trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? '')),
    ];
}

$documentSiblingOptions = [];
foreach (($sibling_options ?? []) as $sib) {
    $label = (string) ($sib['title'] ?? '');
    if (!empty($sib['language'])) {
        $label .= ' (' . (string) $sib['language'] . ')';
    }
    $documentSiblingOptions[] = [
        'value' => (string) (int) $sib['id'],
        'label' => $label,
    ];
}
?>
<div class="grid-4">
    <?= field('text', [
        'name' => 'title',
        'label' => (string) __('documents.title_field'),
        'value' => (string) ($values['title'] ?? ''),
        'required' => true,
        'maxlength' => 190,
        'wrapper_class' => 'grid-span-2',
    ]) ?>
    <?= field('text', [
        'name' => 'document_number',
        'label' => (string) __('documents.number'),
        'value' => (string) ($values['document_number'] ?? ''),
        'maxlength' => 80,
        'hint' => $suggestNumbers !== [] ? (string) __('documents.minutes_number_hint') : '',
        'hint_attrs' => ['style' => 'margin:0.35rem 0 0;font-size:0.8rem'],
        'attrs' => ['data-doc-number' => true],
    ]) ?>
    <?= field('date', [
        'name' => 'document_date',
        'label' => (string) __('documents.date'),
        'value' => (string) ($values['document_date'] ?? $today),
    ]) ?>
</div>
<div class="grid-3">
    <div data-doc-category-wrap
         data-doc-number-pattern="<?= e($numberPattern) ?>"
         data-doc-number-map="<?= e($numberMapJson) ?>">
        <?= field('select', [
            'name' => 'category',
            'label' => (string) __('documents.category'),
            'value' => $selectedCategory,
            'include_empty' => false,
            'groups' => $documentCategoryGroups !== [] ? $documentCategoryGroups : null,
            'options' => $documentCategoryGroups === [] ? array_merge($documentCategoryFlat, [$documentCategoryNewOption]) : [],
            'trailing_options' => $documentCategoryGroups !== [] ? [$documentCategoryNewOption] : [],
            'attrs' => ['data-doc-category' => true],
        ]) ?>
        <div class="doc-new-category" data-doc-new-category <?= $showNewCategory ? '' : 'hidden' ?>>
            <?= field('text', [
                'name' => 'new_category',
                'label' => (string) __('documents.category_new_label'),
                'value' => (string) ($values['new_category'] ?? ''),
                'maxlength' => 80,
                'placeholder' => (string) __('documents.category_new_placeholder'),
                'required' => $showNewCategory,
                'attrs' => ['data-doc-new-category-input' => true],
            ]) ?>
        </div>
    </div>
    <?= field('select', [
        'name' => 'status',
        'label' => (string) __('documents.status'),
        'value' => (string) ($values['status'] ?? 'draft'),
        'include_empty' => false,
        'options' => array_map(
            static fn (string $st): array => [
                'value' => $st,
                'label' => (string) __('documents.status_' . $st),
            ],
            \Socly\Services\DocumentService::STATUSES
        ),
    ]) ?>
    <?= field('select', [
        'name' => 'visibility',
        'label' => (string) __('documents.visibility'),
        'value' => (string) ($values['visibility'] ?? 'internal'),
        'include_empty' => false,
        'options' => array_map(
            static fn (string $vis): array => [
                'value' => $vis,
                'label' => (string) __('documents.visibility_' . $vis),
            ],
            \Socly\Services\DocumentService::VISIBILITIES
        ),
    ]) ?>
</div>
<div class="grid-3">
    <?= field('select', [
        'name' => 'language',
        'label' => (string) __('documents.language'),
        'value' => $selectedLanguage,
        'empty_label' => (string) __('documents.language_none'),
        'options' => $documentLanguageOptions,
    ]) ?>
    <?= field('select', [
        'name' => 'sibling_document_id',
        'label' => (string) __('documents.sibling'),
        'value' => (string) ($values['sibling_document_id'] ?? ''),
        'options' => $documentSiblingOptions,
    ]) ?>
    <?= field('select', [
        'name' => 'member_id',
        'label' => (string) __('documents.member_link'),
        'value' => (string) ($values['member_id'] ?? ''),
        'options' => $documentMemberOptions,
    ]) ?>
</div>
<div class="grid-2">
    <?= field('date', [
        'name' => 'expires_at',
        'label' => (string) __('documents.expires_at'),
        'value' => (string) ($values['expires_at'] ?? ''),
        'hint' => (string) __('documents.expires_at_hint'),
        'hint_attrs' => ['style' => 'margin:0.35rem 0 0;font-size:0.85rem'],
    ]) ?>
    <div class="doc-file-field<?= $fileReady ? ' is-uploaded' : '' ?>" data-doc-file-field>
        <span class="field-label sf-field__label"><?= e(__('documents.file')) ?></span>
        <div class="doc-dropzone" data-doc-dropzone>
            <span class="field-icon doc-dropzone-icon" data-icon="upload" aria-hidden="true"></span>
            <div class="doc-dropzone-text">
                <span class="doc-dropzone-title"><?= e(__('documents.upload_drop')) ?></span>
                <span class="doc-dropzone-meta"><?= e(__('documents.upload_max', ['max' => (string) ($upload_max_mb ?? 2)])) ?></span>
            </div>
            <?= field('file', [
                'label' => '',
                'name' => 'document_file',
                'button_label' => $fileReady ? (string) __('documents.change_file') : (string) __('documents.upload_pick'),
                'accept' => '.pdf,image/jpeg,image/png,image/webp,application/pdf',
                'as_button' => true,
                'button_class' => 'doc-dropzone-pick file-btn sf-field__file-btn',
                'attrs' => ['data-doc-file-input' => true],
                'pick_label_attrs' => ['data-doc-pick-label' => true],
            ]) ?>
        </div>
        <div class="doc-file-meta" data-doc-file-meta>
            <strong class="doc-file-name" data-doc-file-name <?= $fileName === '' ? 'hidden' : '' ?>><?= e($fileName) ?></strong>
            <span class="doc-file-status muted" data-doc-file-status>
                <?= e($fileReady ? __('documents.upload_ok') : __('documents.upload_idle')) ?>
            </span>
            <div class="doc-file-progress" data-doc-file-progress hidden>
                <div class="doc-file-progress-track" aria-hidden="true">
                    <div class="doc-file-progress-bar" data-doc-file-progress-bar style="width:0%"></div>
                </div>
                <span class="doc-file-progress-pct" data-doc-file-progress-pct>0%</span>
            </div>
            <span class="doc-file-hint muted"><?= e(__('documents.upload_hint', ['max' => (string) ($upload_max_mb ?? 2)])) ?></span>
        </div>
    </div>
</div>
<?= field('textarea', [
    'name' => 'summary',
    'label' => (string) __('documents.summary'),
    'value' => (string) ($values['summary'] ?? ''),
    'rows' => 3,
]) ?>
<?php if (!empty($document_id) && (int) $document_id > 0 && component_enabled('treasury') && can('treasury.manage')): ?>
    <p class="muted" style="margin-top:0.75rem">
        <a href="<?= e(url('/treasury?document_id=' . (int) $document_id)) ?>"><?= e(__('documents.link_treasury')) ?></a>
    </p>
<?php endif; ?>
