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
 * @var bool $foreign_inline Compact checkbox on the label row (right of title)
 */
$name = (string) ($name ?? 'birth_place');
$value = (string) ($value ?? '');
$required = !empty($required);
$id = (string) ($id ?? '');
$extraClass = trim((string) ($class ?? ''));
$showForeignToggle = !isset($foreign_toggle) || (bool) $foreign_toggle;
$foreignInline = !empty($foreign_inline);
$withScope = isset($with_scope) ? (bool) $with_scope : ($showForeignToggle || $foreignInline);
$flagName = (string) ($flag_name ?? 'address_foreign');
$fieldClass = trim('setup-field suggest-field geo-field ' . $extraClass);
$wrapOpen = $withScope || ($showForeignToggle && !$foreignInline);
?>
<?php if ($wrapOpen): ?>
<div class="geo-birth-place<?= $withScope ? '' : ' geo-birth-place-bare' ?>"<?= $withScope ? ' data-geo-scope' : '' ?>>
    <?php if ($showForeignToggle && !$foreignInline): ?>
        <?= view_partial('partials/geo_foreign_bar', [
            'flag_name' => $flagName,
            'hint' => __('setup.address_foreign_hint'),
        ]) ?>
    <?php endif; ?>
<?php endif; ?>
<div class="<?= e($fieldClass) ?>">
    <div class="geo-field-label-row geo-field-label-row--split">
        <?php if ($id !== ''): ?>
            <label class="geo-field-text-label" for="<?= e($id) ?>"><?= e(__('setup.field_birth_place')) ?><?= $required ? ' *' : '' ?></label>
        <?php else: ?>
            <span class="geo-field-text-label"><?= e(__('setup.field_birth_place')) ?><?= $required ? ' *' : '' ?></span>
        <?php endif; ?>
        <?php if ($showForeignToggle && $foreignInline): ?>
            <?= view_partial('partials/geo_foreign_bar', [
                'flag_name' => $flagName,
                'variant' => 'inline',
                'label_off' => (string) ($foreign_label ?? __('members.birth_foreign')),
                'label_on' => __('members.birth_foreign_on'),
            ]) ?>
        <?php endif; ?>
    </div>
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
</div>
<?php if ($wrapOpen): ?>
</div>
<?php endif; ?>
