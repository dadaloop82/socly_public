<?php
/** @var array<string,mixed> $document */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<string> $languages */
$old = old_input();
$values = $old !== [] ? $old : [
    'title' => (string) ($document['title'] ?? ''),
    'document_date' => (string) ($document['document_date'] ?? ''),
    'language' => (string) ($document['language'] ?? ''),
    'category' => (string) ($document['category'] ?? 'minutes'),
    'status' => (string) ($document['status'] ?? 'draft'),
    'summary' => (string) ($document['summary'] ?? ''),
    'uploaded_path' => '',
    'uploaded_mime' => '',
    'uploaded_name' => (string) ($existing_file_name ?? ''),
];
$hasExisting = !empty($document['file_path']) && empty($old['uploaded_path']);
if (!empty($old['uploaded_path'])) {
    $hasExisting = false;
}
$docId = (int) ($document['id'] ?? 0);
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('documents.edit')) ?></h1>
        <p class="page-lede"><?= e(__('documents.edit_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>"><?= e(__('common.back')) ?></a>
        <?php if (!empty($document['file_path'])): ?>
            <a class="btn btn-ghost" href="<?= e(url('/documents/' . $docId . '/file')) ?>" target="_blank" rel="noopener"><?= e(__('documents.open_file')) ?></a>
        <?php endif; ?>
    </div>
</div>

<form
    class="panel"
    method="post"
    action="<?= e(url('/documents/' . $docId)) ?>"
    enctype="multipart/form-data"
    data-document-form
    data-upload-url="<?= e(url('/documents/upload')) ?>"
    data-msg-idle="<?= e(__('documents.upload_idle')) ?>"
    data-msg-uploading="<?= e(__('documents.upload_busy')) ?>"
    data-msg-ok="<?= e(__('documents.upload_ok')) ?>"
    data-msg-fail="<?= e(__('documents.upload_fail')) ?>"
    data-msg-change="<?= e(__('documents.change_file')) ?>"
>
    <?= csrf_field() ?>
    <input type="hidden" name="uploaded_path" value="<?= e((string) ($values['uploaded_path'] ?? '')) ?>" data-doc-uploaded-path>
    <input type="hidden" name="uploaded_mime" value="<?= e((string) ($values['uploaded_mime'] ?? '')) ?>" data-doc-uploaded-mime>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e((string) ($document['title'] ?? __('documents.edit'))) ?></h2>
            <p class="section-lede"><?= e(__('documents.edit_lede')) ?></p>
        </div>
        <button class="btn" type="submit"><?= e(__('documents.update')) ?></button>
    </div>
    <?php
    $has_existing_file = $hasExisting || (!empty($values['uploaded_path']));
    require __DIR__ . '/_form_fields.php';
    ?>
</form>
