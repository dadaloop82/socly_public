<?php

/**
 * Terms of Service and General Conditions of Use (Socly legal document).
 *
 * Rendered inside the modal shell of partials/terms_dialog.php, but usable
 * standalone on any legal page. Texts come from the `terms.*` keys of the
 * language files; the `data-i18n` hooks keep the document in sync with the
 * live language switcher of the setup wizard / login page.
 */

$termsSections = ['s1', 's2', 's3', 's4', 's5', 's6'];
?>
<article class="terms-doc" data-terms-doc>
    <p class="terms-doc-meta" data-i18n="terms.subtitle"><?= e(__('terms.subtitle')) ?></p>
    <p class="terms-doc-version" data-i18n="terms.version"><?= e(__('terms.version')) ?></p>
    <p class="terms-doc-intro" data-i18n="terms.intro"><?= e(__('terms.intro')) ?></p>
    <?php foreach ($termsSections as $termsSection): ?>
        <section class="terms-doc-section<?= $termsSection === 's3' ? ' terms-doc-section-strong' : '' ?>">
            <h3 class="terms-doc-heading" data-i18n="terms.<?= e($termsSection) ?>_title"><?= e(__('terms.' . $termsSection . '_title')) ?></h3>
            <p class="terms-doc-text" data-i18n="terms.<?= e($termsSection) ?>_text"><?= e(__('terms.' . $termsSection . '_text')) ?></p>
        </section>
    <?php endforeach; ?>
</article>
