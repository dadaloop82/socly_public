<?php
/**
 * Scadenzario — nuova/modifica scadenza su pagina dedicata (come Soci e Prima cassa).
 *
 * @var bool $isEdit
 * @var array<string,mixed>|null $deadline
 * @var string $action
 * @var string $default_category
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{key:string,label:string,options:list<array{key:string,label:string}>}>|null $category_groups
 * @var list<array> $members
 */
$old = old_input();
if ($old !== []) {
    $values = $old;
} elseif ($isEdit && $deadline !== null) {
    $values = [
        'title' => (string) ($deadline['title'] ?? ''),
        'due_date' => (string) ($deadline['due_date'] ?? ''),
        'category' => (string) ($deadline['category'] ?? 'general'),
        'member_id' => (string) ($deadline['member_id'] ?? ''),
        'notes' => (string) ($deadline['notes'] ?? ''),
        'status' => (string) ($deadline['status'] ?? 'open'),
        'new_category' => '',
        'recurrence' => (string) ($deadline['recurrence'] ?? 'none'),
        'assignee_role' => (string) ($deadline['assignee_role'] ?? ''),
        'notify_days' => (string) ($deadline['notify_days'] ?? '30,7'),
    ];
} else {
    $values = [
        'title' => '',
        'due_date' => date('Y-m-d'),
        'category' => (string) ($default_category ?? 'general'),
        'member_id' => '',
        'notes' => '',
        'status' => 'open',
        'new_category' => '',
        'recurrence' => 'none',
        'assignee_role' => '',
        'notify_days' => '30,7',
    ];
}
$itemId = $isEdit ? (int) ($deadline['id'] ?? 0) : 0;
$pageTitle = $isEdit ? __('deadlines.edit') : __('deadlines.add');
$pageLede = $isEdit ? __('deadlines.edit_lede') : __('deadlines.add_lede');
$submitLabel = $isEdit ? __('deadlines.update') : __('deadlines.submit');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-lede"><?= e($pageLede) ?></p>
    </div>
</div>

<form id="deadline-form" class="panel deadlines-form" method="post" action="<?= e($action) ?>"
      data-deadline-form data-leave-guard data-confirm-template="<?= e(__('deadlines.confirm_save')) ?>">
    <?= csrf_field() ?>
    <?php
    $show_status = $isEdit;
    // I template legali servono solo alla creazione: in modifica sovrascriverebbero i dati.
    $show_templates = !$isEdit;
    require __DIR__ . '/_form_fields.php';
    ?>
    <div class="form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/deadlines')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e($submitLabel) ?></button>
    </div>
</form>
