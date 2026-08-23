<?php
/**
 * Tabbed legal document editor (IT / DE / EN).
 *
 * @var string $namePrefix Field name prefix, e.g. privacy or statute
 * @var array{it?:string,de?:string,en?:string} $values
 * @var string $placeholder Placeholder for Italian textarea
 */
$namePrefix = trim((string) ($namePrefix ?? ''));
$values = is_array($values ?? null) ? $values : [];
$placeholder = (string) ($placeholder ?? '');
$locales = [
    'it' => ['label' => 'Italiano', 'flag' => locale_flag_url('it')],
    'de' => ['label' => 'Deutsch', 'flag' => locale_flag_url('de')],
    'en' => ['label' => 'English', 'flag' => locale_flag_url('en')],
];
?>
<div class="legal-doc-editor" data-legal-doc-editor data-legal-prefix="<?= e($namePrefix) ?>">
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
                <span><?= e($meta['label']) ?></span>
            </button>
        <?php $first = false; endforeach; ?>
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
                rows="10"
                data-legal-textarea="<?= e($code) ?>"
                <?= $code === 'it' && $placeholder !== '' ? 'placeholder="' . e($placeholder) . '"' : '' ?>
            ><?= e((string) ($values[$code] ?? '')) ?></textarea>
        </div>
    <?php $first = false; endforeach; ?>
    <div class="legal-doc-actions">
        <button
            type="button"
            class="btn btn-ghost btn-sm"
            data-legal-translate
            data-translate-url="<?= e(url('/api/translate')) ?>"
            data-msg-busy="<?= e(__('settings.legal_translating')) ?>"
            data-msg-empty="<?= e(__('settings.legal_translate_empty')) ?>"
            data-msg-fail="<?= e(__('settings.legal_translate_fail')) ?>"
        ><?= e(__('settings.legal_translate')) ?></button>
    </div>
</div>
