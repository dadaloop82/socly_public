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

?>

<?php
// A demo is not an installation to configure: the settings/permissions area is
// locked on purpose, so the generic “reserved area” wording would be misleading.
$isDemo = function_exists('is_demo_instance') && is_demo_instance();
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($isDemo ? __('errors.demo_403') : __('errors.403')) ?></h1>
        <p class="page-lede"><?= e($isDemo ? __('errors.demo_403_text') : __('auth.secure_area')) ?></p>
    </div>
    <div class="actions">
        <a class="btn" href="<?= e(url('/dashboard')) ?>"><?= e(__('common.back')) ?></a>
    </div>
</div>
