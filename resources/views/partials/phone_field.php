<?php
/**
 * Phone input with international dial prefix select.
 *
 * @var string $name Input name (stored value is combined +prefix national)
 * @var string $value Current stored phone value
 * @var bool $required
 * @var string $id Optional input id
 * @var string $class Extra wrapper classes
 * @var string $default_dial Default dial code without + (default 39)
 */
$name = (string) ($name ?? 'phone');
$value = (string) ($value ?? '');
$required = !empty($required);
$id = trim((string) ($id ?? ''));
$extraClass = trim((string) ($class ?? ''));
$defaultDial = preg_replace('/\D+/', '', (string) ($default_dial ?? '39')) ?: '39';
$parsed = parse_stored_phone($value, $defaultDial);
$dial = $parsed['dial'] !== '' ? $parsed['dial'] : $defaultDial;
$national = $parsed['national'];
$invalidMsg = __('validation.phone');
$hint = __('members.phone_hint');
?>
<div class="phone-field<?= $extraClass !== '' ? ' ' . e($extraClass) : '' ?>" data-phone-field>
    <select
        class="phone-dial-select"
        data-phone-dial
        aria-label="<?= e(__('setup.field_phone_prefix')) ?>"
    >
        <?php foreach (phone_dial_codes() as $row): ?>
            <?php $code = (string) ($row['dial'] ?? ''); ?>
            <option
                value="<?= e($code) ?>"
                data-flag="<?= e(dial_flag_url((string) ($row['iso'] ?? 'IT'))) ?>"
                <?= $dial === $code ? 'selected' : '' ?>
            >+<?= e($code) ?> <?= e((string) ($row['name'] ?? '')) ?></option>
        <?php endforeach; ?>
    </select>
    <input
        type="tel"
        name="<?= e($name) ?>"
        value="<?= e($national) ?>"
        class="phone-national-input"
        data-phone-input
        inputmode="tel"
        autocomplete="tel"
        placeholder="<?= e($hint) ?>"
        data-hint="<?= e($hint) ?>"
        data-invalid="<?= e($invalidMsg) ?>"
        data-default-dial="<?= e($defaultDial) ?>"
        <?= $required ? 'required' : '' ?>
        <?= $id !== '' ? 'id="' . e($id) . '"' : '' ?>
    >
</div>
