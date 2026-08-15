<?php
/** @var array<string,mixed> $deadline */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<array> $members */
$old = old_input();
$values = $old !== [] ? $old : [
    'title' => (string) ($deadline['title'] ?? ''),
    'due_date' => (string) ($deadline['due_date'] ?? ''),
    'category' => (string) ($deadline['category'] ?? 'general'),
    'member_id' => (string) ($deadline['member_id'] ?? ''),
    'notes' => (string) ($deadline['notes'] ?? ''),
    'status' => (string) ($deadline['status'] ?? 'open'),
    'new_category' => '',
];
$deadlineId = (int) ($deadline['id'] ?? 0);
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('deadlines.edit')) ?></h1>
        <p class="page-lede"><?= e(__('deadlines.edit_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/deadlines')) ?>"><?= e(__('common.back')) ?></a>
        <?php if (!empty($deadline['member_id']) && component_enabled('members') && can('members.manage')): ?>
            <a class="btn btn-ghost" href="<?= e(url('/members/' . (int) $deadline['member_id'])) ?>"><?= e(__('deadlines.open_member')) ?></a>
        <?php endif; ?>
    </div>
</div>

<form class="panel" method="post" action="<?= e(url('/deadlines/' . $deadlineId)) ?>" data-deadline-form>
    <?= csrf_field() ?>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e((string) ($deadline['title'] ?? __('deadlines.edit'))) ?></h2>
            <p class="section-lede"><?= e(__('deadlines.edit_lede')) ?></p>
        </div>
        <button class="btn" type="submit"><?= e(__('deadlines.update')) ?></button>
    </div>
    <?php
    $show_status = true;
    require __DIR__ . '/_form_fields.php';
    ?>
</form>
