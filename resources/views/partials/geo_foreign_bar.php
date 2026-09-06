<?php
/**
 * Toggle “Stato estero” for a geo scope (disables Italian CAP/comune checks).
 *
 * @var string|null $flag_name Optional hidden input name (default address_foreign)
 * @var string|null $hint Optional hint override
 */
$flagName = (string) ($flag_name ?? 'address_foreign');
$hintText = (string) ($hint ?? __('setup.address_foreign_hint'));
?>
<div class="geo-foreign-bar" data-geo-foreign-bar>
    <button type="button" class="btn btn-ghost btn-sm" data-geo-foreign-toggle
            data-label-on="<?= e(__('setup.address_foreign_back')) ?>"
            data-label-off="<?= e(__('setup.address_foreign')) ?>"
            aria-pressed="false"><?= e(__('setup.address_foreign')) ?></button>
    <input type="hidden" name="<?= e($flagName) ?>" value="0" data-geo-foreign-flag>
    <p class="setup-hint muted geo-foreign-hint" data-geo-foreign-hint hidden><?= e($hintText) ?></p>
</div>
