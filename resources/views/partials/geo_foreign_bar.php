<?php
/**
 * Toggle foreign country for a geo scope (disables Italian CAP/comune checks).
 *
 * @var string|null $flag_name Hidden input name (default address_foreign)
 * @var string|null $hint Optional hint override
 * @var string $variant 'bar' (button above fields) or 'inline' (compact pill in field header)
 * @var string|null $label_off Label when off (inline / button)
 * @var string|null $label_on Label when on (inline / button)
 */
$flagName = (string) ($flag_name ?? 'address_foreign');
$hintText = (string) ($hint ?? __('setup.address_foreign_hint'));
$variant = (($variant ?? 'bar') === 'inline') ? 'inline' : 'bar';
$labelOff = (string) ($label_off ?? __('setup.address_foreign'));
$labelOn = (string) ($label_on ?? __('setup.address_foreign_back'));
?>
<?php if ($variant === 'inline'): ?>
<div class="geo-foreign-inline">
    <button
        type="button"
        class="geo-foreign-pill"
        data-geo-foreign-toggle
        data-label-on="<?= e($labelOn) ?>"
        data-label-off="<?= e($labelOff) ?>"
        aria-pressed="false"
        title="<?= e($hintText) ?>"
    ><?= e($labelOff) ?></button>
</div>
<input type="hidden" name="<?= e($flagName) ?>" value="0" data-geo-foreign-flag>
<?php else: ?>
<div class="geo-foreign-bar" data-geo-foreign-bar>
    <button type="button" class="btn btn-ghost btn-sm" data-geo-foreign-toggle
            data-label-on="<?= e($labelOn) ?>"
            data-label-off="<?= e($labelOff) ?>"
            aria-pressed="false"><?= e($labelOff) ?></button>
    <input type="hidden" name="<?= e($flagName) ?>" value="0" data-geo-foreign-flag>
    <p class="setup-hint muted geo-foreign-hint" data-geo-foreign-hint hidden><?= e($hintText) ?></p>
</div>
<?php endif; ?>
