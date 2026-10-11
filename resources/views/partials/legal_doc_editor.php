<?php
/**
 * Tabbed legal document editor (IT / DE / EN).
 *
 * @var string $namePrefix Field name prefix, e.g. privacy or statute
 * @var array{it?:string,de?:string,en?:string} $values
 * @var string $placeholder Placeholder for Italian textarea
 * @var bool $showRuntsBanner Show RUNTS extraction info banner
 */
$namePrefix = trim((string) ($namePrefix ?? ''));
$values = is_array($values ?? null) ? $values : [];
$placeholder = (string) ($placeholder ?? '');
$showRuntsBanner = !empty($showRuntsBanner);
$locales = [
    'it' => ['label' => 'Italiano', 'flag' => locale_flag_url('it')],
    'de' => ['label' => 'Deutsch', 'flag' => locale_flag_url('de')],
    'en' => ['label' => 'English', 'flag' => locale_flag_url('en')],
];
?>
<div class="legal-doc-editor" data-legal-doc-editor data-legal-prefix="<?= e($namePrefix) ?>">
    <?php if ($showRuntsBanner): ?>
        <?php
        $runtsBannerKey = $namePrefix === 'privacy'
            ? 'setup.legal_runts_extract_banner_privacy'
            : 'setup.legal_runts_extract_banner';
        ?>
        <div class="legal-doc-runts-banner" role="status" data-legal-runts-banner>
            <span class="legal-doc-runts-banner-icon" aria-hidden="true">ℹ</span>
            <p class="legal-doc-runts-banner-text"><?= e(__($runtsBannerKey)) ?></p>
        </div>
    <?php endif; ?>
    <div class="legal-doc-toolbar">
        <div class="legal-doc-tablist" role="tablist" aria-label="<?= e($namePrefix) ?>">
            <?php $first = true; foreach ($locales as $code => $meta): ?>
                <button
                    type="button"
                    class="legal-doc-tab"
                    role="tab"
                    id="legal-tab-<?= e($namePrefix) ?>-<?= e($code) ?>"
                    aria-controls="legal-panel-<?= e($namePrefix) ?>-<?= e($code) ?>"
                    aria-selected="<?= $first ? 'true' : 'false' ?>"
                    tabindex="<?= $first ? '0' : '-1' ?>"
                    data-legal-tab="<?= e($code) ?>"
                >
                    <img src="<?= e($meta['flag']) ?>" width="22" height="16" alt="" loading="lazy" decoding="async">
                    <span class="legal-doc-tab-label"><?= e($meta['label']) ?></span>
                    <span class="legal-doc-tab-check" data-legal-tab-check="<?= e($code) ?>" hidden aria-hidden="true">✓</span>
                </button>
            <?php $first = false; endforeach; ?>
        </div>
        <div class="legal-doc-toolbar-actions">
            <button
                type="button"
                class="btn btn-ghost btn-sm legal-doc-translate-btn"
                data-legal-translate
                data-translate-url="<?= e(url('/api/translate')) ?>"
                data-msg-busy="<?= e(__('settings.legal_translating')) ?>"
                data-msg-empty="<?= e(__('settings.legal_translate_empty')) ?>"
                data-msg-fail="<?= e(__('settings.legal_translate_fail')) ?>"
            ><?= e(__('settings.legal_translate')) ?></button>
            <button
                type="button"
                class="legal-doc-expand-btn"
                data-legal-expand
                data-msg-expand="<?= e(__('setup.legal_expand')) ?>"
                data-msg-collapse="<?= e(__('setup.legal_collapse')) ?>"
                aria-expanded="false"
                aria-label="<?= e(__('setup.legal_expand')) ?>"
                title="<?= e(__('setup.legal_expand')) ?>"
            >
                <svg class="legal-doc-expand-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M7 14H5v5h5v-2H7v-3zm0-4h2V7h3V5H5v5h2zm10 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
                </svg>
            </button>
        </div>
    </div>
    <?php $first = true; foreach ($locales as $code => $meta): ?>
        <div
            class="legal-doc-panel"
            role="tabpanel"
            id="legal-panel-<?= e($namePrefix) ?>-<?= e($code) ?>"
            aria-labelledby="legal-tab-<?= e($namePrefix) ?>-<?= e($code) ?>"
            data-legal-panel="<?= e($code) ?>"
            <?= $first ? '' : 'hidden' ?>
        >
            <textarea
                name="<?= e($namePrefix) ?>_<?= e($code) ?>"
                rows="5"
                data-legal-textarea="<?= e($code) ?>"
                <?= $code === 'it' && $placeholder !== '' ? 'placeholder="' . e($placeholder) . '"' : '' ?>
            ><?= e((string) ($values[$code] ?? '')) ?></textarea>
        </div>
    <?php $first = false; endforeach; ?>
</div>
