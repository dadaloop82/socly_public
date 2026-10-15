<?php
/**
 * Campi del form scadenza (usati da deadlines/form.php).
 *
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

// Preavvisi: pillole invece di numeri separati da virgola. I valori non standard
// già salvati restano visibili (e modificabili), così non si perde nulla.
$notifySelected = [];
foreach (preg_split('/[^\d]+/', (string) ($values['notify_days'] ?? '')) ?: [] as $chunk) {
    $chunk = trim((string) $chunk);
    if ($chunk !== '') {
        $notifySelected[] = (int) $chunk;
    }
}
$notifySelected = array_values(array_unique($notifySelected));
$notifyPresets = [30, 7, 0];
foreach ($notifySelected as $day) {
    if (!in_array($day, $notifyPresets, true)) {
        $notifyPresets[] = $day;
    }
}
$notifyLabel = static function (int $day): string {
    return $day === 0
        ? (string) __('deadlines.notify_same_day')
        : (string) __('deadlines.notify_days_generic', ['days' => (string) $day]);
};
$notifyUnit = static function (int $day): string {
    return $day === 0
        ? (string) __('deadlines.notify_unit_same_day')
        : (string) __('deadlines.notify_unit_days');
};

// Prossima ricorrenza annuale di una data fissa (es. 30/04 o 30/06).
$nextAnnualDate = static function (string $monthDay): string {
    [$day, $month] = array_pad(explode('/', $monthDay), 2, '1');
    $year = (int) date('Y');
    $candidate = sprintf('%04d-%02d-%02d', $year, (int) $month, (int) $day);
    return $candidate >= date('Y-m-d')
        ? $candidate
        : sprintf('%04d-%02d-%02d', $year + 1, (int) $month, (int) $day);
};
?>
<?php if (!empty($show_templates)): ?>
    <?php
    // Scadenze tipiche del Terzo Settore: un clic precompila il form.
    $deadlineTemplates = [
        [
            'label' => (string) __('deadlines.template_balance_approval'),
            'data' => [
                'title' => (string) __('deadlines.template_balance_approval'),
                'category' => 'assembly_budget',
                'due_date' => $nextAnnualDate('30/04'),
                'recurrence' => 'yearly',
                'assignee_role' => (string) __('deadlines.role_president'),
                'notify' => [30, 7],
                'notes' => (string) __('deadlines.template_balance_approval_note'),
            ],
        ],
        [
            'label' => (string) __('deadlines.template_runts_deposit'),
            'data' => [
                'title' => (string) __('deadlines.template_runts_deposit'),
                'category' => 'runts_deposit',
                'due_date' => $nextAnnualDate('30/06'),
                'recurrence' => 'yearly',
                'assignee_role' => (string) __('deadlines.role_secretary'),
                'notify' => [30, 7],
                'notes' => (string) __('deadlines.template_runts_deposit_note'),
            ],
        ],
        [
            'label' => (string) __('deadlines.template_volunteer_insurance'),
            'data' => [
                'title' => (string) __('deadlines.template_volunteer_insurance'),
                'category' => 'accident_insurance',
                'recurrence' => 'yearly',
                'assignee_role' => (string) __('deadlines.role_treasurer'),
                'notify' => [30, 7],
                'notes' => (string) __('deadlines.template_volunteer_insurance_note'),
            ],
        ],
        [
            'label' => (string) __('deadlines.template_five_per_thousand'),
            'data' => [
                'title' => (string) __('deadlines.template_five_per_thousand'),
                'category' => 'five_per_thousand',
                'recurrence' => 'none',
                'assignee_role' => (string) __('deadlines.role_treasurer'),
                'notify' => [30],
                'notes' => (string) __('deadlines.template_five_per_thousand_note'),
            ],
        ],
        [
            'label' => (string) __('deadlines.template_medical_cert'),
            'data' => [
                'title' => (string) __('deadlines.template_medical_cert'),
                'category' => 'medical_cert',
                'recurrence' => 'yearly',
                'assignee_role' => (string) __('deadlines.role_secretary'),
                'notify' => [30, 7],
                'notes' => (string) __('deadlines.template_medical_cert_note'),
            ],
        ],
    ];
    ?>
    <div class="deadlines-templates" data-deadline-templates>
        <span class="deadlines-templates-title"><?= e(__('deadlines.templates_title')) ?></span>
        <div class="deadlines-templates-list">
            <?php foreach ($deadlineTemplates as $template): ?>
                <button type="button" class="deadlines-template" data-deadline-template="<?= e(json_encode($template['data'], JSON_UNESCAPED_UNICODE)) ?>">
                    <?= e((string) $template['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="muted deadlines-templates-hint"><?= e(__('deadlines.templates_hint')) ?></p>
    </div>
<?php endif; ?>
<div class="deadlines-form-row deadlines-form-row--main">
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

<?= field('text', [
    'name' => 'assignee_role',
    'label' => (string) __('deadlines.assignee_role'),
    'value' => (string) ($values['assignee_role'] ?? ''),
    'maxlength' => 40,
    'placeholder' => (string) __('deadlines.assignee_role_placeholder'),
]) ?>
<div class="deadlines-form-row deadlines-form-row--single">
    <div data-deadline-member-wrap>
        <?= field('checkbox', [
            'name' => '',
            'label' => (string) __('deadlines.member_involved'),
            'checked' => $memberLinked,
            'label_class' => 'checkbox-row',
            'wrapper_class' => 'deadline-member-toggle',
            'attrs' => ['data-deadline-member-toggle' => true],
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
</div>
<div class="deadlines-form-row deadlines-form-row--<?= $showStatus ? 'pair' : 'single' ?>">
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
</div>
<div class="deadlines-form-row deadlines-form-row--single">
    <div class="deadlines-notify">
        <span class="field-label"><?= e(__('deadlines.notify_title')) ?></span>
        <div class="deadlines-notify-pills">
            <?php foreach ($notifyPresets as $notifyDay): ?>
                <?php $notifyChecked = in_array($notifyDay, $notifySelected, true); ?>
                <label class="deadlines-notify-pill<?= $notifyChecked ? ' is-on' : '' ?>">
                    <?= field('checkbox', [
                        'name' => 'notify_days[]',
                        'id' => 'notify-days-' . $notifyDay,
                        'value' => $notifyChecked ? (string) $notifyDay : '',
                        'check_value' => (string) $notifyDay,
                        'checked' => $notifyChecked,
                        'label' => '',
                        'bare' => true,
                        'attrs' => ['aria-label' => $notifyLabel($notifyDay)],
                    ]) ?>
                    <span class="deadlines-notify-days"><?= (int) $notifyDay ?></span>
                    <span class="deadlines-notify-unit"><?= e($notifyUnit($notifyDay)) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="muted deadlines-notify-hint"><?= e(__('deadlines.notify_hint')) ?></p>
    </div>
</div>
<?= field('textarea', [
    'name' => 'notes',
    'label' => (string) __('deadlines.notes'),
    'value' => (string) ($values['notes'] ?? ''),
    'rows' => 3,
]) ?>

