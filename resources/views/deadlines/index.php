<?php
/** @var list<array{key:string,label:string,items:list<array>}> $deadline_groups */
/** @var array{overdue:int,due_soon:int,open:int} $counts */
/** @var list<array> $members */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var string $today */
/** @var string $soon */
/** @var string $default_category */
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
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('deadlines.title')) ?></h1>
        <p class="page-lede"><?= e(__('deadlines.lede')) ?></p>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="label"><?= e(__('deadlines.overdue')) ?></div>
        <div class="value stat-negative"><?= (int) ($counts['overdue'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('deadlines.due_soon')) ?></div>
        <div class="value"><?= (int) ($counts['due_soon'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('deadlines.open')) ?></div>
        <div class="value"><?= (int) ($counts['open'] ?? 0) ?></div>
    </div>
</div>

<?php if ($canManage): ?>
<form class="panel" method="post" action="<?= e(url('/deadlines')) ?>" data-deadline-form>
    <?= csrf_field() ?>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('deadlines.add')) ?></h2>
            <p class="section-lede"><?= e(__('deadlines.add_lede')) ?></p>
        </div>
        <button class="btn" type="submit"><?= e(__('deadlines.submit')) ?></button>
    </div>
    <?php
    $show_status = false;
    require __DIR__ . '/_form_fields.php';
    ?>
</form>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('deadlines.timeline')) ?></h2>
            <p class="section-lede"><?= e(__('deadlines.timeline_lede')) ?></p>
        </div>
        <form class="doc-archive-search" method="get" action="<?= e(url('/deadlines')) ?>" role="search">
            <label class="visually-hidden" for="deadline-q"><?= e(__('deadlines.search')) ?></label>
            <input
                id="deadline-q"
                type="search"
                name="q"
                value="<?= e((string) ($search_query ?? '')) ?>"
                placeholder="<?= e(__('deadlines.search_placeholder')) ?>"
                maxlength="120"
                autocomplete="off"
            >
            <button class="btn btn-sm" type="submit"><?= e(__('deadlines.search')) ?></button>
            <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/deadlines')) ?>"><?= e(__('deadlines.search_clear')) ?></a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($deadline_groups === []): ?>
        <div class="empty-state">
            <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
                <strong><?= e(__('deadlines.search_empty_title')) ?></strong>
                <?= e(__('deadlines.search_empty_text', ['q' => (string) $search_query])) ?>
            <?php else: ?>
                <strong><?= e(__('deadlines.empty_title')) ?></strong>
                <?= e(__('deadlines.empty_text')) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="doc-archive">
            <?php foreach ($deadline_groups as $group): ?>
                <section class="doc-archive-group">
                    <header class="doc-archive-group-head">
                        <h3 class="doc-archive-group-title"><?= e((string) $group['label']) ?></h3>
                        <span class="doc-archive-group-count muted"><?= e((string) count($group['items'])) ?></span>
                    </header>
                    <div class="table-wrap embedded">
                        <table>
                            <thead>
                            <tr>
                                <th><?= e(__('deadlines.title_field')) ?></th>
                                <th><?= e(__('deadlines.due_date')) ?></th>
                                <th><?= e(__('deadlines.member')) ?></th>
                                <th><?= e(__('deadlines.actions')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['items'] as $item): ?>
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
                                $editUrl = $canManage ? url('/deadlines/' . $itemId . '/edit') : '';
                                ?>
                                <tr
                                    class="deadline-row deadline-row-<?= e($state) ?><?= $canManage ? ' doc-row-editable' : '' ?>"
                                    <?php if ($canManage): ?>
                                        data-href="<?= e($editUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('deadlines.edit') . ': ' . (string) ($item['title'] ?? '')) ?>"
                                    <?php endif; ?>
                                >
                                    <td>
                                        <span class="deadline-badge deadline-badge-<?= e($state) ?>"><?= e(__('deadlines.badge_' . $state)) ?></span>
                                        <strong><?= e((string) ($item['title'] ?? '')) ?></strong>
                                        <?php if (!empty($item['notes'])): ?>
                                            <div class="muted"><?= e((string) $item['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e(format_date($due) ?: '—') ?></td>
                                    <td><?= e($memberLabel !== '' ? $memberLabel : '—') ?></td>
                                    <td class="doc-row-actions">
                                        <?php if ($canManage): ?>
                                            <form method="post" action="<?= e(url('/deadlines/' . $itemId . '/done')) ?>" class="inline-form">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-ghost btn-sm"><?= e(__('deadlines.mark_done')) ?></button>
                                            </form>
                                            <?php if (!empty($item['member_id']) && component_enabled('members') && can('members.manage')): ?>
                                                <a class="btn btn-ghost btn-sm" href="<?= e(url('/members/' . (int) $item['member_id'])) ?>"><?= e(__('deadlines.open_member')) ?></a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="muted">—</span>
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
