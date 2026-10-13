<?php
/**
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{key:string,label:string,options:list<array{key:string,label:string}>}>|null $category_groups
 * @var list<array> $members
 * @var bool $show_status
 */
$selectedCategory = (string) ($values['category'] ?? 'general');
$showNewCategory = $selectedCategory === '__new__';
$showStatus = !empty($show_status);
$categoryGroups = $category_groups ?? null;

$deadlineCategoryGroups = [];
if (is_array($categoryGroups) && $categoryGroups !== []) {
    foreach ($categoryGroups as $group) {
        $opts = [];
        foreach (($group['options'] ?? []) as $cat) {
            $opts[] = [
                'value' => (string) $cat['key'],
                'label' => (string) $cat['label'],
            ];
        }
        $deadlineCategoryGroups[] = [
            'label' => (string) ($group['label'] ?? ''),
            'options' => $opts,
        ];
    }
} else {
    $deadlineCategoryFlat = [];
    foreach ($categories as $cat) {
        $deadlineCategoryFlat[] = [
            'value' => (string) $cat['key'],
            'label' => (string) $cat['label'],
        ];
    }
}
$deadlineCategoryNewOption = ['value' => '__new__', 'label' => (string) __('deadlines.category_new')];

$deadlineMemberOptions = [];
foreach ($members as $member) {
    $label = trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''));
    if (!empty($member['member_number'])) {
        $label .= ' (' . (string) $member['member_number'] . ')';
    }
    $deadlineMemberOptions[] = [
        'value' => (string) (int) $member['id'],
        'label' => $label,
    ];
}

$memberLinked = trim((string) ($values['member_id'] ?? '')) !== '';
?>
<div class="grid-3">
    <?= field('text', [
        'name' => 'title',
        'label' => (string) __('deadlines.title_field'),
        'value' => (string) ($values['title'] ?? ''),
        'required' => true,
        'maxlength' => 190,
    ]) ?>
    <?= field('date', [
        'name' => 'due_date',
        'label' => (string) __('deadlines.due_date'),
        'value' => (string) ($values['due_date'] ?? ''),
        'required' => true,
    ]) ?>
    <div data-deadline-category-wrap>
        <?= field('select', [
            'name' => 'category',
            'label' => (string) __('deadlines.category'),
            'value' => $selectedCategory,
            'include_empty' => false,
            'groups' => $deadlineCategoryGroups !== [] ? $deadlineCategoryGroups : null,
            'options' => $deadlineCategoryGroups === [] ? array_merge($deadlineCategoryFlat, [$deadlineCategoryNewOption]) : [],
            'trailing_options' => $deadlineCategoryGroups !== [] ? [$deadlineCategoryNewOption] : [],
            'attrs' => ['data-deadline-category' => true],
        ]) ?>
        <div class="doc-new-category" data-deadline-new-category <?= $showNewCategory ? '' : 'hidden' ?>>
            <?= field('text', [
                'name' => 'new_category',
                'label' => (string) __('deadlines.category_new_label'),
                'value' => (string) ($values['new_category'] ?? ''),
                'maxlength' => 80,
                'placeholder' => (string) __('deadlines.category_new_placeholder'),
                'required' => $showNewCategory,
                'attrs' => ['data-deadline-new-category-input' => true],
            ]) ?>
        </div>
    </div>
</div>
<div class="grid-<?= $showStatus ? '3' : '2' ?>">
    <div data-deadline-member-wrap>
        <?= field('checkbox', [
            'name' => '',
            'label' => (string) __('deadlines.member_involved'),
            'checked' => $memberLinked,
            'label_class' => '',
            'attrs' => ['data-deadline-member-toggle' => true],
            'wrap_attrs' => ['style' => 'margin-bottom:0.5rem'],
        ]) ?>
        <div data-deadline-member-fields <?= $memberLinked ? '' : 'hidden' ?>>
            <?= field('select', [
                'name' => 'member_id',
                'label' => (string) __('deadlines.member'),
                'value' => (string) ($values['member_id'] ?? ''),
                'options' => $deadlineMemberOptions,
                'attrs' => ['data-deadline-member-select' => true],
            ]) ?>
        </div>
    </div>
    <?php if ($showStatus): ?>
        <?= field('select', [
            'name' => 'status',
            'label' => (string) __('deadlines.status'),
            'value' => (string) ($values['status'] ?? 'open'),
            'include_empty' => false,
            'options' => array_map(
                static fn (string $st): array => [
                    'value' => $st,
                    'label' => (string) __('deadlines.status_' . $st),
                ],
                ['open', 'done', 'dismissed']
            ),
        ]) ?>
    <?php else: ?>
        <?= field('hidden', ['name' => 'status', 'value' => 'open']) ?>
    <?php endif; ?>
    <?= field('select', [
        'name' => 'recurrence',
        'label' => (string) __('deadlines.recurrence'),
        'value' => (string) ($values['recurrence'] ?? 'none'),
        'include_empty' => false,
        'options' => array_map(
            static fn (string $rec): array => [
                'value' => $rec,
                'label' => (string) __('deadlines.recurrence_' . $rec),
            ],
            ['none', 'monthly', 'yearly']
        ),
    ]) ?>
</div>
<div class="grid-2">
    <?= field('text', [
        'name' => 'assignee_role',
        'label' => (string) __('deadlines.assignee_role'),
        'value' => (string) ($values['assignee_role'] ?? ''),
        'maxlength' => 40,
        'placeholder' => (string) __('deadlines.assignee_role_placeholder'),
    ]) ?>
    <?= field('text', [
        'name' => 'notify_days',
        'label' => (string) __('deadlines.notify_days'),
        'value' => (string) ($values['notify_days'] ?? '30,7'),
        'maxlength' => 40,
        'placeholder' => '30,7',
        'hint' => (string) __('deadlines.notify_days_hint'),
        'hint_attrs' => ['style' => 'margin:0.35rem 0 0;font-size:0.85rem'],
    ]) ?>
</div>
<?= field('text', [
    'name' => 'notes',
    'label' => (string) __('deadlines.notes'),
    'value' => (string) ($values['notes'] ?? ''),
]) ?>
