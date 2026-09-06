<?php
/**
 * Shared birth-place suggest field.
 *
 * @var string $name
 * @var string $value
 * @var bool $required
 * @var string $id
 * @var string $class
 * @var bool $foreign_toggle Show “Stato estero” (default true when not nested)
 * @var bool $with_scope Wrap in data-geo-scope (default follows foreign_toggle)
 * @var string $flag_name Hidden flag input name
 */
$name = (string) ($name ?? 'birth_place');
$value = (string) ($value ?? '');
$required = !empty($required);
$id = (string) ($id ?? '');
$extraClass = trim((string) ($class ?? ''));
$showForeignToggle = !isset($foreign_toggle) || (bool) $foreign_toggle;
$withScope = isset($with_scope) ? (bool) $with_scope : $showForeignToggle;
$flagName = (string) ($flag_name ?? 'address_foreign');
$fieldClass = trim('setup-field suggest-field geo-field ' . $extraClass);
$wrapOpen = $withScope || $showForeignToggle;
?>
<?php if ($wrapOpen): ?>
<div class="geo-birth-place<?= $withScope ? '' : ' geo-birth-place-bare' ?>"<?= $withScope ? ' data-geo-scope' : '' ?>>
    <?php if ($showForeignToggle): ?>
        <?= view_partial('partials/geo_foreign_bar', [
            'flag_name' => $flagName,
            'hint' => __('setup.address_foreign_hint'),
        ]) ?>
    <?php endif; ?>
<?php endif; ?>
<label class="<?= e($fieldClass) ?>"<?= $id !== '' ? ' for="' . e($id) . '"' : '' ?>>
    <span><?= e(__('setup.field_birth_place')) ?><?= $required ? ' *' : '' ?></span>
    <div class="suggest-wrap">
        <input
            type="text"
            name="<?= e($name) ?>"
            value="<?= e($value) ?>"
            data-birth-place-input
            autocomplete="off"
            placeholder="<?= e(__('members.birth_place_placeholder')) ?>"
            <?= $id !== '' ? 'id="' . e($id) . '"' : '' ?>
            <?= $required ? 'required' : '' ?>
        >
        <div class="suggest-list" data-birth-place-suggest hidden></div>
    </div>
</label>
<?php if ($wrapOpen): ?>
</div>
<?php endif; ?>
