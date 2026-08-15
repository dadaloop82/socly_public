<h1 class="h1"><?= e(__('auth.forgot_title')) ?></h1>
<p class="lede"><?= e(__('auth.forgot_text')) ?></p>

<form method="post" action="<?= e(url('/password/forgot')) ?>">
    <?= csrf_field() ?>
    <label for="email"><?= e(__('auth.email')) ?></label>
    <input id="email" type="email" name="email" value="<?= e((string)old('email')) ?>" required autofocus autocomplete="username">
    <button class="btn btn-block" type="submit"><?= e(__('auth.forgot_submit')) ?></button>
</form>
<p class="auth-links" style="margin-top:1.1rem;justify-content:center">
    <a href="<?= e(url('/login')) ?>"><?= e(__('auth.back_to_login')) ?></a>
</p>
