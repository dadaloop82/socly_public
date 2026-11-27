<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/**
 * Bare layout for pages opened inside an add-on dialog (iframe): no sidebar, no topbar and only the
 * inner document scrolls, so the popup never shows two scrollbars.
 *
 * @var string $content
 * @var bool|null $embed_saved when true the parent page is told to refresh its data
 */
$branding = app()->branding();
$assocName = (string) ($branding['name'] ?? 'SOCLY');
?>
<!DOCTYPE html>
<html lang="<?= e(app('translator')->getLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(($title ?? 'SOCLY') . ' · ' . $assocName) ?></title>
    <link rel="icon" href="<?= e(socly_icon_url()) ?>" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,560;9..144,700&family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>?v=<?= e((string) (@filemtime(code_path('public/assets/css/app.css')) ?: time())) ?>">
    <style>:root {
      <?= brand_root_style_decls($branding['primary'] ?? null, $branding['accent'] ?? null) ?>
    }</style>
</head>
<body class="app-embed"<?= !empty($embed_saved) ? ' data-embed-saved="1"' : '' ?>>
<main class="main app-embed-main">
    <?php // Validation errors and confirmations must be readable inside the dialog: the app layout
          // shows them, the bare layout used by add-on popups used to swallow them, so a rejected
          // save looked like a form that simply reloaded (and the error flash surfaced later on the
          // parent page, already gone). ?>
    <?php if ($msg = flash('success')): ?>
        <div
            class="alert alert-success alert-dismissible"
            data-flash-success
            data-auto-dismiss-ms="6000"
            role="status"
        >
            <span class="alert-body"><?= e(is_string($msg) ? $msg : '') ?></span>
            <button type="button" class="alert-dismiss" aria-label="<?= e(__('common.close')) ?>">×</button>
        </div>
    <?php endif; ?>
    <?php if ($errors = flash('errors')): ?>
        <div class="alert alert-error" data-flash-error role="alert">
            <?php if (is_array($errors)): foreach ($errors as $err): ?>
                <div><?= e(is_array($err) ? implode(', ', $err) : (string) $err) ?></div>
            <?php endforeach; else: ?>
                <div><?= e((string) $errors) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?= $content ?>
</main>
<script src="<?= e(asset('js/confirm.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/confirm.js')) ?: time())) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/app.js')) ?: time())) ?>"></script>
</body>
</html>
