<?php $isEdit = $user !== null; $selected = $user['permission_keys'] ?? []; $returnTo = (string) ($returnTo ?? ''); ?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="page-lede"><?= e(__('users.form_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e($returnTo === 'settings' ? url('/settings#users') : url('/users')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>
<form class="form-card setup-admin-account" method="post" action="<?= e($isEdit ? url('/users/'.$user['id']) : url('/users')) ?>" data-leave-guard data-user-create-form>
    <?= csrf_field() ?>
    <?php if ($returnTo !== ''): ?>
        <?= field('hidden', ['name' => 'return', 'value' => $returnTo]) ?>
    <?php endif; ?>
    <?= field('email', [
        'name' => 'email',
        'label' => (string) __('users.email'),
        'value' => (string) old('email', $user['email'] ?? ''),
        'required' => true,
        'autocomplete' => 'off',
        'context' => 'setup',
    ]) ?>
    <?php if (!$isEdit): ?>
        <div class="setup-equal-row">
            <?= field('password', [
                'name' => 'password',
                'label' => (string) __('users.password'),
                'required' => true,
                'autocomplete' => 'new-password',
                'minlength' => 8,
                'context' => 'setup',
            ]) ?>
            <?= field('password', [
                'name' => 'password_confirmation',
                'label' => (string) __('users.password_confirmation'),
                'required' => true,
                'autocomplete' => 'new-password',
                'minlength' => 8,
                'context' => 'setup',
            ]) ?>
        </div>
        <div class="setup-password-tools">
            <button type="button" class="btn btn-ghost btn-sm" data-password-generate><?= e(__('setup.admin_password_generate')) ?></button>
            <span class="setup-hint muted"><?= e(__('setup.admin_password_rules')) ?></span>
        </div>
        <div class="setup-password-strength">
            <p class="setup-field-label"><?= e(__('setup.admin_password_strength')) ?></p>
            <div class="password-complexity" data-password-complexity aria-live="polite">
                <span></span><span></span><span></span><span></span>
            </div>
        </div>
    <?php else: ?>
        <div class="setup-equal-row">
            <?= field('password', [
                'name' => 'password',
                'label' => (string) __('users.password') . ' (' . (string) __('users.password_optional') . ')',
                'required' => false,
                'autocomplete' => 'new-password',
                'minlength' => 8,
                'context' => 'setup',
            ]) ?>
            <?= field('password', [
                'name' => 'password_confirmation',
                'label' => (string) __('users.password_confirmation'),
                'required' => false,
                'autocomplete' => 'new-password',
                'minlength' => 8,
                'context' => 'setup',
            ]) ?>
        </div>
        <div class="setup-password-strength">
            <p class="setup-field-label"><?= e(__('setup.admin_password_strength')) ?></p>
            <div class="password-complexity" data-password-complexity aria-live="polite">
                <span></span><span></span><span></span><span></span>
            </div>
        </div>
    <?php endif; ?>
    <fieldset>
        <legend class="setup-field-label"><?= e(__('users.locale')) ?></legend>
        <?= field('locale_cards', [
            'name' => 'locale',
            'value' => (string) old('locale', $user['locale'] ?? 'it'),
            'locales' => ['it', 'de', 'en'],
            'required' => true,
        ]) ?>
    </fieldset>
    <?php if (!$isEdit || empty($user['is_system_admin'])): ?>
        <?= field('checkbox', [
            'name' => 'is_active',
            'label' => (string) __('users.active'),
            'value' => old('is_active', $user['is_active'] ?? 1) ? '1' : '',
            'check_value' => '1',
            'wrapper_class' => 'setup-check setup-check-prominent',
        ]) ?>
    <?php else: ?>
        <p class="setup-hint muted"><?= e(__('users.system_admin')) ?></p>
    <?php endif; ?>
    <?php if (!$isEdit || empty($user['is_system_admin'])): ?>
        <?= view_partial('partials/user_permissions_editor', [
            'selected' => old('permissions', $selected) ?: [],
            'disabled' => false,
        ]) ?>
    <?php endif; ?>
    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e($returnTo === 'settings' ? url('/settings#users') : url('/users')) ?>"><?= e(__('common.cancel')) ?></a>
        <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
    </div>
</form>
<?php if ($isEdit && empty($user['is_system_admin'])): ?>
<form method="post" action="<?= e(url('/users/'.$user['id'].'/delete')) ?>" data-confirm="<?= e(__('users.confirm_delete')) ?>" data-confirm-danger="1" style="margin-top:1rem">
    <?= csrf_field() ?>
    <?php if ($returnTo !== ''): ?>
        <?= field('hidden', ['name' => 'return', 'value' => $returnTo]) ?>
    <?php endif; ?>
    <button class="btn btn-danger" type="submit"><?= e(__('members.delete')) ?></button>
</form>
<?php endif; ?>
