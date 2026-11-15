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
 * Shared “unsaved changes” dialog used by initLeaveGuards().
 * The labels live here (translated server-side), so the JavaScript carries no UI strings;
 * the data-msg-* attributes are the fallback for browsers without <dialog> support.
 */
?>
<dialog class="member-leave-dialog" data-leave-dialog
        data-msg-leave-text="<?= e(__('common.leave_text')) ?>"
        data-msg-leave-save="<?= e(__('common.leave_save')) ?>"
        data-msg-leave-stay="<?= e(__('common.leave_stay')) ?>">
    <div class="member-leave-shell">
        <h3 class="section-title"><?= e(__('common.leave_title')) ?></h3>
        <p class="member-leave-text"><?= e(__('common.leave_text')) ?></p>
        <div class="member-leave-actions">
            <button type="button" class="btn btn-ghost" data-leave-stay><?= e(__('common.leave_stay')) ?></button>
            <button type="button" class="btn btn-ghost" data-leave-discard><?= e(__('common.leave_discard')) ?></button>
            <button type="button" class="btn" data-leave-save><?= e(__('common.leave_save')) ?></button>
        </div>
    </div>
</dialog>
