<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/** @var array<string,mixed> $document */
/** @var string $category_label */
$docId = (int) ($document['id'] ?? 0);
$docTitle = (string) ($document['title'] ?? '');
$language = trim((string) ($document['language'] ?? ''));
$languageLabel = $language !== '' ? __('documents.language_' . $language) : '—';
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e((string) ($document['title'] ?? __('documents.title'))) ?></h1>
        <p class="page-lede"><?= e(__('documents.detail_lede')) ?></p>
        <?php if ($docId > 0): ?>
            <?= view_partial('partials/record_trail', ['entity' => 'document', 'id' => $docId]) ?>
        <?php endif; ?>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/documents')) ?>"><?= e(__('common.back')) ?></a>
        <?php
        $lockedMinutes = (string) ($document['source_ref'] ?? '') === 'minutes'
            && in_array((string) ($document['status'] ?? ''), ['approved', 'signed', 'archived'], true);
        ?>
        <?php if (can('documents.manage') && !$lockedMinutes): ?>
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
        <div><span class="muted"><?= e(__('documents.filter_section')) ?></span><br><a href="<?= e(url('/documents?section=' . rawurlencode((string) ($document['source_section'] ?? 'documents')))) ?>"><?= e((string) ($section_label ?? '—')) ?></a></div>
        <div><span class="muted"><?= e(__('documents.uploaded_by')) ?></span><br><strong><?= e((string) (($document['uploaded_by_name'] ?? '') ?: ($document['author_name'] ?? '—'))) ?></strong><br><span class="muted"><?= e(format_datetime($document['created_at'] ?? null) ?: '—') ?></span></div>
        <div><span class="muted"><?= e(__('documents.reference_period')) ?></span><br><strong><?php
            $periodLabel = document_period_label(is_array($document) ? $document : []);
            echo e($periodLabel !== '' ? $periodLabel : '—');
        ?></strong></div>
        <div><span class="muted"><?= e(__('documents.file_size')) ?></span><br><strong><?= (int) ($document['file_size'] ?? 0) > 0 ? e(number_format((int) $document['file_size'] / 1024, 0, ',', '.') . ' KB') : '—' ?></strong></div>
        <div><span class="muted"><?= e(__('documents.downloads')) ?></span><br><strong><?= (int) ($document['download_count'] ?? 0) ?></strong></div>
    </div>
    <?php if (trim((string) ($document['content_hash'] ?? '')) !== ''): ?>
        <p class="muted documents-hash"><?= e(__('documents.content_hash')) ?>: <code><?= e(substr((string) $document['content_hash'], 0, 16)) ?>…</code></p>
    <?php endif; ?>
    <?php if (!empty($repository_links)): ?>
        <div style="margin-top:1rem">
            <span class="muted"><?= e(__('documents.repository_links_title')) ?></span>
            <ul class="documents-repository-links">
                <?php foreach ($repository_links as $repoLink): ?>
                    <li>
                        <span class="documents-section-badge"><?= e((string) ($repoLink['section'] ?? '')) ?></span>
                        <?php if (trim((string) ($repoLink['context_ref'] ?? '')) !== ''): ?>
                            <code><?= e((string) $repoLink['context_ref']) ?></code>
                        <?php endif; ?>
                        <span class="muted"><?= e(format_datetime($repoLink['created_at'] ?? null) ?: '') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (trim((string) ($document['summary'] ?? '')) !== ''): ?>
        <div style="margin-top:1rem">
            <span class="muted"><?= e(__('documents.summary')) ?></span>
            <p><?= nl2br(e(document_summary_display((string) $document['summary']))) ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($document['file_path'])): ?>
        <div class="actions" style="margin-top:1rem">
            <a class="btn" href="<?= e(url('/documents/' . $docId . '/file')) ?>" target="_blank" rel="noopener"><?= e(__('documents.open_file')) ?></a>
            <?= page_action_icon([
                'icon' => 'download',
                'label' => (string) __('documents.download_file'),
                'href' => url('/documents/' . $docId . '/download'),
            ]) ?>
            <?php if (trim((string) ($signed_url ?? '')) !== ''): ?>
                <a class="btn btn-ghost" href="<?= e((string) $signed_url) ?>" target="_blank" rel="noopener"><?= e(__('documents.signed_link')) ?></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="muted" style="margin-top:1rem"><?= e(__('documents.no_file')) ?></p>
    <?php endif; ?>
</div>

<?php if (can('documents.manage') && (string) ($document['status'] ?? '') === 'draft'): ?>
    <section class="panel treasury-danger-zone documents-danger-zone">
        <div>
            <h2 class="section-title"><?= e(__('documents.delete_draft_title')) ?></h2>
            <p class="muted"><?= e(__('documents.delete_draft_hint')) ?></p>
        </div>
        <form method="post" action="<?= e(url('/documents/' . $docId . '/delete')) ?>"
              data-confirm="<?= e(__('documents.delete_draft_confirm', ['title' => $docTitle])) ?>"
              data-confirm-second="<?= e(__('documents.delete_draft_confirm_again', ['title' => $docTitle])) ?>"
              data-confirm-danger="1">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit"><?= e(__('documents.delete_draft_button')) ?></button>
        </form>
    </section>
<?php endif; ?>
