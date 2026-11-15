<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

$setupOpen = !empty($setupOpen);
try {
    if (!$setupOpen && app()->isInstalled()) {
        $setupOpen = !app(\Socly\Services\SetupService::class)->isComplete();
    }
} catch (Throwable) {
}
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title h1"><?= e(__('errors.419')) ?></h1>
        <p class="page-lede"><?= e($setupOpen ? __('errors.419_setup_text') : __('errors.419_text')) ?></p>
    </div>
    <div class="actions">
        <?php if ($setupOpen): ?>
            <a class="btn" href="<?= e(url('/setup')) ?>"><?= e(__('auth.setup_configure_button')) ?></a>
        <?php else: ?>
            <a class="btn" href="<?= e(url('/')) ?>"><?= e(__('common.back')) ?></a>
        <?php endif; ?>
    </div>
</div>
