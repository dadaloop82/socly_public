<?php
/**
 * Verbali e documenti — carica/modifica documento su pagina dedicata
 * (stesso stile di Soci, Prima cassa e Scadenzario).
 *
 * @var bool $isEdit
 * @var array<string,mixed>|null $document
 * @var string $action
 * @var string $default_category
 * @var list<array{key:string,label:string,builtin:bool}> $categories
 * @var list<array{label:string,options:list<array{key:string,label:string}>}>|null $category_groups
 * @var list<string> $languages
 * @var int|string $upload_max_mb
 * @var string $existing_file_name
 * @var list<array> $members
 * @var list<array> $sibling_options
 * @var int $document_id
 */
$old = old_input();
if ($old !== []) {
    $values = $old;
} elseif ($isEdit && $document !== null) {
    $values = [
        'title' => (string) ($document['title'] ?? ''),
        'document_number' => (string) ($document['document_number'] ?? ''),
        'document_date' => (string) ($document['document_date'] ?? ''),
        'expires_at' => (string) ($document['expires_at'] ?? ''),
        'language' => (string) ($document['language'] ?? ''),
        'category' => (string) ($document['category'] ?? 'minutes'),
        'status' => (string) ($document['status'] ?? 'draft'),
        'visibility' => (string) ($document['visibility'] ?? 'internal'),
        'member_id' => (string) ($document['member_id'] ?? ''),
        'sibling_document_id' => (string) ($document['sibling_document_id'] ?? ''),
        'summary' => (string) ($document['summary'] ?? ''),
        'uploaded_path' => '',
        'uploaded_mime' => '',
        'uploaded_name' => (string) ($existing_file_name ?? ''),
        'new_category' => '',
    ];
} else {
    $values = [
        'title' => '',
        'document_number' => '',
        'document_date' => date('Y-m-d'),
        'expires_at' => '',
        'language' => '',
        'category' => (string) ($default_category ?? 'minutes'),
        'status' => 'draft',
        'visibility' => 'internal',
        'member_id' => '',
        'sibling_document_id' => '',
        'summary' => '',
        'uploaded_path' => '',
        'uploaded_mime' => '',
        'uploaded_name' => '',
        'new_category' => '',
    ];
}
$docId = $isEdit ? (int) ($document['id'] ?? 0) : 0;
$existingFilePath = $isEdit && $document !== null ? trim((string) ($document['file_path'] ?? '')) : '';
$hasExisting = $existingFilePath !== '' && empty($old['uploaded_path']);
$pageTitle = $isEdit ? __('documents.edit') : __('documents.add');
$pageLede = $isEdit ? __('documents.edit_lede') : __('documents.add_lede');
$submitLabel = $isEdit ? __('documents.update') : __('documents.submit');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-lede"><?= e($pageLede) ?></p>
    </div>
</div>

<form
    id="document-form"
    class="panel documents-form"
    method="post"
    action="<?= e($action) ?>"
    enctype="multipart/form-data"
    data-document-form
    data-leave-guard
    data-upload-url="<?= e(url('/documents/upload')) ?>"
    data-msg-idle="<?= e(__('documents.upload_idle')) ?>"
    data-msg-uploading="<?= e(__('documents.upload_busy')) ?>"
    data-msg-ok="<?= e(__('documents.upload_ok')) ?>"
    data-msg-fail="<?= e(__('documents.upload_fail')) ?>"
    data-msg-change="<?= e(__('documents.change_file')) ?>"
>
    <?= csrf_field() ?>
    <?= field('hidden', [
        'name' => 'uploaded_path',
        'value' => (string) ($values['uploaded_path'] ?? ''),
        'attrs' => ['data-doc-uploaded-path' => true],
    ]) ?>
    <?= field('hidden', [
        'name' => 'uploaded_mime',
        'value' => (string) ($values['uploaded_mime'] ?? ''),
        'attrs' => ['data-doc-uploaded-mime' => true],
    ]) ?>
    <?php
    $has_existing_file = $hasExisting || !empty($values['uploaded_path']);
    require __DIR__ . '/_form_fields.php';
    ?>
    <div class="form-actions form-actions-end">
        <?php if ($isEdit && $existingFilePath !== ''): ?>
            <a class="btn btn-ghost" href="<?= e(url('/documents/' . $docId . '/file')) ?>" target="_blank" rel="noopener"><?= e(__('documents.open_file')) ?></a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>"><?= e(__('common.back')) ?></a>
        <button class="btn" type="submit"><?= e($submitLabel) ?></button>
    </div>
</form>
