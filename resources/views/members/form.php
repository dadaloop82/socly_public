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
$ackFields = $fieldsByFormStep[\Socly\Services\MemberService::STEP_ACKNOWLEDGEMENTS] ?? [];
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
        $placeholder = __('members.phone_hint');
    } elseif ($type === 'email') {
        $attrs .= ' data-email-input autocomplete="email"';
        $placeholder = __('auth.email_placeholder');
    } elseif ($key === 'first_name') {
        $attrs .= ' data-first-name autocomplete="given-name"';
    } elseif ($key === 'last_name') {
        $attrs .= ' data-last-name autocomplete="family-name"';
    } elseif ($type === 'date' || $key === 'birth_date') {
        $attrs .= ' data-birth-date';
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
        return '<div class="field-block" data-field="' . e($key) . '">'
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
        return '<div class="field-block" data-field="' . e($key) . '">'
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

    return '<div class="field-block" data-field="' . e($key) . '">'
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

$isRowBreakField = static function (array $field) use ($geoAddressKeySet): bool {
    $key = (string) ($field['key'] ?? '');
    $type = \Socly\Support\MemberFieldTypes::resolve((string) ($field['field_type'] ?? 'text'), $key);
    if (isset($geoAddressKeySet[$key]) || $key === 'photo') {
        return true;
    }
    return in_array($type, [
        \Socly\Support\MemberFieldTypes::PHOTO,
        \Socly\Support\MemberFieldTypes::TEXTAREA,
        \Socly\Support\MemberFieldTypes::CITY,
        \Socly\Support\MemberFieldTypes::STREET,
        \Socly\Support\MemberFieldTypes::HOUSE_NUMBER,
        \Socly\Support\MemberFieldTypes::POSTAL_CODE,
    ], true);
};

$buildProfileFieldsHtml = function (array $list) use (
    $fieldValue,
    $renderTextField,
    $isRowBreakField,
    $geoAddressKeys,
    $geoAddressKeySet,
    $reqFor,
    $valFor,
    $isEdit,
    $member
): string {
    if ($list === []) {
        return '';
    }
    $listByKey = [];
    foreach ($list as $field) {
        $listByKey[(string) ($field['key'] ?? '')] = $field;
    }
    ob_start();
    $renderedKeys = [];
    $fieldCount = count($list);
    $index = 0;
    $pending = [];

    $flushPending = static function () use (&$pending): void {
        if ($pending === []) {
            return;
        }
        echo '<div class="member-field-row cols-4">';
        foreach ($pending as $html) {
            echo $html;
        }
        echo '</div>';
        $pending = [];
    };

    while ($index < $fieldCount):
        $field = $list[$index];
        $key = (string) ($field['key'] ?? '');
        if ($key === '' || isset($renderedKeys[$key])) {
            $index++;
            continue;
        }

        $type = \Socly\Support\MemberFieldTypes::resolve((string) ($field['field_type'] ?? 'text'), $key);

        if ($key === 'photo' || $type === \Socly\Support\MemberFieldTypes::PHOTO):
            $flushPending();
            $val = $fieldValue($field);
            $label = localized($field['label_json'] ?? $field['label'] ?? 'photo');
            $renderedKeys[$key] = true;
            $index++;
            ?>
            <div class="member-field-row is-full">
            <div class="photo-field field-block" data-field="<?= e($key) ?>">
                <label class="field-label">
                    <span class="field-icon" data-icon="camera" aria-hidden="true"></span>
                    <?= e($label) ?><?= !empty($field['is_required']) ? ' *' : '' ?>
                </label>
                <div class="photo-upload">
                    <?php if ($isEdit && $val !== ''): ?>
                        <img class="photo-preview" src="<?= e(url('/members/'.$member['id'].'/photo')) ?>" alt="">
                    <?php else: ?>
                        <div class="photo-placeholder" aria-hidden="true"></div>
                    <?php endif; ?>
                    <div class="photo-controls">
                        <div class="photo-actions">
                            <label class="btn btn-sm btn-ghost file-btn">
                                <span class="field-icon" data-icon="upload" aria-hidden="true"></span>
                                <?= e(__('members.photo_upload')) ?>
                                <input
                                    type="file"
                                    name="fields[photo]"
                                    accept="image/jpeg,image/png,image/webp"
                                    data-photo-input
                                    data-invalid="<?= e(__('validation.photo')) ?>"
                                    data-max-bytes="3145728"
                                    hidden
                                >
                            </label>
                            <button type="button" class="btn btn-sm" data-camera-open hidden>
                                <span class="field-icon" data-icon="camera" aria-hidden="true"></span>
                                <?= e(__('members.photo_capture')) ?>
                            </button>
                            <input type="file" accept="image/*" capture="user" data-camera-capture hidden>
                        </div>
                        <p class="muted" data-photo-hint data-upload-only="<?= e(__('members.photo_hint_upload_only')) ?>" data-invalid="<?= e(__('validation.photo')) ?>"><?= e(__('members.photo_hint')) ?></p>
                        <p
                            class="field-hint"
                            data-camera-error
                            data-unavailable="<?= e(__('members.photo_camera_unavailable')) ?>"
                            data-not-ready="<?= e(__('members.photo_camera_not_ready')) ?>"
                            hidden
                        ></p>
                        <?php if ($isEdit && $val !== ''): ?>
                            <label class="checkbox-row">
                                <input type="checkbox" name="remove_photo" value="1">
                                <?= e(__('members.photo_remove')) ?>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            </div>
            <?php
            continue;
        endif;

        if (isset($geoAddressKeySet[$key]) || in_array($type, [
            \Socly\Support\MemberFieldTypes::CITY,
            \Socly\Support\MemberFieldTypes::STREET,
            \Socly\Support\MemberFieldTypes::HOUSE_NUMBER,
            \Socly\Support\MemberFieldTypes::POSTAL_CODE,
        ], true)):
            $flushPending();
            foreach ($geoAddressKeys as $gk) {
                if (isset($listByKey[$gk])) {
                    $renderedKeys[$gk] = true;
                }
            }
            $index++;
            ?>
            <div class="member-field-row is-full geo-address-host" data-field="residence">
                <?= view_partial('partials/geo_address', [
                    'layout' => 'inline',
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
                        'city' => isset($listByKey['city']),
                        'postal_code' => isset($listByKey['postal_code']),
                        'address' => isset($listByKey['address']),
                        'house_number' => isset($listByKey['house_number']),
                    ],
                ]) ?>
            </div>
            <?php
            continue;
        endif;

        if ($key === 'fiscal_code' || $type === \Socly\Support\MemberFieldTypes::FISCAL_CODE):
            $val = $fieldValue($field);
            $label = localized($field['label_json'] ?? $field['label'] ?? $key);
            $renderedKeys[$key] = true;
            $index++;
            $pending[] = '<div class="field-block" data-field="' . e($key) . '">'
                . '<label class="field-label" for="field-' . e($key) . '">'
                . '<span class="field-icon" data-icon="hash" aria-hidden="true"></span>'
                . e($label) . (!empty($field['is_required']) ? ' *' : '')
                . '</label>'
                . '<div class="cf-row">'
                . '<input id="field-' . e($key) . '" type="text" name="fields[' . e($key) . ']" value="' . e($val) . '"'
                . ' placeholder="' . e(__('members.cf_hint')) . '"'
                . ' data-fiscal-code autocomplete="off" maxlength="16" pattern="[A-Za-z0-9]{16}"'
                . (!empty($field['is_required']) ? ' required' : '') . '>'
                . '<button type="button" class="btn btn-ghost" data-cf-generate>'
                . '<span class="field-icon" data-icon="spark" aria-hidden="true"></span>'
                . e(__('members.cf_generate'))
                . '</button>'
                . '</div>'
                . '<p class="field-hint muted" data-cf-status aria-live="polite"'
                . ' data-incomplete="' . e(__('members.cf_incomplete')) . '"'
                . ' data-ready="' . e(__('members.cf_ready')) . '"'
                . ' data-gender-other="' . e(__('members.cf_gender_other')) . '" hidden></p>'
                . '</div>';
            if (count($pending) >= 4) {
                $flushPending();
            }
            continue;
        endif;

        if ($key === 'birth_place' || $type === \Socly\Support\MemberFieldTypes::BIRTH_PLACE):
            $renderedKeys[$key] = true;
            $index++;
            ob_start();
            ?>
            <div class="field-block suggest-field" data-field="<?= e($key) ?>">
                <?= view_partial('partials/geo_birth_place', [
                    'name' => 'fields[' . $key . ']',
                    'value' => $fieldValue($field),
                    'required' => !empty($field['is_required']),
                    'id' => 'field-' . $key,
                    'class' => 'member-geo-birth',
                ]) ?>
            </div>
            <?php
            $pending[] = (string) ob_get_clean();
            if (count($pending) >= 4) {
                $flushPending();
            }
            continue;
        endif;

        if ($type === \Socly\Support\MemberFieldTypes::TEXTAREA || $isRowBreakField($field)):
            $flushPending();
            $renderedKeys[$key] = true;
            $index++;
            echo '<div class="member-field-row is-full">' . $renderTextField($field) . '</div>';
            continue;
        endif;

        $renderedKeys[$key] = true;
        $index++;
        $pending[] = $renderTextField($field);
        if (count($pending) >= 4) {
            $flushPending();
        }
    endwhile;

    $flushPending();

    return (string) ob_get_clean();
};

$profileHtmlByStep = [];
foreach ($formSteps as $step) {
    $stepKey = (string) ($step['key'] ?? '');
    $profileHtmlByStep[$stepKey] = $buildProfileFieldsHtml($fieldsByFormStep[$stepKey] ?? []);
}
$tesseraExtraHtml = $buildProfileFieldsHtml($tesseraExtraFields);
$paymentExtraHtml = $buildProfileFieldsHtml($paymentExtraFields);
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
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/members')) ?>" data-member-leave><?= e(__('common.back')) ?></a>
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
    data-total-steps="<?= (int)$totalSteps ?>"
    data-cities-url="<?= e(url('/api/geo/cities')) ?>"
    data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
    data-cf-url="<?= e(url('/api/fiscal-code')) ?>"
    data-csrf="<?= e(csrf_token()) ?>"
    data-enrollment-method="<?= e($enrollmentMethod) ?>"
    data-otp-url="<?= e(url('/members/enrollment/otp')) ?>"
    autocomplete="off"
>
    <?= csrf_field() ?>

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
            <div class="member-profile-grid">
                <?= $profileHtmlByStep[$stepKey] ?? '' ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="wizard-panel" data-wizard-panel="<?= (int) $tesseraStep ?>" hidden>
        <div class="wizard-panel-head">
            <h2 class="section-title"><?= e(__('members.wizard_step2_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.wizard_step2_lede')) ?></p>
        </div>
        <div class="tessera-step-layout">
            <?php
            $tesseraPhotoSrc = '';
            if ($isEdit && !empty($member['id']) && trim((string) ($fieldValues['photo'] ?? '')) !== '') {
                $tesseraPhotoSrc = url('/members/' . (int) $member['id'] . '/photo');
            }
            $tesseraName = trim($fieldValue([
                'key' => 'first_name',
            ]) . ' ' . $fieldValue([
                'key' => 'last_name',
            ]));
            if ($tesseraName === '') {
                $tesseraName = __('members.wizard_card_name');
            }
            ?>
            <aside class="tessera-preview" aria-hidden="true">
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
                                    <span class="tessera-photo-placeholder" data-tessera-photo-placeholder hidden></span>
                                <?php else: ?>
                                    <img class="tessera-photo-img" data-tessera-photo alt="" hidden>
                                    <span class="tessera-photo-placeholder" data-tessera-photo-placeholder></span>
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
            <div class="tessera-step-fields">
                <div class="grid-2">
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.member_number')) ?></label>
                        <input type="text" name="member_number" data-member-number value="<?= e((string)old('member_number', $member['member_number'] ?? $nextNumber)) ?>" required>
                    </div>
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.status')) ?></label>
                        <select name="status">
                            <?php foreach (['active','suspended','expired','cancelled'] as $st): ?>
                                <option value="<?= $st ?>" <?= old('status', $member['status'] ?? 'active')===$st?'selected':'' ?>><?= e(__('members.status_'.$st)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.type')) ?></label>
                        <select name="member_type_id" data-member-type required>
                            <?php foreach ($types as $type): ?>
                                <option
                                    value="<?= (int)$type['id'] ?>"
                                    data-price="<?= e(number_format((float)$type['price'], 2, '.', '')) ?>"
                                    data-type-name="<?= e(localized($type['name_json'])) ?>"
                                    <?= (string)old('member_type_id', $member['member_type_id'] ?? '') === (string)$type['id'] ? 'selected' : '' ?>
                                >
                                    <?= e(localized($type['name_json'])) ?> (<?= e(number_format((float)$type['price'], 2, ',', '.')) ?> €)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.period')) ?></label>
                        <select name="membership_period_id" required>
                            <?php foreach ($periods as $period): ?>
                                <option value="<?= (int)$period['id'] ?>" <?= (string)old('membership_period_id', $member['membership_period_id'] ?? ($period['is_current']?$period['id']:'')) === (string)$period['id'] ? 'selected' : '' ?>>
                                    <?= e($period['label']) ?><?= $period['is_current'] ? ' ★' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (!$isEdit): ?>
                <div class="payment-summary" data-tessera-payment-summary>
                    <div>
                        <span class="muted"><?= e(__('members.type')) ?></span>
                        <strong data-payment-type-label>—</strong>
                    </div>
                    <div>
                        <span class="muted"><?= e(__('members.wizard_quota')) ?></span>
                        <strong data-payment-amount>0,00 €</strong>
                    </div>
                    <div data-payment-due-wrap>
                        <span class="muted"><?= e(__('members.wizard_still_due')) ?></span>
                        <strong data-payment-due>0,00 €</strong>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.payment')) ?></label>
                        <select name="payment_status" required>
                            <option value="unpaid" <?= $oldPaymentStatus === 'unpaid' ? 'selected' : '' ?>><?= e(__('members.payment_unpaid')) ?></option>
                            <option value="partial" <?= $oldPaymentStatus === 'partial' ? 'selected' : '' ?>><?= e(__('members.payment_partial')) ?></option>
                            <option value="paid" <?= $oldPaymentStatus === 'paid' ? 'selected' : '' ?>><?= e(__('members.payment_paid')) ?></option>
                        </select>
                    </div>
                    <div class="field-block">
                        <label class="field-label"><?= e(__('members.payment_method')) ?></label>
                        <select name="payment_method" required>
                            <option value="cash" <?= $oldPaymentMethod === 'cash' ? 'selected' : '' ?>><?= e(__('payments.cash')) ?></option>
                            <option value="bank" <?= $oldPaymentMethod === 'bank' ? 'selected' : '' ?>><?= e(__('payments.bank')) ?></option>
                            <option value="other" <?= $oldPaymentMethod === 'other' ? 'selected' : '' ?>><?= e(__('payments.other')) ?></option>
                        </select>
                    </div>
                </div>
                <div data-partial-wrap<?= $oldPaymentStatus === 'partial' ? '' : ' hidden' ?> class="field-block">
                    <label class="field-label"><?= e(__('members.partial_amount')) ?></label>
                    <input type="number" step="0.01" min="0" name="partial_amount" value="<?= e($oldPartialAmount) ?>">
                </div>
                <?php endif; ?>

                <div class="field-block">
                    <label class="field-label"><?= e(__('members.notes')) ?></label>
                    <textarea name="notes" rows="3"><?= e((string)old('notes', $member['notes'] ?? '')) ?></textarea>
                </div>
                <?php if ($tesseraExtraHtml !== ''): ?>
                    <div class="member-profile-grid" style="margin-top:0.85rem">
                        <?= $tesseraExtraHtml ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isEdit && $paymentExtraHtml !== ''): ?>
                    <div class="member-profile-grid" style="margin-top:0.85rem">
                        <?= $paymentExtraHtml ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="wizard-panel" data-wizard-panel="<?= (int) $ackStep ?>" hidden>
        <div class="wizard-panel-head">
            <h2 class="section-title"><?= e(__('members.wizard_step3_title')) ?></h2>
            <p class="section-lede"><?= e(__('members.acknowledgements_lede')) ?></p>
        </div>
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
            <div class="ack-list">
                <?php foreach ($ackFields as $field): ?>
                    <?php
                    $fkey = (string) ($field['key'] ?? '');
                    $doc = $docMap[$fkey] ?? null;
                    if ($doc):
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
                    <?php else: ?>
                        <?= $renderTextField($field) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted"><?= e(__('members.legal_step_empty')) ?></p>
        <?php endif; ?>
    </section>

    <?php if ($needsEnrollment): ?>
    <section class="wizard-panel" data-wizard-panel="<?= (int) $enrollmentStep ?>" hidden>
        <div class="wizard-panel-head">
            <h2 class="section-title"><?= e(__('members.wizard_enrollment_title')) ?></h2>
            <p class="section-lede"><?= e($isEdit ? __('members.wizard_enrollment_fix_lede') : __('members.wizard_enrollment_lede')) ?></p>
        </div>
        <input type="hidden" name="enrollment_method" value="<?= e($enrollmentMethod) ?>">

        <?php if ($enrollmentMethod === 'print_scan'): ?>
            <p class="muted"><?= e(__('members.enrollment_print_hint')) ?></p>
            <div class="field-block">
                <label class="field-label"><?= e(__('members.enrollment_scan')) ?></label>
                <input type="file" name="enrollment_scan" accept="image/*,application/pdf" required>
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
    </section>
    <?php endif; ?>

    <div class="wizard-actions">
        <button type="button" class="btn btn-ghost" data-wizard-prev hidden><?= e(__('members.wizard_prev')) ?></button>
        <div class="wizard-actions-right">
            <a class="btn btn-ghost" href="<?= e(url('/members')) ?>" data-member-leave><?= e(__('common.cancel')) ?></a>
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
