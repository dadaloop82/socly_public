<?php
/**
 * Shared document create/edit fields.
 *
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<string> $languages
 * @var int|string $upload_max_mb
 * @var bool $has_existing_file
 */
$today = date('Y-m-d');
$selectedCategory = (string) ($values['category'] ?? 'minutes');
$selectedLanguage = (string) ($values['language'] ?? '');
$showNewCategory = $selectedCategory === '__new__';
$hasExistingFile = !empty($has_existing_file);
$fileName = (string) ($values['uploaded_name'] ?? '');
$fileReady = $hasExistingFile || trim((string) ($values['uploaded_path'] ?? '')) !== '';
?>
<div class="grid-3">
    <div>
        <label><?= e(__('documents.title_field')) ?> *</label>
        <input type="text" name="title" value="<?= e((string) ($values['title'] ?? '')) ?>" required maxlength="190">
    </div>
    <div>
        <label><?= e(__('documents.number')) ?></label>
        <input type="text" name="document_number" value="<?= e((string) ($values['document_number'] ?? '')) ?>" maxlength="80">
    </div>
    <div>
        <label><?= e(__('documents.date')) ?></label>
        <input type="date" name="document_date" value="<?= e((string) ($values['document_date'] ?? $today)) ?>">
    </div>
    <div>
        <label><?= e(__('documents.language')) ?></label>
        <select name="language">
            <option value=""><?= e(__('documents.language_none')) ?></option>
            <?php foreach ($languages as $lang): ?>
                <option value="<?= e($lang) ?>" <?= $selectedLanguage === $lang ? 'selected' : '' ?>><?= e(__('documents.language_' . $lang)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="grid-3">
    <div data-doc-category-wrap>
        <label><?= e(__('documents.category')) ?></label>
        <select name="category" data-doc-category>
            <?php if (!empty($category_groups) && is_array($category_groups)): ?>
                <?php foreach ($category_groups as $group): ?>
                    <optgroup label="<?= e((string) ($group['label'] ?? '')) ?>">
                        <?php foreach (($group['options'] ?? []) as $cat): ?>
                            <option value="<?= e($cat['key']) ?>" <?= $selectedCategory === $cat['key'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat['key']) ?>" <?= $selectedCategory === $cat['key'] ? 'selected' : '' ?>><?= e($cat['label']) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
            <option value="__new__" <?= $showNewCategory ? 'selected' : '' ?>><?= e(__('documents.category_new')) ?></option>
        </select>
        <div class="doc-new-category" data-doc-new-category <?= $showNewCategory ? '' : 'hidden' ?>>
            <label><?= e(__('documents.category_new_label')) ?></label>
            <input
                type="text"
                name="new_category"
                value="<?= e((string) ($values['new_category'] ?? '')) ?>"
                maxlength="80"
                placeholder="<?= e(__('documents.category_new_placeholder')) ?>"
                data-doc-new-category-input
                <?= $showNewCategory ? 'required' : '' ?>
            >
        </div>
    </div>
    <div>
        <label><?= e(__('documents.status')) ?></label>
        <select name="status">
            <?php foreach (\Socly\Services\DocumentService::STATUSES as $st): ?>
                <option value="<?= e($st) ?>" <?= ($values['status'] ?? 'draft') === $st ? 'selected' : '' ?>><?= e(__('documents.status_' . $st)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label><?= e(__('documents.visibility')) ?></label>
        <select name="visibility">
            <?php foreach (\Socly\Services\DocumentService::VISIBILITIES as $vis): ?>
                <option value="<?= e($vis) ?>" <?= ($values['visibility'] ?? 'internal') === $vis ? 'selected' : '' ?>><?= e(__('documents.visibility_' . $vis)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="grid-3">
    <div>
        <label><?= e(__('documents.expires_at')) ?></label>
        <input type="date" name="expires_at" value="<?= e((string) ($values['expires_at'] ?? '')) ?>">
        <p class="muted" style="margin:0.35rem 0 0;font-size:0.85rem"><?= e(__('documents.expires_at_hint')) ?></p>
    </div>
    <div>
        <label><?= e(__('documents.member_link')) ?></label>
        <select name="member_id">
            <option value="">—</option>
            <?php foreach (($members ?? []) as $member): ?>
                <option value="<?= (int) $member['id'] ?>" <?= (string) ($values['member_id'] ?? '') === (string) $member['id'] ? 'selected' : '' ?>>
                    <?= e(trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label><?= e(__('documents.sibling')) ?></label>
        <select name="sibling_document_id">
            <option value="">—</option>
            <?php foreach (($sibling_options ?? []) as $sib): ?>
                <option value="<?= (int) $sib['id'] ?>" <?= (string) ($values['sibling_document_id'] ?? '') === (string) $sib['id'] ? 'selected' : '' ?>>
                    <?= e((string) ($sib['title'] ?? '')) ?>
                    <?php if (!empty($sib['language'])): ?>
                        (<?= e((string) $sib['language']) ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="grid-3">
    <div class="doc-file-field<?= $fileReady ? ' is-uploaded' : '' ?>" data-doc-file-field>
        <label><?= e(__('documents.file')) ?></label>
        <div class="doc-file-row">
            <label class="btn btn-ghost file-btn">
                <span data-doc-pick-label><?= e($fileReady ? __('documents.change_file') : __('documents.choose_file')) ?></span>
                <input
                    type="file"
                    name="document_file"
                    accept=".pdf,image/jpeg,image/png,image/webp,application/pdf"
                    data-doc-file-input
                >
            </label>
            <div class="doc-file-meta" data-doc-file-meta>
                <strong class="doc-file-name" data-doc-file-name <?= $fileName === '' ? 'hidden' : '' ?>><?= e($fileName) ?></strong>
                <span class="doc-file-status muted" data-doc-file-status>
                    <?= e($fileReady ? __('documents.upload_ok') : __('documents.upload_idle')) ?>
                </span>
                <div class="doc-file-progress" data-doc-file-progress hidden>
                    <div class="doc-file-progress-track" aria-hidden="true">
                        <div class="doc-file-progress-bar" data-doc-file-progress-bar style="width:0%"></div>
                    </div>
                    <span class="doc-file-progress-pct" data-doc-file-progress-pct>0%</span>
                </div>
                <span class="doc-file-hint muted"><?= e(__('documents.upload_hint', ['max' => (string) ($upload_max_mb ?? 2)])) ?></span>
            </div>
        </div>
    </div>
</div>
<div>
    <label><?= e(__('documents.summary')) ?></label>
    <textarea name="summary" rows="3"><?= e((string) ($values['summary'] ?? '')) ?></textarea>
</div>
<?php if (!empty($document_id) && (int) $document_id > 0 && component_enabled('treasury') && can('treasury.manage')): ?>
    <p class="muted" style="margin-top:0.75rem">
        <a href="<?= e(url('/treasury?document_id=' . (int) $document_id)) ?>"><?= e(__('documents.link_treasury')) ?></a>
    </p>
<?php endif; ?>
