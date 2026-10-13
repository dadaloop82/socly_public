<?php
/**
 * Opens a standard field wrapper + optional label.
 *
 * Expects normalized props from Fields::normalizeProps plus:
 * @var string $modifier Extra BEM modifier classes on the wrapper
 * @var string $data_attrs Extra data-* already as HTML string (optional)
 */
use Socly\Support\Fields;

$type = (string) ($type ?? 'text');
$context = (string) ($context ?? 'default');
$modifier = trim((string) ($modifier ?? ''));
$wrapperClass = Fields::wrapperClass($context, (string) ($wrapper_class ?? ''), $modifier);
$id = (string) ($id ?? '');
$label = (string) ($label ?? '');
$required = !empty($required);
$icon = (string) ($icon ?? '');
$dataField = (string) ($data_field ?? '');
$requiredFlag = isset($required_flag) ? (string) $required_flag : ($required ? '1' : '0');

$wrapAttrs = [
    'class' => $wrapperClass,
    'data-sf-field' => $type,
];
if ($dataField !== '') {
    $wrapAttrs['data-field'] = $dataField;
}
if ($required || $dataField !== '') {
    $wrapAttrs['data-field-required'] = $requiredFlag;
}
if (!empty($wrap_attrs) && is_array($wrap_attrs)) {
    $wrapAttrs = array_merge($wrapAttrs, $wrap_attrs);
}
?>
<div<?= Fields::attrs($wrapAttrs) ?>>
<?php if ($label !== ''): ?>
    <label class="field-label sf-field__label"<?= $id !== '' ? ' for="' . e($id) . '"' : '' ?>>
        <?php if ($icon !== ''): ?>
            <span class="field-icon" data-icon="<?= e($icon) ?>" aria-hidden="true"></span>
        <?php endif; ?>
        <?= e($label) ?><?= $required ? ' *' : '' ?>
    </label>
<?php endif; ?>
