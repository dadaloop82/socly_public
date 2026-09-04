<?php
/**
 * @var array<string,mixed> $values
 * @var list<array{key:string,label:string,builtin?:bool}> $categories
 * @var list<array{key:string,label:string,options:list<array{key:string,label:string}>}>|null $category_groups
 * @var list<array> $members
 * @var bool $show_status
 */
$selectedCategory = (string) ($values['category'] ?? 'general');
$showNewCategory = $selectedCategory === '__new__';
$showStatus = !empty($show_status);
$categoryGroups = $category_groups ?? null;
?>
<div class="grid-3">
    <div>
        <label><?= e(__('deadlines.title_field')) ?> *</label>
        <input type="text" name="title" value="<?= e((string) ($values['title'] ?? '')) ?>" required maxlength="190">
    </div>
    <div>
        <label><?= e(__('deadlines.due_date')) ?> *</label>
        <input type="date" name="due_date" value="<?= e((string) ($values['due_date'] ?? '')) ?>" required>
    </div>
    <div data-deadline-category-wrap>
        <label><?= e(__('deadlines.category')) ?></label>
        <select name="category" data-deadline-category>
            <?php if (is_array($categoryGroups) && $categoryGroups !== []): ?>
                <?php foreach ($categoryGroups as $group): ?>
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
            <option value="__new__" <?= $showNewCategory ? 'selected' : '' ?>><?= e(__('deadlines.category_new')) ?></option>
        </select>
        <div class="doc-new-category" data-deadline-new-category <?= $showNewCategory ? '' : 'hidden' ?>>
            <label><?= e(__('deadlines.category_new_label')) ?></label>
            <input
                type="text"
                name="new_category"
                value="<?= e((string) ($values['new_category'] ?? '')) ?>"
                maxlength="80"
                placeholder="<?= e(__('deadlines.category_new_placeholder')) ?>"
                data-deadline-new-category-input
                <?= $showNewCategory ? 'required' : '' ?>
            >
        </div>
    </div>
</div>
<div class="grid-<?= $showStatus ? '3' : '2' ?>">
    <div data-deadline-member-wrap>
        <label class="checkbox-row" style="margin-bottom:0.5rem">
            <input type="checkbox" value="1" data-deadline-member-toggle <?= trim((string) ($values['member_id'] ?? '')) !== '' ? 'checked' : '' ?>>
            <span><?= e(__('deadlines.member_involved')) ?></span>
        </label>
        <div data-deadline-member-fields <?= trim((string) ($values['member_id'] ?? '')) !== '' ? '' : 'hidden' ?>>
            <label><?= e(__('deadlines.member')) ?></label>
            <select name="member_id" data-deadline-member-select>
                <option value="">—</option>
                <?php foreach ($members as $member): ?>
                    <option value="<?= (int) $member['id'] ?>" <?= (string) ($values['member_id'] ?? '') === (string) $member['id'] ? 'selected' : '' ?>>
                        <?= e(trim(($member['last_name'] ?? '') . ' ' . ($member['first_name'] ?? ''))) ?>
                        <?php if (!empty($member['member_number'])): ?>
                            (<?= e((string) $member['member_number']) ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if ($showStatus): ?>
        <div>
            <label><?= e(__('deadlines.status')) ?></label>
            <select name="status">
                <?php foreach (['open', 'done', 'dismissed'] as $st): ?>
                    <option value="<?= e($st) ?>" <?= ($values['status'] ?? 'open') === $st ? 'selected' : '' ?>><?= e(__('deadlines.status_' . $st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php else: ?>
        <input type="hidden" name="status" value="open">
    <?php endif; ?>
    <div>
        <label><?= e(__('deadlines.notes')) ?></label>
        <input type="text" name="notes" value="<?= e((string) ($values['notes'] ?? '')) ?>">
    </div>
</div>
