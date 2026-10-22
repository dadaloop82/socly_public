<?php
/**
 * Inline users list + create form for settings accordion.
 *
 * @var list<array<string,mixed>> $panelUsers
 * @var bool $mailReady
 */
$panelUsers = is_array($panelUsers ?? null) ? $panelUsers : [];
?>
<?php if ($panelUsers !== []): ?>
    <div class="table-wrap embedded settings-users-table">
        <table>
            <thead>
                <tr>
                    <th><?= e(__('users.email')) ?></th>
                    <th><?= e(__('users.role')) ?></th>
                    <th><?= e(__('users.locale')) ?></th>
                    <th><?= e(__('users.active')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($panelUsers as $u): ?>
                    <tr>
                        <td><?= e((string) ($u['email'] ?? '')) ?></td>
                        <td><?= e(\Socly\Services\UserService::roleLabel($u['role'] ?? null) ?: __('users.role_custom')) ?></td>
                        <td><?= e(strtoupper((string) ($u['locale'] ?? 'it'))) ?></td>
                        <td><?= !empty($u['is_active']) ? e(__('common.yes')) : e(__('common.no')) ?></td>
                        <td class="doc-row-actions">
                            <?= row_actions([
                                [
                                    'icon' => 'edit',
                                    'label' => __('users.edit'),
                                    'href' => url('/users/' . (int) ($u['id'] ?? 0) . '/edit?return=settings'),
                                ],
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p class="setup-hint muted"><?= e(__('users.none_yet')) ?></p>
<?php endif; ?>

<div class="setup-membership-card setup-membership-card-new settings-user-create">
    <h3 class="setup-subhead"><?= e(__('users.create')) ?></h3>
    <p class="setup-hint muted"><?= e(__('users.create_hint')) ?></p>
    <form method="post" action="<?= e(url('/users')) ?>" data-leave-guard data-user-create-form>
        <?= csrf_field() ?>
        <?= field('hidden', ['name' => 'return', 'value' => 'settings']) ?>
        <?= field('email', [
            'name' => 'email',
            'label' => (string) __('users.email'),
            'value' => (string) old('email'),
            'required' => true,
            'context' => 'setup',
            'autocomplete' => 'off',
        ]) ?>
        <div class="setup-equal-row">
            <?= field('password', [
                'name' => 'password',
                'label' => (string) __('users.password'),
                'required' => true,
                'autocomplete' => 'new-password',
                'context' => 'setup',
                'minlength' => 8,
            ]) ?>
            <?= field('password', [
                'name' => 'password_confirmation',
                'label' => (string) __('users.password_confirmation'),
                'required' => true,
                'autocomplete' => 'new-password',
                'context' => 'setup',
                'minlength' => 8,
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
        <fieldset>
            <legend class="setup-field-label"><?= e(__('users.locale')) ?></legend>
            <?= field('locale_cards', [
                'name' => 'locale',
                'value' => (string) old('locale', 'it'),
                'locales' => ['it', 'de', 'en'],
                'required' => true,
            ]) ?>
        </fieldset>
        <?= field('checkbox', [
            'name' => 'is_active',
            'label' => (string) __('users.active'),
            'checked' => (bool) old('is_active', '1'),
            'context' => 'setup',
            'label_class' => 'checkbox-row setup-check setup-check-prominent',
        ]) ?>
        <?php if (!empty($mailReady)): ?>
            <p class="setup-hint muted"><?= e(__('users.welcome_email_hint')) ?></p>
        <?php endif; ?>
        <?= view_partial('partials/user_role_picker', ['selected' => old('role', '')]) ?>
        <?= view_partial('partials/user_permissions_editor', ['selected' => old('permissions', [])]) ?>
        <div class="actions form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('users.create_submit')) ?></button>
        </div>
    </form>
</div>
