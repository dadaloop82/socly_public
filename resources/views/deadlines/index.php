<?php
/** @var list<array> $deadline_items */
/** @var array{overdue:int,due_soon:int,open:int} $counts */
/** @var list<array> $members */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var string $today */
/** @var string $soon */
/** @var string $default_category */
/** @var string $active_filter */
$old = old_input();
$values = $old !== [] ? $old : [
    'title' => '',
    'due_date' => '',
    'category' => (string) ($default_category ?? 'general'),
    'member_id' => '',
    'notes' => '',
    'status' => 'open',
    'new_category' => '',
];
$canManage = can('deadlines.manage');
$formOpen = $old !== [];
$activeFilter = (string) ($active_filter ?? '');
$filterUrl = static function (string $filter = '') use ($search_query): string {
    $params = [];
    if (trim((string) ($search_query ?? '')) !== '') {
        $params['q'] = (string) $search_query;
    }
    if ($filter !== '') {
        $params['filter'] = $filter;
    }
    $qs = http_build_query($params);
    return url('/deadlines' . ($qs !== '' ? '?' . $qs : ''));
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('deadlines.title')) ?></h1>
        <p class="page-lede"><?= e(__('deadlines.lede')) ?></p>
    </div>
</div>

<div class="stats stats-context-deadlines">
    <a class="stat<?= $activeFilter === 'overdue' ? ' is-active' : '' ?>" href="<?= e($filterUrl('overdue')) ?>">
        <div class="label"><?= e(__('deadlines.overdue')) ?></div>
        <div class="value stat-negative"><?= (int) ($counts['overdue'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $activeFilter === 'soon' ? ' is-active' : '' ?>" href="<?= e($filterUrl('soon')) ?>">
        <div class="label"><?= e(__('deadlines.due_soon')) ?></div>
        <div class="value"><?= (int) ($counts['due_soon'] ?? 0) ?></div>
    </a>
    <a class="stat<?= $activeFilter === 'open' ? ' is-active' : '' ?>" href="<?= e($filterUrl('open')) ?>">
        <div class="label"><?= e(__('deadlines.open')) ?></div>
        <div class="value"><?= (int) ($counts['open'] ?? 0) ?></div>
    </a>
</div>
<?php if ($activeFilter !== ''): ?>
    <p class="muted" style="margin:-0.35rem 0 1rem">
        <a href="<?= e($filterUrl('')) ?>"><?= e(__('deadlines.filter_clear')) ?></a>
    </p>
<?php endif; ?>

<form class="panel filter-bar members-filter" method="get" action="<?= e(url('/deadlines')) ?>" role="search" style="margin-bottom:1rem">
    <?php if ($activeFilter !== ''): ?>
        <input type="hidden" name="filter" value="<?= e($activeFilter) ?>">
    <?php endif; ?>
    <label class="visually-hidden" for="deadline-q-top"><?= e(__('deadlines.search')) ?></label>
    <input
        id="deadline-q-top"
        class="members-filter-q"
        type="search"
        name="q"
        value="<?= e((string) ($search_query ?? '')) ?>"
        placeholder="<?= e(__('deadlines.search_placeholder')) ?>"
        maxlength="120"
        autocomplete="off"
    >
    <button class="btn btn-sm" type="submit"><?= e(__('deadlines.search')) ?></button>
    <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e($filterUrl($activeFilter)) ?>"><?= e(__('deadlines.search_clear')) ?></a>
    <?php endif; ?>
</form>

<?php if ($canManage): ?>
<details class="panel treasury-form-panel" data-deadline-form-panel <?= $formOpen ? 'open' : '' ?>>
    <summary class="treasury-form-summary">
        <span class="treasury-form-summary-text">
            <span class="section-title"><?= e(__('deadlines.add')) ?></span>
            <span class="section-lede"><?= e(__('deadlines.add_lede')) ?></span>
        </span>
        <span class="treasury-form-chevron" aria-hidden="true"></span>
    </summary>
    <form class="treasury-form-body" method="post" action="<?= e(url('/deadlines')) ?>" data-deadline-form data-leave-guard data-confirm-template="<?= e(__('deadlines.confirm_save')) ?>">
        <?= csrf_field() ?>
        <?php
        $show_status = false;
        require __DIR__ . '/_form_fields.php';
        ?>
        <div class="form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('deadlines.submit')) ?></button>
        </div>
    </form>
</details>
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
            <?php if (trim((string) ($search_query ?? '')) !== '' || $activeFilter !== ''): ?>
                <strong><?= e(__('deadlines.search_empty_title')) ?></strong>
                <?= e(__('deadlines.search_empty_text', ['q' => (string) ($search_query !== '' ? $search_query : $activeFilter)])) ?>
            <?php else: ?>
                <strong><?= e(__('deadlines.empty_title')) ?></strong>
                <?= e(__('deadlines.empty_text')) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('deadlines.title_field')) ?></th>
                    <th><?= e(__('deadlines.category')) ?></th>
                    <th><?= e(__('deadlines.due_date')) ?></th>
                    <th><?= e(__('deadlines.member')) ?></th>
                    <th><?= e(__('deadlines.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                            <?php foreach ($deadline_items as $item): ?>
                                <?php
                                $due = (string) ($item['due_date'] ?? '');
                                $state = 'valid';
                                if ($due !== '' && $due < $today) {
                                    $state = 'overdue';
                                } elseif ($due !== '' && $due <= $soon) {
                                    $state = 'soon';
                                }
                                $memberLabel = trim((string) (($item['last_name'] ?? '') . ' ' . ($item['first_name'] ?? '')));
                                $itemId = (int) ($item['id'] ?? 0);
                                $isSystem = str_starts_with((string) ($item['source'] ?? ''), 'system:');
                                $editable = $canManage && !$isSystem;
                                $editUrl = $editable ? url('/deadlines/' . $itemId . '/edit') : '';
                                $categoryLabel = '';
                                foreach ($categories as $category) {
                                    if ((string) $category['key'] === (string) ($item['category'] ?? 'general')) {
                                        $categoryLabel = (string) $category['label'];
                                        break;
                                    }
                                }
                                ?>
                                <tr
                                    class="deadline-row deadline-row-<?= e($state) ?><?= $editable ? ' doc-row-editable' : '' ?>"
                                    <?php if ($editable): ?>
                                        data-href="<?= e($editUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('deadlines.edit') . ': ' . (string) ($item['title'] ?? '')) ?>"
                                    <?php endif; ?>
                                >
                                    <td>
                                        <span class="deadline-badge deadline-badge-<?= e($state) ?>"><?= e(__('deadlines.badge_' . $state)) ?></span>
                                        <strong><?= e((string) ($item['title'] ?? '')) ?></strong>
                                        <?php if ($isSystem): ?>
                                            <span class="doc-status doc-status-approved"><?= e(__('deadlines.system_badge')) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['notes'])): ?>
                                            <div class="muted"><?= e((string) $item['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="doc-category-badge"><?= e($categoryLabel !== '' ? $categoryLabel : (string) ($item['category'] ?? '')) ?></span></td>
                                    <td><?= e(format_date($due) ?: '—') ?></td>
                                    <td><?= e($memberLabel !== '' ? $memberLabel : '—') ?></td>
                                    <td class="doc-row-actions">
                                        <?php if ($editable): ?>
                                            <form method="post" action="<?= e(url('/deadlines/' . $itemId . '/done')) ?>" class="inline-form">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-ghost btn-sm"><?= e(__('deadlines.mark_done')) ?></button>
                                            </form>
                                            <?php if (!empty($item['member_id']) && component_enabled('members') && can('members.manage')): ?>
                                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/members/' . (int) $item['member_id'])) ?>"><?= e(__('deadlines.open_member')) ?></a>
                                            <?php endif; ?>
                                        <?php elseif (!$isSystem): ?>
                                            <span class="muted">—</span>
                                        <?php else: ?>
                                            <span class="muted"><?= e(__('deadlines.system_readonly_short')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
