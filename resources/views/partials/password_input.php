<?php
/**
 * Password field with show/hide toggle (standard across SOCLY).
 *
 * @var string $name Input name
 * @var string|null $id Element id (defaults to name)
 * @var string|null $label Label text (empty = no label row)
 * @var bool $required
 * @var string $value
 * @var string $placeholder
 * @var string $autocomplete
 * @var int|null $minlength
 * @var string $wrapper_class CSS classes on the label wrapper
 * @var string $input_attrs Extra raw attributes on the input (e.g. data-smtp-password)
 * @var string|null $hint_attrs Raw attributes on the optional hint paragraph
 */
$name = (string) ($name ?? 'password');
$id = (string) ($id ?? $name);
$label = isset($label) ? (string) $label : (string) __('common.password');
$required = !empty($required);
$value = (string) ($value ?? '');
$placeholder = (string) ($placeholder ?? '');
$autocomplete = (string) ($autocomplete ?? 'current-password');
$minlength = isset($minlength) && (int) $minlength > 0 ? (int) $minlength : null;
$wrapperClass = trim('password-field ' . (string) ($wrapper_class ?? 'setup-field'));
$inputAttrs = trim((string) ($input_attrs ?? ''));
$hintAttrs = trim((string) ($hint_attrs ?? ''));
$tag = $label !== '' ? 'label' : 'div';
$star = $required ? ' *' : '';
?>
<<?= $tag ?> class="<?= e($wrapperClass) ?>" data-password-field>
    <?php if ($label !== ''): ?>
        <span><?= e($label) ?><?= e($star) ?></span>
    <?php endif; ?>
    <span class="password-input-wrap">
        <input
            type="password"
            name="<?= e($name) ?>"
            id="<?= e($id) ?>"
            value="<?= e($value) ?>"
            autocomplete="<?= e($autocomplete) ?>"
            <?= $placeholder !== '' ? 'placeholder="' . e($placeholder) . '"' : '' ?>
            <?= $required ? 'required' : '' ?>
            <?= $minlength !== null ? 'minlength="' . (int) $minlength . '"' : '' ?>
            <?= $inputAttrs !== '' ? $inputAttrs : '' ?>
        >
        <button
            type="button"
            class="password-toggle"
            data-password-toggle
            aria-label="<?= e(__('common.show_password')) ?>"
            aria-pressed="false"
            data-show-label="<?= e(__('common.show_password')) ?>"
            data-hide-label="<?= e(__('common.hide_password')) ?>"
        >
            <svg class="password-toggle-icon password-toggle-icon--show" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            <svg class="password-toggle-icon password-toggle-icon--hide" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15"/></svg>
        </button>
    </span>
    <?php if ($hintAttrs !== ''): ?>
        <p class="field-hint setup-field-hint" <?= $hintAttrs ?> hidden></p>
    <?php endif; ?>
</<?= $tag ?>>
