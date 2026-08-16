<?php $isEdit = $user !== null; $selected = $user['permission_keys'] ?? []; ?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="page-lede"><?= e(__('users.form_lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/users')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>
<form class="form-card" method="post" action="<?= e($isEdit ? url('/users/'.$user['id']) : url('/users')) ?>" data-leave-guard>
    <?= csrf_field() ?>
    <div class="grid-2">
        <div>
            <label>Name</label>
            <input type="text" name="name" value="<?= e((string)old('name', $user['name'] ?? '')) ?>" required>
        </div>
        <div>
            <label>Email</label>
            <input type="email" name="email" value="<?= e((string)old('email', $user['email'] ?? '')) ?>" required>
        </div>
        <div>
            <label><?= e(__('users.locale')) ?></label>
            <select name="locale">
                <?php foreach (['it','de','en'] as $loc): ?>
                    <option value="<?= $loc ?>" <?= old('locale', $user['locale'] ?? 'it')===$loc?'selected':'' ?>><?= e(match ($loc) { 'it' => '🇮🇹 Italiano', 'de' => '🇩🇪 Deutsch', default => '🇬🇧 English' }) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <?php if (!$isEdit || empty($user['is_system_admin'])): ?>
                <label class="checkbox-row" style="margin-top:1.8rem"><input type="checkbox" name="is_active" value="1" <?= old('is_active', $user['is_active'] ?? 1) ? 'checked' : '' ?>> <?= e(__('users.active')) ?></label>
            <?php else: ?>
                <p class="muted" style="margin-top:1.8rem"><?= e(__('users.system_admin')) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="grid-2">
        <div>
            <?= view_partial('partials/password_input', [
                'name' => 'password',
                'label' => (string) __('users.password') . ($isEdit ? ' (' . (string) __('users.password_optional') . ')' : ''),
                'required' => !$isEdit,
                'autocomplete' => 'new-password',
                'wrapper_class' => 'password-field',
            ]) ?>
        </div>
        <div>
            <?= view_partial('partials/password_input', [
                'name' => 'password_confirmation',
                'label' => (string) __('users.password_confirmation'),
                'required' => !$isEdit,
                'autocomplete' => 'new-password',
                'wrapper_class' => 'password-field',
            ]) ?>
        </div>
    </div>
    <?php if (!$isEdit || empty($user['is_system_admin'])): ?>
        <h3 class="h3"><?= e(__('users.permissions')) ?></h3>
        <?php foreach ($permissions as $perm): ?>
            <label class="checkbox-row">
                <input type="checkbox" name="permissions[]" value="<?= e($perm['key']) ?>" <?= in_array($perm['key'], old('permissions', $selected) ?: [], true) ? 'checked' : '' ?>>
                <?= e($perm['key']) ?>
            </label>
        <?php endforeach; ?>
    <?php endif; ?>
    <div class="actions form-actions form-actions-end">
        <a class="btn btn-ghost" href="<?= e(url('/users')) ?>"><?= e(__('common.cancel')) ?></a>
        <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
    </div>
</form>
<?php if ($isEdit && empty($user['is_system_admin'])): ?>
<form method="post" action="<?= e(url('/users/'.$user['id'].'/delete')) ?>" onsubmit="return confirm('OK?')" style="margin-top:1rem">
    <?= csrf_field() ?>
    <button class="btn btn-danger" type="submit"><?= e(__('members.delete')) ?></button>
</form>
<?php endif; ?>
