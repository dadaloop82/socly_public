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
                    <th><?= e(__('users.locale')) ?></th>
                    <th><?= e(__('users.active')) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($panelUsers as $u): ?>
                    <tr>
                        <td><?= e((string) ($u['email'] ?? '')) ?></td>
                        <td><?= e(strtoupper((string) ($u['locale'] ?? 'it'))) ?></td>
                        <td><?= !empty($u['is_active']) ? e(__('common.yes')) : e(__('common.no')) ?></td>
                        <td>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/users/' . (int) ($u['id'] ?? 0) . '/edit?return=settings')) ?>">
                                <?= e(__('users.edit')) ?>
                            </a>
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
        <input type="hidden" name="return" value="settings">
        <label class="setup-field">
            <span><?= e(__('users.email')) ?> *</span>
            <input type="email" name="email" value="<?= e((string) old('email')) ?>" required autocomplete="off">
        </label>
        <div class="setup-equal-row">
            <?= view_partial('partials/password_input', [
                'name' => 'password',
                'label' => (string) __('users.password'),
                'required' => true,
                'autocomplete' => 'new-password',
                'minlength' => 8,
            ]) ?>
            <?= view_partial('partials/password_input', [
                'name' => 'password_confirmation',
                'label' => (string) __('users.password_confirmation'),
                'required' => true,
                'autocomplete' => 'new-password',
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
        <fieldset class="setup-locale-grid">
            <legend class="setup-field-label"><?= e(__('users.locale')) ?></legend>
            <?php foreach (['it', 'de', 'en'] as $loc): ?>
                <label class="setup-locale-card">
                    <input type="radio" name="locale" value="<?= $loc ?>" <?= old('locale', 'it') === $loc ? 'checked' : '' ?> required>
                    <img src="<?= e(locale_flag_url($loc)) ?>" width="28" height="21" alt="" loading="lazy" decoding="async">
                    <span><?= e(match ($loc) { 'it' => 'Italiano', 'de' => 'Deutsch', default => 'English' }) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <label class="setup-check setup-check-prominent">
            <input type="checkbox" name="is_active" value="1" <?= old('is_active', '1') ? 'checked' : '' ?>>
            <span><?= e(__('users.active')) ?></span>
        </label>
        <?php if (!empty($mailReady)): ?>
            <p class="setup-hint muted"><?= e(__('users.welcome_email_hint')) ?></p>
        <?php endif; ?>
        <?= view_partial('partials/user_permissions_editor', ['selected' => old('permissions', [])]) ?>
        <div class="actions form-actions form-actions-end">
            <button class="btn" type="submit"><?= e(__('users.create_submit')) ?></button>
        </div>
    </form>
</div>
