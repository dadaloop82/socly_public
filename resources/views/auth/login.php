<?php
$currentLocale = (string) app('translator')->getLocale();
$needsSetup = !empty($needsSetup);
?>

<div class="auth-lang" data-i18n-endpoint="<?= e(url('/i18n/messages')) ?>">
    <div class="auth-lang-rotate" aria-live="polite">
        <span class="auth-lang-rotate-item h2 is-active" data-rotate-lang="it">Scegli la lingua</span>
        <span class="auth-lang-rotate-item h2" data-rotate-lang="de">Sprache wählen</span>
        <span class="auth-lang-rotate-item h2" data-rotate-lang="en">Choose language</span>
    </div>
    <div
        class="auth-lang-flags"
        role="group"
        data-lang-select
        data-lang-value="<?= e($currentLocale) ?>"
        aria-label="<?= e(__('auth.choose_language')) ?>"
    >
        <button
            type="button"
            class="auth-lang-flag<?= $currentLocale === 'it' ? ' is-active' : '' ?>"
            data-set-lang="it"
            lang="it"
            title="Italiano"
            aria-label="Italiano"
            aria-pressed="<?= $currentLocale === 'it' ? 'true' : 'false' ?>"
        >🇮🇹</button>
        <button
            type="button"
            class="auth-lang-flag<?= $currentLocale === 'de' ? ' is-active' : '' ?>"
            data-set-lang="de"
            lang="de"
            title="Deutsch"
            aria-label="Deutsch"
            aria-pressed="<?= $currentLocale === 'de' ? 'true' : 'false' ?>"
        >🇩🇪</button>
        <button
            type="button"
            class="auth-lang-flag<?= $currentLocale === 'en' ? ' is-active' : '' ?>"
            data-set-lang="en"
            lang="en"
            title="English"
            aria-label="English"
            aria-pressed="<?= $currentLocale === 'en' ? 'true' : 'false' ?>"
        >🇬🇧</button>
    </div>
</div>

<?php if ($needsSetup): ?>
    <h1 class="h1" data-i18n="auth.setup_required_title"><?= e(__('auth.setup_required_title')) ?></h1>
    <p class="lede auth-setup-lede" data-i18n="auth.setup_required_text"><?= e(__('auth.setup_required_text')) ?></p>
    <a class="btn btn-block" href="<?= e(url('/setup')) ?>" data-i18n="auth.setup_configure_button" data-setup-lang-link><?= e(__('auth.setup_configure_button')) ?></a>
    <?php
      $extraCta = code_path('resources/views/auth/extra_setup_cta.php');
      if (is_file($extraCta)) {
          require $extraCta;
      }
    ?>
<?php else: ?>
    <h1 class="h1" data-i18n="auth.welcome_title"><?= e(__('auth.welcome_title')) ?></h1>
    <p class="lede" data-i18n="auth.welcome_text"><?= e(__('auth.welcome_text')) ?></p>

    <form method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="lang" value="<?= e($currentLocale) ?>" data-login-lang>
        <label for="email" data-i18n="auth.email"><?= e(__('auth.email')) ?></label>
        <input id="email" type="email" name="email" value="<?= e((string)old('email')) ?>" required autofocus autocomplete="username" placeholder="<?= e(__('auth.email_placeholder')) ?>" data-i18n-placeholder="auth.email_placeholder">

        <label for="password" data-i18n="auth.password"><?= e(__('auth.password')) ?></label>
        <?= view_partial('partials/password_input', [
            'name' => 'password',
            'id' => 'password',
            'label' => '',
            'required' => true,
            'placeholder' => (string) __('auth.password_placeholder'),
            'autocomplete' => 'current-password',
            'wrapper_class' => 'password-field password-field--bare',
            'input_attrs' => 'data-i18n-placeholder="auth.password_placeholder"',
        ]) ?>

        <label class="checkbox-row auth-remember">
            <input type="checkbox" name="remember" value="1">
            <span data-i18n="auth.remember"><?= e(__('auth.remember')) ?></span>
        </label>

        <div class="auth-links">
            <a href="<?= e(url('/password/forgot')) ?>" data-i18n="auth.forgot"><?= e(__('auth.forgot')) ?></a>
        </div>

        <button class="btn btn-block" type="submit" data-i18n="auth.submit_full"><?= e(__('auth.submit_full')) ?></button>
    </form>
<?php endif; ?>
