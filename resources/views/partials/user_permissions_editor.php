<?php
/**
 * Grouped permission checkboxes with optional role templates.
 *
 * @var list<string> $selected Selected permission keys
 * @var bool $disabled Disable all inputs (system admin edit)
 * @var string $inputPrefix Optional name prefix for nested forms
 */
$selected = is_array($selected ?? null) ? $selected : (is_array(old('permissions')) ? old('permissions') : []);
$disabled = !empty($disabled);
$inputPrefix = trim((string) ($inputPrefix ?? ''));
$roleTemplates = \Socly\Services\UserService::roleTemplates();
?>
<div class="user-permissions-editor" data-user-permissions-editor>
    <p class="setup-hint muted"><?= e(__('users.permissions_hint')) ?></p>
    <?php if (!$disabled && $roleTemplates !== []): ?>
        <div class="user-permissions-templates">
            <p class="setup-subhead"><?= e(__('users.role_templates')) ?></p>
            <div class="user-permissions-template-list">
                <?php foreach ($roleTemplates as $tpl): ?>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm"
                        data-permission-template="<?= e((string) ($tpl['key'] ?? '')) ?>"
                        data-permission-keys="<?= e(json_encode(array_values($tpl['permissions'] ?? []), JSON_UNESCAPED_UNICODE)) ?>"
                        title="<?= e(__((string) ($tpl['description_key'] ?? ''))) ?>"
                    ><?= e(__((string) ($tpl['label_key'] ?? ''))) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <div class="user-permissions-groups">
        <?php foreach (\Socly\Support\Permission::groups() as $group): ?>
            <section class="user-permissions-group">
                <h4 class="user-permissions-group-title"><?= e(__((string) ($group['label_key'] ?? ''))) ?></h4>
                <div class="user-permissions-group-items">
                    <?php foreach ($group['keys'] as $permKey): ?>
                        <?php $checked = in_array($permKey, $selected, true); ?>
                        <label class="setup-check setup-check-inline user-permission-item">
                            <input
                                type="checkbox"
                                name="<?= e($inputPrefix) ?>permissions[]"
                                value="<?= e($permKey) ?>"
                                <?= $checked ? 'checked' : '' ?>
                                <?= $disabled ? 'disabled' : '' ?>
                                data-permission-key="<?= e($permKey) ?>"
                            >
                            <span><?= e(__(\Socly\Support\Permission::labelKey($permKey))) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
