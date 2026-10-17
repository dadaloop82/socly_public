<?php
/**
 * Scadenzario — pagina elenco (stesso stile di Soci e Prima cassa):
 * intestazione con azione primaria, KPI cliccabili, barra filtri live e
 * archivio raggruppato per urgenza, con righe cliccabili e azioni rapide.
 *
 * @var list<array> $deadline_items
 * @var array{overdue:int,due_soon:int,open:int} $counts
 * @var list<array> $members
 * @var list<array{key:string,label:string,builtin:bool}> $categories
 * @var string $today
 * @var string $soon
 * @var string $default_category
 * @var string $active_filter
 * @var string $filter_category
 * @var string $search_query
 */
$canManage = can('deadlines.manage');
$activeFilter = (string) ($active_filter ?? '');
$query = trim((string) ($search_query ?? ''));
$filterCategory = (string) ($filter_category ?? '');
$hasActiveFilters = $query !== '' || $activeFilter !== '' || $filterCategory !== '';

$categoryFilterOptions = [];
foreach ($categories as $category) {
    $categoryFilterOptions[(string) $category['key']] = (string) $category['label'];
}

// Keeps the other filters when one of them changes (stat cards, reset link…).
$filterUrl = static function (array $overrides = []) use ($query, $activeFilter, $filterCategory): string {
    $params = [];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if ($activeFilter !== '') {
        $params['filter'] = $activeFilter;
    }
    if ($filterCategory !== '') {
        $params['category'] = $filterCategory;
    }
    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }
    return url('/deadlines') . ($params !== [] ? '?' . http_build_query($params) : '');
};

$statusFilterOptions = [
    'open' => (string) __('deadlines.tab_open'),
    'overdue' => (string) __('deadlines.overdue'),
    'soon' => (string) __('deadlines.due_soon'),
    'done' => (string) __('deadlines.tab_done'),
    'all' => (string) __('deadlines.status_all'),
];

// Ogni voce finisce in un solo gruppo: l'elenco si legge dall'alto verso il basso.
$groupLabels = [
    'overdue' => (string) __('deadlines.overdue'),
    'soon' => (string) __('deadlines.due_soon'),
    'later' => (string) __('deadlines.bucket_later'),
    'done' => (string) __('deadlines.bucket_done'),
    'dismissed' => (string) __('deadlines.bucket_dismissed'),
];
$groupTones = [
    'overdue' => 'overdue',
    'soon' => 'soon',
    'later' => 'future',
    'done' => 'done',
    'dismissed' => 'done',
];
$groups = [];
foreach (array_keys($groupLabels) as $groupKey) {
    $groups[$groupKey] = [];
}
foreach ($deadline_items as $item) {
    $itemStatus = (string) ($item['status'] ?? 'open');
    $due = (string) ($item['due_date'] ?? '');
    if ($itemStatus === 'done') {
        $bucketKey = 'done';
    } elseif ($itemStatus === 'dismissed') {
        $bucketKey = 'dismissed';
    } elseif ($due !== '' && $due < $today) {
        $bucketKey = 'overdue';
    } elseif ($due !== '' && $due <= $soon) {
        $bucketKey = 'soon';
    } else {
        $bucketKey = 'later';
    }
    $groups[$bucketKey][] = $item;
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('deadlines.title')) ?></h1>
        <p class="page-lede"><?= e(__('deadlines.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if ($hasActiveFilters): ?>
            <a class="btn btn-ghost" href="<?= e($filterUrl(['q' => '', 'filter' => '', 'category' => ''])) ?>"><?= e(__('deadlines.filter_reset')) ?></a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn<?= ($deadline_items === [] && !$hasActiveFilters) ? ' is-first-cta' : '' ?>" href="<?= e(url('/deadlines/create')) ?>"><?= e(__('deadlines.add')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stats stats-context-deadlines">
    <a class="stat<?= $activeFilter === 'overdue' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['filter' => $activeFilter === 'overdue' ? '' : 'overdue'])) ?>">
        <div class="label"><?= e(__('deadlines.overdue')) ?></div>
        <div class="value stat-negative"><?= (int) ($counts['overdue'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $activeFilter === 'soon' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['filter' => $activeFilter === 'soon' ? '' : 'soon'])) ?>">
        <div class="label"><?= e(__('deadlines.due_soon')) ?></div>
        <div class="value"><?= (int) ($counts['due_soon'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $activeFilter === 'open' ? ' is-active' : '' ?>" href="<?= e($filterUrl(['filter' => $activeFilter === 'open' ? '' : 'open'])) ?>">
        <div class="label"><?= e(__('deadlines.open')) ?></div>
        <div class="value"><?= (int) ($counts['open'] ?? 0) ?></div>
    </a>
</div>

<?php if (!empty($deadline_items) || $hasActiveFilters): ?>
<form class="panel filter-bar members-filter deadlines-filter" method="get" action="<?= e(url('/deadlines')) ?>" role="search" data-deadlines-filter>
    <div class="members-filter-row deadlines-filter-row">
        <div class="members-filter-q">
            <?= field('search', [
                'name' => 'q',
                'id' => 'deadlines-q',
                'label' => (string) __('deadlines.search'),
                'value' => $query,
                'context' => 'filter',
                'placeholder' => (string) __('deadlines.search_placeholder'),
                'maxlength' => 120,
                'attrs' => ['enterkeyhint' => 'search', 'autocomplete' => 'off'],
            ]) ?>
        </div>
        <?= field('select', [
            'name' => 'category',
            'id' => 'deadlines-category-filter',
            'label' => (string) __('deadlines.filter_category'),
            'value' => $filterCategory,
            'context' => 'filter',
            'empty_label' => (string) __('deadlines.filter_all'),
            'options' => $categoryFilterOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'filter',
            'id' => 'deadlines-status-filter',
            'label' => (string) __('deadlines.filter_status'),
            'value' => $activeFilter !== '' ? $activeFilter : 'open',
            'context' => 'filter',
            'include_empty' => false,
            'options' => $statusFilterOptions,
        ]) ?>
        <?php if ($hasActiveFilters): ?>
            <a class="btn btn-ghost btn-sm deadlines-filter-reset" href="<?= e($filterUrl(['q' => '', 'filter' => '', 'category' => ''])) ?>"><?= e(__('deadlines.filter_reset')) ?></a>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('deadlines.timeline')) ?></h2>
            <p class="section-lede"><?= e(__('deadlines.timeline_lede')) ?></p>
        </div>
    </div>
    <?php if ($deadline_items === []): ?>
        <div class="empty-state">
            <?php if ($hasActiveFilters): ?>
                <strong><?= e(__('deadlines.search_empty_title')) ?></strong>
                <?= e(__('deadlines.search_empty_text', ['q' => $query !== '' ? $query : $filterCategory])) ?>
            <?php else: ?>
                <strong><?= e(__('deadlines.empty_title')) ?></strong>
                <?= e(__('deadlines.empty_text')) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="doc-archive deadlines-archive">
            <?php foreach ($groups as $groupKey => $groupItems): ?>
                <?php if ($groupItems === []) { continue; } ?>
                <section class="doc-archive-group deadline-group deadline-group--<?= e($groupTones[$groupKey]) ?>">
                    <header class="doc-archive-group-head">
                        <h3 class="doc-archive-group-title"><?= e($groupLabels[$groupKey]) ?></h3>
                        <span class="doc-archive-group-count"><?= count($groupItems) ?></span>
                    </header>
                    <div class="table-wrap embedded">
                        <table class="deadlines-table">
                            <thead>
                            <tr>
                                <th><?= e(__('deadlines.due_date')) ?></th>
                                <th><?= e(__('deadlines.title_field')) ?></th>
                                <th><?= e(__('deadlines.category')) ?></th>
                                <th><?= e(__('deadlines.member')) ?></th>
                                <th><?= e(__('deadlines.assignee_role')) ?></th>
                                <th><?= e(__('deadlines.actions')) ?></th>
                            </tr>
                            </thead>
                            <tbody>

                            <?php foreach ($groupItems as $item): ?>
                                <?php
                                $due = (string) ($item['due_date'] ?? '');
                                $itemStatus = (string) ($item['status'] ?? 'open');
                                $state = 'future';
                                if ($itemStatus === 'done' || $itemStatus === 'dismissed') {
                                    $state = 'done';
                                } elseif ($due !== '' && $due < $today) {
                                    $state = 'overdue';
                                } elseif ($due !== '' && $due <= $soon) {
                                    $state = 'soon';
                                }
                                // Solo gli avvisi temporali meritano un badge: le scadenze
                                // lontane restano neutre (senza etichetta).
                                $badgeLabel = match ($state) {
                                    'overdue' => (string) __('deadlines.badge_overdue'),
                                    'soon' => (string) __('deadlines.badge_soon'),
                                    default => '',
                                };
                                if ($itemStatus === 'done') {
                                    $badgeLabel = (string) __('deadlines.status_done');
                                } elseif ($itemStatus === 'dismissed') {
                                    $badgeLabel = (string) __('deadlines.status_dismissed');
                                }
                                $memberLabel = trim((string) (($item['last_name'] ?? '') . ' ' . ($item['first_name'] ?? '')));
                                if ($memberLabel === '') {
                                    // Mandato in organigramma: la persona può non essere (ancora) un socio.
                                    $memberLabel = trim((string) ($item['source_person_name'] ?? ''));
                                }
                                $source = (string) ($item['source'] ?? '');
                                $itemId = (int) ($item['id'] ?? 0);
                                $isSystem = str_starts_with($source, 'system:');
                                $canEdit = $canManage && !$isSystem;
                                $canAct = $canEdit && $itemStatus === 'open';
                                $editUrl = $canEdit ? url('/deadlines/' . $itemId . '/edit') : '';
                                $categoryKey = (string) ($item['category'] ?? 'general');
                                $categoryLabel = $categoryFilterOptions[$categoryKey] ?? $categoryKey;
                                $recurrence = (string) ($item['recurrence'] ?? 'none');
                                $assignee = trim((string) ($item['assignee_role'] ?? ''));
                                $notifyAlert = \Socly\Services\DeadlineService::notifyAlert($item, $today);
                                // Scadenze automatiche: azione contestuale verso il modulo che le genera.
                                $sourceAction = [];
                                if ($isSystem) {
                                    if (str_starts_with($source, 'system:mandate:')) {
                                        $sourceAction = [
                                            'icon' => 'edit',
                                            'label' => __('deadlines.open_org_chart'),
                                            'href' => url('/org'),
                                            'show' => can('org.view'),
                                        ];
                                    } elseif (str_starts_with($source, 'system:member_cert:')) {
                                        $sourceAction = [
                                            'icon' => 'user',
                                            'label' => __('deadlines.open_member'),
                                            'href' => !empty($item['member_id']) ? url('/members/' . (int) $item['member_id']) : '',
                                            'show' => !empty($item['member_id']) && can('members.view'),
                                        ];
                                    } elseif (str_starts_with($source, 'system:membership_period:')) {
                                        $sourceAction = [
                                            'icon' => 'toggle',
                                            'label' => __('deadlines.manage_periods'),
                                            'href' => url('/settings') . '#periods',
                                            'show' => can('settings.manage'),
                                        ];
                                    } elseif (str_starts_with($source, 'system:document:')) {
                                        $sourceAction = [
                                            'icon' => 'file',
                                            'label' => __('deadlines.open_document'),
                                            'href' => url('/documents/' . (int) substr($source, strlen('system:document:'))),
                                            'show' => can('documents.view'),
                                        ];
                                    }
                                }
                                ?>
                                <tr
                                    class="deadline-row deadline-row-<?= e($state) ?><?= $canEdit ? ' doc-row-editable' : '' ?>"
                                    <?php if ($canEdit): ?>
                                        data-href="<?= e($editUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('deadlines.edit') . ': ' . (string) ($item['title'] ?? '')) ?>"
                                    <?php endif; ?>
                                >
                                    <td data-label="<?= e(__('deadlines.due_date')) ?>">
                                        <span class="deadline-due"><?= e(format_date($due) ?: '—') ?></span>
                                        <?php if ($badgeLabel !== ''): ?>
                                            <span class="deadline-badge deadline-badge-<?= e($state) ?>"><?= e($badgeLabel) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= e(__('deadlines.title_field')) ?>">
                                        <div class="deadline-title">
                                            <strong><?= e((string) ($item['title'] ?? '')) ?></strong>
                                            <?php if ($isSystem): ?>
                                                <span class="doc-status doc-status-approved"><?= e(__('deadlines.system_badge')) ?></span>
                                            <?php endif; ?>
                                            <?php if ($recurrence !== '' && $recurrence !== 'none'): ?>
                                                <span class="deadline-recurrence"><?= e(__('deadlines.recurrence_' . $recurrence)) ?></span>
                                            <?php endif; ?>
                                            <?php if ($notifyAlert !== null): ?>
                                                <span class="deadline-notify" title="<?= e(__('deadlines.notify_title')) ?>">
                                                    <?= e($notifyAlert === 0
                                                        ? __('deadlines.notify_badge_today')
                                                        : __('deadlines.notify_badge', ['days' => (string) $notifyAlert])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($item['notes'])): ?>
                                            <div class="muted deadline-notes"><?= e((string) $item['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= e(__('deadlines.category')) ?>">
                                        <span class="doc-category-badge"><?= e($categoryLabel !== '' ? $categoryLabel : $categoryKey) ?></span>
                                    </td>

                                    <td data-label="<?= e(__('deadlines.member')) ?>"><?= e($memberLabel !== '' ? $memberLabel : '—') ?></td>
                                    <td data-label="<?= e(__('deadlines.assignee_role')) ?>"><?= e($assignee !== '' ? $assignee : '—') ?></td>
                                    <td class="doc-row-actions" data-stop-row-nav>
                                        <?php
                                        $actions = [];
                                        if ($canAct) {
                                            $actions[] = [
                                                'icon' => 'check',
                                                'label' => __('deadlines.mark_done'),
                                                'form_action' => url('/deadlines/' . $itemId . '/done'),
                                            ];
                                            $actions[] = [
                                                'icon' => 'renew',
                                                'label' => __('deadlines.renew'),
                                                'form_action' => url('/deadlines/' . $itemId . '/renew'),
                                            ];
                                        }
                                        if ($canEdit) {
                                            $actions[] = [
                                                'icon' => 'user',
                                                'label' => __('deadlines.open_member'),
                                                'show' => !empty($item['member_id']) && component_enabled('members') && can('members.manage'),
                                                'href' => !empty($item['member_id']) ? url('/members/' . (int) $item['member_id']) : '',
                                            ];
                                            $actions[] = [
                                                'icon' => 'edit',
                                                'label' => __('deadlines.edit'),
                                                'href' => $editUrl,
                                            ];
                                        }
                                        // Scadenza automatica: vai al modulo che la genera.
                                        if ($sourceAction !== []) {
                                            $actions[] = $sourceAction;
                                        }
                                        ?>
                                        <?php if ($actions !== []): ?>
                                            <?= row_actions($actions) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

