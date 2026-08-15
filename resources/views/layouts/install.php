<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="<?= e(app('translator')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= view_partial('partials/password_i18n_meta') ?>
    <title><?= e($title ?? __('install.title')) ?></title>
    <link rel="icon" href="<?= e(socly_icon_url()) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/css/app.css')) ?: time())) ?>">
</head>
<body>
<div class="install-wrap">
    <div class="install-shell">
        <div class="install-top">
            <?= socly_mark_img('install-mark') ?>
            <div>
                <div class="muted"><?= credit_line() ?></div>
            </div>
        </div>
        <div class="install-card">
            <?php if ($errors = flash('errors')): ?>
                <div class="alert alert-error">
                    <?php foreach ((array)$errors as $err): ?><div><?= e(is_array($err)?implode(', ',$err):(string)$err) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </div>
    </div>
</div>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/app.js')) ?: time())) ?>"></script>
</body>
</html>
