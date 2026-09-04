<?php
/** @var list<array> $documents */
/** @var list<array{key:string,label:string,builtin:bool}> $categories */
/** @var list<string> $languages */
/** @var string $default_category */
$old = old_input();
$values = $old !== [] ? $old : [
    'title' => '',
    'document_number' => '',
    'document_date' => date('Y-m-d'),
    'language' => '',
    'category' => (string) ($default_category ?? 'minutes'),
    'status' => 'draft',
    'summary' => '',
    'uploaded_path' => '',
    'uploaded_mime' => '',
    'uploaded_name' => '',
    'new_category' => '',
];
$canManage = can('documents.manage');
$formOpen = $old !== [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('documents.title')) ?></h1>
        <p class="page-lede"><?= e(__('documents.lede')) ?></p>
    </div>
</div>

<form class="panel filter-bar members-filter" method="get" action="<?= e(url('/documents')) ?>" role="search" style="margin-bottom:1rem">
    <label class="visually-hidden" for="doc-archive-q-top"><?= e(__('documents.search')) ?></label>
    <input
        id="doc-archive-q-top"
        class="members-filter-q"
        type="search"
        name="q"
        value="<?= e((string) ($search_query ?? '')) ?>"
        placeholder="<?= e(__('documents.search_placeholder')) ?>"
        maxlength="120"
        autocomplete="off"
    >
    <button class="btn btn-sm" type="submit"><?= e(__('documents.search')) ?></button>
    <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/documents')) ?>"><?= e(__('documents.search_clear')) ?></a>
    <?php endif; ?>
</form>

<?php if ($canManage): ?>
<details class="panel treasury-form-panel" data-document-form-panel <?= $formOpen ? 'open' : '' ?>>
    <summary class="treasury-form-summary">
        <span class="treasury-form-summary-text">
            <span class="section-title"><?= e(__('documents.add')) ?></span>
            <span class="section-lede"><?= e(__('documents.add_lede')) ?></span>
        </span>
        <span class="treasury-form-chevron" aria-hidden="true"></span>
    </summary>
    <form
        class="treasury-form-body"
        method="post"
        action="<?= e(url('/documents')) ?>"
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
        <input type="hidden" name="uploaded_path" value="<?= e((string) ($values['uploaded_path'] ?? '')) ?>" data-doc-uploaded-path>
        <input type="hidden" name="uploaded_mime" value="<?= e((string) ($values['uploaded_mime'] ?? '')) ?>" data-doc-uploaded-mime>
        <?php
        $has_existing_file = false;
        require __DIR__ . '/_form_fields.php';
        ?>
        <div class="form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('documents.submit')) ?></button>
        </div>
    </form>
</details>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('documents.archive')) ?></h2>
            <p class="section-lede"><?= e(__('documents.archive_lede')) ?></p>
        </div>
    </div>
    <?php if ($documents === []): ?>
        <div class="empty-state">
            <?php if (trim((string) ($search_query ?? '')) !== ''): ?>
                <strong><?= e(__('documents.search_empty_title')) ?></strong>
                <?= e(__('documents.search_empty_text', ['q' => (string) $search_query])) ?>
            <?php else: ?>
                <strong><?= e(__('documents.empty_title')) ?></strong>
                <?= e(__('documents.empty_text')) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('documents.title_field')) ?></th>
                    <th><?= e(__('documents.number')) ?></th>
                    <th><?= e(__('documents.category')) ?></th>
                    <th><?= e(__('documents.language')) ?></th>
                    <th><?= e(__('documents.date')) ?></th>
                    <th><?= e(__('documents.status')) ?></th>
                    <th><?= e(__('documents.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                            <?php foreach ($documents as $doc): ?>
                                <?php
                                $lang = trim((string) ($doc['language'] ?? ''));
                                $langLabel = $lang !== '' ? __('documents.language_' . $lang) : '—';
                                if ($lang !== '' && $langLabel === 'documents.language_' . $lang) {
                                    $langLabel = $lang;
                                }
                                $docId = (int) ($doc['id'] ?? 0);
                                $detailUrl = url('/documents/' . $docId);
                                $categoryLabel = '';
                                foreach ($categories as $category) {
                                    if ((string) $category['key'] === (string) ($doc['category'] ?? 'other')) {
                                        $categoryLabel = (string) $category['label'];
                                        break;
                                    }
                                }
                                ?>
                                <tr
                                    class="doc-row-editable"
                                        data-href="<?= e($detailUrl) ?>"
                                        tabindex="0"
                                        role="link"
                                        aria-label="<?= e(__('documents.view') . ': ' . (string) ($doc['title'] ?? '')) ?>"
                                >
                                    <td>
                                        <strong><?= e((string) ($doc['title'] ?? '')) ?></strong>
                                        <?php if (!empty($doc['summary'])): ?>
                                            <div class="muted"><?= e((string) $doc['summary']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($doc['document_number'] ?? '') ?: '—') ?></td>
                                    <td><span class="doc-category-badge"><?= e($categoryLabel !== '' ? $categoryLabel : (string) ($doc['category'] ?? '')) ?></span></td>
                                    <td><?= e($langLabel) ?></td>
                                    <td><?= e(format_date($doc['document_date'] ?? null) ?: '—') ?></td>
                                    <td><span class="doc-status doc-status-<?= e((string) ($doc['status'] ?? 'draft')) ?>"><?= e(__('documents.status_' . (string) ($doc['status'] ?? 'draft'))) ?></span></td>
                                    <td class="doc-row-actions" onclick="event.stopPropagation()">
                                        <?php if (!empty($doc['file_path'])): ?>
                                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/documents/' . $docId . '/file')) ?>" target="_blank" rel="noopener"><?= e(__('documents.open_file')) ?></a>
                                        <?php elseif (!$canManage): ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                        <?php if ($canManage): ?>
                                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/documents/' . $docId . '/edit')) ?>"><?= e(__('documents.edit')) ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
