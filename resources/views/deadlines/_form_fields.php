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
        <label><?= e(__('deadlines.recurrence')) ?></label>
        <select name="recurrence">
            <?php foreach (['none', 'monthly', 'yearly'] as $rec): ?>
                <option value="<?= e($rec) ?>" <?= (string) ($values['recurrence'] ?? 'none') === $rec ? 'selected' : '' ?>><?= e(__('deadlines.recurrence_' . $rec)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="grid-2">
    <div>
        <label><?= e(__('deadlines.assignee_role')) ?></label>
        <input type="text" name="assignee_role" value="<?= e((string) ($values['assignee_role'] ?? '')) ?>" maxlength="40" placeholder="<?= e(__('deadlines.assignee_role_placeholder')) ?>">
    </div>
    <div>
        <label><?= e(__('deadlines.notify_days')) ?></label>
        <input type="text" name="notify_days" value="<?= e((string) ($values['notify_days'] ?? '30,7')) ?>" maxlength="40" placeholder="30,7">
        <p class="muted" style="margin:0.35rem 0 0;font-size:0.85rem"><?= e(__('deadlines.notify_days_hint')) ?></p>
    </div>
</div>
<div>
    <label><?= e(__('deadlines.notes')) ?></label>
    <input type="text" name="notes" value="<?= e((string) ($values['notes'] ?? '')) ?>">
</div>
