<?php
/**
 * Shared editor for member field definitions + configurable form steps.
 *
 * @var list<array<string,mixed>> $fields
 * @var list<array<string,mixed>> $formSteps
 * @var list<string> $typeOptions
 * @var bool $allowTypeEdit
 * @var bool $setupMode
 * @var string $autosaveUrl
 */
$fields = is_array($fields ?? null) ? $fields : [];
$formSteps = is_array($formSteps ?? null) ? $formSteps : [];
$typeOptions = is_array($typeOptions ?? null) ? $typeOptions : \Socly\Support\MemberFieldTypes::keys();
$allowTypeEdit = !isset($allowTypeEdit) || (bool) $allowTypeEdit;
$setupMode = !empty($setupMode);
$autosaveUrl = trim((string) ($autosaveUrl ?? ''));

$members = app(\Socly\Services\MemberService::class);
$editorSteps = $members->editorFormSteps($formSteps);
if ($setupMode) {
    $editorSteps = array_values(array_filter(
        $editorSteps,
        static fn (array $step): bool => empty($step['is_system'])
    ));
}
$isSetupToggleableField = static function (string $key) use ($members): bool {
    return !\Socly\Support\MemberFieldTypes::isCoreArchiveField($key)
        && !$members->isSystemLockedFieldKey($key);
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
            <input type="hidden" name="field_order[]" value="<?= e($fkey) ?>">
            <input type="hidden" name="field_step[<?= e($fkey) ?>]" value="<?= e($stepKey) ?>" data-field-step-input>
        </td>
        <th scope="row"><span class="setup-fields-name"><?= e($flabel) ?></span></th>
        <?php if (!$setupMode): ?>
        <td>
            <?php if (!$allowTypeEdit || $lockedType !== null): ?>
                <input type="hidden" name="field_types[<?= e($fkey) ?>]" value="<?= e($lockedType ?? $resolvedType) ?>">
                <span class="setup-fields-type-locked" title="<?= e(\Socly\Support\MemberFieldTypes::description($lockedType ?? $resolvedType)) ?>">
                    <?= e(\Socly\Support\MemberFieldTypes::label($lockedType ?? $resolvedType)) ?>
                </span>
            <?php else: ?>
                <label class="setup-fields-type">
                    <select name="field_types[<?= e($fkey) ?>]" aria-label="<?= e(__('setup.fields_col_type') . ': ' . $flabel) ?>">
                        <?php foreach ($typeOptions as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $resolvedType === $opt ? 'selected' : '' ?>><?= e(\Socly\Support\MemberFieldTypes::label($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </td>
        <?php else: ?>
            <input type="hidden" name="field_types[<?= e($fkey) ?>]" value="<?= e($lockedType ?? $resolvedType) ?>">
        <?php endif; ?>
        <td class="setup-fields-col-check">
            <?php if ($coreLocked): ?>
                <input type="hidden" name="fields[]" value="<?= e($fkey) ?>">
                <label class="setup-fields-toggle is-locked" title="<?= e(__('setup.fields_core_locked')) ?>">
                    <input type="checkbox" checked disabled aria-label="<?= e(__('setup.fields_col_enabled') . ': ' . $flabel) ?>">
                </label>
            <?php else: ?>
                <label class="setup-fields-toggle">
                    <input type="checkbox" name="fields[]" value="<?= e($fkey) ?>" <?= $enabledChecked ? 'checked' : '' ?> aria-label="<?= e(__('setup.fields_col_enabled') . ': ' . $flabel) ?>">
                </label>
            <?php endif; ?>
        </td>
        <td class="setup-fields-col-check">
            <?php if ($coreLocked): ?>
                <input type="hidden" name="required[]" value="<?= e($fkey) ?>">
                <label class="setup-fields-toggle is-locked" title="<?= e(__('setup.fields_core_locked')) ?>">
                    <input type="checkbox" checked disabled aria-label="<?= e(__('setup.fields_col_required') . ': ' . $flabel) ?>">
                </label>
            <?php else: ?>
                <label class="setup-fields-toggle">
                    <input type="checkbox" name="required[]" value="<?= e($fkey) ?>" <?= $requiredChecked ? 'checked' : '' ?> aria-label="<?= e(__('setup.fields_col_required') . ': ' . $flabel) ?>">
                </label>
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
    <?= $setupMode ? 'data-fields-setup-mode="1"' : '' ?>
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
            <?php if ($setupMode): ?>
                <p class="setup-hint muted"><?= e(__('setup.fields_setup_hint')) ?></p>
            <?php else: ?>
                <p class="setup-hint muted"><?= e(__('setup.fields_reorder_hint')) ?></p>
                <p class="setup-hint muted"><?= e(__('setup.fields_steps_hint')) ?></p>
                <p class="setup-hint muted"><?= e(__('setup.fields_autosave_hint')) ?></p>
            <?php endif; ?>
        </div>
        <p class="setup-fields-autosave-status muted" data-fields-autosave-status aria-live="polite"></p>
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
                    <input type="hidden" name="form_steps[]" value="<?= e($stepKey) ?>" data-step-key-input>
                    <?php if (!$setupMode): ?>
                        <div class="setup-fields-step-titles">
                            <label class="setup-field">
                                <span><?= e(__('setup.fields_step_title_it')) ?></span>
                                <input type="text" name="form_step_title_it[<?= e($stepKey) ?>]" value="<?= e($titleIt) ?>" data-step-title="it" required>
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.fields_step_title_de')) ?></span>
                                <input type="text" name="form_step_title_de[<?= e($stepKey) ?>]" value="<?= e($titleDe) ?>" data-step-title="de">
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.fields_step_title_en')) ?></span>
                                <input type="text" name="form_step_title_en[<?= e($stepKey) ?>]" value="<?= e($titleEn) ?>" data-step-title="en">
                            </label>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="form_step_title_it[<?= e($stepKey) ?>]" value="<?= e($titleIt !== '' ? $titleIt : $displayTitle) ?>" data-step-title="it">
                        <input type="hidden" name="form_step_title_de[<?= e($stepKey) ?>]" value="<?= e($titleDe) ?>">
                        <input type="hidden" name="form_step_title_en[<?= e($stepKey) ?>]" value="<?= e($titleEn) ?>">
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
                                    static fn (array $field): bool => $isSetupToggleableField((string) ($field['key'] ?? ''))
                                ))
                                : $stepFields;
                            ?>
                            <?php if ($visibleStepFields === [] && ($setupMode || $stepPlaceholders === [])): ?>
                                <tr class="setup-fields-empty-row" data-fields-empty-row>
                                    <td colspan="<?= $setupMode ? 4 : 5 ?>" class="muted"><?= e($setupMode ? __('setup.fields_settings_empty') : __('setup.fields_step_empty')) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visibleStepFields as $field): ?>
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
            <input type="hidden" name="form_steps[]" value="__KEY__" data-step-key-input>
            <div class="setup-fields-step-titles">
                <label class="setup-field">
                    <span><?= e(__('setup.fields_step_title_it')) ?></span>
                    <input type="text" name="form_step_title_it[__KEY__]" value="" data-step-title="it" required>
                </label>
                <label class="setup-field">
                    <span><?= e(__('setup.fields_step_title_de')) ?></span>
                    <input type="text" name="form_step_title_de[__KEY__]" value="" data-step-title="de">
                </label>
                <label class="setup-field">
                    <span><?= e(__('setup.fields_step_title_en')) ?></span>
                    <input type="text" name="form_step_title_en[__KEY__]" value="" data-step-title="en">
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
