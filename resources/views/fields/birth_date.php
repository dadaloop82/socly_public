<?php
use Socly\Support\Fields;

$icon = $icon !== '' ? $icon : 'calendar';
$val = is_string($value) ? $value : '';
$ageYears = null;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val)) {
    try {
        $born = new \DateTimeImmutable($val);
        $today = new \DateTimeImmutable('today');
        if ($born <= $today) {
            $ageYears = (int) $born->diff($today)->y;
        }
    } catch (\Exception) {
        $ageYears = null;
    }
}
$ageText = $ageYears !== null ? __('members.age_years', ['age' => (string) $ageYears]) : '';
$showAge = !isset($show_age) || (bool) $show_age;

$attrs = array_merge([
    'id' => $id,
    'name' => $name,
    'type' => 'date',
    'value' => $val,
    'class' => trim('sf-field__control input-emphasis ' . $input_class),
    'data-birth-date' => true,
    'data-birth-allow-minor' => !empty($allow_minor) || !isset($allow_minor) ? '1' : null,
    'required' => $required,
    'disabled' => $disabled,
    'min' => isset($min) ? (string) $min : null,
    'max' => isset($max) ? (string) $max : null,
], $attrs);

$modifier = trim(($modifier ?? '') . ' field-block--birth-date sf-field--birth-date');
echo view_partial('fields/_shell_open', array_merge(get_defined_vars(), ['modifier' => $modifier]));
?>
<?php if ($showAge): ?>
    <div class="birth-date-row sf-field__birth-row">
        <input<?= Fields::attrs($attrs) ?>>
        <output
            class="birth-age"
            data-birth-age
            data-template="<?= e(__('members.age_years')) ?>"
            aria-live="polite"
            <?= $ageText === '' ? 'hidden' : '' ?>
        ><?= e($ageText) ?></output>
    </div>
<?php else: ?>
    <input<?= Fields::attrs($attrs) ?>>
<?php endif; ?>
<?= view_partial('fields/_shell_close', get_defined_vars()) ?>
