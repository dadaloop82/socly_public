<?php
/** @var string $content */
/** @var bool $showNewsWidget */
/** @var string $newsApiUrl */
$branding = app()->branding();
$assocName = trim((string) ($branding['name'] ?? ''));
$hasAssoc = $assocName !== '' && strcasecmp($assocName, 'SOCLY') !== 0;
$year = date('Y');
 $version = app_version();
 $siteName = (string) __('app.name');

 if ($hasAssoc) {
     $assocLegal = trim((string) ($branding['legal_name'] ?? ''));
     $assocPlain = assoc_capitalize_name($assocName) . ($assocLegal !== '' ? ' ' . $assocLegal : '');
     $assocPart = trim((string) __('auth.for') . ' ' . $assocPlain);
 } else {
     $assocPart = trim((string) __('auth.for_new_prefix') . (string) __('auth.for_new_highlight') . (string) __('auth.for_new_suffix'));
 }
 $computedTitle = $siteName . ' · ' . $assocPart . ' · © ' . $year . ' · v' . $version;
?>
<!DOCTYPE html>
<html lang="<?= e(app('translator')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= view_partial('partials/password_i18n_meta') ?>
    <title><?= e($computedTitle) ?></title>
    <link rel="icon" href="<?= e(socly_icon_url()) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/css/app.css')) ?: time())) ?>">
    <style>:root {
      <?= brand_root_style_decls($branding['primary'] ?? null, $branding['accent'] ?? null) ?>
      --brand-socly: #0B4875;
    }</style>
</head>
<body
    <?php if (!empty($demoLoginNotice)): ?>
    data-demo-login-notice="1"
    data-demo-expires="<?= e((string) ($demoLoginNotice['expires_label'] ?? '')) ?>"
    data-demo-login-notice-text="<?= e(__('auth.demo_login_notice')) ?>"
    data-demo-login-notice-ok="<?= e(__('auth.demo_login_notice_ok')) ?>"
    <?php endif; ?>
    data-max-upload-bytes="<?= (int) upload_limit_bytes() ?>"
>
<div class="auth-shell">
    <div class="auth-main">
        <aside class="auth-brand">
            <div class="auth-brand-inner">
                <?= socly_mark_img('auth-mark', 'SOCLY', 'light') ?>
                <?php if ($hasAssoc): ?>
                    <?= assoc_logo_img('auth-assoc-logo') ?>
                <?php endif; ?>
                <h1 class="auth-product">
                    <?php if ($hasAssoc): ?>
                        <span class="auth-product-line">
                            <span class="per" data-i18n="auth.for"><?= e(__('auth.for')) ?></span>
                            <?= assoc_lockup_html(['class' => 'assoc-lockup-auth']) ?>
                        </span>
                    <?php else: ?>
                        <span class="auth-product-line">
                            <span class="per" data-i18n="auth.for_new_prefix"><?= e(__('auth.for_new_prefix')) ?></span>
                            <span class="assoc-new" data-i18n="auth.for_new_highlight"><?= e(__('auth.for_new_highlight')) ?></span>
                            <span class="per" data-i18n="auth.for_new_suffix"><?= e(__('auth.for_new_suffix')) ?></span>
                        </span>
                    <?php endif; ?>
                </h1>
                <p class="auth-motto" data-i18n-html="auth.motto"><?= with_auth_asterisk(e(__('auth.motto'))) ?></p>
                <p class="auth-desc" data-i18n-html="auth.product_description"><?= with_auth_asterisk(with_socly_word(__('auth.product_description'))) ?></p>
            </div>
            <?php if (!empty($showNewsWidget)): ?>
            <div
                class="auth-news-slot"
                data-auth-news
                data-news-api="<?= e((string) ($newsApiUrl ?? socly_news_api_url())) ?>"
                data-news-unavailable="<?= e(__('auth.news_unavailable')) ?>"
                data-news-read-more="<?= e(__('auth.news_read_more')) ?>"
            ></div>
            <?php endif; ?>
            <div class="auth-brand-meta">
                <p class="auth-license-note" data-i18n-html="auth.license_note"><?= with_auth_asterisk(e(__('auth.license_note'))) ?></p>
                <p class="auth-updates-line">
                    <button type="button" class="auth-check-updates" data-auth-check-updates data-updates-endpoint="<?= e(url('/api/updates/check')) ?>">
                        <span data-i18n="auth.check_updates"><?= e(__('auth.check_updates')) ?></span>
                    </button>
                </p>
            </div>
        </aside>
        <section class="auth-panel">
            <div class="auth-card">
                <?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= e((string)$msg) ?></div><?php endif; ?>
                <?php if ($errors = flash('errors')): ?>
                    <div class="alert alert-error">
                        <?php foreach ((array)$errors as $err): ?><div><?= e(is_array($err)?implode(', ',$err):(string)$err) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?= $content ?>
            </div>
        </section>
    </div>
    <dialog class="setup-exit-dialog auth-updates-dialog" data-auth-updates-dialog
        data-i18n-checking="<?= e(__('auth.updates_checking')) ?>"
        data-i18n-none-title="<?= e(__('auth.updates_none_title')) ?>"
        data-i18n-none-text="<?= e(__('auth.updates_none_text')) ?>"
        data-i18n-error-title="<?= e(__('auth.updates_error_title')) ?>"
        data-i18n-error-text="<?= e(__('auth.updates_error_text')) ?>"
        data-i18n-available-title="<?= e(__('updates.available_title')) ?>"
        data-i18n-available-text="<?= e(__('updates.available_text')) ?>"
        data-i18n-manual-hint="<?= e(__('updates.manual_hint')) ?>"
        data-i18n-notes="<?= e(__('updates.notes')) ?>"
        data-i18n-download="<?= e(__('updates.download')) ?>"
        data-i18n-guide="<?= e(__('updates.guide')) ?>"
        data-i18n-current-label="<?= e(__('updates.current_label')) ?>"
        data-i18n-remote-label="<?= e(__('updates.remote_label')) ?>"
        data-i18n-commit-label="<?= e(__('updates.commit_label')) ?>"
        data-i18n-released-label="<?= e(__('updates.released_label')) ?>"
    >
        <div class="setup-exit-shell auth-updates-shell">
            <h2 class="setup-exit-title" data-auth-updates-title></h2>
            <p class="setup-exit-text" data-auth-updates-text></p>
            <dl class="auth-updates-meta" data-auth-updates-meta hidden></dl>
            <div class="setup-exit-actions auth-updates-actions" data-auth-updates-actions hidden></div>
            <div class="setup-exit-actions">
                <button type="button" class="btn" data-auth-updates-close data-i18n="common.cancel"><?= e(__('common.cancel')) ?></button>
            </div>
        </div>
    </dialog>
    <footer class="auth-footer">
        <div class="auth-footer-brand">
            <?= socly_word_html('socly-word-footer') ?>
            <?php if ($hasAssoc): ?>
                <span class="auth-footer-assoc">· <span data-i18n="auth.for"><?= e(__('auth.for')) ?></span> <?= assoc_lockup_html(['class' => 'assoc-lockup-footer']) ?></span>
            <?php else: ?>
                <span class="auth-footer-assoc">· <span data-i18n="auth.for_new_prefix"><?= e(__('auth.for_new_prefix')) ?></span><span class="footer-new" data-i18n="auth.for_new_highlight"><?= e(__('auth.for_new_highlight')) ?></span><span data-i18n="auth.for_new_suffix"><?= e(__('auth.for_new_suffix')) ?></span></span>
            <?php endif; ?>
            <span class="auth-footer-meta">· © <?= e($year) ?></span>
            <span class="auth-footer-version">· v<?= e(app_version()) ?></span>
        </div>
        <nav>
            <span data-i18n-html="auth.footer_tagline"><?= credit_line() ?></span>
        </nav>
    </footer>
</div>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/app.js')) ?: time())) ?>"></script>
</body>
</html>
