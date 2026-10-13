<?php
$isEdit = $member !== null;
$fieldValues = $member['fields'] ?? [];
$oldFields = $_SESSION['_old']['fields'] ?? [];
$enrollmentMethod = (string) ($enrollmentMethod ?? 'none');
$hasEnrollmentArtifact = !empty($hasEnrollmentArtifact);
$needsEnrollment = $enrollmentMethod !== '' && $enrollmentMethod !== 'none'
    && (!$isEdit || !$hasEnrollmentArtifact);

$formSteps = is_array($formSteps ?? null) ? $formSteps : [];
$formSteps = array_values(array_filter($formSteps, static function ($step): bool {
    $key = (string) ($step['key'] ?? '');
    return $key !== '' && !in_array($key, \Socly\Services\MemberService::systemFormStepKeys(), true);
}));
if ($formSteps === []) {
    $formSteps = [[
        'key' => 'profile',
        'title_json' => json_encode([
            'it' => 'Anagrafica',
            'de' => 'Stammdaten',
            'en' => 'Profile',
        ], JSON_UNESCAPED_UNICODE),
        'sort_order' => 10,
    ]];
}
$fieldStepCount = count($formSteps);
$totalSteps = $fieldStepCount + 2 + ($needsEnrollment ? 1 : 0);
$tesseraStep = $fieldStepCount + 1;
$ackStep = $fieldStepCount + 2;
$enrollmentStep = $needsEnrollment ? ($fieldStepCount + 3) : null;
$paymentStep = null; // quota + stato pagamento sono nello step Tessera
$oldPaymentStatus = (string) old('payment_status', 'unpaid');
$oldPartialAmount = (string) old('partial_amount', '0');
$oldPaymentMethod = (string) old('payment_method', 'cash');
$treasuryEnabled = !empty($treasuryEnabled);
$guardianCandidates = is_array($guardianCandidates ?? null) ? $guardianCandidates : [];
$oldGuardianId = (string) old('guardian_member_id', (string) ($member['guardian_member_id'] ?? ''));
$oldGuardianQuick = old('guardian_quick', []);
$oldGuardianQuick = is_array($oldGuardianQuick) ? $oldGuardianQuick : [];

$legalAckKeys = ['privacy_ack', 'statute_ack'];
$gdprEnabled = (string) (app()->isInstalled() ? (app(\Socly\Services\SettingsService::class)->get('gdpr.enabled', '0') ?: '0') : '0') === '1';
$defaultFormStepKey = (string) ($formSteps[0]['key'] ?? 'profile');
$fieldsByFormStep = [];
foreach ($formSteps as $step) {
    $fieldsByFormStep[(string) $step['key']] = [];
}
foreach (\Socly\Services\MemberService::systemFormStepKeys() as $sysKey) {
    $fieldsByFormStep[$sysKey] = [];
}

foreach ($fields as $field) {
    $key = (string) ($field['key'] ?? '');
    if ($key === 'privacy_ack' && !$gdprEnabled) {
        continue;
    }
    $stepKey = (string) ($field['form_step'] ?? $defaultFormStepKey);
    if (in_array($key, $legalAckKeys, true)) {
        $stepKey = \Socly\Services\MemberService::STEP_ACKNOWLEDGEMENTS;
    }
    if (!isset($fieldsByFormStep[$stepKey])) {
        $stepKey = $defaultFormStepKey;
    }
    $fieldsByFormStep[$stepKey][] = $field;
}

$profileFields = [];
foreach ($formSteps as $step) {
    foreach ($fieldsByFormStep[(string) $step['key']] ?? [] as $field) {
        $profileFields[] = $field;
    }
}
$ackFields = [];
$optionalConsentKeys = [
    'image_consent', 'photo_consent', 'image_ack', 'photo_ack', 'images_consent',
    'newsletter_consent', 'comms_consent', 'marketing_consent', 'newsletter_ack',
];
$optionalConsentFields = [];
foreach ($fields as $field) {
    if (empty($field['is_enabled'])) {
        continue;
    }
    $fkey = (string) ($field['key'] ?? '');
    if ($fkey === 'statute_ack' || ($fkey === 'privacy_ack' && $gdprEnabled)) {
        $ackFields[] = $field;
        continue;
    }
    $type = (string) ($field['field_type'] ?? '');
    $isOptionalConsent = in_array($fkey, $optionalConsentKeys, true)
        || (
            $type === 'checkbox'
            && (string) ($field['form_step'] ?? '') === \Socly\Services\MemberService::STEP_ACKNOWLEDGEMENTS
            && !in_array($fkey, $legalAckKeys, true)
            && preg_match('/image|photo|immagin|newsletter|comms|marketing|comunicaz|liberatoria/i', $fkey)
        );
    if ($isOptionalConsent) {
        $optionalConsentFields[] = $field;
    }
}
usort($optionalConsentFields, static function (array $a, array $b): int {
    return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
});
$tesseraExtraFields = $fieldsByFormStep[\Socly\Services\MemberService::STEP_TESSERA] ?? [];
$paymentExtraFields = $fieldsByFormStep[\Socly\Services\MemberService::STEP_PAYMENT] ?? [];

$fieldValue = static function (array $field) use ($oldFields, $fieldValues): string {
    return (string) ($oldFields[$field['key']] ?? $fieldValues[$field['key']] ?? '');
};

$icons = [
    'photo' => 'camera',
    'first_name' => 'user',
    'last_name' => 'user',
    'gender' => 'users',
    'preferred_language' => 'globe',
    'birth_place' => 'map-pin',
    'birth_date' => 'calendar',
    'fiscal_code' => 'hash',
    'city' => 'building',
    'address' => 'home',
    'house_number' => 'hash',
    'postal_code' => 'mail',
    'email' => 'at',
    'phone' => 'phone',
];

$profileByKey = [];
foreach ($fields as $field) {
    $profileByKey[(string) $field['key']] = $field;
}

$geoAddressKeys = ['city', 'postal_code', 'address', 'house_number'];
$geoAddressKeySet = array_fill_keys($geoAddressKeys, true);

/** Full residence block across all archive fields (never split across wizard steps). */
$allGeoFieldsByKey = [];
foreach ($fields as $field) {
    $gk = (string) ($field['key'] ?? '');
    $gType = \Socly\Support\MemberFieldTypes::resolve((string) ($field['field_type'] ?? 'text'), $gk);
    if (isset($geoAddressKeySet[$gk]) || in_array($gType, [
        \Socly\Support\MemberFieldTypes::CITY,
        \Socly\Support\MemberFieldTypes::STREET,
        \Socly\Support\MemberFieldTypes::HOUSE_NUMBER,
        \Socly\Support\MemberFieldTypes::POSTAL_CODE,
    ], true)) {
        $allGeoFieldsByKey[$gk] = $field;
    }
}
$geoHomeStepKey = null;
foreach ($formSteps as $step) {
    $sk = (string) ($step['key'] ?? '');
    foreach ($fieldsByFormStep[$sk] ?? [] as $field) {
        $gk = (string) ($field['key'] ?? '');
        if (isset($allGeoFieldsByKey[$gk])) {
            $geoHomeStepKey = $sk;
            break 2;
        }
    }
}
if ($geoHomeStepKey === null && $allGeoFieldsByKey !== []) {
    $geoHomeStepKey = $defaultFormStepKey;
}

$renderTextField = static function (array $field) use ($fieldValue, $icons): string {
    $val = $fieldValue($field);
    $key = (string) $field['key'];
    $type = \Socly\Support\MemberFieldTypes::resolve((string) ($field['field_type'] ?? 'text'), $key);
    $label = localized($field['label_json'] ?? $field['label'] ?? $key);
    $icon = $icons[$key] ?? match ($type) {
        'email' => 'at',
        'phone' => 'phone',
        'date' => 'calendar',
        'fiscal_code' => 'hash',
        default => 'dot',
    };
    $inputType = match ($type) {
        'email' => 'email',
        'date' => 'date',
        'phone' => 'tel',
        default => 'text',
    };
    $required = !empty($field['is_required']);
    $attrs = $required ? ' required' : '';
    $placeholder = '';
    if ($type === 'phone') {
        $attrs .= ' data-phone-input inputmode="tel" autocomplete="tel"'
            . ' data-hint="' . e(__('members.phone_hint')) . '"'
            . ' data-invalid="' . e(__('validation.phone')) . '"';
        $placeholder = __('members.phone_placeholder');
    } elseif ($type === 'email') {
        $attrs .= ' data-email-input autocomplete="email"';
        $placeholder = __('members.email_placeholder');
    } elseif ($key === 'first_name') {
        $attrs .= ' data-first-name autocomplete="given-name"';
    } elseif ($key === 'last_name') {
        $attrs .= ' data-last-name autocomplete="family-name"';
    } elseif ($type === 'date' || $key === 'birth_date') {
        $attrs .= ' data-birth-date data-birth-allow-minor="1"';
    } elseif ($type === 'fiscal_code') {
        $attrs .= ' data-fiscal-code autocomplete="off" maxlength="16"';
        $placeholder = __('members.cf_hint');
    }
    $emphasis = ($type === 'phone' || $type === 'email') ? ' input-emphasis' : '';
    $phAttr = $placeholder !== '' ? ' placeholder="' . e($placeholder) . '"' : '';

    if ($type === 'textarea') {
        return '<div class="field-block" data-field="' . e($key) . '">'
            . '<label class="field-label" for="field-' . e($key) . '">'
            . '<span class="field-icon" data-icon="' . e($icon) . '" aria-hidden="true"></span>'
            . e($label) . ($required ? ' *' : '')
            . '</label>'
            . '<textarea id="field-' . e($key) . '" name="fields[' . e($key) . ']" rows="3"' . $attrs . $phAttr . '>' . e($val) . '</textarea>'
            . '</div>';
    }

    if ($type === 'gender') {
        return '<div class="field-block field-block--gender" data-field="' . e($key) . '">'
            . '<label class="field-label" for="field-' . e($key) . '">'
            . '<span class="field-icon" data-icon="users" aria-hidden="true"></span>'
            . e($label) . ($required ? ' *' : '')
            . '</label>'
            . '<select id="field-' . e($key) . '" name="fields[' . e($key) . ']" data-gender-input' . $attrs . '>'
            . '<option value="">—</option>'
            . '<option value="M"' . ($val === 'M' ? ' selected' : '') . '>' . e(__('members.gender_m')) . '</option>'
            . '<option value="F"' . ($val === 'F' ? ' selected' : '') . '>' . e(__('members.gender_f')) . '</option>'
            . '</select></div>';
    }

    if ($type === 'language') {
        if ($val === '') {
            $sessionLocale = (string) ($_SESSION['locale'] ?? '');
            $fallbackLocale = function_exists('app_locale') ? app_locale() : 'it';
            $currentLocale = in_array($sessionLocale, ['it', 'de', 'en'], true)
                ? $sessionLocale
                : (in_array($fallbackLocale, ['it', 'de', 'en'], true) ? $fallbackLocale : 'it');
            $val = $currentLocale;
        }
        return '<div class="field-block field-block--language" data-field="' . e($key) . '">'
            . '<label class="field-label" for="field-' . e($key) . '">'
            . '<span class="field-icon" data-icon="globe" aria-hidden="true"></span>'
            . e($label) . ($required ? ' *' : '')
            . '</label>'
            . '<select id="field-' . e($key) . '" name="fields[' . e($key) . ']" data-language-input' . $attrs . '>'
            . '<option value="">—</option>'
            . '<option value="it"' . ($val === 'it' ? ' selected' : '') . '>' . e(__('members.lang_it')) . '</option>'
            . '<option value="de"' . ($val === 'de' ? ' selected' : '') . '>' . e(__('members.lang_de')) . '</option>'
            . '<option value="en"' . ($val === 'en' ? ' selected' : '') . '>' . e(__('members.lang_en')) . '</option>'
            . '<option value="other"' . ($val === 'other' ? ' selected' : '') . '>' . e(__('members.lang_other')) . '</option>'
            . '</select></div>';
    }

    if ($type === 'checkbox') {
        $checked = $val === '1' || $val === 'on' || $val === 'yes';
        return '<div class="field-block" data-field="' . e($key) . '">'
            . '<label class="checkbox-row field-label" for="field-' . e($key) . '">'
            . '<input id="field-' . e($key) . '" type="checkbox" name="fields[' . e($key) . ']" value="1"'
            . ($checked ? ' checked' : '') . ($required ? ' required' : '') . '>'
            . '<span>' . e($label) . ($required ? ' *' : '') . '</span>'
            . '</label></div>';
    }

    if ($type === 'phone') {
        return '<div class="field-block" data-field="' . e($key) . '">'
            . '<label class="field-label" for="field-' . e($key) . '">'
            . '<span class="field-icon" data-icon="' . e($icon) . '" aria-hidden="true"></span>'
            . e($label) . ($required ? ' *' : '')
            . '</label>'
            . view_partial('partials/phone_field', [
                'name' => 'fields[' . $key . ']',
                'value' => $val,
                'required' => $required,
                'id' => 'field-' . $key,
                'class' => 'input-emphasis',
            ])
            . '</div>';
    }

    $requiredAttr = $required ? ' data-field-required="1"' : ' data-field-required="0"';

    if ($key === 'birth_date') {
        $ageYears = null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $bm)) {
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
        $ageText = $ageYears !== null
            ? __('members.age_years', ['age' => (string) $ageYears])
            : '';

        return '<div class="field-block field-block--birth-date" data-field="' . e($key) . '"' . $requiredAttr . '>'
            . '<label class="field-label" for="field-' . e($key) . '">'
            . '<span class="field-icon" data-icon="' . e($icon) . '" aria-hidden="true"></span>'
            . e($label) . ($required ? ' *' : '')
            . '</label>'
            . '<div class="birth-date-row">'
            . '<input id="field-' . e($key) . '" class="' . trim($emphasis) . '" type="date" name="fields[' . e($key) . ']" value="' . e($val) . '"' . $attrs . '>'
            . '<output class="birth-age" data-birth-age'
            . ' data-template="' . e(__('members.age_years')) . '"'
            . ' aria-live="polite"'
            . ($ageText === '' ? ' hidden' : '') . '>'
            . e($ageText)
            . '</output>'
            . '</div>'
            . '</div>';
    }

    return '<div class="field-block" data-field="' . e($key) . '"' . $requiredAttr . '>'
        . '<label class="field-label" for="field-' . e($key) . '">'
        . '<span class="field-icon" data-icon="' . e($icon) . '" aria-hidden="true"></span>'
        . e($label) . ($required ? ' *' : '')
        . '</label>'
        . '<input id="field-' . e($key) . '" class="' . trim($emphasis) . '" type="' . e($inputType) . '" name="fields[' . e($key) . ']" value="' . e($val) . '"' . $attrs . $phAttr . '>'
        . '</div>';
};

$reqFor = static function (string $k) use ($profileByKey): bool {
    return !empty($profileByKey[$k]['is_required']);
};
$valFor = static function (string $k) use ($profileByKey, $fieldValue): string {
    return isset($profileByKey[$k]) ? $fieldValue($profileByKey[$k]) : '';
};

$profileSideHtmlByStep = [];

$buildProfileFieldsHtml = function (array $list, ?string $stepKey = null) use (
    $fieldValue,
    $renderTextField,
    $geoAddressKeys,
    $allGeoFieldsByKey,
    $geoHomeStepKey,
    $profileByKey,
    $reqFor,
    $valFor,
    $isEdit,
    $member,
    &$profileSideHtmlByStep
): string {
    if ($list === []) {
        return '';
    }
    $listByKey = [];
    foreach ($list as $field) {
        $listByKey[(string) ($field['key'] ?? '')] = $field;
    }
    $geoBagByKey = $allGeoFieldsByKey !== [] ? $allGeoFieldsByKey : $listByKey;
    $renderFullGeoHere = $stepKey === null
        || $geoHomeStepKey === null
        || $stepKey === $geoHomeStepKey;

    $pick = static function (string $key) use ($listByKey): ?array {
        return $listByKey[$key] ?? null;
    };

    $canonicalKeys = array_fill_keys(\Socly\Support\MemberFieldTypes::profileFormRenderKeys(), true);
    $extras = [];
    foreach ($list as $field) {
        $ek = (string) ($field['key'] ?? '');
        if ($ek === '' || isset($canonicalKeys[$ek])) {
            continue;
        }
        $extras[] = $field;
    }
    usort($extras, static function (array $a, array $b): int {
        return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
    });

    $renderFiscal = static function (array $field) use ($fieldValue): string {
        $key = (string) ($field['key'] ?? 'fiscal_code');
        $val = $fieldValue($field);
        $label = localized($field['label_json'] ?? $field['label'] ?? $key);
        $required = !empty($field['is_required']);

        return '<div class="field-block" data-field="' . e($key) . '" data-field-required="' . ($required ? '1' : '0') . '">'
            . '<label class="field-label" for="field-' . e($key) . '">'
            . '<span class="field-icon" data-icon="hash" aria-hidden="true"></span>'
            . e($label) . ($required ? ' *' : '')
            . '</label>'
            . '<div class="cf-row">'
            . '<input id="field-' . e($key) . '" type="text" name="fields[' . e($key) . ']" value="' . e($val) . '"'
            . ' placeholder="' . e(__('members.cf_hint')) . '"'
            . ' size="18"'
            . ' data-fiscal-code autocomplete="off" maxlength="16" pattern="[A-Za-z0-9]{16}"'
            . ' data-incomplete="' . e(__('members.cf_incomplete')) . '"'
            . ' data-gender-other="' . e(__('members.cf_gender_other')) . '"'
            . ($required ? ' required' : '') . '>'
            . '<button type="button" class="btn btn-ghost" data-cf-generate>'
            . '<span class="field-icon" data-icon="spark" aria-hidden="true"></span>'
            . e(__('members.cf_generate'))
            . '</button>'
            . '</div>'
            . '</div>';
    };

    $renderPhoto = static function (array $field) use ($fieldValue, $valFor, $isEdit, $member): void {
        $key = (string) ($field['key'] ?? 'photo');
        $val = $fieldValue($field);
        $required = !empty($field['is_required']);
        $genderRaw = strtoupper(trim($valFor('gender')));
        $photoGender = $genderRaw === 'M' ? 'm' : ($genderRaw === 'F' ? 'f' : 'neutral');
        $hasExistingPhoto = $isEdit && $val !== '';
        ?>
        <div
            class="photo-field field-block"
            data-field="<?= e($key) ?>"
            data-field-required="<?= $required ? '1' : '0' ?>"
            data-photo-field
            data-has-photo="<?= $hasExistingPhoto ? '1' : '0' ?>"
        >
            <div class="photo-upload photo-upload--card">
                <?php if ($hasExistingPhoto): ?>
                    <button type="button" class="photo-preview-btn" data-photo-lightbox-open aria-label="<?= e(__('members.photo_preview_open')) ?>">
                        <img class="photo-preview" src="<?= e(url('/members/'.$member['id'].'/photo')) ?>" alt="">
                    </button>
                <?php else: ?>
                    <div class="photo-placeholder" data-photo-placeholder data-photo-gender="<?= e($photoGender) ?>" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="photo-controls">
                    <div class="photo-actions">
                        <label class="btn btn-sm btn-ghost file-btn" data-photo-upload-btn<?= $hasExistingPhoto ? ' hidden' : '' ?>>
                            <span class="field-icon" data-icon="upload" aria-hidden="true"></span>
                            <?= e(__('members.photo_upload')) ?>
                            <input
                                type="file"
                                name="fields[photo]"
                                accept="image/jpeg,image/png,image/webp"
                                data-photo-input
                                data-invalid="<?= e(__('validation.photo')) ?>"
                                data-max-bytes="3145728"
                                <?= $required && !$hasExistingPhoto ? ' required' : '' ?>
                                hidden
                            >
                        </label>
                        <button
                            type="button"
                            class="btn btn-sm btn-ghost"
                            data-photo-remove
                            <?= $hasExistingPhoto ? '' : ' hidden' ?>
                        >
                            <?= e(__('members.photo_remove')) ?>
                        </button>
                        <button type="button" class="btn btn-sm" data-camera-open<?= $hasExistingPhoto ? ' hidden' : '' ?>>
                            <span class="field-icon" data-icon="camera" aria-hidden="true"></span>
                            <?= e(__('members.photo_capture')) ?>
                        </button>
                        <input type="file" accept="image/*" capture="user" data-camera-capture hidden>
                    </div>
                    <input type="hidden" name="remove_photo" value="" data-photo-remove-flag>
                    <p class="muted" data-photo-hint data-upload-only="<?= e(__('members.photo_hint_upload_only')) ?>" data-invalid="<?= e(__('validation.photo')) ?>"><?= e(__('members.photo_hint')) ?></p>
                    <p
                        class="field-hint"
                        data-camera-error
                        data-unavailable="<?= e(__('members.photo_camera_unavailable')) ?>"
                        data-not-ready="<?= e(__('members.photo_camera_not_ready')) ?>"
                        hidden
                    ></p>
                </div>
            </div>
        </div>
        <?php
    };

    $emitRow = static function (array $cells, string $rowClass = 'member-field-row'): void {
        if ($cells === []) {
            return;
        }
        echo '<div class="' . e($rowClass) . '">';
        foreach ($cells as $html) {
            echo $html;
        }
        echo '</div>';
    };

    $blockHasAny = static function (array $keys) use ($pick): bool {
        foreach ($keys as $key) {
            if ($pick($key) !== null) {
                return true;
            }
        }

        return false;
    };

    ob_start();

    $baseKeys = ['first_name', 'last_name', 'gender', 'preferred_language'];
    $hasBase = $blockHasAny($baseKeys);
    $photoField = $pick('photo');
    $profileHasBase = false;
    foreach ($baseKeys as $baseKey) {
        if (isset($profileByKey[$baseKey])) {
            $profileHasBase = true;
            break;
        }
    }
    // Keep photography with anagrafica (right column) even when the field lives on another step.
    if ($hasBase && $photoField === null && isset($profileByKey['photo'])) {
        $photoField = $profileByKey['photo'];
    } elseif (!$hasBase && $photoField !== null && $profileHasBase) {
        $photoField = null;
    }
    if ($hasBase): ?>
        <div class="member-profile-identity">
            <div class="member-profile-block member-profile-block--base">
                <h3 class="member-profile-block-title"><?= e(__('members.profile_block_base')) ?></h3>
                <?php
                $nameRow = array_filter([
                    $pick('first_name') ? $renderTextField($pick('first_name')) : null,
                    $pick('last_name') ? $renderTextField($pick('last_name')) : null,
                ]);
                $emitRow($nameRow, 'member-field-row member-field-row--identity-names');

                $metaRow = array_filter([
                    $pick('gender') ? $renderTextField($pick('gender')) : null,
                    $pick('preferred_language') ? $renderTextField($pick('preferred_language')) : null,
                ]);
                $emitRow($metaRow, 'member-field-row member-field-row--identity-meta');
                ?>
            </div>
        </div>
    <?php endif;
    if ($photoField !== null && $stepKey !== null):
        ob_start();
        ?>
        <div class="member-profile-block member-profile-block--photo member-profile-block--photo-side">
            <h3 class="member-profile-block-title">
                <?= e(__('members.profile_block_photo')) ?>
                <?= !empty($photoField['is_required']) ? ' *' : '' ?>
            </h3>
            <?php $renderPhoto($photoField); ?>
        </div>
        <?php
        $profileSideHtmlByStep[$stepKey] = (string) ob_get_clean();
    elseif ($photoField !== null && $stepKey === null):
        // Non-step contexts (rare): keep photo inline.
        ?>
        <div class="member-profile-block member-profile-block--photo">
            <h3 class="member-profile-block-title">
                <?= e(__('members.profile_block_photo')) ?>
                <?= !empty($photoField['is_required']) ? ' *' : '' ?>
            </h3>
            <?php $renderPhoto($photoField); ?>
        </div>
    <?php endif;

    $fiscalField = $pick('fiscal_code');
    if ($blockHasAny(['birth_date', 'birth_place']) || $fiscalField !== null): ?>
        <div class="member-profile-block member-profile-block--birth">
            <h3 class="member-profile-block-title"><?= e(__('members.profile_block_birth')) ?></h3>
            <div class="member-field-row member-field-row--birth member-field-row--birth-fiscal">
                <?php
                $birthDate = $pick('birth_date');
                if ($birthDate !== null) {
                    echo $renderTextField($birthDate);
                }
                $birthPlace = $pick('birth_place');
                if ($birthPlace !== null):
                ?>
                    <div class="geo-birth-host" data-field="birth_place">
                        <?= view_partial('partials/geo_birth_place', [
                            'name' => 'fields[birth_place]',
                            'value' => $fieldValue($birthPlace),
                            'required' => !empty($birthPlace['is_required']),
                            'id' => 'field-birth_place',
                            'class' => 'member-geo-birth',
                            'flag_name' => 'birth_place_foreign',
                            'foreign_inline' => true,
                        ]) ?>
                    </div>
                <?php endif;
                if ($fiscalField !== null) {
                    echo $renderFiscal($fiscalField);
                }
                ?>
            </div>
        </div>
    <?php endif;

    $hasResidence = false;
    foreach (['city', 'postal_code', 'address', 'house_number'] as $gk) {
        if (isset($geoBagByKey[$gk])) {
            $hasResidence = true;
            break;
        }
    }
    if ($hasResidence && $renderFullGeoHere): ?>
        <div class="member-profile-block">
            <h3 class="member-profile-block-title"><?= e(__('members.profile_block_residence')) ?></h3>
            <div class="geo-address-host" data-field="residence">
                <?= view_partial('partials/geo_address', [
                    'layout' => 'rows',
                    'class' => 'geo-address-member',
                    'foreign_toggle' => false,
                    'foreign_inline' => true,
                    'names' => [
                        'city' => 'fields[city]',
                        'postal_code' => 'fields[postal_code]',
                        'address' => 'fields[address]',
                        'house_number' => 'fields[house_number]',
                    ],
                    'values' => [
                        'city' => $valFor('city'),
                        'postal_code' => $valFor('postal_code'),
                        'address' => $valFor('address'),
                        'house_number' => $valFor('house_number'),
                    ],
                    'required' => [
                        'city' => $reqFor('city'),
                        'postal_code' => $reqFor('postal_code'),
                        'address' => $reqFor('address'),
                        'house_number' => $reqFor('house_number'),
                    ],
                    'ids' => [
                        'city' => 'field-city',
                        'postal_code' => 'field-postal_code',
                        'address' => 'field-address',
                        'house_number' => 'field-house_number',
                    ],
                    'enabled' => [
                        'city' => isset($geoBagByKey['city']),
                        'postal_code' => isset($geoBagByKey['postal_code']),
                        'address' => isset($geoBagByKey['address']),
                        'house_number' => isset($geoBagByKey['house_number']),
                    ],
                ]) ?>
            </div>
        </div>
    <?php endif;

    if ($extras !== []): ?>
        <div class="member-profile-block member-profile-block--extra">
            <?php foreach ($extras as $extra): ?>
                <?php
                $type = \Socly\Support\MemberFieldTypes::resolve((string) ($extra['field_type'] ?? 'text'), (string) ($extra['key'] ?? ''));
                $full = in_array($type, [
                    \Socly\Support\MemberFieldTypes::TEXTAREA,
                    \Socly\Support\MemberFieldTypes::PHOTO,
                ], true);
                ?>
                <div class="member-field-row is-full">
                    <?= $renderTextField($extra) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;

    if ($blockHasAny(['email', 'phone'])): ?>
        <div class="member-profile-block">
            <h3 class="member-profile-block-title"><?= e(__('members.profile_block_contact')) ?></h3>
            <?php
            $contactRow = [];
            foreach (['email', 'phone'] as $contactKey) {
                $cf = $pick($contactKey);
                if ($cf !== null) {
                    $contactRow[] = $renderTextField($cf);
                }
            }
            $emitRow($contactRow, 'member-field-row member-field-row--contact');
            ?>
        </div>
    <?php endif;

    return (string) ob_get_clean();
};

$profileHtmlByStep = [];
foreach ($formSteps as $step) {
    $stepKey = (string) ($step['key'] ?? '');
    $profileHtmlByStep[$stepKey] = $buildProfileFieldsHtml($fieldsByFormStep[$stepKey] ?? [], $stepKey);
}
$tesseraExtraHtml = $buildProfileFieldsHtml($tesseraExtraFields, \Socly\Services\MemberService::STEP_TESSERA);
$paymentExtraHtml = $buildProfileFieldsHtml($paymentExtraFields, \Socly\Services\MemberService::STEP_PAYMENT);
?>

<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php
        $pageLede = $isEdit ? __('members.edit_lede') : __('members.create_lede');
        if (trim($pageLede) !== ''):
        ?>
            <p class="page-lede"><?= e($pageLede) ?></p>
        <?php endif; ?>
    </div>
</div>

<form
    class="form-card member-form member-wizard"
    method="post"
    action="<?= e($isEdit ? url('/members/'.$member['id']) : url('/members')) ?>"
    enctype="multipart/form-data"
    data-member-form
    data-wizard
    data-member-leave-guard="<?= $isEdit ? 'edit' : 'create' ?>"
    data-photo-preview-open="<?= e(__('members.photo_preview_open')) ?>"
    data-photo-preview-close="<?= e(__('members.photo_preview_close')) ?>"
    data-photo-avatar-base="<?= e(asset('img/avatars')) ?>"
    data-total-steps="<?= (int)$totalSteps ?>"
    data-cities-url="<?= e(url('/api/geo/cities')) ?>"
    data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
    data-cap-url="<?= e(url('/api/geo/cap')) ?>"
    data-provinces-url="<?= e(url('/api/geo/provinces')) ?>"
    data-cf-url="<?= e(url('/api/fiscal-code')) ?>"
    data-csrf="<?= e(csrf_token()) ?>"
    data-enrollment-method="<?= e($enrollmentMethod) ?>"
    data-otp-url="<?= e(url('/members/enrollment/otp')) ?>"
    data-treasury-enabled="<?= $treasuryEnabled ? '1' : '0' ?>"
    autocomplete="off"
>
    <?= csrf_field() ?>
    <?php if (!$isEdit && $treasuryEnabled): ?>
        <input type="hidden" name="treasury_skip" value="" data-treasury-skip>
        <input type="hidden" name="treasury_register" value="" data-treasury-register>
        <input type="hidden" name="treasury_movement_date" value="" data-treasury-movement-date>
        <input type="hidden" name="treasury_description" value="" data-treasury-description>
    <?php endif; ?>

    <ol class="wizard-steps" data-wizard-steps aria-label="<?= e(__('members.wizard_nav')) ?>">
        <?php foreach ($formSteps as $i => $step): ?>
            <?php
            $stepNum = $i + 1;
            $stepTitle = localized($step['title_json'] ?? '') ?: (string) ($step['key'] ?? '');
            ?>
            <li class="wizard-step<?= $stepNum === 1 ? ' is-active' : '' ?>" data-step-indicator="<?= (int) $stepNum ?>" aria-label="<?= e($stepTitle) ?>" title="<?= e($stepTitle) ?>">
                <span class="wizard-index"><?= (int) $stepNum ?></span>
            </li>
        <?php endforeach; ?>
        <li class="wizard-step" data-step-indicator="<?= (int) $tesseraStep ?>" aria-label="<?= e(__('members.wizard_step2_title')) ?>" title="<?= e(__('members.wizard_step2_title')) ?>">
            <span class="wizard-index"><?= (int) $tesseraStep ?></span>
        </li>
        <li class="wizard-step" data-step-indicator="<?= (int) $ackStep ?>" aria-label="<?= e(__('members.wizard_step3_title')) ?>" title="<?= e(__('members.wizard_step3_title')) ?>">
            <span class="wizard-index"><?= (int) $ackStep ?></span>
        </li>
        <?php if ($needsEnrollment && $enrollmentStep !== null): ?>
        <li class="wizard-step" data-step-indicator="<?= (int) $enrollmentStep ?>" aria-label="<?= e(__('members.wizard_enrollment_title')) ?>" title="<?= e(__('members.wizard_enrollment_title')) ?>">
            <span class="wizard-index"><?= (int) $enrollmentStep ?></span>
        </li>
        <?php endif; ?>
    </ol>

    <div class="wizard-progress" aria-hidden="true"><span data-wizard-progress></span></div>

    <?php foreach ($formSteps as $i => $step): ?>
        <?php
        $stepNum = $i + 1;
        $stepKey = (string) ($step['key'] ?? '');
        $stepTitle = localized($step['title_json'] ?? '') ?: $stepKey;
        ?>
        <section class="wizard-panel<?= $stepNum === 1 ? ' is-active' : '' ?>" data-wizard-panel="<?= (int) $stepNum ?>"<?= $stepNum === 1 ? '' : ' hidden' ?>>
            <div class="wizard-panel-head">
                <h2 class="section-title"><?= e($stepTitle) ?></h2>
                <p class="section-lede"><?= e(__('members.profile_lede')) ?></p>
            </div>
            <?php
            $sideHtml = trim((string) ($profileSideHtmlByStep[$stepKey] ?? ''));
            $hasPhotoSide = $sideHtml !== '';
            ?>
            <div class="wizard-panel-body<?= $hasPhotoSide ? ' wizard-panel-body--photo' : '' ?>">
                <div class="wizard-panel-main member-profile-grid">
                    <?= $profileHtmlByStep[$stepKey] ?? '' ?>
                </div>
                <?php if ($hasPhotoSide): ?>
                    <aside class="wizard-panel-side wizard-panel-side--photo">
                        <?= $sideHtml ?>
                    </aside>
                <?php else: ?>
                    <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="wizard-panel" data-wizard-panel="<?= (int) $tesseraStep ?>" hidden>
        <div class="wizard-panel-head">
            <h2 class="section-title"><?= e(__('members.wizard_step2_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.wizard_step2_lede')) ?></p>
        </div>
        <div class="wizard-panel-body wizard-panel-body--tessera">
            <?php
            $tesseraPhotoSrc = '';
            if ($isEdit && !empty($member['id']) && trim((string) ($fieldValues['photo'] ?? '')) !== '') {
                $tesseraPhotoSrc = url('/members/' . (int) $member['id'] . '/photo');
            }
            $tesseraGenderRaw = strtoupper(trim($valFor('gender')));
            $tesseraPhotoGender = $tesseraGenderRaw === 'M' ? 'm' : ($tesseraGenderRaw === 'F' ? 'f' : 'neutral');
            $tesseraName = trim($fieldValue([
                'key' => 'first_name',
            ]) . ' ' . $fieldValue([
                'key' => 'last_name',
            ]));
            if ($tesseraName === '') {
                $tesseraName = __('members.wizard_card_name');
            }
            ?>
            <div class="wizard-panel-main tessera-step-fields member-profile-grid">
                <?php
                $defaultStatus = $isEdit ? (string) ($member['status'] ?? 'active') : 'active';
                $currentStatus = (string) old('status', $defaultStatus);
                ?>
                <div class="member-profile-block">
                    <h3 class="member-profile-block-title"><?= e(__('members.wizard_step2_title')) ?></h3>
                    <div class="member-field-row member-field-row--tessera-meta">
                        <div class="field-block field-block--member-number">
                            <label class="field-label" for="field-member-number"><?= e(__('members.member_number')) ?></label>
                            <input
                                id="field-member-number"
                                type="text"
                                name="member_number"
                                data-member-number
                                inputmode="text"
                                autocomplete="off"
                                value="<?= e((string) old('member_number', $member['member_number'] ?? $nextNumber)) ?>"
                                required
                            >
                        </div>
                        <div class="field-block field-block--status">
                            <label class="field-label" for="field-member-status"><?= e(__('members.status')) ?></label>
                            <select id="field-member-status" name="status">
                                <?php foreach (\Socly\Services\MemberService::memberStatuses() as $st): ?>
                                    <option value="<?= $st ?>" <?= $currentStatus === $st ? 'selected' : '' ?>><?= e(__('members.status_'.$st)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-block field-block--type">
                            <label class="field-label" for="field-member-type"><?= e(__('members.type')) ?></label>
                            <select id="field-member-type" name="member_type_id" data-member-type required>
                                <?php foreach ($types as $type): ?>
                                    <option
                                        value="<?= (int) $type['id'] ?>"
                                        data-price="<?= e(number_format((float) $type['price'], 2, '.', '')) ?>"
                                        data-type-name="<?= e(localized($type['name_json'])) ?>"
                                        <?= (string) old('member_type_id', $member['member_type_id'] ?? '') === (string) $type['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e(localized($type['name_json'])) ?> (<?= e(number_format((float) $type['price'], 2, ',', '.')) ?> €)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-block field-block--period">
                            <label class="field-label" for="field-member-period"><?= e(__('members.period')) ?></label>
                            <select id="field-member-period" name="membership_period_id" required>
                                <?php foreach ($periods as $period): ?>
                                    <option value="<?= (int) $period['id'] ?>" <?= (string) old('membership_period_id', $member['membership_period_id'] ?? ($period['is_current'] ? $period['id'] : '')) === (string) $period['id'] ? 'selected' : '' ?>>
                                        <?= e($period['label']) ?><?= $period['is_current'] ? ' ★' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (!$isEdit): ?>
                <div class="payment-summary member-profile-block member-profile-block--payment-summary" data-tessera-payment-summary>
                    <div class="payment-summary-head">
                        <span class="payment-summary-kicker"><?= e(__('members.wizard_summary_kicker')) ?></span>
                        <strong class="payment-summary-type" data-payment-type-label>—</strong>
                    </div>
                    <div class="payment-summary-metrics">
                        <div class="payment-summary-metric">
                            <span class="payment-summary-label"><?= e(__('members.wizard_quota')) ?></span>
                            <strong class="payment-summary-value" data-payment-amount>0,00 €</strong>
                        </div>
                        <div class="payment-summary-metric payment-summary-metric--due" data-payment-due-wrap>
                            <span class="payment-summary-label"><?= e(__('members.wizard_still_due')) ?></span>
                            <strong class="payment-summary-value" data-payment-due>0,00 €</strong>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="member-profile-block" data-guardian-field hidden>
                    <div class="field-block" data-guardian-required-label="<?= e(__('members.guardian_required_badge')) ?>">
                        <div class="guardian-label-row">
                            <label class="field-label" for="field-guardian-select"><?= e(__('members.guardian_label')) ?></label>
                            <span class="guardian-required-badge" data-guardian-badge><?= e(__('members.guardian_required_badge')) ?></span>
                        </div>
                        <input
                            type="search"
                            class="guardian-search"
                            data-guardian-search
                            placeholder="<?= e(__('members.guardian_search_placeholder')) ?>"
                            autocomplete="off"
                            hidden
                        >
                        <select id="field-guardian-select" name="guardian_member_id" data-guardian-select>
                            <option value=""><?= e(__('members.guardian_none')) ?></option>
                            <?php foreach ($guardianCandidates as $candidate): ?>
                                <option
                                    value="<?= (int) $candidate['id'] ?>"
                                    <?= $oldGuardianId === (string) $candidate['id'] ? 'selected' : '' ?>
                                ><?= e((string) ($candidate['display_name'] ?? $candidate['member_number'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <details class="guardian-quick" data-guardian-quick<?= ($oldGuardianQuick['first_name'] ?? '') !== '' || ($oldGuardianQuick['last_name'] ?? '') !== '' ? ' open' : '' ?>>
                            <summary><?= e(__('members.guardian_quick_summary')) ?></summary>
                            <div class="guardian-quick-grid">
                                <div class="field-block">
                                    <label class="field-label" for="field-guardian-fn"><?= e(__('members.guardian_quick_first_name')) ?></label>
                                    <input id="field-guardian-fn" type="text" name="guardian_quick[first_name]" value="<?= e((string) ($oldGuardianQuick['first_name'] ?? '')) ?>" data-guardian-quick-first autocomplete="given-name">
                                </div>
                                <div class="field-block">
                                    <label class="field-label" for="field-guardian-ln"><?= e(__('members.guardian_quick_last_name')) ?></label>
                                    <input id="field-guardian-ln" type="text" name="guardian_quick[last_name]" value="<?= e((string) ($oldGuardianQuick['last_name'] ?? '')) ?>" data-guardian-quick-last autocomplete="family-name">
                                </div>
                                <div class="field-block">
                                    <label class="field-label" for="field-guardian-phone"><?= e(__('members.guardian_quick_phone')) ?></label>
                                    <input id="field-guardian-phone" type="tel" name="guardian_quick[phone]" value="<?= e((string) ($oldGuardianQuick['phone'] ?? '')) ?>" data-guardian-quick-phone autocomplete="tel">
                                </div>
                                <div class="field-block">
                                    <label class="field-label" for="field-guardian-email"><?= e(__('members.guardian_quick_email')) ?></label>
                                    <input id="field-guardian-email" type="email" name="guardian_quick[email]" value="<?= e((string) ($oldGuardianQuick['email'] ?? '')) ?>" data-guardian-quick-email autocomplete="email">
                                </div>
                            </div>
                            <p class="muted"><?= e(__('members.guardian_quick_hint')) ?></p>
                        </details>
                        <p class="muted" data-guardian-hint><?= e(__('members.guardian_hint')) ?></p>
                    </div>
                </div>

                <?php if (!$isEdit):
                    $paymentStatusCards = [
                        'unpaid' => ['label' => __('members.payment_unpaid'), 'icon' => 'clock'],
                        'partial' => ['label' => __('members.payment_partial'), 'icon' => 'split'],
                        'paid' => ['label' => __('members.payment_paid'), 'icon' => 'check'],
                    ];
                    $paymentMethodCards = [
                        'cash' => ['label' => __('payments.cash'), 'icon' => 'cash'],
                        'bank' => ['label' => __('payments.bank'), 'icon' => 'bank'],
                        'other' => ['label' => __('payments.other'), 'icon' => 'other'],
                    ];
                    $paymentIcons = [
                        'clock' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 11h4v-2h-3V7h-2z"/></svg>',
                        'split' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 6h7v2H4zm0 5h16v2H4zm0 5h11v2H4z"/></svg>',
                        'check' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9.2 16.6 4.8 12.2l1.4-1.4 3 3 8-8 1.4 1.4z"/></svg>',
                        'cash' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 6h18v12H3zm2 2v8h14V8zm7 1.5A2.5 2.5 0 1 1 9.5 12 2.5 2.5 0 0 1 12 9.5z"/></svg>',
                        'bank' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 10h18v2H3zm2 3h3v5H5zm5 0h3v5h-3zm5 0h3v5h-3zM2 20h20v2H2zM12 3 2 8v2h20V8z"/></svg>',
                        'other' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 7a2 2 0 1 0-2-2 2 2 0 0 0 2 2zm0 7a2 2 0 1 0-2-2 2 2 0 0 0 2 2zm0 7a2 2 0 1 0-2-2 2 2 0 0 0 2 2z"/></svg>',
                    ];
                ?>
                <div class="member-profile-block tessera-payment-panel" data-payment-fields>
                    <div class="field-block">
                        <span class="field-label"><?= e(__('members.payment')) ?></span>
                        <div class="tessera-choice-cards" role="radiogroup" aria-label="<?= e(__('members.payment')) ?>">
                            <?php foreach ($paymentStatusCards as $optVal => $card): ?>
                                <label class="tessera-choice-card<?= $oldPaymentStatus === $optVal ? ' is-selected' : '' ?>">
                                    <input type="radio" name="payment_status" value="<?= e($optVal) ?>" <?= $oldPaymentStatus === $optVal ? 'checked' : '' ?> required>
                                    <span class="tessera-choice-card-icon"><?= $paymentIcons[$card['icon']] ?></span>
                                    <span class="tessera-choice-card-title"><?= e($card['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="field-block">
                        <span class="field-label"><?= e(__('members.payment_method')) ?></span>
                        <div class="tessera-choice-cards" role="radiogroup" aria-label="<?= e(__('members.payment_method')) ?>">
                            <?php foreach ($paymentMethodCards as $optVal => $card): ?>
                                <label class="tessera-choice-card<?= $oldPaymentMethod === $optVal ? ' is-selected' : '' ?>">
                                    <input type="radio" name="payment_method" value="<?= e($optVal) ?>" <?= $oldPaymentMethod === $optVal ? 'checked' : '' ?> required>
                                    <span class="tessera-choice-card-icon"><?= $paymentIcons[$card['icon']] ?></span>
                                    <span class="tessera-choice-card-title"><?= e($card['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div data-partial-wrap<?= $oldPaymentStatus === 'partial' ? '' : ' hidden' ?> class="field-block tessera-partial-amount">
                        <label class="field-label" for="field-partial-amount"><?= e(__('members.partial_amount')) ?></label>
                        <input
                            id="field-partial-amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            name="partial_amount"
                            inputmode="decimal"
                            placeholder="0,00"
                            value="<?= e($oldPartialAmount === '0' ? '' : $oldPartialAmount) ?>"
                            data-partial-amount
                            <?= $oldPaymentStatus === 'partial' ? 'required' : '' ?>
                        >
                        <p class="muted tessera-partial-hint"><?= e(__('members.partial_amount_hint')) ?></p>
                    </div>
                    <?php if ($treasuryEnabled): ?>
                    <p class="field-hint muted payment-treasury-note" data-payment-treasury-note>
                        <?= e(__('members.payment_treasury_note')) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="member-profile-block">
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.notes')) ?></label>
                        <textarea name="notes" rows="3"><?= e((string) old('notes', $member['notes'] ?? '')) ?></textarea>
                    </div>
                    <?php if ($tesseraExtraHtml !== ''): ?>
                        <?= $tesseraExtraHtml ?>
                    <?php endif; ?>
                    <?php if (!$isEdit && $paymentExtraHtml !== ''): ?>
                        <?= $paymentExtraHtml ?>
                    <?php endif; ?>
                </div>
            </div>
            <aside class="wizard-panel-side tessera-preview" aria-hidden="true">
                <?php $tesseraLogoUrl = assoc_logo_url(); ?>
                <div class="tessera-card"<?= $tesseraLogoUrl ? ' style="--tessera-logo:url(\'' . e($tesseraLogoUrl) . '\')"' : '' ?>>
                    <div class="tessera-card-bg" aria-hidden="true"></div>
                    <?php if ($tesseraLogoUrl): ?>
                        <div class="tessera-card-mark" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div class="tessera-card-shine"></div>
                    <div class="tessera-card-face">
                        <div class="tessera-card-top">
                            <div class="tessera-brand">
                                <?= assoc_lockup_html(['class' => 'assoc-lockup-tessera']) ?: socly_mark_img('tessera-mark', 'SOCLY', 'light') ?>
                            </div>
                        </div>
                        <div class="tessera-card-main">
                            <div class="tessera-photo<?= $tesseraPhotoSrc === '' ? ' is-empty' : '' ?>" data-tessera-photo-wrap>
                                <?php if ($tesseraPhotoSrc !== ''): ?>
                                    <img
                                        class="tessera-photo-img"
                                        data-tessera-photo
                                        src="<?= e($tesseraPhotoSrc) ?>"
                                        alt=""
                                    >
                                    <span class="tessera-photo-placeholder photo-placeholder" data-photo-placeholder data-tessera-photo-placeholder data-photo-gender="<?= e($tesseraPhotoGender) ?>" hidden></span>
                                <?php else: ?>
                                    <img class="tessera-photo-img" data-tessera-photo alt="" hidden>
                                    <span class="tessera-photo-placeholder photo-placeholder" data-photo-placeholder data-tessera-photo-placeholder data-photo-gender="<?= e($tesseraPhotoGender) ?>"></span>
                                <?php endif; ?>
                            </div>
                            <div class="tessera-meta">
                                <span class="tessera-name" data-tessera-name data-fallback="<?= e(__('members.wizard_card_name')) ?>"><?= e($tesseraName) ?></span>
                                <strong class="tessera-number" data-tessera-number>#<?= e((string) old('member_number', $member['member_number'] ?? $nextNumber)) ?></strong>
                                <span class="tessera-type" data-tessera-type data-role="<?= e(__('members.card_role')) ?>"><strong><?= e(__('members.card_role')) ?></strong> —</span>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <section class="wizard-panel" data-wizard-panel="<?= (int) $ackStep ?>" hidden>
        <div class="wizard-panel-head">
            <h2 class="section-title"><?= e(__('members.wizard_step3_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.acknowledgements_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main">
        <?php
        $legal = $legal ?? ['privacy' => '', 'statute' => ''];
        $docMap = [
            'privacy_ack' => [
                'title' => __('members.legal_privacy_title'),
                'body' => (string) ($legal['privacy'] ?? ''),
                'read' => __('members.legal_privacy_read'),
            ],
            'statute_ack' => [
                'title' => __('members.legal_statute_title'),
                'body' => (string) ($legal['statute'] ?? ''),
                'read' => __('members.legal_statute_read'),
            ],
        ];
        ?>
        <?php if ($ackFields): ?>
            <div class="ack-list member-profile-block">
                <h3 class="member-profile-block-title"><?= e(__('members.ack_docs_title')) ?></h3>
                <?php foreach ($ackFields as $field): ?>
                    <?php
                    $fkey = (string) ($field['key'] ?? '');
                    $doc = $docMap[$fkey] ?? null;
                    if (!$doc) {
                        continue;
                    }
                    $val = $fieldValue($field);
                    $checked = $val === '1' || $val === 'on' || $val === 'yes';
                    $label = localized($field['label_json'] ?? $field['label'] ?? $fkey);
                    $hasDoc = trim($doc['body']) !== '';
                    ?>
                    <div class="ack-item" data-ack-item>
                        <label class="checkbox-row ack-row">
                            <input
                                type="checkbox"
                                name="fields[<?= e($fkey) ?>]"
                                value="1"
                                data-ack-checkbox
                                <?= $checked ? 'checked' : '' ?>
                                <?= !empty($field['is_required']) ? 'required' : '' ?>
                                <?= ($hasDoc && !$checked) ? 'data-requires-read="1"' : '' ?>
                            >
                            <span><?= e($label) ?><?= !empty($field['is_required']) ? ' *' : '' ?></span>
                        </label>
                        <button type="button" class="btn btn-sm btn-ghost" data-doc-open data-doc-key="<?= e($fkey) ?>">
                            <span class="field-icon" data-icon="doc" aria-hidden="true"></span>
                            <?= e($doc['read']) ?>
                        </button>
                        <dialog class="doc-dialog" data-doc-dialog="<?= e($fkey) ?>">
                            <div class="doc-shell">
                                <div class="doc-head">
                                    <h3 class="section-title"><?= e($doc['title']) ?></h3>
                                    <button type="button" class="btn btn-sm btn-ghost" data-doc-close><?= e(__('common.cancel')) ?></button>
                                </div>
                                <?php if ($hasDoc): ?>
                                    <div class="doc-body"><?= nl2br(e($doc['body'])) ?></div>
                                    <div class="doc-actions">
                                        <button type="button" class="btn" data-doc-accept><?= e(__('members.legal_confirm_read')) ?></button>
                                    </div>
                                <?php else: ?>
                                    <p class="muted"><?= e(__('members.legal_missing')) ?></p>
                                    <?php if (can('settings.manage')): ?>
                                        <p><a href="<?= e(url('/settings#legal')) ?>"><?= e(__('members.legal_configure')) ?></a></p>
                                    <?php endif; ?>
                                    <div class="doc-actions">
                                        <button type="button" class="btn btn-ghost" data-doc-close><?= e(__('common.back')) ?></button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </dialog>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="member-profile-block">
                <p class="muted"><?= e(__('members.legal_step_empty')) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($optionalConsentFields !== []): ?>
            <div class="ack-optional member-profile-block">
                <h3 class="member-profile-block-title"><?= e(__('members.ack_optional_title')) ?></h3>
                <p class="muted ack-optional-lede"><?= e(__('members.ack_optional_lede')) ?></p>
                <div class="ack-optional-list">
                    <?php foreach ($optionalConsentFields as $field): ?>
                        <?php
                        $fkey = (string) ($field['key'] ?? '');
                        $val = $fieldValue($field);
                        $checked = $val === '1' || $val === 'on' || $val === 'yes';
                        $label = localized($field['label_json'] ?? $field['label'] ?? $fkey);
                        ?>
                        <label class="checkbox-row ack-optional-row">
                            <input
                                type="checkbox"
                                name="fields[<?= e($fkey) ?>]"
                                value="1"
                                <?= $checked ? 'checked' : '' ?>
                            >
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

            <p class="ack-trace muted" data-ack-trace>
                <?= e(__('members.ack_secretariat_trace')) ?>
            </p>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>

    <?php if ($needsEnrollment): ?>
    <section class="wizard-panel" data-wizard-panel="<?= (int) $enrollmentStep ?>" hidden>
        <div class="wizard-panel-head">
            <h2 class="section-title"><?= e(__('members.wizard_enrollment_title')) ?></h2>
            <p class="section-lede"><?= e($isEdit ? __('members.wizard_enrollment_fix_lede') : __('members.wizard_enrollment_lede')) ?></p>
        </div>
        <div class="wizard-panel-body">
            <div class="wizard-panel-main member-profile-block">
                <input type="hidden" name="enrollment_method" value="<?= e($enrollmentMethod) ?>">

        <?php if ($enrollmentMethod === 'print_scan'): ?>
            <p class="muted"><?= e(__('members.enrollment_print_hint')) ?></p>
            <p class="actions" style="margin:0 0 0.75rem">
                <?php if ($isEdit && !empty($member['id'])): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/members/' . (int) $member['id'] . '/enrollment-form')) ?>" target="_blank" rel="noopener"><?= e(__('members.enrollment_print_form')) ?></a>
                <?php endif; ?>
            </p>
            <div class="field-block">
                <label class="field-label"><?= e(__('members.enrollment_scan')) ?></label>
                <input type="file" name="enrollment_scan" accept="image/*,application/pdf"<?= ($isEdit && $needsEnrollment) ? ' required' : '' ?>>
                <?php if (!$isEdit): ?>
                    <p class="muted"><?= e(__('members.enrollment_scan_optional')) ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ($enrollmentMethod === 'tablet_sign'): ?>
            <p class="muted"><?= e(__('members.enrollment_tablet_hint')) ?></p>
            <div class="enrollment-pad" data-enrollment-pad>
                <canvas data-sign-canvas width="640" height="220" aria-label="<?= e(__('members.enrollment_sign')) ?>"></canvas>
                <input type="hidden" name="enrollment_signature" data-sign-input>
                <div class="enrollment-pad-actions">
                    <button type="button" class="btn btn-ghost btn-sm" data-sign-clear><?= e(__('members.enrollment_clear')) ?></button>
                </div>
            </div>
        <?php elseif ($enrollmentMethod === 'otp_email'): ?>
            <p class="muted"><?= e(__('members.enrollment_otp_hint')) ?></p>
            <div class="enrollment-otp" data-enrollment-otp>
                <button type="button" class="btn btn-ghost" data-otp-send><?= e(__('members.enrollment_otp_send')) ?></button>
                <div class="field-block" style="margin-top:0.75rem">
                    <label class="field-label"><?= e(__('members.enrollment_otp_code')) ?></label>
                    <input type="text" name="enrollment_otp" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required>
                </div>
                <p class="muted" data-otp-status hidden></p>
            </div>
        <?php endif; ?>
            </div>
            <aside class="wizard-panel-side wizard-panel-side--spacer" aria-hidden="true"></aside>
        </div>
    </section>
    <?php endif; ?>

    <div class="wizard-actions">
        <button type="button" class="btn btn-ghost" data-wizard-prev hidden><?= e(__('members.wizard_prev')) ?></button>
        <div class="wizard-actions-right">
            <button type="button" class="btn" data-wizard-next><?= e(__('members.wizard_next')) ?></button>
            <button type="submit" class="btn" data-wizard-submit hidden disabled><?= e(__('members.save')) ?></button>
        </div>
    </div>
    <p class="wizard-error muted" data-wizard-error hidden><?= e(__('members.wizard_incomplete')) ?></p>
</form>

<dialog class="member-leave-dialog" data-member-leave-dialog>
    <div class="member-leave-shell">
        <h3 class="section-title"><?= e(__('members.leave_title')) ?></h3>
        <p class="member-leave-text">
            <?= e($isEdit ? __('members.leave_text_edit') : __('members.leave_text')) ?>
        </p>
        <div class="member-leave-actions">
            <button type="button" class="btn btn-ghost" data-member-leave-no><?= e(__('members.leave_no')) ?></button>
            <button type="button" class="btn" data-member-leave-yes><?= e(__('members.leave_yes')) ?></button>
        </div>
    </div>
</dialog>

<?php if (!$isEdit && $treasuryEnabled): ?>
<dialog class="member-treasury-dialog" data-treasury-ask-dialog>
    <div class="member-leave-shell">
        <h3 class="section-title"><?= e(__('members.treasury_wizard_ask_title')) ?></h3>
        <p class="member-leave-text"><?= e(__('members.treasury_wizard_ask_lede')) ?></p>
        <div class="member-leave-actions">
            <button type="button" class="btn btn-ghost" data-treasury-ask-no><?= e(__('members.treasury_wizard_no')) ?></button>
            <button type="button" class="btn" data-treasury-ask-yes><?= e(__('members.treasury_wizard_yes')) ?></button>
        </div>
    </div>
</dialog>
<dialog class="member-treasury-dialog" data-treasury-detail-dialog>
    <div class="member-leave-shell">
        <h3 class="section-title"><?= e(__('members.treasury_wizard_details_title')) ?></h3>
        <div class="grid-2" style="margin-top:0.75rem">
            <div class="field-block">
                <label class="field-label"><?= e(__('treasury.amount')) ?></label>
                <input type="text" data-treasury-detail-amount readonly>
            </div>
            <div class="field-block">
                <label class="field-label"><?= e(__('treasury.date')) ?></label>
                <input type="date" data-treasury-detail-date value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="field-block" style="grid-column:1/-1">
                <label class="field-label"><?= e(__('treasury.description')) ?></label>
                <input type="text" data-treasury-detail-description maxlength="500">
            </div>
        </div>
        <div class="member-leave-actions">
            <button type="button" class="btn btn-ghost" data-treasury-detail-cancel><?= e(__('common.cancel')) ?></button>
            <button type="button" class="btn" data-treasury-detail-confirm><?= e(__('members.treasury_wizard_confirm')) ?></button>
        </div>
    </div>
</dialog>
<?php endif; ?>

<dialog class="camera-dialog" data-camera-dialog>
    <div class="camera-shell">
        <h3 class="section-title"><?= e(__('members.photo_capture')) ?></h3>
        <video data-camera-video autoplay playsinline muted></video>
        <canvas data-camera-canvas hidden></canvas>
        <div class="actions">
            <button type="button" class="btn" data-camera-shot><?= e(__('members.photo_shot')) ?></button>
            <button type="button" class="btn btn-ghost" data-camera-close><?= e(__('common.cancel')) ?></button>
        </div>
        <p class="muted"><?= e(__('members.photo_capture_hint')) ?></p>
    </div>
</dialog>

<?php if ($isEdit && can('payments.manage')): ?>
<div class="form-card">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('payments.record')) ?></h2>
            <p class="section-lede"><?= e(__('members.balance_due')) ?>: <strong><?= e(number_format((float)$member['balance_due'], 2, ',', '.')) ?></strong></p>
        </div>
    </div>
    <form method="post" action="<?= e(url('/members/'.$member['id'].'/payments')) ?>">
        <?= csrf_field() ?>
        <div class="grid-3">
            <div>
                <label><?= e(__('payments.amount')) ?></label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <div>
                <label><?= e(__('payments.method')) ?></label>
                <select name="method">
                    <option value="cash"><?= e(__('payments.cash')) ?></option>
                    <option value="bank"><?= e(__('payments.bank')) ?></option>
                    <option value="other"><?= e(__('payments.other')) ?></option>
                </select>
            </div>
            <div>
                <label><?= e(__('payments.type')) ?></label>
                <select name="type">
                    <option value="membership" selected><?= e(__('payments.membership')) ?></option>
                    <option value="debt"><?= e(__('payments.debt')) ?></option>
                    <option value="adjustment"><?= e(__('payments.adjustment')) ?></option>
                </select>
            </div>
        </div>
        <label><?= e(__('payments.note')) ?></label>
        <input type="text" name="note">
        <button class="btn" type="submit"><?= e(__('payments.record')) ?></button>
    </form>
    <?php if ($payments): ?>
    <div class="table-wrap embedded" style="margin-top:1rem">
        <table>
            <thead><tr><th><?= e(__('payments.amount')) ?></th><th><?= e(__('payments.method')) ?></th><th><?= e(__('payments.type')) ?></th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e(number_format((float)$p['amount'], 2, ',', '.')) ?></td>
                    <td><?= e(__('payments.'.$p['method'])) ?></td>
                    <td><?= e(__('payments.'.$p['type'])) ?></td>
                    <td><?= e($p['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
