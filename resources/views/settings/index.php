<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('settings.title')) ?></h1>
        <p class="page-lede"><?= e(__('settings.lede')) ?></p>
    </div>
    <?php if (can('dashboard.view')): ?>
        <div class="actions">
            <a class="btn btn-ghost" href="<?= e(url('/dashboard')) ?>"><?= e(__('nav.back_to_dashboard')) ?></a>
        </div>
    <?php endif; ?>
</div>

<div class="config-layout" data-settings-nav data-default-group="assoc">
    <nav class="config-nav" role="tablist" aria-label="<?= e(__('settings.nav_aria')) ?>">
        <button type="button" class="config-nav-item" role="tab" data-settings-nav-item="assoc" aria-selected="true" aria-controls="settings-group-assoc">
            <span class="config-nav-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M3 21V8l9-5 9 5v13h-7v-6h-4v6H3z"/></svg>
            </span>
            <span class="config-nav-text">
                <span class="config-nav-title"><?= e(__('settings.nav_assoc')) ?></span>
                <span class="config-nav-lede"><?= e(__('settings.nav_assoc_lede')) ?></span>
            </span>
        </button>
        <button type="button" class="config-nav-item" role="tab" data-settings-nav-item="members" aria-selected="false" aria-controls="settings-group-members" tabindex="-1">
            <span class="config-nav-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05A4.4 4.4 0 0 1 17 16.5V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </span>
            <span class="config-nav-text">
                <span class="config-nav-title"><?= e(__('settings.nav_members')) ?></span>
                <span class="config-nav-lede"><?= e(__('settings.nav_members_lede')) ?></span>
            </span>
        </button>
        <button type="button" class="config-nav-item" role="tab" data-settings-nav-item="comms" aria-selected="false" aria-controls="settings-group-comms" tabindex="-1">
            <span class="config-nav-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></svg>
            </span>
            <span class="config-nav-text">
                <span class="config-nav-title"><?= e(__('settings.nav_comms')) ?></span>
                <span class="config-nav-lede"><?= e(__('settings.nav_comms_lede')) ?></span>
            </span>
        </button>
        <button type="button" class="config-nav-item" role="tab" data-settings-nav-item="cassa" aria-selected="false" aria-controls="settings-group-cassa" tabindex="-1">
            <span class="config-nav-icon" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M18 8h-1V6a5 5 0 0 0-10 0v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zm-6 9a2 2 0 1 1 0-4 2 2 0 0 1 0 4zm3-9H9V6a3 3 0 0 1 6 0v2z"/></svg>
            </span>
            <span class="config-nav-text">
                <span class="config-nav-title"><?= e(__('settings.nav_cassa')) ?></span>
                <span class="config-nav-lede"><?= e(__('settings.nav_cassa_lede')) ?></span>
            </span>
        </button>
    </nav>

    <div class="config-accordions" data-config-accordions
         data-autosave-busy="<?= e(__('settings.autosave_busy')) ?>"
         data-autosave-ok="<?= e(__('settings.autosaved')) ?>"
         data-autosave-fail="<?= e(__('settings.autosave_failed')) ?>"
         data-autosave-ago="<?= e(__('settings.autosave_ago')) ?>">
<details class="config-accordion" id="general" data-config-accordion data-settings-group="assoc">
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
            <?= field('text', [
                'name' => 'association_name',
                'label' => (string) __('setup.field_assoc_name'),
                'value' => (string) $settings['association.name'],
                'required' => true,
                'context' => 'setup',
                'wrapper_class' => 'setup-field-name',
                'autocomplete' => 'organization',
                'attrs' => ['data-setup-assoc-name' => true],
            ]) ?>
            <?= field('text', [
                'name' => 'association_name_de',
                'label' => (string) __('setup.field_assoc_name_de') . ' (' . __('setup.optional_short') . ')',
                'value' => (string) ($settings['association.name_de'] ?? ''),
                'context' => 'setup',
                'wrapper_class' => 'setup-field-name-de',
                'autocomplete' => 'organization',
                'hint' => (string) __('setup.field_assoc_name_de_hint'),
                'attrs' => ['data-setup-assoc-name-de' => true],
                // Only meaningful for South Tyrol associations (RUNTS/seat detection).
                'wrap_attrs' => array_filter([
                    'data-setup-name-de-wrap' => true,
                    'hidden' => empty($showNameDe),
                ]),
            ]) ?>
            <?php
            $legalForms = \Socly\Setup\AssociationLegalForms::all();
            $currentLegal = (string) ($settings['association.legal_name'] ?? '');
            $legalOptions = [];
            foreach ($legalForms as $code => $labelKey) {
                $legalOptions[] = [
                    'value' => (string) $code,
                    'label' => \Socly\Setup\AssociationLegalForms::displayCode((string) $code),
                    'attrs' => ['data-meaning' => \Socly\Setup\AssociationLegalForms::meaning((string) $code)],
                ];
            }
            ?>
            <?= field('select', [
                'name' => 'association_legal_name',
                'label' => (string) __('setup.field_assoc_legal_name'),
                'value' => $currentLegal,
                'required' => true,
                'context' => 'setup',
                'wrapper_class' => 'setup-field-legal',
                'options' => $legalOptions,
                'empty_label' => (string) __('setup.legal_form_placeholder'),
                'include_empty' => true,
                'attrs' => ['data-setup-legal-name' => true, 'maxlength' => '6'],
            ]) ?>
            <p class="setup-hint setup-full-name-preview muted" data-setup-full-name-preview hidden></p>
        </div>
        <div class="grid-2">
            <div>
                <?= field('text', [
                    'name' => 'association_fiscal_code',
                    'label' => (string) __('setup.field_fiscal_code'),
                    'value' => (string) ($settings['association.fiscal_code'] ?? ''),
                    'required' => true,
                    'context' => 'setup',
                    'maxlength' => 16,
                ]) ?>
            </div>
            <div>
                <?= field('text', [
                    'name' => 'association_vat',
                    'label' => (string) __('setup.field_vat_number'),
                    'value' => (string) ($settings['association.vat_number'] ?? ''),
                    'context' => 'setup',
                    'maxlength' => 11,
                ]) ?>
            </div>
        </div>
        <h3 class="section-title" style="margin-top:1rem"><?= e(__('setup.step_seat_title')) ?></h3>
        <?= field('address', [
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
            'required_map' => [
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
                <?= field('email', [
                    'name' => 'association_pec',
                    'label' => (string) __('setup.field_pec'),
                    'value' => (string) ($settings['association.pec'] ?? ''),
                    'required' => true,
                    'context' => 'setup',
                ]) ?>
            </div>
            <div>
                <?= field('email', [
                    'name' => 'association_email',
                    'label' => (string) __('setup.field_email'),
                    'value' => (string) ($settings['association.email'] ?? ''),
                    'context' => 'setup',
                ]) ?>
            </div>
        </div>
        <div class="grid-2">
            <div>
                <?= field('phone', [
                    'name' => 'association_phone',
                    'label' => (string) __('setup.field_phone'),
                    'value' => (string) ($settings['association.phone'] ?? ''),
                    'context' => 'setup',
                ]) ?>
            </div>
            <div>
                <?= field('text', [
                    'name' => 'association_runts',
                    'label' => (string) __('setup.field_runts'),
                    'value' => (string) ($settings['association.runts'] ?? ''),
                    'context' => 'setup',
                    'inputmode' => 'numeric',
                    'maxlength' => 6,
                    'autocomplete' => 'off',
                ]) ?>
            </div>
        </div>
        <div class="setup-logo settings-logo-block" data-setup-logo>
            <?php $settingsLogo = assoc_logo_url(); ?>
            <?php if ($settingsLogo): ?>
                <div class="setup-logo-preview" data-setup-logo-preview>
                    <img src="<?= e($settingsLogo) ?>" alt="<?= e(__('setup.field_logo')) ?>" data-setup-logo-img>
                </div>
                <?= field('checkbox', [
                    'name' => 'remove_logo',
                    'label' => (string) __('setup.logo_remove'),
                    'context' => 'setup',
                    'label_class' => 'checkbox-row setup-check',
                ]) ?>
            <?php else: ?>
                <div class="setup-logo-preview" data-setup-logo-preview hidden>
                    <img alt="<?= e(__('setup.field_logo')) ?>" data-setup-logo-img hidden>
                </div>
            <?php endif; ?>
            <div class="setup-logo-actions">
                <?= field('file', [
                    'name' => 'logo',
                    'button_label' => (string) __('setup.logo_upload'),
                    'accept' => 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml',
                    'context' => 'setup',
                    'button_class' => 'btn btn-ghost file-btn',
                    'icon' => 'upload',
                    'attrs' => ['data-setup-logo-input' => true],
                ]) ?>
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
            <?= field('color', [
                'name' => 'primary',
                'label' => (string) __('install.primary'),
                'value' => (string) $settings['branding.primary'],
                'required' => true,
                'setup_brand' => true,
                'brand_key' => 'primary',
            ]) ?>
            <?= field('color', [
                'name' => 'accent',
                'label' => (string) __('install.accent'),
                'value' => (string) $settings['branding.accent'],
                'required' => true,
                'setup_brand' => true,
                'brand_key' => 'accent',
            ]) ?>
        </div>
        <fieldset data-setup-line>
            <legend class="setup-field-label"><?= e(__('install.locale')) ?></legend>
            <?= field('locale_cards', [
                'name' => 'locale',
                'value' => (string) ($settings['app.locale'] ?? 'it'),
                'locales' => ['it', 'de', 'en'],
                'required' => true,
            ]) ?>
        </fieldset>
        <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
    </form>
    </div>
</details>

<details class="config-accordion" id="people" data-config-accordion data-settings-group="assoc">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.people')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.people_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

<form method="post" action="<?= e(url('/settings/people')) ?>" data-cities-url="<?= e(url('/api/geo/cities')) ?>" data-addresses-url="<?= e(url('/api/geo/addresses')) ?>" data-settings-geo data-leave-guard>
        <?= csrf_field() ?>
        <p class="muted"><?= e(__('settings.people_hint')) ?></p>
        <?php
        $roleOptions = [];
        foreach ($roles as $role) {
            $roleOptions[] = [
                'value' => (string) $role['key'],
                'label' => (string) __((string) $role['label_key']),
            ];
        }
        ?>
        <div class="assoc-people" data-people-list>
            <div class="assoc-people-rows" data-people-rows>
                <?php foreach ($people as $i => $person): ?>
                    <?php $roleKey = (string) ($person['role_key'] ?? 'board'); ?>
                    <div class="assoc-person-card" data-people-row data-role="<?= e($roleKey) ?>">
                        <div class="assoc-person-main">
                            <?= field('select', [
                                'name' => 'people[' . (int) $i . '][role_key]',
                                'label' => (string) __('settings.people_role'),
                                'value' => $roleKey,
                                'required' => true,
                                'context' => 'setup',
                                'include_empty' => false,
                                'options' => $roleOptions,
                            ]) ?>
                            <?= field('text', [
                                'name' => 'people[' . (int) $i . '][first_name]',
                                'label' => (string) __('setup.field_first_name'),
                                'value' => (string) ($person['first_name'] ?? ''),
                                'context' => 'setup',
                            ]) ?>
                            <?= field('text', [
                                'name' => 'people[' . (int) $i . '][last_name]',
                                'label' => (string) __('setup.field_last_name'),
                                'value' => (string) ($person['last_name'] ?? ''),
                                'context' => 'setup',
                            ]) ?>
                            <?= field('text', [
                                'name' => 'people[' . (int) $i . '][fiscal_code]',
                                'label' => (string) __('setup.field_person_fiscal_code'),
                                'value' => (string) ($person['fiscal_code'] ?? ''),
                                'context' => 'setup',
                                'maxlength' => 16,
                            ]) ?>
                            <button type="button" class="btn btn-ghost btn-sm" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                        </div>
                        <?= field('address', [
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
                            <?= field('date', [
                                'name' => 'people[' . (int) $i . '][appointed_at]',
                                'label' => (string) __('setup.field_appointed_at'),
                                'value' => (string) ($person['appointed_at'] ?? ''),
                                'context' => 'setup',
                            ]) ?>
                            <?= field('date', [
                                'name' => 'people[' . (int) $i . '][mandate_ends_at]',
                                'label' => (string) __('setup.field_mandate_ends_at'),
                                'value' => (string) ($person['mandate_ends_at'] ?? ''),
                                'context' => 'setup',
                            ]) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-ghost" data-people-add><?= e(__('setup.add_person')) ?></button>
            <template data-people-template>
                <div class="assoc-person-card" data-people-row data-role="board">
                    <div class="assoc-person-main">
                        <?= field('select', [
                            'name' => 'people[__i__][role_key]',
                            'label' => (string) __('settings.people_role'),
                            'value' => 'board',
                            'required' => true,
                            'context' => 'setup',
                            'include_empty' => false,
                            'options' => $roleOptions,
                        ]) ?>
                        <?= field('text', [
                            'name' => 'people[__i__][first_name]',
                            'label' => (string) __('setup.field_first_name'),
                            'value' => '',
                            'context' => 'setup',
                        ]) ?>
                        <?= field('text', [
                            'name' => 'people[__i__][last_name]',
                            'label' => (string) __('setup.field_last_name'),
                            'value' => '',
                            'context' => 'setup',
                        ]) ?>
                        <?= field('text', [
                            'name' => 'people[__i__][fiscal_code]',
                            'label' => (string) __('setup.field_person_fiscal_code'),
                            'value' => '',
                            'context' => 'setup',
                            'maxlength' => 16,
                        ]) ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                    </div>
                    <?= field('address', [
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
                        <?= field('date', [
                            'name' => 'people[__i__][appointed_at]',
                            'label' => (string) __('setup.field_appointed_at'),
                            'value' => '',
                            'context' => 'setup',
                        ]) ?>
                        <?= field('date', [
                            'name' => 'people[__i__][mandate_ends_at]',
                            'label' => (string) __('setup.field_mandate_ends_at'),
                            'value' => '',
                            'context' => 'setup',
                        ]) ?>
                    </div>
                </div>
            </template>
        </div>
        <button class="btn" type="submit" style="margin-top:1rem"><?= e(__('common.save')) ?></button>
    </form>
    </div>
</details>

<details class="config-accordion" id="legal" data-config-accordion data-settings-group="cassa">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.legal')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.legal_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">

<form method="post" action="<?= e(url('/settings/legal')) ?>" data-leave-guard data-settings-autosave>
        <?= csrf_field() ?>
        <div class="setup-gdpr-options" data-setup-gdpr-options>
            <?= field('checkbox', [
                'name' => 'gdpr_enabled',
                'label' => (string) __('setup.gdpr_label'),
                'checked' => ($settings['gdpr.enabled'] ?? '') === '1',
                'context' => 'setup',
                'label_class' => 'checkbox-row setup-check setup-check-prominent',
                'attrs' => ['data-gdpr-master' => true],
            ]) ?>
            <p class="setup-gdpr-model-note" data-gdpr-extras>
                <span class="setup-gdpr-model-note-icon" aria-hidden="true">ℹ</span>
                <span><?= e(__('setup.gdpr_model_note')) ?></span>
            </p>
            <div class="setup-gdpr-extras" data-gdpr-extras>
                <?= field('checkbox', [
                    'name' => 'require_image_consent',
                    'label' => (string) __('setup.gdpr_image_consent'),
                    'checked' => ($settings['gdpr.require_image_consent'] ?? '0') === '1',
                    'context' => 'setup',
                    'label_class' => 'checkbox-row setup-check',
                    'attrs' => ['data-gdpr-extra' => true],
                ]) ?>
                <?= field('checkbox', [
                    'name' => 'require_comms_consent',
                    'label' => (string) __('setup.gdpr_comms_consent'),
                    'checked' => ($settings['gdpr.require_comms_consent'] ?? '0') === '1',
                    'context' => 'setup',
                    'label_class' => 'checkbox-row setup-check',
                    'attrs' => ['data-gdpr-extra' => true],
                ]) ?>
            </div>
        </div>
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

<details class="config-accordion" id="types" data-config-accordion data-settings-group="members">
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

<details class="config-accordion" id="periods" data-config-accordion data-settings-group="members">
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

<details class="config-accordion" id="fields" data-config-accordion data-settings-group="members">
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
            'setupMode' => true,
            'autosaveUrl' => url('/settings/fields/autosave'),
        ]) ?>
        <div class="setup-membership-card setup-membership-card-new setup-fields-add">
            <h3 class="setup-subhead"><?= e(__('settings.add_field')) ?></h3>
            <p class="setup-hint muted"><?= e(__('setup.fields_add_hint')) ?></p>
            <div class="setup-equal-row">
                <?= field('text', [
                    'name' => 'new_label',
                    'label' => (string) __('setup.fields_new_label'),
                    'value' => '',
                    'context' => 'setup',
                    'wrapper_class' => 'setup-field-grow',
                    'placeholder' => (string) __('setup.fields_new_label_ph'),
                ]) ?>
                <?php
                $fieldTypeOptions = [];
                foreach (\Socly\Support\MemberFieldTypes::keys() as $t) {
                    $fieldTypeOptions[] = ['value' => $t, 'label' => \Socly\Support\MemberFieldTypes::label($t)];
                }
                ?>
                <?= field('select', [
                    'name' => 'new_type',
                    'label' => (string) __('setup.fields_col_type'),
                    'value' => 'text',
                    'context' => 'setup',
                    'include_empty' => false,
                    'options' => $fieldTypeOptions,
                ]) ?>
            </div>
            <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
        </div>
    </form>
    </div>
</details>

<details class="config-accordion" id="mail" data-config-accordion data-settings-group="comms">
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
            $mailEnc = (string) ($mailCfg['encryption'] ?? 'tls');
            $assocEmail = (string) ($mailCfg['from_address'] ?: '');
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
            <?= field('checkbox', [
                'name' => 'outbound_disabled',
                'label' => (string) __('setup.mail_skip_outbound'),
                'checked' => $mailSkipped,
                'context' => 'setup',
                'label_class' => 'checkbox-row setup-check setup-check-prominent setup-smtp-skip',
                'attrs' => ['data-smtp-skip' => true],
            ]) ?>
            <p
                class="setup-hint muted"
                data-smtp-skip-hint
                data-hint-skip="<?= e(__('setup.mail_skip_outbound_hint')) ?>"
                data-hint-enable="<?= e(__('setup.mail_enable_outbound_hint')) ?>"
            ><?= e($mailSkipped ? __('setup.mail_skip_outbound_hint') : __('setup.mail_enable_outbound_hint')) ?></p>
            <div class="setup-smtp-fields" data-smtp-fields <?= $mailSkipped ? 'hidden' : '' ?>>
                <div class="setup-smtp-section">
                    <p class="setup-hint muted"><?= e(__('setup.mail_simple_hint')) ?></p>
                    <div class="setup-equal-row setup-smtp-credentials">
                        <?= field('email', [
                            'name' => 'from_address',
                            'label' => (string) __('mail.from_address'),
                            'value' => (string) $mailCfg['from_address'],
                            'required' => true,
                            'context' => 'setup',
                            'wrapper_class' => 'setup-field-grow',
                            'placeholder' => 'noreply@tuodominio.it',
                            'autocomplete' => 'off',
                            'attrs' => ['data-smtp-from' => true],
                        ]) ?>
                        <p class="field-hint setup-field-hint" data-smtp-from-hint hidden></p>
                        <?= field('password', [
                            'name' => 'password',
                            'label' => (string) __('mail.password'),
                            'required' => empty($mailCfg['has_password']),
                            'placeholder' => !empty($mailCfg['has_password']) ? (string) __('mail.password_keep') : '',
                            'autocomplete' => 'new-password',
                            'context' => 'setup',
                            'input_attrs' => 'data-smtp-password',
                            'hint_attrs' => 'data-smtp-password-hint',
                        ]) ?>
                    </div>
                    <div class="setup-smtp-discover-row" data-smtp-discover-row>
                        <button type="button" class="btn setup-scrape-btn" data-smtp-discover-btn>
                            <span data-smtp-discover-label><?= e(__('setup.mail_discover_btn')) ?></span>
                        </button>
                    </div>
                    <div class="setup-smtp-live" data-smtp-live hidden>
                        <div class="setup-scrape-spinner" aria-hidden="true"></div>
                        <p class="setup-hint muted setup-smtp-live-status" data-smtp-discover-status hidden></p>
                    </div>
                </div>
                <div class="setup-smtp-manual" data-smtp-manual <?= $showManual ? '' : 'hidden' ?>>
                    <p class="setup-smtp-manual-title"><?= e(__('setup.mail_manual_title')) ?></p>
                    <p class="setup-hint muted"><?= e(__('setup.mail_manual_hint')) ?></p>
                    <div class="setup-equal-row">
                        <?= field('text', [
                            'name' => 'host',
                            'label' => (string) __('mail.host'),
                            'value' => (string) $mailCfg['host'],
                            'required' => true,
                            'context' => 'setup',
                            'placeholder' => 'smtp.example.com',
                            'autocomplete' => 'off',
                            'attrs' => ['data-smtp-host' => true],
                        ]) ?>
                        <?= field('number', [
                            'name' => 'port',
                            'label' => (string) __('mail.port'),
                            'value' => (string) ($mailCfg['port'] ?: 587),
                            'required' => true,
                            'context' => 'setup',
                            'min' => 1,
                            'max' => 65535,
                            'attrs' => ['data-smtp-port' => true],
                        ]) ?>
                    </div>
                    <?= field('select', [
                        'name' => 'encryption',
                        'label' => (string) __('mail.encryption'),
                        'value' => $mailEnc,
                        'required' => true,
                        'context' => 'setup',
                        'include_empty' => false,
                        'options' => [
                            ['value' => 'tls', 'label' => (string) __('mail.encryption_tls')],
                            ['value' => 'ssl', 'label' => (string) __('mail.encryption_ssl')],
                            ['value' => 'none', 'label' => (string) __('mail.encryption_none')],
                        ],
                        'attrs' => ['data-smtp-encryption' => true],
                    ]) ?>
                    <?= field('text', [
                        'name' => 'username',
                        'label' => (string) __('mail.username'),
                        'value' => (string) $mailCfg['username'],
                        'required' => true,
                        'context' => 'setup',
                        'autocomplete' => 'off',
                        'placeholder' => (string) __('mail.username_default_hint'),
                        'attrs' => ['data-smtp-username' => true],
                    ]) ?>
                    <?= field('text', [
                        'name' => 'from_name',
                        'label' => (string) __('mail.from_name'),
                        'value' => (string) $mailCfg['from_name'],
                        'context' => 'setup',
                        'autocomplete' => 'organization',
                        'attrs' => ['data-smtp-from-name' => true],
                    ]) ?>
                    <div class="setup-smtp-actions">
                        <button type="button" class="btn btn-ghost" data-smtp-verify-btn><?= e(__('setup.mail_verify_btn')) ?></button>
                    </div>
                    <p class="setup-hint muted" data-smtp-verify-status hidden></p>
                </div>
                <div class="setup-smtp-section setup-smtp-test" data-smtp-test-section <?= $mailReady ? '' : 'hidden' ?>>
                    <?= field('email', [
                        'name' => 'test_to',
                        'label' => (string) __('mail.test_to'),
                        'value' => $assocEmail,
                        'required' => $mailReady,
                        'disabled' => !$mailReady,
                        'context' => 'setup',
                        'autocomplete' => 'off',
                        'attrs' => ['data-smtp-test-to' => true],
                    ]) ?>
                    <div class="setup-smtp-actions">
                        <button type="button" class="btn btn-ghost" data-smtp-test-btn <?= $mailReady ? '' : 'disabled' ?>><?= e(__('setup.mail_test_btn')) ?></button>
                    </div>
                    <p class="setup-hint muted" data-smtp-test-status <?= $mailReady ? '' : 'hidden' ?>><?= $mailReady ? e(__('mail.test_ok_badge')) : '' ?></p>
                    <p class="setup-hint muted"><?= e(__('setup.mail_save_hint')) ?></p>
                </div>
            </div>
            <div class="actions setup-smtp-actions" style="margin-top:0.25rem">
                <button class="btn" type="submit" name="action" value="save_test"><?= e(__('mail.save_and_test')) ?></button>
                <button class="btn btn-ghost" type="submit" name="action" value="save"><?= e(__('common.save')) ?></button>
            </div>
        </form>
    </div>
</details>

<details class="config-accordion" id="email-templates" data-config-accordion data-settings-group="comms">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.email_templates_title')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.email_templates_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <?= view_partial('partials/email_templates_panel', [
            'emailTemplates' => $emailTemplates ?? [],
            'editingTemplate' => $editingTemplate ?? null,
            'emailSampleJson' => $emailSampleJson ?? '{}',
            'mailReady' => !empty($mailReady),
            'defaultTestEmail' => (string) ($settings['association.email'] ?? ''),
        ]) ?>
    </div>
</details>

<details class="config-accordion" id="workflow" data-config-accordion data-settings-group="comms">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.workflow_title')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.workflow_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <?= view_partial('partials/workflow_panel', [
            'workflowRules' => $workflowRules ?? [],
            'emailTemplates' => $emailTemplates ?? [],
            'editingWorkflow' => $editingWorkflow ?? null,
        ]) ?>
    </div>
</details>

<details class="config-accordion" id="enrollment" data-config-accordion data-settings-group="members">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.enrollment')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.enrollment_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <form method="post" action="<?= e(url('/settings/enrollment')) ?>" data-leave-guard data-settings-autosave>
            <?= csrf_field() ?>
            <?= view_partial('partials/enrollment_method_picker', [
                'name' => 'enrollment_validation',
                'value' => (string) ($settings['membership.enrollment_validation'] ?? 'none'),
                'mailReady' => !empty($mailReady),
                'mailSetupHref' => url('/settings#mail'),
                'showFieldLabel' => true,
                'fieldLabel' => (string) __('settings.enrollment_method'),
            ]) ?>
            <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
        </form>
    </div>
</details>

<details class="config-accordion" id="platform" data-config-accordion data-settings-group="assoc">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.platform')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.platform_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <form method="post" action="<?= e(url('/settings/platform')) ?>" data-platform-consents data-mail-ready="<?= !empty($mailReady) ? '1' : '0' ?>" data-leave-guard data-settings-autosave>
            <?= csrf_field() ?>
            <p class="setup-hint muted"><?= e(__('setup.platform_hint')) ?></p>
            <?= field('checkbox', [
                'name' => 'news_opt_in',
                'label' => (string) __('settings.platform_news'),
                'checked' => ($settings['platform.news_opt_in'] ?? '1') !== '0',
                'context' => 'setup',
                'label_class' => 'checkbox-row setup-check setup-check-prominent',
                'attrs' => ['data-platform-opt' => true],
            ]) ?>
            <?= field('checkbox', [
                'name' => 'usage_stats_opt_in',
                'label' => (string) __('settings.platform_stats'),
                'checked' => ($settings['platform.usage_stats_opt_in'] ?? '1') !== '0',
                'context' => 'setup',
                'label_class' => 'checkbox-row setup-check setup-check-prominent',
                'attrs' => ['data-platform-opt' => true],
            ]) ?>
            <p class="setup-hint muted"><?= e(__('setup.platform_stats_hint')) ?></p>
            <?php if (!is_temporary_instance()): ?>
                <?= field('checkbox', [
                    'name' => 'contact_sync_opt_in',
                    'label' => (string) __('settings.platform_contacts'),
                    'checked' => ($settings['platform.contacts_opt_in'] ?? '1') !== '0',
                    'context' => 'setup',
                    'label_class' => 'checkbox-row setup-check setup-check-prominent',
                    'attrs' => ['data-platform-opt' => true],
                ]) ?>
                <p class="setup-hint muted"><?= e(__('setup.platform_contacts_hint')) ?></p>
            <?php endif; ?>
            <?php
                $termsAcceptedAt = (string) ($settings['legal.terms_accepted_at'] ?? '');
                $termsAcceptedLabel = $termsAcceptedAt !== ''
                    ? __('terms.accepted_at', ['date' => format_datetime($termsAcceptedAt)])
                    : __('terms.not_accepted');
            ?>
            <div class="settings-terms-status">
                <p class="settings-terms-title"><?= e(__('settings.terms_status_title')) ?></p>
                <p class="settings-terms-note muted">
                    <span><?= e($termsAcceptedLabel) ?></span>
                    <span class="settings-terms-sep" aria-hidden="true">·</span>
                    <button type="button" class="setup-terms-link" data-terms-open="terms-dialog"><?= e(__('terms.read_all')) ?></button>
                </p>
                <p class="settings-terms-lede muted"><?= e(__('settings.terms_status_lede')) ?></p>
            </div>
            <?php
                $anyPlatformSettings = (($settings['platform.news_opt_in'] ?? '1') !== '0')
                    || (($settings['platform.usage_stats_opt_in'] ?? '1') !== '0')
                    || (!is_temporary_instance() && (($settings['platform.contacts_opt_in'] ?? '1') !== '0'));
            ?>
            <div class="setup-platform-confirm" data-platform-confirm <?= $anyPlatformSettings ? '' : 'hidden' ?>>
                <p class="setup-hint muted"><?= e(__('setup.platform_confirm_hint')) ?></p>
                <div class="setup-equal-row">
                    <?= field('text', [
                        'name' => 'confirm_first_name',
                        'label' => (string) __('setup.platform_confirm_first'),
                        'value' => '',
                        'required' => $anyPlatformSettings,
                        'context' => 'setup',
                        'autocomplete' => 'off',
                        'placeholder' => (string) ($presidentFirstPlaceholder ?? ''),
                        'attrs' => ['data-platform-confirm-input' => true],
                    ]) ?>
                    <?= field('text', [
                        'name' => 'confirm_last_name',
                        'label' => (string) __('setup.platform_confirm_last'),
                        'value' => '',
                        'required' => $anyPlatformSettings,
                        'context' => 'setup',
                        'autocomplete' => 'off',
                        'placeholder' => (string) ($presidentLastPlaceholder ?? ''),
                        'attrs' => ['data-platform-confirm-input' => true],
                    ]) ?>
                </div>
            </div>
            <p class="settings-autosave-status muted" data-settings-autosave-status aria-live="polite"></p>
        </form>
    </div>
</details>

<?php if (can('users.manage')): ?>
<details class="config-accordion" id="users" data-config-accordion data-settings-group="assoc">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.users_manage_title')) ?></span>
        <span class="config-accordion-lede"><?= e(__('users.lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <?= view_partial('partials/users_panel', [
            'panelUsers' => $panelUsers ?? [],
            'mailReady' => !empty($mailReady),
        ]) ?>
    </div>
</details>
<?php endif; ?>

<details class="config-accordion" id="components" data-config-accordion data-settings-group="assoc">
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
                        <?= field('checkbox', [
                            'bare' => true,
                            'name' => 'components[]',
                            'value' => $checked ? $cKey : '',
                            'check_value' => $cKey,
                            'checked' => $checked,
                        ]) ?>
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
                        <?= field('checkbox', [
                            'name' => 'treasury_auto_from_payments',
                            'label' => (string) __('settings.treasury_auto_from_payments'),
                            'checked' => !empty($componentConfigs['treasury']['auto_from_payments']),
                            'context' => 'setup',
                            'label_class' => 'checkbox-row',
                        ]) ?>
                        <p class="muted"><?= e(__('settings.treasury_auto_from_payments_hint')) ?></p>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($enabledComponents['deadlines'])): ?>
                <details class="config-sub-accordion">
                    <summary><?= e(__('components.deadlines.name')) ?></summary>
                    <div class="config-sub-body">
                        <?= field('number', [
                            'name' => 'deadlines_warn_days',
                            'label' => (string) __('settings.deadlines_warn_days'),
                            'value' => (string) ($componentConfigs['deadlines']['warn_days'] ?? 30),
                            'context' => 'setup',
                            'min' => 1,
                            'max' => 90,
                        ]) ?>
                    </div>
                </details>
                <?php endif; ?>

                <?php if (!empty($enabledComponents['documents'])): ?>
                <details class="config-sub-accordion">
                    <summary><?= e(__('components.documents.name')) ?></summary>
                    <div class="config-sub-body">
                        <?php
                        $docCats = $componentConfigs['documents']['category_options'] ?? null;
                        if (!is_array($docCats) || $docCats === []) {
                            $docCats = ['minutes', 'board_minutes', 'statute', 'regulation', 'contract', 'other'];
                        }
                        $docCategoryOptions = [];
                        foreach ($docCats as $cat) {
                            $catKey = is_array($cat) ? (string) ($cat['key'] ?? '') : (string) $cat;
                            if ($catKey === '') {
                                continue;
                            }
                            $catLabel = is_array($cat)
                                ? (string) ($cat['label'] ?? $catKey)
                                : (string) __('documents.category_' . $catKey);
                            $docCategoryOptions[] = ['value' => $catKey, 'label' => $catLabel];
                        }
                        ?>
                        <?= field('select', [
                            'name' => 'documents_default_category',
                            'label' => (string) __('settings.documents_default_category'),
                            'value' => (string) ($componentConfigs['documents']['default_category'] ?? 'minutes'),
                            'context' => 'setup',
                            'include_empty' => false,
                            'options' => $docCategoryOptions,
                        ]) ?>
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

        <?php if (false && can('plugins.manage')): ?>
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
                                                <?php
                                                $pluginFieldType = !empty($def['encrypted']) ? 'password' : 'text';
                                                echo field($pluginFieldType, [
                                                    'name' => (string) $key,
                                                    'label' => (string) ($def['label'] ?? $key),
                                                    'value' => (string) ($plugin['values'][$key] ?? ''),
                                                    'context' => 'setup',
                                                ]);
                                                ?>
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

<details class="config-accordion" id="treasury-accounts" data-config-accordion data-settings-group="cassa">
    <summary class="config-accordion-summary">
        <span class="config-accordion-title"><?= e(__('settings.treasury_accounts_title')) ?></span>
        <span class="config-accordion-lede"><?= e(__('settings.treasury_accounts_lede')) ?></span>
    </summary>
    <div class="config-accordion-body">
        <?php if (empty($enabledComponents['treasury'])): ?>
            <p class="muted"><?= e(__('settings.treasury_accounts_component_off')) ?></p>
        <?php else: ?>
            <p class="muted"><?= e(__('settings.treasury_accounts_hint')) ?></p>
            <div class="accounts-admin">
                <?php foreach (($treasuryAccounts ?? []) as $accountRow): ?>
                    <?php
                    $accountId = (int) ($accountRow['id'] ?? 0);
                    $accountSlug = (string) ($accountRow['key'] ?? '');
                    $accountLabel = (string) ($accountRow['label'] ?? '');
                    $accountActive = !empty($accountRow['active']);
                    $accountBuiltin = !empty($accountRow['builtin']);
                    $accountMovements = (int) (($treasuryAccountUsage ?? [])[$accountSlug] ?? 0);
                    ?>
                    <article class="accounts-admin-row<?= $accountActive ? '' : ' is-inactive' ?>">
                        <form method="post" action="<?= e(url('/settings/treasury-accounts')) ?>" class="accounts-admin-form">
                            <?= csrf_field() ?>
                            <?= field('hidden', ['name' => 'id', 'value' => (string) $accountId]) ?>
                            <div class="accounts-admin-identity">
                                <span class="accounts-admin-slug"><?= e($accountSlug) ?></span>
                                <span class="accounts-admin-meta muted"><?= e(__('settings.treasury_account_meta', ['slug' => $accountSlug, 'count' => (string) $accountMovements])) ?></span>
                            </div>
                            <?= field('text', [
                                'name' => 'label',
                                'label' => (string) __('settings.treasury_account_label'),
                                'value' => $accountLabel,
                                'context' => 'setup',
                                'maxlength' => 80,
                                'wrapper_class' => 'accounts-admin-label',
                                'placeholder' => $accountBuiltin ? (string) __('settings.treasury_account_builtin_placeholder') : '',
                            ]) ?>
                            <?= field('checkbox', [
                                'name' => 'is_active',
                                'label' => (string) __('settings.treasury_account_active'),
                                'checked' => $accountActive,
                                'context' => 'inline',
                                'value' => '1',
                                'check_value' => '1',
                                'label_class' => 'checkbox-row',
                                'wrapper_class' => 'accounts-admin-toggle',
                            ]) ?>
                            <button class="btn btn-sm accounts-admin-save" type="submit"><?= e(__('common.save')) ?></button>
                        </form>
                        <div class="accounts-admin-side">
                            <?php if ($accountMovements === 0): ?>
                                <form method="post" action="<?= e(url('/settings/treasury-accounts/delete')) ?>" class="accounts-admin-delete"
                                      data-confirm="<?= e(__('settings.treasury_account_delete_confirm', ['name' => $accountLabel])) ?>" data-confirm-danger="1">
                                    <?= csrf_field() ?>
                                    <?= field('hidden', ['name' => 'id', 'value' => (string) $accountId]) ?>
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('settings.treasury_account_delete')) ?></button>
                                </form>
                            <?php else: ?>
                                <span class="accounts-admin-locked muted"><?= e(__('settings.treasury_account_locked', ['count' => (string) $accountMovements])) ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <form method="post" action="<?= e(url('/settings/treasury-accounts')) ?>" class="accounts-admin-add">
                <?= csrf_field() ?>
                <?= field('hidden', ['name' => 'id', 'value' => '0']) ?>
                <div class="accounts-admin-add-label">
                    <?= field('text', [
                        'name' => 'label',
                        'label' => (string) __('settings.treasury_account_new_label'),
                        'value' => '',
                        'context' => 'setup',
                        'maxlength' => 80,
                        'required' => true,
                        'placeholder' => (string) __('settings.treasury_account_new_placeholder'),
                    ]) ?>
                </div>
                <?= field('checkbox', [
                    'name' => 'is_active',
                    'label' => (string) __('settings.treasury_account_active'),
                    'checked' => true,
                    'context' => 'inline',
                    'value' => '1',
                    'check_value' => '1',
                    'label_class' => 'checkbox-row',
                    'wrapper_class' => 'accounts-admin-toggle',
                ]) ?>
                <button class="btn" type="submit"><?= e(__('settings.treasury_account_add')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</details>

<?php if (!empty(auth_user()['is_system_admin'])): ?>
<section class="config-danger-zone" id="danger-zone" data-reset-user-data>
    <h2 class="config-danger-title"><?= e(__('settings.reset_zone_title')) ?></h2>
    <p class="config-danger-text"><?= e(__('settings.reset_zone_lede')) ?></p>
    <form method="post" action="<?= e(url('/settings/reset-user-data')) ?>" data-reset-user-form>
        <?= csrf_field() ?>
        <?= field('hidden', ['name' => 'confirm_reset_1', 'value' => '0', 'attrs' => ['data-reset-confirm-1' => true]]) ?>
        <?= field('hidden', ['name' => 'confirm_reset_2', 'value' => '0', 'attrs' => ['data-reset-confirm-2' => true]]) ?>
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
    </div><!-- .config-accordions -->
</div><!-- .config-layout -->

<?= view_partial('partials/terms_dialog', ['dialogId' => 'terms-dialog']) ?>
