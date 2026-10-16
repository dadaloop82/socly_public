<?php

/**
 * Modal shell for the Terms of Service (onboarding disclaimer + settings).
 *
 * Trigger: any element with `data-terms-open` (optionally set to the dialog id).
 * Closes via `data-terms-close`, the Esc key or a click on the backdrop.
 * Wiring lives in initTermsDialogs() (public/assets/js/app.js).
 *
 * @var string $dialogId Optional id, useful when a page hosts more dialogs.
 */
$dialogId = trim((string) ($dialogId ?? ''));
?>
<dialog class="setup-exit-dialog auth-terms-dialog" data-terms-dialog<?= $dialogId !== '' ? ' id="' . e($dialogId) . '"' : '' ?>>
    <div class="setup-exit-shell auth-terms-shell">
        <h2 class="setup-exit-title" data-i18n="terms.title"><?= e(__('terms.title')) ?></h2>
        <div class="auth-terms-body terms-doc-scroll">
            <?= view_partial('partials/terms_of_service') ?>
        </div>
        <div class="setup-exit-actions">
            <button type="button" class="btn" data-terms-close data-i18n="common.close"><?= e(__('common.close')) ?></button>
        </div>
    </div>
</dialog>
