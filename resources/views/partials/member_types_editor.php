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
                                <input
                                    type="text"
                                    name="types[<?= $tid ?>][name_<?= e($code) ?>]"
                                    value="<?= e((string) ($typeRow['name_' . $code] ?? '')) ?>"
                                    <?= $code === 'it' ? 'required' : '' ?>
                                    data-type-name-<?= e($code) ?>
                                >
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="setup-equal-row">
                        <label class="setup-field">
                            <span><?= e(__('install.type_price')) ?> *</span>
                            <div class="setup-price-with-currency">
                                <input type="number" step="0.01" min="0" name="types[<?= $tid ?>][price]" value="<?= e((string) ($typeRow['price'] ?? '0')) ?>" required data-type-price data-type-name-ref="types[<?= $tid ?>][name_it]" inputmode="decimal">
                                <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                            </div>
                        </label>
                        <label class="setup-check setup-check-inline setup-check-prominent">
                            <input type="checkbox" name="types[<?= $tid ?>][is_active]" value="1" <?= !empty($typeRow['is_active']) ? 'checked' : '' ?><?= $singleType ? ' checked disabled' : '' ?>>
                            <?php if ($singleType): ?>
                                <input type="hidden" name="types[<?= $tid ?>][is_active]" value="1">
                            <?php endif; ?>
                            <span><?= e(__('settings.is_active')) ?></span>
                        </label>
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
                            <input
                                type="text"
                                name="new_types[<?= $ni ?>][name_<?= e($code) ?>]"
                                value="<?= e((string) ($newRow['name_' . $code] ?? '')) ?>"
                                <?= $isEmpty && $code === 'it' ? 'required' : '' ?>
                                data-type-name-<?= e($code) ?>
                                placeholder="<?= $code === 'it' ? e(__('setup.type_name_placeholder')) : '' ?>"
                            >
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="setup-equal-row">
                    <label class="setup-field">
                        <span><?= e(__('install.type_price')) ?><?= $isEmpty ? ' *' : '' ?></span>
                        <div class="setup-price-with-currency">
                            <input type="number" step="0.01" min="0" name="new_types[<?= $ni ?>][price]" value="<?= e((string) ($newRow['price'] ?? '')) ?>" <?= $isEmpty ? 'required' : '' ?> placeholder="0.00" data-type-price data-type-name-ref="new_types[<?= $ni ?>][name_it]" inputmode="decimal">
                            <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                        </div>
                    </label>
                    <?php if ($typeRows !== []): ?>
                        <label class="setup-check setup-check-inline setup-check-prominent">
                            <input type="checkbox" name="new_types[<?= $ni ?>][is_active]" value="1" <?= !empty($newRow['is_active']) ? 'checked' : '' ?>>
                            <span><?= e(__('settings.is_active')) ?></span>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="new_types[<?= $ni ?>][is_active]" value="1">
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
                        <input
                            type="text"
                            name="new_types[__i__][name_<?= e($code) ?>]"
                            value=""
                            data-type-name-<?= e($code) ?>
                            placeholder="<?= $code === 'it' ? e(__('setup.type_name_placeholder')) : '' ?>"
                        >
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="setup-equal-row">
                <label class="setup-field">
                    <span><?= e(__('install.type_price')) ?></span>
                    <div class="setup-price-with-currency">
                        <input type="number" step="0.01" min="0" name="new_types[__i__][price]" value="" placeholder="0.00" data-type-price data-type-name-ref="new_types[__i__][name_it]" inputmode="decimal">
                        <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                    </div>
                </label>
                <?php if ($typeRows !== []): ?>
                    <label class="setup-check setup-check-inline setup-check-prominent">
                        <input type="checkbox" name="new_types[__i__][is_active]" value="1" checked>
                        <span><?= e(__('settings.is_active')) ?></span>
                    </label>
                <?php else: ?>
                    <input type="hidden" name="new_types[__i__][is_active]" value="1">
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" data-type-translate-now><?= e(__('settings.legal_translate')) ?></button>
        </div>
    </template>
    <?php if ($singleType): ?>
        <p class="setup-hint muted"><?= e(__('settings.types_single_active_hint')) ?></p>
    <?php endif; ?>
</div>
