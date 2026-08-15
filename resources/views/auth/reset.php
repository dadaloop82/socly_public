<h1 class="h1"><?= e(__('auth.reset_title')) ?></h1>
<p class="lede"><?= e(__('auth.reset_text')) ?></p>

<form method="post" action="<?= e(url('/password/reset')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e((string)($token ?? '')) ?>">
    <input type="hidden" name="email" value="<?= e((string)($email ?? old('email'))) ?>">

    <label for="password"><?= e(__('auth.new_password')) ?></label>
    <?= view_partial('partials/password_input', [
        'name' => 'password',
        'id' => 'password',
        'label' => '',
        'required' => true,
        'autocomplete' => 'new-password',
        'wrapper_class' => 'password-field password-field--bare',
    ]) ?>

    <label for="password_confirmation"><?= e(__('auth.new_password_confirmation')) ?></label>
    <?= view_partial('partials/password_input', [
        'name' => 'password_confirmation',
        'id' => 'password_confirmation',
        'label' => '',
        'required' => true,
        'autocomplete' => 'new-password',
        'wrapper_class' => 'password-field password-field--bare',
    ]) ?>

    <button class="btn btn-block" type="submit"><?= e(__('auth.reset_submit')) ?></button>
</form>
<p class="auth-links" style="margin-top:1.1rem;justify-content:center">
    <a href="<?= e(url('/login')) ?>"><?= e(__('auth.back_to_login')) ?></a>
</p>
