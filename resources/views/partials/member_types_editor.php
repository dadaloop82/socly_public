<?php
/**
 * Member type editor (existing rows + add new, multi-row).
 *
 * @var list<array<string,mixed>> $types Raw rows from member_types
 */
$typeRows = [];
foreach ($types ?? [] as $row) {
    $names = json_decode((string) ($row['name_json'] ?? ''), true);
    if (!is_array($names)) {
        $names = [];
    }
    $typeRows[] = [
        'id' => (int) ($row['id'] ?? 0),
        'name_it' => (string) ($names['it'] ?? ''),
        'name_de' => (string) ($names['de'] ?? ''),
        'name_en' => (string) ($names['en'] ?? ''),
        'price' => (string) ($row['price'] ?? '0'),
        'is_active' => !empty($row['is_active']),
    ];
}
$singleType = count($typeRows) === 1;
$isEmpty = $typeRows === [];
$draftNew = $isEmpty
    ? [['name_it' => 'Ordinaria', 'name_de' => 'Ordentlich', 'name_en' => 'Ordinary', 'price' => '0', 'is_active' => true]]
    : [['name_it' => '', 'name_de' => '', 'name_en' => '', 'price' => '', 'is_active' => true]];
$langLabels = [
    'it' => 'Italiano',
    'de' => 'Deutsch',
    'en' => 'English',
];
$currencyDisplay = '€';
try {
    $currencyDisplay = (string) app(\Socly\Services\CurrencyService::class)->display();
} catch (Throwable) {
}
?>
<div class="setup-membership" data-setup-member-types
     data-translate-url="<?= e(url('/api/translate')) ?>"
     data-has-existing="<?= $isEmpty ? '0' : '1' ?>"
     data-currency-display="<?= e($currencyDisplay) ?>">
    <?php if ($typeRows !== []): ?>
        <p class="setup-hint muted"><?= e(__('setup.types_edit_hint')) ?></p>
        <div class="setup-membership-list">
            <?php foreach ($typeRows as $typeRow): ?>
                <?php $tid = (int) ($typeRow['id'] ?? 0); ?>
                <div class="setup-membership-card">
                    <div class="setup-equal-row setup-langs-row">
                        <?php foreach (['it', 'de', 'en'] as $code): ?>
                            <label class="setup-field">
                                <span class="setup-lang-label">
                                    <img src="<?= e(locale_flag_url($code)) ?>" width="22" height="16" alt="" loading="lazy" decoding="async">
                                    <?= e($langLabels[$code]) ?><?= $code === 'it' ? ' *' : '' ?>
                                </span>
                                <?= view_partial('partials/member_type_field', [
                                    'kind' => 'name',
                                    'name' => 'types[' . $tid . '][name_' . $code . ']',
                                    'value' => (string) ($typeRow['name_' . $code] ?? ''),
                                    'required' => $code === 'it',
                                    'langCode' => $code,
                                ]) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="setup-equal-row">
                        <label class="setup-field">
                            <span><?= e(__('install.type_price')) ?> *</span>
                            <div class="setup-price-with-currency">
                                <?= view_partial('partials/member_type_field', [
                                    'kind' => 'price',
                                    'name' => 'types[' . $tid . '][price]',
                                    'value' => (string) ($typeRow['price'] ?? '0'),
                                    'required' => true,
                                    'currencyDisplay' => $currencyDisplay,
                                    'typeNameRef' => 'types[' . $tid . '][name_it]',
                                ]) ?>
                                <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                            </div>
                        </label>
                        <?= view_partial('partials/member_type_field', [
                            'kind' => 'active',
                            'name' => 'types[' . $tid . '][is_active]',
                            'checked' => !empty($typeRow['is_active']) || $singleType,
                            'disabled' => $singleType,
                        ]) ?>
                        <?php if ($singleType): ?>
                            <?= view_partial('partials/member_type_field', [
                                'kind' => 'active_hidden',
                                'name' => 'types[' . $tid . '][is_active]',
                            ]) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="setup-membership-new-list" data-member-types-new>
        <?php foreach ($draftNew as $ni => $newRow): ?>
            <?php $ni = (int) $ni; ?>
            <div class="setup-membership-card setup-membership-card-new" data-member-type-new-row>
                <div class="setup-membership-card-head">
                    <button type="button" class="btn btn-ghost btn-sm setup-membership-remove" data-member-type-remove aria-label="<?= e(__('setup.remove_type')) ?>" <?= ($isEmpty && count($draftNew) <= 1) ? 'hidden' : '' ?>>×</button>
                </div>
                <div class="setup-equal-row setup-langs-row">
                    <?php foreach (['it', 'de', 'en'] as $code): ?>
                        <label class="setup-field">
                            <span class="setup-lang-label">
                                <img src="<?= e(locale_flag_url($code)) ?>" width="22" height="16" alt="" loading="lazy" decoding="async">
                                <?= e($langLabels[$code]) ?><?= $isEmpty && $code === 'it' ? ' *' : '' ?>
                            </span>
                            <?= view_partial('partials/member_type_field', [
                                'kind' => 'name',
                                'name' => 'new_types[' . $ni . '][name_' . $code . ']',
                                'value' => (string) ($newRow['name_' . $code] ?? ''),
                                'required' => $isEmpty && $code === 'it',
                                'langCode' => $code,
                                'placeholder' => $code === 'it' ? (string) __('setup.type_name_placeholder') : '',
                            ]) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="setup-equal-row">
                    <label class="setup-field">
                        <span><?= e(__('install.type_price')) ?><?= $isEmpty ? ' *' : '' ?></span>
                        <div class="setup-price-with-currency">
                            <?= view_partial('partials/member_type_field', [
                                'kind' => 'price',
                                'name' => 'new_types[' . $ni . '][price]',
                                'value' => (string) ($newRow['price'] ?? ''),
                                'required' => $isEmpty,
                                'currencyDisplay' => $currencyDisplay,
                                'typeNameRef' => 'new_types[' . $ni . '][name_it]',
                            ]) ?>
                            <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                        </div>
                    </label>
                    <?php if ($typeRows !== []): ?>
                        <?= view_partial('partials/member_type_field', [
                            'kind' => 'active',
                            'name' => 'new_types[' . $ni . '][is_active]',
                            'checked' => !empty($newRow['is_active']),
                        ]) ?>
                    <?php else: ?>
                        <?= view_partial('partials/member_type_field', [
                            'kind' => 'active_hidden',
                            'name' => 'new_types[' . $ni . '][is_active]',
                        ]) ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" data-type-translate-now><?= e(__('settings.legal_translate')) ?></button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-ghost" data-member-type-add><?= e(__('settings.add_type')) ?></button>
    <template data-member-type-template>
        <div class="setup-membership-card setup-membership-card-new" data-member-type-new-row>
            <div class="setup-membership-card-head">
                <button type="button" class="btn btn-ghost btn-sm setup-membership-remove" data-member-type-remove aria-label="<?= e(__('setup.remove_type')) ?>">×</button>
            </div>
            <div class="setup-equal-row setup-langs-row">
                <?php foreach (['it', 'de', 'en'] as $code): ?>
                    <label class="setup-field">
                        <span class="setup-lang-label">
                            <img src="<?= e(locale_flag_url($code)) ?>" width="22" height="16" alt="" loading="lazy" decoding="async">
                            <?= e($langLabels[$code]) ?>
                        </span>
                        <?= view_partial('partials/member_type_field', [
                            'kind' => 'name',
                            'name' => 'new_types[__i__][name_' . $code . ']',
                            'value' => '',
                            'langCode' => $code,
                            'placeholder' => $code === 'it' ? (string) __('setup.type_name_placeholder') : '',
                        ]) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="setup-equal-row">
                <label class="setup-field">
                    <span><?= e(__('install.type_price')) ?></span>
                    <div class="setup-price-with-currency">
                        <?= view_partial('partials/member_type_field', [
                            'kind' => 'price',
                            'name' => 'new_types[__i__][price]',
                            'value' => '',
                            'currencyDisplay' => $currencyDisplay,
                            'typeNameRef' => 'new_types[__i__][name_it]',
                        ]) ?>
                        <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                    </div>
                </label>
                <?php if ($typeRows !== []): ?>
                    <?= view_partial('partials/member_type_field', [
                        'kind' => 'active',
                        'name' => 'new_types[__i__][is_active]',
                        'checked' => true,
                    ]) ?>
                <?php else: ?>
                    <?= view_partial('partials/member_type_field', [
                        'kind' => 'active_hidden',
                        'name' => 'new_types[__i__][is_active]',
                    ]) ?>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" data-type-translate-now><?= e(__('settings.legal_translate')) ?></button>
        </div>
    </template>
    <?php if ($singleType): ?>
        <p class="setup-hint muted"><?= e(__('settings.types_single_active_hint')) ?></p>
    <?php endif; ?>
</div>
