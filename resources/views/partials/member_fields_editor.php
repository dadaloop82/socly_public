<?php
/**
 * Shared editor for member field definitions + configurable form steps.
 *
 * @var list<array<string,mixed>> $fields
 * @var list<array<string,mixed>> $formSteps
 * @var list<string> $typeOptions
 * @var bool $allowTypeEdit
 * @var bool $setupMode Compact UI (no type column / step chrome)
 * @var bool $wizardMode Onboarding wizard: no autosave copy, hide privacy consents
 * @var string $autosaveUrl
 */
$fields = is_array($fields ?? null) ? $fields : [];
$formSteps = is_array($formSteps ?? null) ? $formSteps : [];
$typeOptions = is_array($typeOptions ?? null) ? $typeOptions : \Socly\Support\MemberFieldTypes::keys();
$allowTypeEdit = !isset($allowTypeEdit) || (bool) $allowTypeEdit;
$setupMode = !empty($setupMode);
$wizardMode = !empty($wizardMode);
$autosaveUrl = trim((string) ($autosaveUrl ?? ''));

$members = app(\Socly\Services\MemberService::class);
$editorSteps = $members->editorFormSteps($formSteps);
if ($setupMode) {
    $editorSteps = array_values(array_filter(
        $editorSteps,
        static fn (array $step): bool => empty($step['is_system'])
    ));
}
$addressBundleKeys = ['city', 'address', 'house_number', 'postal_code'];
$consentFieldKeys = [
    'privacy_ack',
    'statute_ack',
    'image_consent',
    'photo_consent',
    'image_ack',
    'photo_ack',
    'images_consent',
    'newsletter_consent',
    'comms_consent',
    'marketing_consent',
    'newsletter_ack',
];
$isSetupConsentField = static function (string $key, array $field = []) use ($consentFieldKeys, $members): bool {
    if (in_array($key, $consentFieldKeys, true) || $members->isSystemLockedFieldKey($key)) {
        return true;
    }
    $step = (string) ($field['form_step'] ?? '');
    return $step === \Socly\Services\MemberService::STEP_ACKNOWLEDGEMENTS;
};
$isSetupToggleableField = static function (string $key, array $field = []) use ($members): bool {
    return !\Socly\Support\MemberFieldTypes::isCoreArchiveField($key);
};
$placeholders = $members->systemStepPlaceholders();
$defaultStepKey = (string) ($editorSteps[0]['key'] ?? 'profile');

$fieldsByStep = [];
foreach ($editorSteps as $step) {
    $fieldsByStep[(string) $step['key']] = [];
}
foreach ($fields as $field) {
    $fkey = (string) ($field['key'] ?? '');
    $fstep = (string) ($field['form_step'] ?? $defaultStepKey);
    if ($members->isSystemLockedFieldKey($fkey)) {
        $fstep = \Socly\Services\MemberService::STEP_ACKNOWLEDGEMENTS;
    }
    if (!isset($fieldsByStep[$fstep])) {
        $fstep = $defaultStepKey;
    }
    $fieldsByStep[$fstep][] = $field;
}

$dragIcon = '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">'
    . '<circle cx="5" cy="3.5" r="1.25" fill="currentColor"/>'
    . '<circle cx="11" cy="3.5" r="1.25" fill="currentColor"/>'
    . '<circle cx="5" cy="8" r="1.25" fill="currentColor"/>'
    . '<circle cx="11" cy="8" r="1.25" fill="currentColor"/>'
    . '<circle cx="5" cy="12.5" r="1.25" fill="currentColor"/>'
    . '<circle cx="11" cy="12.5" r="1.25" fill="currentColor"/>'
    . '</svg>';

$renderFieldRow = static function (
    array $field,
    string $stepKey,
    bool $allowTypeEdit,
    array $typeOptions,
    string $dragIcon,
    bool $locked,
    bool $setupMode = false
) use ($members): void {
    $fkey = (string) ($field['key'] ?? '');
    $flabel = localized($field['label_json'] ?? '');
    if ($flabel === '') {
        $flabel = $fkey;
    }
    $resolvedType = \Socly\Support\MemberFieldTypes::resolve((string) ($field['field_type'] ?? 'text'), $fkey);
    $lockedType = \Socly\Support\MemberFieldTypes::lockedTypeForKey($fkey);
    $locked = $locked || $members->isSystemLockedFieldKey($fkey);
    $coreLocked = \Socly\Support\MemberFieldTypes::isCoreArchiveField($fkey);
    $enabledChecked = $coreLocked || !empty($field['is_enabled']);
    $requiredChecked = $coreLocked || !empty($field['is_required']);
    ?>
    <tr data-field-key="<?= e($fkey) ?>" data-field-step="<?= e($stepKey) ?>"<?= $locked ? ' data-field-locked="1"' : '' ?><?= $coreLocked ? ' data-field-core="1"' : '' ?>>
        <td class="setup-fields-col-drag">
            <?php if (!$locked): ?>
                <button
                    type="button"
                    class="setup-fields-drag-handle"
                    data-field-drag-handle
                    aria-label="<?= e(__('setup.fields_drag_handle') . ': ' . $flabel) ?>"
                    title="<?= e(__('setup.fields_drag_handle')) ?>"
                ><?= $dragIcon ?></button>
            <?php else: ?>
                <span class="setup-fields-drag-locked" title="<?= e(__('setup.fields_system_locked')) ?>" aria-hidden="true">•</span>
            <?php endif; ?>
            <?= field('hidden', ['name' => 'field_order[]', 'value' => $fkey]) ?>
            <?= field('hidden', [
                'name' => 'field_step[' . $fkey . ']',
                'value' => $stepKey,
                'attrs' => ['data-field-step-input' => true],
            ]) ?>
        </td>
        <th scope="row"><span class="setup-fields-name"><?= e($flabel) ?></span></th>
        <?php if (!$setupMode): ?>
        <td>
            <?php if (!$allowTypeEdit || $lockedType !== null): ?>
                <?= field('hidden', ['name' => 'field_types[' . $fkey . ']', 'value' => $lockedType ?? $resolvedType]) ?>
                <span class="setup-fields-type-locked" title="<?= e(\Socly\Support\MemberFieldTypes::description($lockedType ?? $resolvedType)) ?>">
                    <?= e(\Socly\Support\MemberFieldTypes::label($lockedType ?? $resolvedType)) ?>
                </span>
            <?php else: ?>
                <?= field('select', [
                    'name' => 'field_types[' . $fkey . ']',
                    'label' => '',
                    'context' => 'inline',
                    'wrapper_class' => 'setup-fields-type',
                    'include_empty' => false,
                    'value' => $resolvedType,
                    'options' => array_map(
                        static fn (string $opt): array => [
                            'value' => $opt,
                            'label' => \Socly\Support\MemberFieldTypes::label($opt),
                        ],
                        $typeOptions
                    ),
                    'attrs' => ['aria-label' => __('setup.fields_col_type') . ': ' . $flabel],
                ]) ?>
            <?php endif; ?>
        </td>
        <?php else: ?>
            <?= field('hidden', ['name' => 'field_types[' . $fkey . ']', 'value' => $lockedType ?? $resolvedType]) ?>
        <?php endif; ?>
        <td class="setup-fields-col-check">
            <?php if ($coreLocked): ?>
                <?= field('hidden', ['name' => 'fields[]', 'value' => $fkey]) ?>
                <?= field('checkbox', [
                    'name' => '',
                    'label' => '',
                    'checked' => true,
                    'disabled' => true,
                    'context' => 'inline',
                    'wrapper_class' => 'setup-fields-toggle is-locked',
                    'wrap_attrs' => ['title' => (string) __('setup.fields_core_locked')],
                    'attrs' => ['aria-label' => __('setup.fields_col_enabled') . ': ' . $flabel],
                ]) ?>
            <?php else: ?>
                <?= field('checkbox', [
                    'name' => 'fields[]',
                    'check_value' => $fkey,
                    'label' => '',
                    'checked' => $enabledChecked,
                    'context' => 'inline',
                    'wrapper_class' => 'setup-fields-toggle',
                    'attrs' => ['aria-label' => __('setup.fields_col_enabled') . ': ' . $flabel],
                ]) ?>
            <?php endif; ?>
        </td>
        <td class="setup-fields-col-check">
            <?php if ($coreLocked): ?>
                <?= field('hidden', ['name' => 'required[]', 'value' => $fkey]) ?>
                <?= field('checkbox', [
                    'name' => '',
                    'label' => '',
                    'checked' => true,
                    'disabled' => true,
                    'context' => 'inline',
                    'wrapper_class' => 'setup-fields-toggle is-locked',
                    'wrap_attrs' => ['title' => (string) __('setup.fields_core_locked')],
                    'attrs' => ['aria-label' => __('setup.fields_col_required') . ': ' . $flabel],
                ]) ?>
            <?php else: ?>
                <?= field('checkbox', [
                    'name' => 'required[]',
                    'check_value' => $fkey,
                    'label' => '',
                    'checked' => $requiredChecked,
                    'context' => 'inline',
                    'wrapper_class' => 'setup-fields-toggle',
                    'attrs' => ['aria-label' => __('setup.fields_col_required') . ': ' . $flabel],
                ]) ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php
};

$customStepCount = 0;
foreach ($editorSteps as $s) {
    if (empty($s['is_system'])) {
        $customStepCount++;
    }
}
?>
<div
    class="setup-fields-editor<?= $setupMode ? ' setup-fields-editor-compact' : '' ?>"
    data-fields-editor
    <?= $wizardMode ? 'data-fields-setup-mode="1"' : '' ?>
    data-step-prefix="step_"
    data-autosave-url="<?= e($autosaveUrl) ?>"
    data-csrf="<?= e(csrf_token()) ?>"
    data-autosave-busy="<?= e(__('setup.fields_autosave_busy')) ?>"
    data-autosave-ok="<?= e(__('setup.fields_autosaved')) ?>"
    data-autosave-fail="<?= e(__('setup.fields_autosave_failed')) ?>"
    data-add-step-label="<?= e(__('setup.fields_add_step')) ?>"
    data-remove-step-label="<?= e(__('setup.fields_remove_step')) ?>"
    data-step-title-it="<?= e(__('setup.fields_step_title_it')) ?>"
    data-step-title-de="<?= e(__('setup.fields_step_title_de')) ?>"
    data-step-title-en="<?= e(__('setup.fields_step_title_en')) ?>"
    data-step-empty="<?= e(__('setup.fields_step_empty')) ?>"
    data-drag-handle="<?= e(__('setup.fields_drag_handle')) ?>"
>
    <div class="setup-fields-editor-meta">
        <div>
            <?php if ($wizardMode): ?>
                <p class="setup-hint muted"><?= e(__('setup.fields_setup_hint')) ?></p>
            <?php elseif ($setupMode): ?>
                <p class="setup-hint muted"><?= e(__('setup.fields_hint')) ?></p>
                <p class="setup-hint muted"><?= e(__('setup.fields_autosave_hint')) ?></p>
            <?php else: ?>
                <p class="setup-hint muted"><?= e(__('setup.fields_reorder_hint')) ?></p>
                <p class="setup-hint muted"><?= e(__('setup.fields_steps_hint')) ?></p>
                <p class="setup-hint muted"><?= e(__('setup.fields_autosave_hint')) ?></p>
            <?php endif; ?>
        </div>
        <?php if (!$wizardMode): ?>
            <p class="setup-fields-autosave-status muted" data-fields-autosave-status aria-live="polite"></p>
        <?php endif; ?>
    </div>

    <div class="setup-fields-steps" data-fields-steps>
        <?php foreach ($editorSteps as $stepIndex => $step): ?>
            <?php
            $stepKey = (string) ($step['key'] ?? '');
            $isSystem = !empty($step['is_system']);
            $titles = $step['title_json'] ?? [];
            if (is_string($titles)) {
                $decoded = json_decode($titles, true);
                $titles = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($titles)) {
                $titles = [];
            }
            $titleIt = (string) ($titles['it'] ?? '');
            $titleDe = (string) ($titles['de'] ?? '');
            $titleEn = (string) ($titles['en'] ?? '');
            $displayTitle = localized($step['title_json'] ?? '') ?: $stepKey;
            $stepFields = $fieldsByStep[$stepKey] ?? [];
            $stepPlaceholders = $placeholders[$stepKey] ?? [];
            $canRemove = !$isSystem && $customStepCount > 1;
            ?>
            <section
                class="setup-fields-step<?= $isSystem ? ' setup-fields-step-system' : '' ?>"
                data-fields-step
                data-step-key="<?= e($stepKey) ?>"
                <?= $isSystem ? 'data-fields-step-system="1"' : '' ?>
            >
                <header class="setup-fields-step-head"<?= $setupMode ? ' hidden' : '' ?>>
                    <div class="setup-fields-step-badge">
                        <span class="setup-fields-step-index" data-step-index><?= (int) $stepIndex + 1 ?></span>
                        <strong class="setup-fields-step-name" data-step-display-name><?= e($displayTitle) ?></strong>
                        <?php if ($isSystem): ?>
                            <span class="setup-fields-step-locked-note muted"><?= e(__('setup.fields_system_step_hint')) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($canRemove): ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-fields-step-remove aria-label="<?= e(__('setup.fields_remove_step')) ?>">
                            <?= e(__('setup.fields_remove_step')) ?>
                        </button>
                    <?php endif; ?>
                </header>
                <?php if (!$isSystem): ?>
                    <?= field('hidden', [
                        'name' => 'form_steps[]',
                        'value' => $stepKey,
                        'attrs' => ['data-step-key-input' => true],
                    ]) ?>
                    <?php if (!$setupMode): ?>
                        <div class="setup-fields-step-titles">
                            <label class="setup-field">
                                <span><?= e(__('setup.fields_step_title_it')) ?></span>
                                <?= field('text', [
                                    'name' => 'form_step_title_it[' . $stepKey . ']',
                                    'label' => '',
                                    'context' => 'inline',
                                    'value' => $titleIt,
                                    'required' => true,
                                    'attrs' => ['data-step-title' => 'it'],
                                ]) ?>
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.fields_step_title_de')) ?></span>
                                <?= field('text', [
                                    'name' => 'form_step_title_de[' . $stepKey . ']',
                                    'label' => '',
                                    'context' => 'inline',
                                    'value' => $titleDe,
                                    'attrs' => ['data-step-title' => 'de'],
                                ]) ?>
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.fields_step_title_en')) ?></span>
                                <?= field('text', [
                                    'name' => 'form_step_title_en[' . $stepKey . ']',
                                    'label' => '',
                                    'context' => 'inline',
                                    'value' => $titleEn,
                                    'attrs' => ['data-step-title' => 'en'],
                                ]) ?>
                            </label>
                        </div>
                    <?php else: ?>
                        <?= field('hidden', [
                            'name' => 'form_step_title_it[' . $stepKey . ']',
                            'value' => $titleIt !== '' ? $titleIt : $displayTitle,
                            'attrs' => ['data-step-title' => 'it'],
                        ]) ?>
                        <?= field('hidden', ['name' => 'form_step_title_de[' . $stepKey . ']', 'value' => $titleDe]) ?>
                        <?= field('hidden', ['name' => 'form_step_title_en[' . $stepKey . ']', 'value' => $titleEn]) ?>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="setup-fields-table-wrap">
                    <table class="setup-fields-table" data-fields-sortable>
                        <thead>
                            <tr>
                                <th scope="col" class="setup-fields-col-drag"><span class="visually-hidden"><?= e(__('setup.fields_col_order')) ?></span></th>
                                <th scope="col"><?= e(__('setup.fields_col_name')) ?></th>
                                <?php if (!$setupMode): ?>
                                    <th scope="col"><?= e(__('setup.fields_col_type')) ?></th>
                                <?php endif; ?>
                                <th scope="col" class="setup-fields-col-check"><?= e(__('setup.fields_col_enabled')) ?></th>
                                <th scope="col" class="setup-fields-col-check"><?= e(__('setup.fields_col_required')) ?></th>
                            </tr>
                        </thead>
                        <tbody data-fields-step-body>
                            <?php if (!$setupMode): ?>
                                <?php foreach ($stepPlaceholders as $ph): ?>
                                    <tr class="setup-fields-system-row" data-field-locked="1">
                                        <td class="setup-fields-col-drag">
                                            <span class="setup-fields-drag-locked" title="<?= e(__('setup.fields_system_locked')) ?>" aria-hidden="true">•</span>
                                        </td>
                                        <th scope="row"><span class="setup-fields-name"><?= e((string) $ph['label']) ?></span></th>
                                        <td><span class="setup-fields-type-locked"><?= e(__('setup.fields_system_type')) ?></span></td>
                                        <td class="setup-fields-col-check">—</td>
                                        <td class="setup-fields-col-check">—</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php
                            $visibleStepFields = $setupMode
                                ? array_values(array_filter(
                                    $stepFields,
                                    static function (array $field) use ($isSetupToggleableField, $wizardMode, $isSetupConsentField): bool {
                                        $key = (string) ($field['key'] ?? '');
                                        if (!$isSetupToggleableField($key, $field)) {
                                            return false;
                                        }
                                        // Onboarding: privacy consents stay on steps 13–14, not here.
                                        if ($wizardMode && $isSetupConsentField($key, $field)) {
                                            return false;
                                        }
                                        return true;
                                    }
                                ))
                                : $stepFields;
                            $addressBundleFields = [];
                            $renderFields = [];
                            if ($setupMode) {
                                foreach ($visibleStepFields as $field) {
                                    $fkey = (string) ($field['key'] ?? '');
                                    if (in_array($fkey, $addressBundleKeys, true)) {
                                        $addressBundleFields[$fkey] = $field;
                                    } else {
                                        $renderFields[] = $field;
                                    }
                                }
                            } else {
                                $renderFields = $visibleStepFields;
                            }
                            ?>
                            <?php if ($renderFields === [] && $addressBundleFields === [] && ($setupMode || $stepPlaceholders === [])): ?>
                                <tr class="setup-fields-empty-row" data-fields-empty-row>
                                    <td colspan="<?= $setupMode ? 4 : 5 ?>" class="muted"><?= e($setupMode ? __('setup.fields_settings_empty') : __('setup.fields_step_empty')) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php if ($setupMode && $addressBundleFields !== []): ?>
                                    <?php
                                    $bundleEnabled = false;
                                    $bundleRequired = false;
                                    foreach ($addressBundleFields as $af) {
                                        if (!empty($af['is_enabled'])) {
                                            $bundleEnabled = true;
                                        }
                                        if (!empty($af['is_required'])) {
                                            $bundleRequired = true;
                                        }
                                    }
                                    $bundleStep = $stepKey;
                                    foreach ($addressBundleFields as $af) {
                                        $bundleStep = (string) ($af['form_step'] ?? $stepKey);
                                        break;
                                    }
                                    ?>
                                    <tr data-address-bundle data-field-step="<?= e($bundleStep) ?>">
                                        <td class="setup-fields-col-drag">
                                            <span class="setup-fields-drag-locked" title="<?= e(__('setup.fields_system_locked')) ?>" aria-hidden="true">•</span>
                                            <?php foreach ($addressBundleKeys as $addrKey): ?>
                                                <?php if (!isset($addressBundleFields[$addrKey])) {
                                                    continue;
                                                } ?>
                                                <?= field('hidden', ['name' => 'field_order[]', 'value' => $addrKey]) ?>
                                                <?= field('hidden', [
                                                    'name' => 'field_step[' . $addrKey . ']',
                                                    'value' => $bundleStep,
                                                    'attrs' => ['data-field-step-input' => true],
                                                ]) ?>
                                                <?= field('hidden', [
                                                    'name' => 'field_types[' . $addrKey . ']',
                                                    'value' => (string) ($addressBundleFields[$addrKey]['field_type'] ?? $addrKey),
                                                ]) ?>
                                            <?php endforeach; ?>
                                        </td>
                                        <th scope="row">
                                            <span class="setup-fields-name"><?= e(__('setup.fields_address_bundle')) ?></span>
                                        </th>
                                        <td class="setup-fields-col-check">
                                            <?= field('checkbox', [
                                                'name' => '',
                                                'label' => '',
                                                'checked' => $bundleEnabled,
                                                'context' => 'inline',
                                                'wrapper_class' => 'setup-fields-toggle',
                                                'attrs' => [
                                                    'data-address-bundle-enabled' => true,
                                                    'aria-label' => __('setup.fields_col_enabled') . ': ' . __('setup.fields_address_bundle'),
                                                ],
                                            ]) ?>
                                            <?php foreach ($addressBundleKeys as $addrKey): ?>
                                                <?php if (!isset($addressBundleFields[$addrKey])) {
                                                    continue;
                                                } ?>
                                                <?= field('checkbox', [
                                                    'name' => 'fields[]',
                                                    'check_value' => $addrKey,
                                                    'label' => '',
                                                    'checked' => $bundleEnabled,
                                                    'context' => 'inline',
                                                    'input_class' => 'setup-fields-bundle-sync',
                                                    'attrs' => [
                                                        'data-address-bundle-field' => 'enabled',
                                                        'hidden' => true,
                                                        'tabindex' => '-1',
                                                        'aria-hidden' => 'true',
                                                    ],
                                                ]) ?>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="setup-fields-col-check">
                                            <?= field('checkbox', [
                                                'name' => '',
                                                'label' => '',
                                                'checked' => $bundleRequired,
                                                'disabled' => !$bundleEnabled,
                                                'context' => 'inline',
                                                'wrapper_class' => 'setup-fields-toggle',
                                                'attrs' => [
                                                    'data-address-bundle-required' => true,
                                                    'aria-label' => __('setup.fields_col_required') . ': ' . __('setup.fields_address_bundle'),
                                                ],
                                            ]) ?>
                                            <?php foreach ($addressBundleKeys as $addrKey): ?>
                                                <?php if (!isset($addressBundleFields[$addrKey])) {
                                                    continue;
                                                } ?>
                                                <?= field('checkbox', [
                                                    'name' => 'required[]',
                                                    'check_value' => $addrKey,
                                                    'label' => '',
                                                    'checked' => $bundleRequired,
                                                    'disabled' => !$bundleEnabled,
                                                    'context' => 'inline',
                                                    'input_class' => 'setup-fields-bundle-sync',
                                                    'attrs' => [
                                                        'data-address-bundle-field' => 'required',
                                                        'hidden' => true,
                                                        'tabindex' => '-1',
                                                        'aria-hidden' => 'true',
                                                    ],
                                                ]) ?>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($renderFields as $field): ?>
                                    <?php
                                    $fkey = (string) ($field['key'] ?? '');
                                    $renderFieldRow(
                                        $field,
                                        $stepKey,
                                        $setupMode ? false : $allowTypeEdit,
                                        $typeOptions,
                                        $dragIcon,
                                        $members->isSystemLockedFieldKey($fkey),
                                        $setupMode
                                    );
                                    ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php if (!$setupMode && !$isSystem && $stepIndex + 1 === $customStepCount): ?>
                <div class="setup-fields-step-actions">
                    <button type="button" class="btn btn-ghost" data-fields-step-add><?= e(__('setup.fields_add_step')) ?></button>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <template data-fields-step-template>
        <section class="setup-fields-step" data-fields-step data-step-key="__KEY__">
            <header class="setup-fields-step-head">
                <div class="setup-fields-step-badge">
                    <span class="setup-fields-step-index" data-step-index>1</span>
                    <strong class="setup-fields-step-name" data-step-display-name><?= e(__('setup.fields_new_step_name')) ?></strong>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" data-fields-step-remove aria-label="<?= e(__('setup.fields_remove_step')) ?>">
                    <?= e(__('setup.fields_remove_step')) ?>
                </button>
            </header>
            <?= field('hidden', [
                'name' => 'form_steps[]',
                'value' => '__KEY__',
                'attrs' => ['data-step-key-input' => true],
            ]) ?>
            <div class="setup-fields-step-titles">
                <label class="setup-field">
                    <span><?= e(__('setup.fields_step_title_it')) ?></span>
                    <?= field('text', [
                        'name' => 'form_step_title_it[__KEY__]',
                        'label' => '',
                        'context' => 'inline',
                        'value' => '',
                        'required' => true,
                        'attrs' => ['data-step-title' => 'it'],
                    ]) ?>
                </label>
                <label class="setup-field">
                    <span><?= e(__('setup.fields_step_title_de')) ?></span>
                    <?= field('text', [
                        'name' => 'form_step_title_de[__KEY__]',
                        'label' => '',
                        'context' => 'inline',
                        'value' => '',
                        'attrs' => ['data-step-title' => 'de'],
                    ]) ?>
                </label>
                <label class="setup-field">
                    <span><?= e(__('setup.fields_step_title_en')) ?></span>
                    <?= field('text', [
                        'name' => 'form_step_title_en[__KEY__]',
                        'label' => '',
                        'context' => 'inline',
                        'value' => '',
                        'attrs' => ['data-step-title' => 'en'],
                    ]) ?>
                </label>
            </div>
            <div class="setup-fields-table-wrap">
                <table class="setup-fields-table" data-fields-sortable>
                    <thead>
                        <tr>
                            <th scope="col" class="setup-fields-col-drag"><span class="visually-hidden"><?= e(__('setup.fields_col_order')) ?></span></th>
                            <th scope="col"><?= e(__('setup.fields_col_name')) ?></th>
                            <th scope="col"><?= e(__('setup.fields_col_type')) ?></th>
                            <th scope="col" class="setup-fields-col-check"><?= e(__('setup.fields_col_enabled')) ?></th>
                            <th scope="col" class="setup-fields-col-check"><?= e(__('setup.fields_col_required')) ?></th>
                        </tr>
                    </thead>
                    <tbody data-fields-step-body>
                        <tr class="setup-fields-empty-row" data-fields-empty-row>
                            <td colspan="5" class="muted"><?= e(__('setup.fields_step_empty')) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </template>
</div>
