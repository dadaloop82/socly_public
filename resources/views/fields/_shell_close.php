<?php
/** Closes standard field wrapper; optional hint. */
$hint = (string) ($hint ?? '');
$hintAttrs = is_array($hint_attrs ?? null) ? $hint_attrs : [];
?>
<?php if ($hint !== ''): ?>
    <p class="muted sf-field__hint"<?= \Socly\Support\Fields::attrs($hintAttrs) ?>><?= e($hint) ?></p>
<?php endif; ?>
</div>
