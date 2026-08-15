<?php
/**
 * Shared birth-place suggest field.
 *
 * @var string $name
 * @var string $value
 * @var bool $required
 * @var string $id
 * @var string $class
 */
$name = (string) ($name ?? 'birth_place');
$value = (string) ($value ?? '');
$required = !empty($required);
$id = (string) ($id ?? '');
$extraClass = trim((string) ($class ?? ''));
$fieldClass = trim('setup-field suggest-field geo-field ' . $extraClass);
?>
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
