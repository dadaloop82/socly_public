<h1 class="h1"><?= e(__('auth.reset_title')) ?></h1>
<p class="lede"><?= e(__('auth.reset_text')) ?></p>

<form method="post" action="<?= e(url('/password/reset')) ?>">
    <?= csrf_field() ?>
    <?= field('hidden', ['name' => 'token', 'value' => (string) ($token ?? '')]) ?>
    <?= field('hidden', [
        'name' => 'email',
        'value' => (string) ($email ?? old('email')),
    ]) ?>

    <?= field('password', [
        'name' => 'password',
        'id' => 'password',
        'label' => (string) __('auth.new_password'),
        'required' => true,
        'autocomplete' => 'new-password',
        'wrapper_class' => 'password-field--bare',
    ]) ?>

    <?= field('password', [
        'name' => 'password_confirmation',
        'id' => 'password_confirmation',
        'label' => (string) __('auth.new_password_confirmation'),
        'required' => true,
        'autocomplete' => 'new-password',
        'wrapper_class' => 'password-field--bare',
    ]) ?>

    <button class="btn btn-block" type="submit"><?= e(__('auth.reset_submit')) ?></button>
</form>
<p class="auth-links" style="margin-top:1.1rem;justify-content:center">
    <a href="<?= e(url('/login')) ?>"><?= e(__('auth.back_to_login')) ?></a>
</p>
