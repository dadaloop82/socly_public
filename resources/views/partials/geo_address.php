<?php
/**
 * Shared city / CAP / street / house-number block (suggest-enabled).
 *
 * @var array<string,string> $names Input name attributes
 * @var array<string,string> $values Current values
 * @var array<string,bool> $required Per-field required flags
 * @var array<string,string> $ids Optional element ids
 * @var array<string,bool> $enabled Optional per-field visibility (default all true)
 * @var bool $show_hint Show address autocomplete hint
 * @var string $class Extra classes on the root (e.g. assoc-person-extra)
 * @var bool $with_scope Add data-geo-scope on the root
 * @var string $layout 'rows' (city+CAP / street+number) or 'inline' (all four on one line)
 */
$names = array_merge([
    'city' => 'city',
    'postal_code' => 'postal_code',
    'province' => 'province',
    'address' => 'address',
    'house_number' => 'house_number',
], is_array($names ?? null) ? $names : []);
$values = array_merge([
    'city' => '',
    'postal_code' => '',
    'province' => '',
    'address' => '',
    'house_number' => '',
], is_array($values ?? null) ? $values : []);
$required = array_merge([
    'city' => false,
    'postal_code' => false,
    'province' => false,
    'address' => false,
    'house_number' => false,
], is_array($required ?? null) ? $required : []);
$ids = is_array($ids ?? null) ? $ids : [];
$enabled = array_merge([
    'city' => true,
    'postal_code' => true,
    'province' => false,
    'address' => true,
    'house_number' => true,
], is_array($enabled ?? null) ? $enabled : []);
$extraClass = trim((string) ($class ?? ''));
$withScope = !isset($with_scope) || (bool) $with_scope;
$layout = (($layout ?? 'rows') === 'inline') ? 'inline' : 'rows';
$star = static fn (bool $need): string => $need ? ' *' : '';
$req = static fn (bool $need): string => $need ? ' required' : '';
$rootClass = trim('geo-address setup-address geo-layout-' . $layout . ' ' . $extraClass);
$anyEnabled = !empty($enabled['city']) || !empty($enabled['postal_code']) || !empty($enabled['province']) || !empty($enabled['address']) || !empty($enabled['house_number']);
?>
<?php if ($anyEnabled): ?>
<div class="<?= e($rootClass) ?>"<?= $withScope ? ' data-geo-scope' : '' ?>>
    <?php if ($layout === 'inline'): ?>
        <div class="geo-address-row geo-address-row-inline setup-address-row">
            <?php if (!empty($enabled['city'])): ?>
            <label class="setup-field suggest-field setup-field-grow geo-field">
                <span><?= e(__('setup.field_city')) ?><?= e($star((bool) $required['city'])) ?></span>
                <div class="suggest-wrap">
                    <input
                        type="text"
                        name="<?= e($names['city']) ?>"
                        value="<?= e((string) $values['city']) ?>"
                        data-city-input
                        autocomplete="off"
                        placeholder="<?= e(__('members.city_placeholder')) ?>"
                        <?= !empty($ids['city']) ? 'id="' . e($ids['city']) . '"' : '' ?>
                        <?= $req((bool) $required['city']) ?>
                    >
                    <div class="suggest-list" data-city-suggest hidden></div>
                </div>
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['address'])): ?>
            <label class="setup-field suggest-field setup-field-grow geo-field">
                <span><?= e(__('setup.field_street')) ?><?= e($star((bool) $required['address'])) ?></span>
                <div class="suggest-wrap">
                    <input
                        type="text"
                        name="<?= e($names['address']) ?>"
                        value="<?= e((string) $values['address']) ?>"
                        data-address-input
                        autocomplete="street-address"
                        placeholder="<?= e(__('members.address_hint')) ?>"
                        <?= !empty($ids['address']) ? 'id="' . e($ids['address']) . '"' : '' ?>
                        <?= $req((bool) $required['address']) ?>
                    >
                    <div class="suggest-list" data-address-suggest hidden></div>
                </div>
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['house_number'])): ?>
            <label class="setup-field setup-field-num geo-field">
                <span><?= e(__('setup.field_house_number')) ?><?= e($star((bool) $required['house_number'])) ?></span>
                <input
                    type="text"
                    name="<?= e($names['house_number']) ?>"
                    value="<?= e((string) $values['house_number']) ?>"
                    data-house-number
                    autocomplete="off"
                    placeholder="<?= e(__('members.house_number_placeholder')) ?>"
                    <?= !empty($ids['house_number']) ? 'id="' . e($ids['house_number']) . '"' : '' ?>
                    <?= $req((bool) $required['house_number']) ?>
                >
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['postal_code'])): ?>
            <label class="setup-field setup-field-cap geo-field">
                <span><?= e(__('setup.field_postal_code')) ?><?= e($star((bool) $required['postal_code'])) ?></span>
                <input
                    type="text"
                    name="<?= e($names['postal_code']) ?>"
                    value="<?= e((string) $values['postal_code']) ?>"
                    data-postal-code
                    inputmode="numeric"
                    autocomplete="postal-code"
                    <?= !empty($ids['postal_code']) ? 'id="' . e($ids['postal_code']) . '"' : '' ?>
                    <?= $req((bool) $required['postal_code']) ?>
                >
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['province'])): ?>
            <label class="setup-field setup-field-province geo-field">
                <span><?= e(__('setup.field_province')) ?><?= e($star((bool) $required['province'])) ?></span>
                <input
                    type="text"
                    name="<?= e($names['province']) ?>"
                    value="<?= e((string) $values['province']) ?>"
                    data-province-input
                    autocomplete="address-level1"
                    maxlength="4"
                    placeholder="<?= e(__('setup.field_province_placeholder')) ?>"
                    <?= !empty($ids['province']) ? 'id="' . e($ids['province']) . '"' : '' ?>
                    <?= $req((bool) $required['province']) ?>
                >
            </label>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if (!empty($enabled['city']) || !empty($enabled['postal_code']) || !empty($enabled['province'])): ?>
        <div class="geo-address-row setup-address-row">
            <?php if (!empty($enabled['city'])): ?>
            <label class="setup-field suggest-field setup-field-grow geo-field">
                <span><?= e(__('setup.field_city')) ?><?= e($star((bool) $required['city'])) ?></span>
                <div class="suggest-wrap">
                    <input
                        type="text"
                        name="<?= e($names['city']) ?>"
                        value="<?= e((string) $values['city']) ?>"
                        data-city-input
                        autocomplete="off"
                        placeholder="<?= e(__('members.city_placeholder')) ?>"
                        <?= !empty($ids['city']) ? 'id="' . e($ids['city']) . '"' : '' ?>
                        <?= $req((bool) $required['city']) ?>
                    >
                    <div class="suggest-list" data-city-suggest hidden></div>
                </div>
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['postal_code'])): ?>
            <label class="setup-field setup-field-cap geo-field">
                <span><?= e(__('setup.field_postal_code')) ?><?= e($star((bool) $required['postal_code'])) ?></span>
                <input
                    type="text"
                    name="<?= e($names['postal_code']) ?>"
                    value="<?= e((string) $values['postal_code']) ?>"
                    data-postal-code
                    inputmode="numeric"
                    autocomplete="postal-code"
                    <?= !empty($ids['postal_code']) ? 'id="' . e($ids['postal_code']) . '"' : '' ?>
                    <?= $req((bool) $required['postal_code']) ?>
                >
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['province'])): ?>
            <label class="setup-field setup-field-province geo-field">
                <span><?= e(__('setup.field_province')) ?><?= e($star((bool) $required['province'])) ?></span>
                <input
                    type="text"
                    name="<?= e($names['province']) ?>"
                    value="<?= e((string) $values['province']) ?>"
                    data-province-input
                    autocomplete="address-level1"
                    maxlength="4"
                    placeholder="<?= e(__('setup.field_province_placeholder')) ?>"
                    <?= !empty($ids['province']) ? 'id="' . e($ids['province']) . '"' : '' ?>
                    <?= $req((bool) $required['province']) ?>
                >
            </label>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($enabled['address']) || !empty($enabled['house_number'])): ?>
        <div class="geo-address-row setup-address-row">
            <?php if (!empty($enabled['address'])): ?>
            <label class="setup-field suggest-field setup-field-grow geo-field">
                <span><?= e(__('setup.field_street')) ?><?= e($star((bool) $required['address'])) ?></span>
                <div class="suggest-wrap">
                    <input
                        type="text"
                        name="<?= e($names['address']) ?>"
                        value="<?= e((string) $values['address']) ?>"
                        data-address-input
                        autocomplete="street-address"
                        placeholder="<?= e(__('members.address_hint')) ?>"
                        <?= !empty($ids['address']) ? 'id="' . e($ids['address']) . '"' : '' ?>
                        <?= $req((bool) $required['address']) ?>
                    >
                    <div class="suggest-list" data-address-suggest hidden></div>
                </div>
            </label>
            <?php endif; ?>
            <?php if (!empty($enabled['house_number'])): ?>
            <label class="setup-field setup-field-num geo-field">
                <span><?= e(__('setup.field_house_number')) ?><?= e($star((bool) $required['house_number'])) ?></span>
                <input
                    type="text"
                    name="<?= e($names['house_number']) ?>"
                    value="<?= e((string) $values['house_number']) ?>"
                    data-house-number
                    autocomplete="off"
                    placeholder="<?= e(__('members.house_number_placeholder')) ?>"
                    <?= !empty($ids['house_number']) ? 'id="' . e($ids['house_number']) . '"' : '' ?>
                    <?= $req((bool) $required['house_number']) ?>
                >
            </label>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
