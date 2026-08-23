<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('settings.title')) ?></h1>
        <p class="page-lede"><?= e(__('settings.lede')) ?></p>
    </div>
</div>

<div class="config-accordions" data-config-accordions
     data-autosave-busy="<?= e(__('settings.autosave_busy')) ?>"
     data-autosave-ok="<?= e(__('settings.autosaved')) ?>"
     data-autosave-fail="<?= e(__('settings.autosave_failed')) ?>"
     data-autosave-ago="<?= e(__('settings.autosave_ago')) ?>">
<details class="config-accordion" id="general" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.general')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.general_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

<form
        method="post"
        action="<?= e(url('/settings/general')) ?>"
        data-leave-guard
        data-settings-autosave
        enctype="multipart/form-data"
        data-cities-url="<?= e(url('/api/geo/cities')) ?>"
        data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
        data-settings-geo
    >
        <?= csrf_field() ?>
        <div class="setup-name-pair settings-name-pair" data-setup-name-pair
             data-preview-template="<?= e(__('setup.full_name_preview')) ?>">
            <label class="setup-field setup-field-name">
                <span><?= e(__('setup.field_assoc_name')) ?></span>
                <input type="text" name="association_name" value="<?= e((string)$settings['association.name']) ?>" required data-setup-assoc-name>
            </label>
            <label class="setup-field setup-field-legal">
                <span><?= e(__('setup.field_assoc_legal_name')) ?></span>
                <?php $legalForms = \Socly\Setup\AssociationLegalForms::all(); $currentLegal = (string)($settings['association.legal_name'] ?? ''); ?>
                <select name="association_legal_name" required maxlength="6" data-setup-legal-name>
                    <option value="" disabled <?= $currentLegal === '' ? 'selected' : '' ?>><?= e(__('setup.legal_form_placeholder')) ?></option>
                    <?php foreach ($legalForms as $code => $labelKey): ?>
                        <option value="<?= e($code) ?>" <?= $currentLegal === $code ? 'selected' : '' ?>>
                            <?= e($code) ?> — <?= e(__($labelKey)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="setup-hint setup-full-name-preview muted" data-setup-full-name-preview hidden></p>
        </div>
        <div class="grid-2">
            <div>
                <label><?= e(__('setup.field_fiscal_code')) ?></label>
                <input type="text" name="association_fiscal_code" value="<?= e((string)($settings['association.fiscal_code'] ?? '')) ?>" required maxlength="16">
            </div>
            <div>
                <label><?= e(__('setup.field_vat_number')) ?></label>
                <input type="text" name="association_vat" value="<?= e((string)($settings['association.vat_number'] ?? '')) ?>" maxlength="11">
            </div>
        </div>
        <h3 class="section-title" style="margin-top:1rem"><?= e(__('setup.step_seat_title')) ?></h3>
        <?= view_partial('partials/geo_address', [
            'names' => [
                'city' => 'association_city',
                'postal_code' => 'association_postal_code',
                'province' => 'association_province',
                'address' => 'association_address',
                'house_number' => 'association_house_number',
            ],
            'values' => [
                'city' => (string) ($settings['association.city'] ?? ''),
                'postal_code' => (string) ($settings['association.postal_code'] ?? ''),
                'province' => (string) ($settings['association.province'] ?? ''),
                'address' => (string) ($settings['association.address'] ?? ''),
                'house_number' => (string) ($settings['association.house_number'] ?? ''),
            ],
            'required' => [
                'city' => true,
                'postal_code' => true,
                'province' => true,
                'address' => true,
                'house_number' => true,
            ],
            'enabled' => [
                'city' => true,
                'postal_code' => true,
                'province' => true,
                'address' => true,
                'house_number' => true,
            ],
        ]) ?>
        <div class="grid-2" style="margin-top:1rem">
            <div>
                <label><?= e(__('setup.field_pec')) ?></label>
                <input type="email" name="association_pec" value="<?= e((string)($settings['association.pec'] ?? '')) ?>" required>
            </div>
            <div>
                <label><?= e(__('setup.field_email')) ?></label>
                <input type="email" name="association_email" value="<?= e((string)($settings['association.email'] ?? '')) ?>" required>
            </div>
        </div>
        <div class="grid-2">
            <div>
                <label><?= e(__('setup.field_phone')) ?></label>
                <?= view_partial('partials/phone_field', [
                    'name' => 'association_phone',
                    'value' => (string) ($settings['association.phone'] ?? ''),
                ]) ?>
            </div>
            <div>
                <label><?= e(__('setup.field_runts')) ?></label>
                <input type="text" name="association_runts" value="<?= e((string)($settings['association.runts'] ?? '')) ?>" inputmode="numeric" maxlength="6" autocomplete="off">
            </div>
        </div>
        <div class="setup-logo settings-logo-block" data-setup-logo>
            <?php $settingsLogo = assoc_logo_url(); ?>
            <?php if ($settingsLogo): ?>
                <div class="setup-logo-preview" data-setup-logo-preview>
                    <img src="<?= e($settingsLogo) ?>" alt="<?= e(__('setup.field_logo')) ?>" data-setup-logo-img>
                </div>
                <label class="checkbox-row setup-check">
                    <input type="checkbox" name="remove_logo" value="1">
                    <span><?= e(__('setup.logo_remove')) ?></span>
                </label>
            <?php else: ?>
                <div class="setup-logo-preview" data-setup-logo-preview hidden>
                    <img alt="<?= e(__('setup.field_logo')) ?>" data-setup-logo-img hidden>
                </div>
            <?php endif; ?>
            <div class="setup-logo-actions">
                <label class="btn btn-ghost file-btn">
                    <span class="field-icon" data-icon="upload" aria-hidden="true"></span>
                    <?= e(__('setup.logo_upload')) ?>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" data-setup-logo-input hidden>
                </label>
            </div>
            <div class="setup-logo-upload-progress" data-setup-logo-progress hidden>
                <span class="setup-logo-upload-progress-bar" data-setup-logo-progress-bar style="--progress: 0%"></span>
            </div>
            <p class="setup-hint muted"><?= e(__('setup.logo_hint')) ?></p>
        </div>
        <?php $paletteOptions = app(\Socly\Services\BrandingService::class)->paletteSuggestions(); ?>
        <?php if ($paletteOptions !== []): ?>
            <div class="setup-palette-grid" data-palette-grid data-settings-brand>
                <p class="setup-subhead"><?= e(__('setup.palette_choose')) ?></p>
                <?php foreach ($paletteOptions as $palette): ?>
                    <button type="button" class="setup-palette-card" data-palette-pick
                            data-primary="<?= e((string) ($palette['primary'] ?? '')) ?>"
                            data-accent="<?= e((string) ($palette['accent'] ?? '')) ?>">
                        <span class="setup-palette-swatch" style="--swatch-a: <?= e((string) ($palette['primary'] ?? '#0D6E66')) ?>; --swatch-b: <?= e((string) ($palette['accent'] ?? '#B84A1B')) ?>;"></span>
                        <span class="setup-palette-name"><?= e((string) ($palette['name'] ?? __('setup.palette_custom'))) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="setup-brand-preview" data-brand-preview
             style="--brand-primary: <?= e((string)$settings['branding.primary']) ?>; --brand-accent: <?= e((string)$settings['branding.accent']) ?>;">
            <span class="setup-brand-preview-primary"></span>
            <span class="setup-brand-preview-accent"></span>
        </div>
        <div class="setup-colors setup-color-picker-grid" data-setup-line>
            <label class="setup-field setup-color-picker-card">
                <span><?= e(__('install.primary')) ?></span>
                <span class="setup-color-picker-control">
                    <input type="color" name="primary" value="<?= e((string)$settings['branding.primary']) ?>" required data-brand-color="primary">
                    <code><?= e(strtoupper((string)$settings['branding.primary'])) ?></code>
                </span>
            </label>
            <label class="setup-field setup-color-picker-card">
                <span><?= e(__('install.accent')) ?></span>
                <span class="setup-color-picker-control">
                    <input type="color" name="accent" value="<?= e((string)$settings['branding.accent']) ?>" required data-brand-color="accent">
                    <code><?= e(strtoupper((string)$settings['branding.accent'])) ?></code>
                </span>
            </label>
        </div>
        <fieldset class="setup-locale-grid" data-setup-line>
            <legend class="setup-field-label"><?= e(__('install.locale')) ?></legend>
            <?php foreach (['it','de','en'] as $loc): ?>
                <label class="setup-locale-card">
                    <input type="radio" name="locale" value="<?= $loc ?>" <?= $settings['app.locale']===$loc?'checked':'' ?> required>
                    <img src="<?= e(locale_flag_url($loc)) ?>" width="28" height="21" alt="" loading="lazy" decoding="async">
                    <span><?= e(match ($loc) { 'it' => 'Italiano', 'de' => 'Deutsch', default => 'English' }) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
    </form>
    </div>
</details>

<details class="config-accordion" id="people" data-config-accordion hidden>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.people')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.people_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

<form method="post" action="<?= e(url('/settings/people')) ?>" data-cities-url="<?= e(url('/api/geo/cities')) ?>" data-addresses-url="<?= e(url('/api/geo/addresses')) ?>" data-settings-geo data-leave-guard>
        <?= csrf_field() ?>
        <p class="muted"><?= e(__('settings.people_hint')) ?></p>
        <div class="assoc-people" data-people-list>
            <div class="assoc-people-rows" data-people-rows>
                <?php foreach ($people as $i => $person): ?>
                    <?php $roleKey = (string) ($person['role_key'] ?? 'board'); ?>
                    <div class="assoc-person-card" data-people-row data-role="<?= e($roleKey) ?>">
                        <div class="assoc-person-main">
                            <label class="setup-field">
                                <span><?= e(__('settings.people_role')) ?></span>
                                <select name="people[<?= (int) $i ?>][role_key]" required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= e((string) $role['key']) ?>" <?= $roleKey === (string) $role['key'] ? 'selected' : '' ?>>
                                            <?= e(__((string) $role['label_key'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_first_name')) ?></span>
                                <input type="text" name="people[<?= (int) $i ?>][first_name]" value="<?= e((string) ($person['first_name'] ?? '')) ?>">
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_last_name')) ?></span>
                                <input type="text" name="people[<?= (int) $i ?>][last_name]" value="<?= e((string) ($person['last_name'] ?? '')) ?>">
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_person_fiscal_code')) ?></span>
                                <input type="text" name="people[<?= (int) $i ?>][fiscal_code]" value="<?= e((string) ($person['fiscal_code'] ?? '')) ?>" maxlength="16">
                            </label>
                            <button type="button" class="btn btn-ghost btn-sm" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                        </div>
                        <?= view_partial('partials/geo_address', [
                            'class' => 'assoc-person-extra',
                            'show_hint' => false,
                            'names' => [
                                'city' => 'people[' . (int) $i . '][city]',
                                'postal_code' => 'people[' . (int) $i . '][postal_code]',
                                'address' => 'people[' . (int) $i . '][address]',
                                'house_number' => 'people[' . (int) $i . '][house_number]',
                            ],
                            'values' => [
                                'city' => (string) ($person['city'] ?? ''),
                                'postal_code' => (string) ($person['postal_code'] ?? ''),
                                'address' => (string) ($person['address'] ?? ''),
                                'house_number' => (string) ($person['house_number'] ?? ''),
                            ],
                        ]) ?>
                        <div class="setup-address-row assoc-person-dates">
                            <label class="setup-field">
                                <span><?= e(__('setup.field_appointed_at')) ?></span>
                                <input type="date" name="people[<?= (int) $i ?>][appointed_at]" value="<?= e((string) ($person['appointed_at'] ?? '')) ?>">
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_mandate_ends_at')) ?></span>
                                <input type="date" name="people[<?= (int) $i ?>][mandate_ends_at]" value="<?= e((string) ($person['mandate_ends_at'] ?? '')) ?>">
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-ghost" data-people-add><?= e(__('setup.add_person')) ?></button>
            <template data-people-template>
                <div class="assoc-person-card" data-people-row data-role="board">
                    <div class="assoc-person-main">
                        <label class="setup-field">
                            <span><?= e(__('settings.people_role')) ?></span>
                            <select name="people[__i__][role_key]" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e((string) $role['key']) ?>" <?= (string) $role['key'] === 'board' ? 'selected' : '' ?>>
                                        <?= e(__((string) $role['label_key'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="setup-field">
                            <span><?= e(__('setup.field_first_name')) ?></span>
                            <input type="text" name="people[__i__][first_name]" value="">
                        </label>
                        <label class="setup-field">
                            <span><?= e(__('setup.field_last_name')) ?></span>
                            <input type="text" name="people[__i__][last_name]" value="">
                        </label>
                        <label class="setup-field">
                            <span><?= e(__('setup.field_person_fiscal_code')) ?></span>
                            <input type="text" name="people[__i__][fiscal_code]" value="" maxlength="16">
                        </label>
                        <button type="button" class="btn btn-ghost btn-sm" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                    </div>
                    <?= view_partial('partials/geo_address', [
                        'class' => 'assoc-person-extra',
                        'show_hint' => false,
                        'names' => [
                            'city' => 'people[__i__][city]',
                            'postal_code' => 'people[__i__][postal_code]',
                            'address' => 'people[__i__][address]',
                            'house_number' => 'people[__i__][house_number]',
                        ],
                        'values' => [
                            'city' => '',
                            'postal_code' => '',
                            'address' => '',
                            'house_number' => '',
                        ],
                    ]) ?>
                    <div class="setup-address-row assoc-person-dates">
                        <label class="setup-field">
                            <span><?= e(__('setup.field_appointed_at')) ?></span>
                            <input type="date" name="people[__i__][appointed_at]" value="">
                        </label>
                        <label class="setup-field">
                            <span><?= e(__('setup.field_mandate_ends_at')) ?></span>
                            <input type="date" name="people[__i__][mandate_ends_at]" value="">
                        </label>
                    </div>
                </div>
            </template>
        </div>
        <button class="btn" type="submit" style="margin-top:1rem"><?= e(__('common.save')) ?></button>
    </form>
    </div>
</details>

<details class="config-accordion" id="legal" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.legal')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.legal_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

<form method="post" action="<?= e(url('/settings/legal')) ?>" data-leave-guard data-settings-autosave>
        <?= csrf_field() ?>
        <label class="setup-check setup-check-prominent">
            <input type="checkbox" name="gdpr_enabled" value="1" <?= $settings['gdpr.enabled']==='1'?'checked':'' ?>>
            <span><?= e(__('setup.gdpr_label')) ?></span>
        </label>
        <p class="setup-hint muted"><?= e(__('setup.step_gdpr_desc')) ?></p>
        <h3 class="section-title" style="margin-top:1.25rem"><?= e(__('settings.legal_privacy')) ?></h3>
        <p class="muted"><?= e(__('settings.legal_privacy_hint')) ?></p>
        <?= view_partial('partials/legal_doc_editor', [
            'namePrefix' => 'privacy',
            'values' => $settings['legal.privacy'] ?? [],
            'placeholder' => __('settings.legal_privacy_placeholder'),
        ]) ?>

        <h3 class="section-title" style="margin-top:1.5rem"><?= e(__('settings.legal_statute')) ?></h3>
        <p class="muted"><?= e(__('settings.legal_statute_hint')) ?></p>
        <?= view_partial('partials/legal_doc_editor', [
            'namePrefix' => 'statute',
            'values' => $settings['legal.statute'] ?? [],
            'placeholder' => __('settings.legal_statute_placeholder'),
        ]) ?>
        <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
    </form>
    </div>
</details>

<details class="config-accordion" id="types" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.types')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.types_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

    <form method="post" action="<?= e(url('/settings/types')) ?>" data-leave-guard data-settings-autosave>
        <?= csrf_field() ?>
        <?= view_partial('partials/member_types_editor', ['types' => $types]) ?>
        <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
    </form>
    </div>
</details>

<details class="config-accordion" id="periods" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.periods')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.periods_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

    <form method="post" action="<?= e(url('/settings/periods')) ?>" data-leave-guard data-settings-autosave>
        <?= csrf_field() ?>
        <?= view_partial('partials/member_periods_editor', ['periods' => $periods]) ?>
        <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
    </form>
    </div>
</details>

<details class="config-accordion" id="fields" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.fields')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.fields_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

<form method="post" action="<?= e(url('/settings/fields')) ?>" data-leave-guard>
        <?= csrf_field() ?>
        <?= view_partial('partials/member_fields_editor', [
            'fields' => $fields,
            'formSteps' => $formSteps ?? [],
            'typeOptions' => \Socly\Support\MemberFieldTypes::keys(),
            'allowTypeEdit' => false,
            'autosaveUrl' => url('/settings/fields/autosave'),
        ]) ?>
        <div class="setup-membership-card setup-membership-card-new setup-fields-add">
            <h3 class="setup-subhead"><?= e(__('settings.add_field')) ?></h3>
            <p class="setup-hint muted"><?= e(__('setup.fields_add_hint')) ?></p>
            <div class="setup-equal-row">
                <label class="setup-field setup-field-grow">
                    <span><?= e(__('setup.fields_new_label')) ?></span>
                    <input type="text" name="new_label" placeholder="<?= e(__('setup.fields_new_label_ph')) ?>">
                </label>
                <label class="setup-field">
                    <span><?= e(__('setup.fields_col_type')) ?></span>
                    <select name="new_type">
                        <?php foreach (\Socly\Support\MemberFieldTypes::keys() as $t): ?>
                            <option value="<?= e($t) ?>"><?= e(\Socly\Support\MemberFieldTypes::label($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
        </div>
    </form>
    </div>
</details>

<details class="config-accordion" id="mail" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.mail')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.mail_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <?php
            $mailCfg = $mailConfig ?? [
                'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '',
                'from_address' => '', 'from_name' => '', 'has_password' => false,
                'last_test_ok' => false, 'last_test_at' => '',
            ];
            $mailReady = !empty($mailReady);
        $showManual = !empty($mailCfg['host']) || !empty(flash('smtp_needs_manual'));
        $mailSkipped = !empty($mailCfg['outbound_disabled']);
        ?>
        <form
            method="post"
            action="<?= e(url('/settings/mail')) ?>"
            data-leave-guard
            class="setup-smtp"
            data-setup-smtp
            data-smtp-initial-manual="<?= $showManual ? '1' : '0' ?>"
            data-discover-url="<?= e(url('/setup/mail/discover')) ?>"
            data-test-url="<?= e(url('/setup/mail/test')) ?>"
            data-discover-ok="<?= e(__('setup.mail_discover_ok')) ?>"
            data-verify-ok="<?= e(__('setup.mail_verify_ok')) ?>"
            data-discover-fail="<?= e(__('mail.discovery_failed')) ?>"
            data-discover-busy="<?= e(__('setup.mail_discover_busy')) ?>"
            data-test-busy="<?= e(__('setup.mail_test_busy')) ?>"
            data-test-ok="<?= e(__('mail.test_ok')) ?>"
            data-verify-busy="<?= e(__('setup.mail_verify_busy')) ?>"
            data-password-required="<?= e(__('mail.password_required')) ?>"
            data-email-invalid="<?= e(__('validation.email')) ?>"
            data-has-password="<?= !empty($mailCfg['has_password']) ? '1' : '0' ?>"
            data-csrf="<?= e(csrf_token()) ?>"
        >
            <?= csrf_field() ?>
            <label class="checkbox-row setup-check setup-smtp-skip">
                <input type="checkbox" name="outbound_disabled" value="1" data-smtp-skip <?= $mailSkipped ? 'checked' : '' ?>>
                <span><?= e(__('setup.mail_skip_outbound')) ?></span>
            </label>
            <p
                class="setup-hint muted"
                data-smtp-skip-hint
                data-hint-skip="<?= e(__('setup.mail_skip_outbound_hint')) ?>"
                data-hint-enable="<?= e(__('setup.mail_enable_outbound_hint')) ?>"
            ><?= e($mailSkipped ? __('setup.mail_skip_outbound_hint') : __('setup.mail_enable_outbound_hint')) ?></p>
            <div class="setup-smtp-fields" data-smtp-fields <?= $mailSkipped ? 'hidden' : '' ?>>
                <div class="setup-smtp-section">
                    <p class="setup-hint muted"><?= e(__('setup.mail_simple_hint')) ?></p>
                    <div class="setup-equal-row">
                        <label class="setup-field">
                            <span><?= e(__('mail.from_address')) ?> *</span>
                            <input type="email" name="from_address" value="<?= e((string) $mailCfg['from_address']) ?>" required placeholder="noreply@example.com" data-smtp-from>
                            <p class="field-hint setup-field-hint" data-smtp-from-hint hidden></p>
                        </label>
                        <?= view_partial('partials/password_input', [
                            'name' => 'password',
                            'label' => (string) __('mail.password'),
                            'required' => false,
                            'placeholder' => !empty($mailCfg['has_password']) ? (string) __('mail.password_keep') : '',
                            'autocomplete' => 'new-password',
                            'input_attrs' => 'data-smtp-password',
                            'hint_attrs' => 'data-smtp-password-hint',
                        ]) ?>
                    </div>
                    <div class="setup-smtp-actions" data-smtp-simple-actions <?= $showManual ? 'hidden' : '' ?>>
                        <button type="button" class="btn btn-ghost" data-smtp-discover-btn><?= e(__('setup.mail_discover_btn')) ?></button>
                    </div>
                    <p class="setup-hint muted" data-smtp-discover-status hidden></p>
                </div>
                <div class="setup-smtp-manual" data-smtp-manual <?= $showManual ? '' : 'hidden' ?>>
                    <p class="setup-smtp-manual-title"><?= e(__('setup.mail_manual_title')) ?></p>
                    <p class="setup-hint muted"><?= e(__('setup.mail_manual_hint')) ?></p>
                    <div class="grid-2">
                        <div>
                            <label><?= e(__('mail.host')) ?> *</label>
                            <input type="text" name="host" value="<?= e((string) $mailCfg['host']) ?>" placeholder="smtp.example.com" data-smtp-host>
                        </div>
                        <div>
                            <label><?= e(__('mail.port')) ?> *</label>
                            <input type="number" name="port" value="<?= e((string) ($mailCfg['port'] ?: 587)) ?>" min="1" max="65535" data-smtp-port>
                        </div>
                    </div>
                    <label><?= e(__('mail.encryption')) ?> *</label>
                    <select name="encryption" data-smtp-encryption>
                        <?php $enc = (string) ($mailCfg['encryption'] ?? 'tls'); ?>
                        <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>><?= e(__('mail.encryption_tls')) ?></option>
                        <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>><?= e(__('mail.encryption_ssl')) ?></option>
                        <option value="none" <?= $enc === 'none' ? 'selected' : '' ?>><?= e(__('mail.encryption_none')) ?></option>
                    </select>
                    <label><?= e(__('mail.username')) ?> *</label>
                    <input type="text" name="username" value="<?= e((string) $mailCfg['username']) ?>" autocomplete="off" placeholder="<?= e(__('mail.username_default_hint')) ?>" data-smtp-username>
                    <label><?= e(__('mail.from_name')) ?></label>
                    <input type="text" name="from_name" value="<?= e((string) $mailCfg['from_name']) ?>" data-smtp-from-name>
                    <div class="setup-smtp-actions" style="margin-top:0.75rem">
                        <button type="button" class="btn btn-ghost" data-smtp-verify-btn><?= e(__('setup.mail_verify_btn')) ?></button>
                    </div>
                    <p class="setup-hint muted" data-smtp-verify-status hidden></p>
                </div>
                <div class="setup-smtp-section setup-smtp-test" data-smtp-test-section <?= !empty($mailCfg['last_test_ok']) ? '' : 'hidden' ?>>
                    <label class="setup-field">
                        <span><?= e(__('mail.test_to')) ?> *</span>
                        <input type="email" name="test_to" value="<?= e((string) ($mailCfg['from_address'] ?: '')) ?>" <?= !empty($mailCfg['last_test_ok']) ? 'required' : '' ?> data-smtp-test-to <?= !empty($mailCfg['last_test_ok']) ? '' : 'disabled' ?>>
                    </label>
                    <div class="setup-smtp-actions">
                        <button type="button" class="btn btn-ghost" data-smtp-test-btn <?= !empty($mailCfg['last_test_ok']) ? '' : 'disabled' ?>><?= e(__('setup.mail_test_btn')) ?></button>
                    </div>
                    <p class="setup-hint muted" data-smtp-test-status <?= !empty($mailCfg['last_test_ok']) ? '' : 'hidden' ?>>
                        <?php if (!empty($mailCfg['last_test_ok'])): ?>
                            <?= e(__('mail.test_ok_badge')) ?><?= !empty($mailCfg['last_test_at']) ? ' · ' . e((string) $mailCfg['last_test_at']) : '' ?>
                        <?php endif; ?>
                    </p>
                    <p class="setup-hint muted"><?= e(__('settings.mail_save_hint')) ?></p>
                </div>
            </div>
            <div class="actions setup-smtp-actions" style="margin-top:0.25rem">
                <button class="btn" type="submit" name="action" value="save_test"><?= e(__('mail.save_and_test')) ?></button>
                <button class="btn btn-ghost" type="submit" name="action" value="save"><?= e(__('common.save')) ?></button>
            </div>
        </form>
    </div>
</details>

<details class="config-accordion" id="enrollment" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.enrollment')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.enrollment_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <form method="post" action="<?= e(url('/settings/enrollment')) ?>" data-leave-guard data-settings-autosave>
            <?= csrf_field() ?>
            <label for="enrollment_validation"><?= e(__('settings.enrollment_method')) ?></label>
            <select id="enrollment_validation" name="enrollment_validation" required>
                <?php
                $enroll = (string) ($settings['membership.enrollment_validation'] ?? 'none');
                $enrollOpts = [
                    'none' => 'setup.enrollment_none',
                    'print_scan' => 'setup.enrollment_print_scan',
                    'tablet_sign' => 'setup.enrollment_tablet_sign',
                    'otp_email' => 'setup.enrollment_otp_email',
                ];
                foreach ($enrollOpts as $val => $labelKey):
                    $otpBlocked = $val === 'otp_email' && empty($mailReady);
                ?>
                    <option value="<?= e($val) ?>" <?= $enroll === $val ? 'selected' : '' ?> <?= $otpBlocked ? 'disabled' : '' ?>><?= e(__($labelKey)) ?><?= $otpBlocked ? ' — ' . e(__('mail.required_short')) : '' ?></option>
                <?php endforeach; ?>
            </select>
            <p class="muted" style="margin-top:0.75rem"><?= e(__('setup.step_enrollment_desc')) ?></p>
            <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
        </form>
    </div>
</details>

<details class="config-accordion" id="platform" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.platform')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.platform_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <form method="post" action="<?= e(url('/settings/platform')) ?>" data-platform-consents data-mail-ready="<?= !empty($mailReady) ? '1' : '0' ?>" data-leave-guard data-settings-autosave>
            <?= csrf_field() ?>
            <p class="muted"><?= e(__('setup.platform_hint')) ?></p>
            <label class="setup-check setup-check-prominent">
                <input type="checkbox" name="news_opt_in" value="1" data-platform-opt <?= ($settings['platform.news_opt_in'] ?? '1') !== '0' ? 'checked' : '' ?>>
                <span><?= e(__('settings.platform_news')) ?></span>
            </label>
            <label class="setup-check setup-check-prominent">
                <input type="checkbox" name="usage_stats_opt_in" value="1" data-platform-opt <?= ($settings['platform.usage_stats_opt_in'] ?? '1') !== '0' ? 'checked' : '' ?>>
                <span><?= e(__('settings.platform_stats')) ?></span>
            </label>
            <label class="setup-check setup-check-prominent">
                <input type="checkbox" name="showcase_consent" value="1" data-platform-opt <?= ($settings['platform.showcase_consent'] ?? '1') !== '0' ? 'checked' : '' ?>>
                <span><?= e(__('settings.platform_showcase')) ?></span>
            </label>
            <p class="muted"><?= e(__('setup.platform_showcase_hint')) ?></p>
            <?php
                $anyPlatformSettings = (($settings['platform.news_opt_in'] ?? '1') !== '0')
                    || (($settings['platform.usage_stats_opt_in'] ?? '1') !== '0')
                    || (($settings['platform.showcase_consent'] ?? '1') !== '0');
            ?>
            <div class="setup-platform-confirm" data-platform-confirm <?= $anyPlatformSettings ? '' : 'hidden' ?>>
                <p class="muted"><?= e(__('setup.platform_confirm_hint')) ?></p>
                <div class="grid-2">
                    <div>
                        <label><?= e(__('setup.platform_confirm_first')) ?> *</label>
                        <input type="text" name="confirm_first_name" value="" autocomplete="off" data-platform-confirm-input placeholder="<?= e((string) ($presidentFirstPlaceholder ?? '')) ?>" <?= $anyPlatformSettings ? 'required' : '' ?>>
                    </div>
                    <div>
                        <label><?= e(__('setup.platform_confirm_last')) ?> *</label>
                        <input type="text" name="confirm_last_name" value="" autocomplete="off" data-platform-confirm-input placeholder="<?= e((string) ($presidentLastPlaceholder ?? '')) ?>" <?= $anyPlatformSettings ? 'required' : '' ?>>
                    </div>
                </div>
            </div>
            <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
        </form>
    </div>
</details>

<?php if (can('users.manage')): ?>
<details class="config-accordion" id="users" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('nav.users')) ?></span>
        <span class="config-accordion-lede"><?= e(__('users.lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <p class="muted"><?= e(__('settings.users_panel_hint')) ?></p>
        <a class="btn" href="<?= e(url('/users')) ?>"><?= e(__('settings.open_users')) ?></a>
    </div>
</details>
<?php endif; ?>

<details class="config-accordion" id="components" data-config-accordion>
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.components_title')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.components_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <form method="post" action="<?= e(url('/settings/components')) ?>" data-leave-guard>
            <?= csrf_field() ?>
            <p class="muted"><?= e(__('settings.components_hint')) ?></p>
            <div class="setup-component-list settings-component-list">
                <?php foreach ($components as $component): ?>
                    <?php
                        $cKey = (string) ($component['key'] ?? '');
                        $checked = !empty($enabledComponents[$cKey]);
                    ?>
                    <label class="setup-component-row<?= $checked ? ' is-selected' : '' ?>">
                        <input type="checkbox" name="components[]" value="<?= e($cKey) ?>" <?= $checked ? 'checked' : '' ?>>
                        <span class="setup-component-row-body">
                            <span class="setup-component-row-top">
                                <strong class="setup-component-name"><?= e(__((string) ($component['name_key'] ?? ''))) ?></strong>
                                <span class="setup-component-price"><?= e(__('components.price_included')) ?></span>
                            </span>
                            <span class="setup-component-desc"><?= e(__((string) ($component['description_key'] ?? ''))) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="config-sub-accordions">
                <?php if (!empty($enabledComponents['treasury'])): ?>
                <details class="config-sub-accordion" open>
                    <summary><?= e(__('components.treasury.name')) ?></summary>
                    <div class="config-sub-body">
                        <label class="checkbox-row">
                            <input type="checkbox" name="treasury_auto_from_payments" value="1" <?= !empty($componentConfigs['treasury']['auto_from_payments']) ? 'checked' : '' ?>>
                            <?= e(__('settings.treasury_auto_from_payments')) ?>
                        </label>
                        <p class="muted"><?= e(__('settings.treasury_auto_from_payments_hint')) ?></p>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($enabledComponents['deadlines'])): ?>
                <details class="config-sub-accordion">
                    <summary><?= e(__('components.deadlines.name')) ?></summary>
                    <div class="config-sub-body">
                        <label><?= e(__('settings.deadlines_warn_days')) ?></label>
                        <input type="number" name="deadlines_warn_days" min="1" max="90" value="<?= e((string) ($componentConfigs['deadlines']['warn_days'] ?? 30)) ?>">
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($enabledComponents['documents'])): ?>
                <details class="config-sub-accordion">
                    <summary><?= e(__('components.documents.name')) ?></summary>
                    <div class="config-sub-body">
                        <label><?= e(__('settings.documents_default_category')) ?></label>
                        <select name="documents_default_category">
                            <?php
                            $docCats = $componentConfigs['documents']['category_options'] ?? null;
                            if (!is_array($docCats) || $docCats === []) {
                                $docCats = ['minutes','board_minutes','statute','regulation','contract','other'];
                            }
                            foreach ($docCats as $cat):
                                $catKey = is_array($cat) ? (string) ($cat['key'] ?? '') : (string) $cat;
                                $catLabel = is_array($cat)
                                    ? (string) ($cat['label'] ?? $catKey)
                                    : __('documents.category_' . $catKey);
                                if ($catKey === '') {
                                    continue;
                                }
                            ?>
                                <option value="<?= e($catKey) ?>" <?= ($componentConfigs['documents']['default_category'] ?? 'minutes') === $catKey ? 'selected' : '' ?>><?= e($catLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($enabledComponents['org_roles'])): ?>
                <details class="config-sub-accordion">
                    <summary><?= e(__('components.org_roles.name')) ?></summary>
                    <div class="config-sub-body">
                        <p class="muted"><?= e(__('settings.org_roles_hint')) ?></p>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('/org')) ?>"><?= e(__('settings.open_org')) ?></a>
                    </div>
                </details>
                <?php endif; ?>
            </div>

            <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
        </form>

        <?php if (can('plugins.manage')): ?>
            <?php $pluginCatalog = $plugin_catalog ?? []; ?>
            <div class="settings-plugins-block">
                <h3 class="section-title"><?= e(__('settings.plugins_section_title')) ?></h3>
                <p class="muted"><?= e(__('settings.plugins_panel_hint')) ?></p>
                <?php if ($pluginCatalog === []): ?>
                    <div class="empty-state compact">
                        <strong><?= e(__('plugins.none')) ?></strong>
                        <?= e(__('plugins.none_hint')) ?>
                    </div>
                <?php else: ?>
                    <div class="config-sub-accordions">
                        <?php foreach ($pluginCatalog as $plugin): ?>
                            <details class="config-sub-accordion" <?= !empty($plugin['is_enabled']) ? 'open' : '' ?>>
                                <summary>
                                    <?= e((string) ($plugin['name'] ?? '')) ?>
                                    <span class="muted"> · <?= e(__('plugins.version')) ?> <?= e((string) ($plugin['version'] ?? '')) ?></span>
                                </summary>
                                <div class="config-sub-body">
                                    <p class="muted"><?= e((string) ($plugin['description'] ?? '')) ?></p>
                                    <form method="post" action="<?= e(url('/plugins/' . rawurlencode((string) ($plugin['id'] ?? '')) . '/' . (!empty($plugin['is_enabled']) ? 'disable' : 'enable'))) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm <?= !empty($plugin['is_enabled']) ? 'btn-ghost' : '' ?>" type="submit">
                                            <?= e(!empty($plugin['is_enabled']) ? __('plugins.disable') : __('plugins.enable')) ?>
                                        </button>
                                    </form>
                                    <?php if (!empty($plugin['is_enabled']) && !empty($plugin['settings'])): ?>
                                        <h4 class="setup-subhead"><?= e(__('plugins.settings')) ?></h4>
                                        <form method="post" action="<?= e(url('/plugins/' . rawurlencode((string) ($plugin['id'] ?? '')) . '/settings')) ?>">
                                            <?= csrf_field() ?>
                                            <?php foreach ($plugin['settings'] as $key => $def): ?>
                                                <label><?= e((string) ($def['label'] ?? $key)) ?></label>
                                                <input type="<?= !empty($def['encrypted']) ? 'password' : 'text' ?>" name="<?= e((string) $key) ?>" value="<?= e((string) ($plugin['values'][$key] ?? '')) ?>">
                                            <?php endforeach; ?>
                                            <button class="btn btn-sm" type="submit"><?= e(__('common.save')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</details>

<?php if (!empty(auth_user()['is_system_admin'])): ?>
<section class="config-danger-zone" id="danger-zone" data-reset-user-data>
    <h2 class="config-danger-title"><?= e(__('settings.reset_zone_title')) ?></h2>
    <p class="config-danger-text"><?= e(__('settings.reset_zone_lede')) ?></p>
    <form method="post" action="<?= e(url('/settings/reset-user-data')) ?>" data-reset-user-form>
        <?= csrf_field() ?>
        <input type="hidden" name="confirm_reset_1" value="0" data-reset-confirm-1>
        <input type="hidden" name="confirm_reset_2" value="0" data-reset-confirm-2>
        <button type="button" class="btn btn-danger" data-reset-user-open><?= e(__('settings.reset_button')) ?></button>
    </form>
</section>

<dialog class="config-reset-dialog" data-reset-dialog-1>
    <div class="config-reset-shell">
        <p class="config-reset-text"><?= e(__('settings.reset_confirm_1')) ?></p>
        <div class="config-reset-actions">
            <button type="button" class="btn btn-ghost" data-reset-cancel><?= e(__('common.cancel')) ?></button>
            <button type="button" class="btn btn-danger" data-reset-next><?= e(__('settings.reset_confirm_continue')) ?></button>
        </div>
    </div>
</dialog>

<dialog class="config-reset-dialog" data-reset-dialog-2>
    <div class="config-reset-shell">
        <p class="config-reset-text"><?= e(__('settings.reset_confirm_2')) ?></p>
        <div class="config-reset-actions">
            <button type="button" class="btn btn-ghost" data-reset-cancel><?= e(__('common.cancel')) ?></button>
            <button type="button" class="btn btn-danger" data-reset-final><?= e(__('settings.reset_confirm_final')) ?></button>
        </div>
    </div>
</dialog>
<?php endif; ?>

</div>
