<?php
/** @var bool $needsSetup */
/** @var string $setupRequiredTitle */
/** @var string $setupRequiredI18nKey */
/** @var string $setupRequiredTextKey */
/** @var string $setupUrl */
$currentLocale = (string) app('translator')->getLocale();
$needsSetup = !empty($needsSetup);
$setupRequiredTitle = trim((string) ($setupRequiredTitle ?? ''));
$setupRequiredI18nKey = trim((string) ($setupRequiredI18nKey ?? 'auth.setup_required_title_first'));
$setupRequiredTextKey = trim((string) ($setupRequiredTextKey ?? 'auth.setup_required_text'));
if ($setupRequiredTextKey === '') {
    $setupRequiredTextKey = 'auth.setup_required_text';
}
$setupUrl = trim((string) ($setupUrl ?? ''));
if ($setupUrl === '') {
    $setupUrl = url('/setup');
}
?>

<div class="auth-lang" data-i18n-endpoint="<?= e(url('/i18n/messages')) ?>">
    <div class="auth-lang-rotate" aria-live="polite">
        <span class="auth-lang-rotate-item h2 is-active" data-rotate-lang="it">Scegli la lingua</span>
        <span class="auth-lang-rotate-item h2" data-rotate-lang="de">Sprache wählen</span>
        <span class="auth-lang-rotate-item h2" data-rotate-lang="en">Choose language</span>
    </div>
    <?php
    $loginLocaleCardOptions = [];
    foreach (['it' => 'Italiano', 'de' => 'Deutsch', 'en' => 'English'] as $loc => $label) {
        $loginLocaleCardOptions[] = [
            'value' => $loc,
            'label' => $label,
            'flag' => locale_flag_url($loc),
            'label_attrs' => ['data-lang-label' => $loc],
        ];
    }
    ?>
    <fieldset role="radiogroup" aria-label="<?= e(__('auth.choose_language')) ?>" data-lang-group>
        <legend class="visually-hidden"><?= e(__('auth.choose_language')) ?></legend>
        <?= field('locale_cards', [
            'name' => 'lang',
            'value' => $currentLocale,
            'options' => $loginLocaleCardOptions,
            'attrs' => ['data-lang-radio' => true],
            'grid_class' => 'auth-locale-grid',
            'card_class' => 'setup-locale-card auth-locale-card',
        ]) ?>
    </fieldset>
</div>

<?php if ($needsSetup): ?>
    <h1 class="h1" data-i18n="<?= e($setupRequiredI18nKey) ?>"><?= e($setupRequiredTitle !== '' ? $setupRequiredTitle : __('auth.setup_required_title_first')) ?></h1>
    <p class="lede auth-setup-lede" data-i18n="<?= e($setupRequiredTextKey) ?>"><?= e(__($setupRequiredTextKey)) ?></p>
    <a class="btn btn-block" href="<?= e($setupUrl) ?>" data-i18n="auth.setup_configure_button" data-setup-lang-link><?= e(__('auth.setup_configure_button')) ?></a>
    <?php
      $extraCta = code_path('resources/views/auth/extra_setup_cta.php');
      if (is_file($extraCta) && !str_contains($setupUrl, '/install')) {
          require $extraCta;
      }
    ?>
<?php else: ?>
    <h1 class="h1" data-i18n="auth.welcome_title"><?= e(__('auth.welcome_title')) ?></h1>
    <p class="lede" data-i18n="auth.welcome_text"><?= e(__('auth.welcome_text')) ?></p>

    <form method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <?= field('hidden', [
            'name' => 'lang',
            'value' => $currentLocale,
            'attrs' => ['data-login-lang' => true],
        ]) ?>
        <label for="email" data-i18n="auth.email"><?= e(__('auth.email')) ?></label>
        <?= field('email', [
            'name' => 'email',
            'id' => 'email',
            'label' => '',
            'value' => (string) old('email'),
            'required' => true,
            'placeholder' => (string) __('auth.email_placeholder'),
            'autocomplete' => 'username',
            'input_class' => 'auth-field-control',
            'attrs' => [
                'autofocus' => true,
                'data-i18n-placeholder' => 'auth.email_placeholder',
            ],
        ]) ?>

        <label for="password" data-i18n="auth.password"><?= e(__('auth.password')) ?></label>
        <?= field('password', [
            'name' => 'password',
            'id' => 'password',
            'label' => '',
            'required' => true,
            'placeholder' => (string) __('auth.password_placeholder'),
            'autocomplete' => 'current-password',
            'wrapper_class' => 'password-field--bare',
            'input_attrs' => 'data-i18n-placeholder="auth.password_placeholder"',
        ]) ?>

        <?= field('checkbox', [
            'name' => 'remember',
            'id' => 'remember',
            'label' => (string) __('auth.remember'),
            'check_value' => '1',
            'wrapper_class' => 'auth-remember',
        ]) ?>

        <div class="auth-links">
            <a href="<?= e(url('/password/forgot')) ?>" data-i18n="auth.forgot"><?= e(__('auth.forgot')) ?></a>
        </div>

        <button class="btn btn-block" type="submit" data-i18n="auth.submit_full"><?= e(__('auth.submit_full')) ?></button>
    </form>
<?php endif; ?>
