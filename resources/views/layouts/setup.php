<?php
/** @var string $content */
$branding = app()->branding();
$assocName = trim((string) ($branding['name'] ?? ''));
$hasAssoc = $assocName !== '' && strcasecmp($assocName, 'SOCLY') !== 0;
$year = date('Y');
$assetVer = (string) max(
    (int) (@filemtime(dirname(__DIR__, 3) . '/public/assets/css/app.css') ?: time()),
    (int) (@filemtime(dirname(__DIR__, 3) . '/public/assets/js/app.js') ?: time())
);
?>
<!DOCTYPE html>
<html lang="<?= e(app('translator')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= view_partial('partials/password_i18n_meta') ?>
    <title><?= e(($title ?? __('setup.title')) . ' · SOCLY') ?></title>
    <link rel="icon" href="<?= e(socly_icon_url()) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e($assetVer) ?>">
    <style>:root {
      <?= brand_root_style_decls($branding['primary'] ?? null, $branding['accent'] ?? null) ?>
      --brand-socly: #0B4875;
    }
    html {
      transition: --brand-primary 0.9s ease, --brand-accent 0.9s ease;
    }
    /* Hide before JS so texts appear only with the fade-in (no flash) */
    .setup-card {
      opacity: 0;
      transform: translateY(28px);
    }
    .setup-card [data-setup-line] {
      opacity: 0;
      transform: translateY(28px);
    }
    .setup-card .setup-progress span {
      transform: scaleX(0);
      transform-origin: left center;
    }
    </style>
    <noscript>
      <style>
        .setup-card,
        .setup-card [data-setup-line] {
          opacity: 1 !important;
          transform: none !important;
        }
        .setup-card .setup-progress span {
          transform: scaleX(1) !important;
        }
      </style>
    </noscript>
</head>
<body class="setup-body"
      data-cities-url="<?= e(url('/api/geo/cities')) ?>"
      data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
      data-msg-city-first="<?= e(__('members.address_city_first')) ?>">
<div class="setup-page">
    <main class="setup-shell">
        <?= $content ?>
    </main>
    <footer class="auth-footer setup-footer">
        <div class="auth-footer-brand">
            <?= socly_word_html('socly-word-footer') ?>
            <?php if ($hasAssoc): ?>
                <span class="auth-footer-assoc">· <?= e(__('auth.for')) ?> <?= assoc_lockup_html(['class' => 'assoc-lockup-footer']) ?></span>
            <?php else: ?>
                <span class="auth-footer-assoc">· <?= e(__('auth.for_new_prefix')) ?><span class="footer-new"><?= e(__('auth.for_new_highlight')) ?></span><?= e(__('auth.for_new_suffix')) ?></span>
            <?php endif; ?>
            <span class="auth-footer-meta">· © <?= e($year) ?></span>
            <span class="auth-footer-version">· v<?= e(app_version()) ?></span>
        </div>
        <nav>
            <span><?= credit_line() ?></span>
        </nav>
    </footer>
</div>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e($assetVer) ?>"></script>
</body>
</html>
