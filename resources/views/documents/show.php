<?php
/** @var array<string,mixed> $document */
/** @var string $category_label */
$docId = (int) ($document['id'] ?? 0);
$language = trim((string) ($document['language'] ?? ''));
$languageLabel = $language !== '' ? __('documents.language_' . $language) : '—';
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e((string) ($document['title'] ?? __('documents.title'))) ?></h1>
        <p class="page-lede"><?= e(__('documents.detail_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>"><?= e(__('common.back')) ?></a>
        <?php if (can('documents.manage')): ?>
            <a class="btn" href="<?= e(url('/documents/' . $docId . '/edit')) ?>"><?= e(__('documents.edit')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="grid-3">
        <div><span class="muted"><?= e(__('documents.number')) ?></span><br><strong><?= e((string) ($document['document_number'] ?? '') ?: '—') ?></strong></div>
        <div><span class="muted"><?= e(__('documents.date')) ?></span><br><strong><?= e(format_date($document['document_date'] ?? null) ?: '—') ?></strong></div>
        <div><span class="muted"><?= e(__('documents.category')) ?></span><br><span class="doc-category-badge"><?= e($category_label) ?></span></div>
        <div><span class="muted"><?= e(__('documents.language')) ?></span><br><strong><?= e($languageLabel) ?></strong></div>
        <div><span class="muted"><?= e(__('documents.status')) ?></span><br><span class="doc-status doc-status-<?= e((string) ($document['status'] ?? 'draft')) ?>"><?= e(__('documents.status_' . (string) ($document['status'] ?? 'draft'))) ?></span></div>
    </div>
    <?php if (trim((string) ($document['summary'] ?? '')) !== ''): ?>
        <div style="margin-top:1rem">
            <span class="muted"><?= e(__('documents.summary')) ?></span>
            <p><?= nl2br(e((string) $document['summary'])) ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($document['file_path'])): ?>
        <div class="actions" style="margin-top:1rem">
            <a class="btn" href="<?= e(url('/documents/' . $docId . '/file')) ?>" target="_blank" rel="noopener"><?= e(__('documents.open_file')) ?></a>
            <a class="btn btn-ghost" href="<?= e(url('/documents/' . $docId . '/download')) ?>"><?= e(__('documents.download_file')) ?></a>
        </div>
    <?php else: ?>
        <p class="muted" style="margin-top:1rem"><?= e(__('documents.no_file')) ?></p>
    <?php endif; ?>
</div>
