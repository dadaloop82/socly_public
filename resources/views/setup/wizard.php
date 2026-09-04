<?php
/** @var string $mode */
$errors = flash('errors');
$errorStep = flash('setup_error_step');
?>
<div class="setup-stage" data-setup-wizard data-mode="<?= e($mode) ?>" data-has-progress="<?= !empty($hasProgress) ? '1' : '0' ?>" data-setup-incremental="<?= !empty($isIncremental) ? '1' : '0' ?>" data-i18n-endpoint="<?= e(url('/i18n/messages')) ?>">
    <div class="setup-ambient" aria-hidden="true"></div>

    <?php if ($mode === 'greeting'): ?>
        <?php $errors = null; ?>
        <section class="setup-card setup-greeting" data-setup-panel>
            <div class="setup-card-bar setup-card-bar-end" data-setup-line>
                <button type="button" class="btn btn-ghost btn-sm setup-exit" data-setup-exit><?= e(__('setup.exit')) ?></button>
            </div>
            <p class="setup-kicker" data-setup-line>
                <?= socly_mark_img('setup-mark') ?>
            </p>
            <h1 class="setup-hello">
                <span class="setup-hello-main" data-setup-line><?= e(__('setup.greeting_' . $greeting)) ?></span>
                <span class="setup-hello-sub" data-setup-line><?= e(__(!empty($isIncremental) ? 'setup.greeting_lede_incremental' : 'setup.greeting_lede')) ?></span>
            </h1>
            <p class="setup-copy" data-setup-line><?= with_socly_word(__(!empty($isIncremental) ? 'setup.greeting_text_incremental' : 'setup.greeting_text', ['count' => (string) $missingCount])) ?></p>
            <p class="setup-copy setup-copy-reassure setup-copy-reassure-strong muted" data-setup-line><?= e(__('setup.greeting_reassure')) ?></p>
            <p class="setup-copy setup-copy-reassure setup-copy-reassure-eta muted" data-setup-line><?= e(setup_eta_reassure_line((int) $missingCount)) ?></p>
            <form method="post" action="<?= e(url('/setup/greet')) ?>">
                <?= csrf_field() ?>
                <button class="btn setup-cta" type="submit" data-setup-line><?= e(__('setup.start')) ?></button>
            </form>
        </section>
    <?php elseif ($mode === 'thanks'): ?>
        <?php $errors = null; ?>
        <section class="setup-card setup-thanks" data-setup-panel>
            <div class="setup-card-bar setup-card-bar-end" data-setup-line>
                <button type="button" class="btn btn-ghost btn-sm setup-exit" data-setup-exit><?= e(__('setup.exit')) ?></button>
            </div>
            <p class="setup-kicker" data-setup-line>
                <?= socly_mark_img('setup-mark') ?>
            </p>
            <h1 class="setup-thanks-title" data-setup-line>
                <span class="setup-thanks-lead"><?= e(__('setup.thanks_before')) ?></span>
                <?= assoc_lockup_html([
                    'name' => (string) ($assocName ?? ''),
                    'legal_name' => (string) ($assocLegal ?? ''),
                    'class' => 'assoc-lockup-thanks',
                ]) ?>
                <span class="setup-thanks-trail"><?= with_socly_word(__('setup.thanks_after')) ?></span>
            </h1>
            <p class="setup-copy setup-thanks-copy" data-setup-line>
                <?= e(__('setup.thanks_text')) ?>
            </p>
            <form method="post" action="<?= e(url('/setup/thanks')) ?>">
                <?= csrf_field() ?>
                <div class="setup-actions" data-setup-line>
                    <?php if (!empty($backHref)): ?>
                        <a class="btn btn-ghost setup-back" href="<?= e((string) $backHref) ?>"><?= e(__('common.back')) ?></a>
                    <?php endif; ?>
                    <button class="btn setup-cta" type="submit"><?= e(__('setup.thanks_agree')) ?></button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <?php
        /** @var array $step */
        $progress = $totalSteps > 0 ? (($stepIndex + 1) / $totalSteps) * 100 : 100;
        $stepType = (string) ($step['type'] ?? '');
        $currentStepKey = (string) ($step['key'] ?? '');
        // Never show validation from another step (e.g. APS name rule on website).
        if (
            $errors
            && is_string($errorStep)
            && $errorStep !== ''
            && $errorStep !== $currentStepKey
        ) {
            $errors = null;
        }
        if (
            $errors
            && ($errorStep === null || $errorStep === '')
            && $currentStepKey !== 'association.name'
        ) {
            $filtered = [];
            foreach ((array) $errors as $key => $err) {
                $text = is_array($err) ? implode(', ', $err) : (string) $err;
                if ($key === 'name') {
                    continue;
                }
                $filtered[$key] = $err;
            }
            $errors = $filtered === [] ? null : $filtered;
        }
        ?>
        <section class="setup-card setup-step" data-setup-panel>
            <div class="setup-card-bar" data-setup-line>
                <div class="setup-progress" aria-hidden="true">
                    <span style="--setup-progress: <?= e((string) round($progress)) ?>%"></span>
                </div>
                <button type="button" class="btn btn-ghost btn-sm setup-exit" data-setup-exit data-i18n="setup.exit"><?= e(__('setup.exit')) ?></button>
            </div>
            <p class="setup-meta" data-setup-line data-i18n="setup.step_of" data-i18n-current="<?= e((string) ($stepIndex + 1)) ?>" data-i18n-total="<?= e((string) $totalSteps) ?>"><?= e(__('setup.step_of', ['current' => (string) ($stepIndex + 1), 'total' => (string) $totalSteps])) ?></p>
            <?php
            $stepKey = (string) ($step['key'] ?? '');
            $websiteTitleName = trim((string) ($assocName ?? ''));
            $showWebsiteLockup = $stepKey === 'association.website'
                && $websiteTitleName !== ''
                && strcasecmp($websiteTitleName, 'SOCLY') !== 0;
            $showLogoLockup = $stepKey === 'branding.logo'
                && $websiteTitleName !== ''
                && strcasecmp($websiteTitleName, 'SOCLY') !== 0;
            $showComponentsLockup = $stepKey === 'components.select'
                && $websiteTitleName !== ''
                && strcasecmp($websiteTitleName, 'SOCLY') !== 0;
            $showColorsLockup = $stepKey === 'branding.colors'
                && $websiteTitleName !== ''
                && strcasecmp($websiteTitleName, 'SOCLY') !== 0;
            ?>
            <?php if ($showWebsiteLockup): ?>
                <h1 class="h1 h1-brand setup-title setup-title-lockup" data-setup-line data-setup-fit-title>
                    <span class="setup-title-prefix"><?= e(__('setup.step_website_of')) ?></span>
                    <?= assoc_lockup_html([
                        'name' => $websiteTitleName,
                        'legal_name' => (string) ($assocLegal ?? ''),
                        'class' => 'assoc-lockup-setup-title',
                    ]) ?>
                </h1>
            <?php elseif ($showLogoLockup): ?>
                <h1 class="h1 h1-brand setup-title setup-title-lockup" data-setup-line data-setup-fit-title>
                    <span class="setup-title-prefix"><?= e(__('setup.step_logo_of')) ?></span>
                    <?= assoc_lockup_html([
                        'name' => $websiteTitleName,
                        'legal_name' => (string) ($assocLegal ?? ''),
                        'class' => 'assoc-lockup-setup-title',
                    ]) ?>
                </h1>
            <?php elseif ($showComponentsLockup): ?>
                <h1 class="h1 h1-brand setup-title setup-title-lockup" data-setup-line data-setup-fit-title>
                    <span class="setup-title-prefix"><?= e(__('setup.step_components_of')) ?></span>
                    <?= assoc_lockup_html([
                        'name' => $websiteTitleName,
                        'legal_name' => (string) ($assocLegal ?? ''),
                        'class' => 'assoc-lockup-setup-title',
                    ]) ?>
                </h1>
            <?php elseif ($showColorsLockup): ?>
                <h1 class="h1 h1-brand setup-title setup-title-lockup" data-setup-line data-setup-fit-title>
                    <span class="setup-title-prefix"><?= e(__('setup.step_colors_of')) ?></span>
                    <?= assoc_lockup_html([
                        'name' => $websiteTitleName,
                        'legal_name' => (string) ($assocLegal ?? ''),
                        'class' => 'assoc-lockup-setup-title',
                    ]) ?>
                </h1>
            <?php else: ?>
                <h1 class="h1 h1-brand setup-title" data-setup-line data-i18n="<?= e((string) ($step['title_key'] ?? '')) ?>"><?= e(__($step['title_key'])) ?></h1>
            <?php endif; ?>
            <?php
            $stepDesc = trim((string) __($step['description_key']));
            if ($stepDesc !== '' && $stepDesc !== ($step['description_key'] ?? '')):
            ?>
                <p class="setup-copy" data-setup-line data-i18n="<?= e((string) ($step['description_key'] ?? '')) ?>"><?= e($stepDesc) ?></p>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-error" data-setup-line>
                    <?php foreach ((array) $errors as $err): ?>
                        <div><?= e(is_array($err) ? implode(', ', $err) : (string) $err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="<?= e(url('/setup')) ?>"
                class="setup-form"
                data-setup-form
                data-leave-guard
                data-cities-url="<?= e(url('/api/geo/cities')) ?>"
                data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
                data-cap-url="<?= e(url('/api/geo/cap')) ?>"
                data-provinces-url="<?= e(url('/api/geo/provinces')) ?>"
                data-cf-url="<?= e(url('/api/fiscal-code')) ?>"
                data-csrf="<?= e(csrf_token()) ?>"
            >
                <?= csrf_field() ?>
                <input type="hidden" name="step_index" value="<?= (int) $stepIndex ?>">
                <input type="hidden" name="step_key" value="<?= e((string) ($step['key'] ?? '')) ?>">
                <input type="hidden" name="setup_exit" value="0" data-setup-exit-flag>
                <input type="hidden" name="setup_defer" value="0" data-setup-defer-flag>

                <?php if ($stepType === 'colors'): ?>
                    <?php
                    $palettes = is_array($value['palettes'] ?? null) ? $value['palettes'] : [];
                    unset($value['palettes'], $value['logo_url']);
                    ?>
                    <div class="setup-brand-preview" data-setup-line data-brand-preview
                         style="--brand-primary: <?= e((string) ($value['primary'] ?? '#0D6E66')) ?>; --brand-accent: <?= e((string) ($value['accent'] ?? '#B84A1B')) ?>;">
                        <span class="setup-brand-preview-primary"></span>
                        <span class="setup-brand-preview-accent"></span>
                    </div>
                    <?php if ($palettes !== []): ?>
                        <div class="setup-palette-grid" data-setup-line data-palette-grid>
                            <p class="setup-subhead"><?= e(__('setup.palette_choose')) ?></p>
                            <?php foreach ($palettes as $palette): ?>
                                <?php
                                $pPrimary = strtoupper((string) ($palette['primary'] ?? ''));
                                $pAccent = strtoupper((string) ($palette['accent'] ?? ''));
                                $isSelected = $pPrimary === strtoupper((string) ($value['primary'] ?? ''))
                                    && $pAccent === strtoupper((string) ($value['accent'] ?? ''));
                                ?>
                                <button type="button" class="setup-palette-card<?= $isSelected ? ' is-selected' : '' ?>" data-palette-pick
                                        data-primary="<?= e((string) ($palette['primary'] ?? '')) ?>"
                                        data-accent="<?= e((string) ($palette['accent'] ?? '')) ?>">
                                    <span class="setup-palette-swatch" style="--swatch-a: <?= e((string) ($palette['primary'] ?? '#0D6E66')) ?>; --swatch-b: <?= e((string) ($palette['accent'] ?? '#B84A1B')) ?>;"></span>
                                    <span class="setup-palette-name"><?= e((string) ($palette['name'] ?? __('setup.palette_custom'))) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="setup-colors setup-color-picker-grid" data-setup-line>
                        <?php foreach ($step['fields'] as $field): ?>
                            <label class="setup-field setup-color-picker-card">
                                <span><?= e(__($field['label_key'])) ?></span>
                                <span class="setup-color-picker-control">
                                    <input type="color" name="<?= e($field['key']) ?>" value="<?= e((string) ($value[$field['key']] ?? '#0D6E66')) ?>" required data-brand-color="<?= e($field['key']) ?>">
                                    <code><?= e(strtoupper((string) ($value[$field['key']] ?? '#0D6E66'))) ?></code>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($stepType === 'logo'): ?>
                    <?php
                    $logoUrl = is_array($value) ? (string) ($value['logo_url'] ?? '') : '';
                    $hasLogo = $logoUrl !== '';
                    ?>
                    <div class="setup-logo" data-setup-line data-setup-logo
                         data-logo-upload-url="<?= e(url('/setup/logo')) ?>"
                         data-csrf="<?= e(csrf_token()) ?>"
                         data-msg-fail="<?= e(__('setup.scrape_logo_fail')) ?>"
                         data-msg-uploading="<?= e(__('setup.logo_uploading')) ?>">
                        <div class="setup-logo-preview" data-setup-logo-preview<?= $hasLogo ? '' : ' hidden' ?>>
                            <img
                                <?php if ($hasLogo): ?>
                                    src="<?= e($logoUrl) ?>?v=<?= e((string) time()) ?>"
                                <?php endif; ?>
                                alt="<?= e(__('setup.field_logo')) ?>"
                                data-setup-logo-img<?= $hasLogo ? '' : ' hidden' ?>
                            >
                        </div>
                        <div class="setup-logo-actions">
                            <label class="btn btn-ghost file-btn">
                                <span class="field-icon" data-icon="upload" aria-hidden="true"></span>
                                <?= e(__('setup.logo_upload')) ?>
                                <input type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" data-setup-logo-input hidden>
                            </label>
                            <button type="button" class="btn btn-ghost setup-logo-remove" data-setup-logo-remove<?= $hasLogo ? '' : ' hidden' ?>>
                                <?= e(__('setup.logo_remove')) ?>
                            </button>
                        </div>
                        <div class="setup-logo-upload-progress" data-setup-logo-progress hidden>
                            <span class="setup-logo-upload-progress-bar" data-setup-logo-progress-bar style="--progress: 0%"></span>
                        </div>
                        <p class="setup-logo-status muted" data-setup-logo-status hidden></p>
                        <p class="setup-hint muted"><?= e(__('setup.logo_hint')) ?></p>
                    </div>
                <?php elseif ($stepType === 'name_pair'): ?>
                    <div class="setup-name-pair" data-setup-line data-setup-name-pair
                         data-preview-template="<?= e(__('setup.full_name_preview')) ?>">
                        <?php foreach ($step['fields'] as $field): ?>
                            <?php $fieldKey = (string) ($field['key'] ?? ''); ?>
                            <?php if ($fieldKey === 'runts'): ?>
                                <div class="setup-runts-block" data-setup-runts
                                     data-runts-url="<?= e(url('/setup/runts-lookup')) ?>"
                                     data-csrf="<?= e(csrf_token()) ?>"
                                     data-label-template="<?= e(__('setup.runts_button')) ?>"
                                     data-msg-need="<?= e(__('setup.runts_need_number')) ?>"
                                     data-msg-loading="<?= e(__('setup.runts_loading')) ?>"
                                     data-msg-ok="<?= e(__('setup.runts_ok')) ?>"
                                     data-msg-fail="<?= e(__('setup.runts_fail')) ?>"
                                     data-msg-not-found="<?= e(__('setup.runts_not_found')) ?>"
                                     data-msg-timeout="<?= e(__('setup.runts_timeout')) ?>"
                                     data-msg-elapsed="<?= e(__('setup.runts_elapsed')) ?>"
                                     data-msg-phase-connect="<?= e(__('setup.runts_phase_connect')) ?>"
                                     data-msg-phase-download-active="<?= e(__('setup.runts_phase_download_active')) ?>"
                                     data-msg-phase-download-cancelled="<?= e(__('setup.runts_phase_download_cancelled')) ?>"
                                     data-msg-phase-search-active="<?= e(__('setup.runts_phase_search_active')) ?>"
                                     data-msg-phase-search-cancelled="<?= e(__('setup.runts_phase_search_cancelled')) ?>"
                                     data-msg-phase-detail="<?= e(__('setup.runts_phase_detail')) ?>"
                                     data-msg-phase-docs="<?= e(__('setup.runts_phase_docs')) ?>"
                                     data-msg-phase-docs-save="<?= e(__('setup.runts_phase_docs_save')) ?>"
                                     data-msg-phase-docs-ocr="<?= e(__('setup.runts_phase_docs_ocr')) ?>"
                                     data-msg-phase-apply="<?= e(__('setup.runts_phase_apply')) ?>"
                                     data-msg-docs-saved="<?= e(__('setup.runts_docs_saved')) ?>"
                                     data-msg-docs-none="<?= e(__('setup.runts_docs_none')) ?>"
                                     data-msg-doc-view="<?= e(__('setup.runts_doc_view')) ?>"
                                     data-msg-fields-heading="<?= e(__('setup.runts_fields_heading')) ?>"
                                     data-msg-people-heading="<?= e(__('setup.runts_people_heading')) ?>"
                                     data-msg-field-fiscal-code="<?= e(__('setup.runts_field_fiscal_code')) ?>"
                                     data-msg-field-pec="<?= e(__('setup.runts_field_pec')) ?>"
                                     data-msg-field-website="<?= e(__('setup.runts_field_website')) ?>"
                                     data-msg-field-address="<?= e(__('setup.runts_field_address')) ?>"
                                     data-msg-field-city="<?= e(__('setup.runts_field_city')) ?>"
                                     data-msg-field-province="<?= e(__('setup.runts_field_province')) ?>"
                                     data-msg-field-postal-code="<?= e(__('setup.runts_field_postal_code')) ?>"
                                     data-msg-field-president-name="<?= e(__('setup.runts_field_president_name')) ?>"
                                     data-msg-field-section="<?= e(__('setup.runts_field_section')) ?>"
                                     data-msg-field-forma-giuridica="<?= e(__('setup.runts_field_forma_giuridica')) ?>"
                                     data-msg-field-founded-on="<?= e(__('setup.runts_field_founded_on')) ?>"
                                     data-msg-field-statute-updated-on="<?= e(__('setup.runts_field_statute_updated_on')) ?>"
                                     data-msg-field-members-count="<?= e(__('setup.runts_field_members_count')) ?>"
                                     data-msg-field-volunteers-count="<?= e(__('setup.runts_field_volunteers_count')) ?>"
                                     data-msg-field-activities="<?= e(__('setup.runts_field_activities')) ?>"
                                     data-msg-doc-close="<?= e(__('common.close')) ?>"
                                     data-doc-view-base="<?= e(url('/setup/runts-document')) ?>"
                                     data-msg-legal-prefilled="<?= e(__('setup.runts_legal_prefilled')) ?>"
                                     data-msg-legal-statute="<?= e(__('setup.runts_legal_statute')) ?>"
                                     data-msg-legal-privacy="<?= e(__('setup.runts_legal_privacy')) ?>"
                                     data-msg-legal-ocr-pending="<?= e(__('setup.runts_legal_ocr_pending')) ?>"
                                     data-msg-legal-ocr-ok="<?= e(__('setup.runts_legal_ocr_ok')) ?>"
                                     data-msg-legal-ocr-fail="<?= e(__('setup.runts_legal_ocr_fail')) ?>"
                                     data-msg-legal-ocr-unavailable="<?= e(__('setup.runts_legal_ocr_unavailable')) ?>"
                                     data-legal-status-url="<?= e(url('/setup/runts-legal-prefill-status')) ?>"
                                     data-msg-limit-wait="<?= e(__('setup.runts_limit_wait')) ?>"
                                     data-msg-limit-exhausted="<?= e(__('setup.runts_limit_exhausted')) ?>"
                                >
                                    <label class="setup-field setup-field-runts" for="setup-runts-number">
                                        <span><?= e(__($field['label_key'])) ?></span>
                                    </label>
                                    <div class="setup-runts-row">
                                        <p class="setup-runts-hint" data-setup-runts-hint><?= e(__('setup.runts_hint')) ?></p>
                                        <input
                                            id="setup-runts-number"
                                            type="text"
                                            name="runts"
                                            value="<?= e((string) ($value['runts'] ?? '')) ?>"
                                            inputmode="numeric"
                                            autocomplete="off"
                                            spellcheck="false"
                                            maxlength="6"
                                            placeholder="<?= e(__('setup.field_runts_placeholder')) ?>"
                                            data-setup-runts-input
                                        >
                                    </div>
                                    <div class="setup-runts-actions">
                                        <button type="button" class="btn setup-scrape-btn" data-setup-runts-btn hidden disabled aria-hidden="true">
                                            <span data-setup-runts-label><?= e(__('setup.runts_button_fallback')) ?></span>
                                        </button>
                                    </div>
                                    <div class="setup-runts-live" data-setup-runts-live hidden>
                                        <div class="setup-scrape-spinner" aria-hidden="true"></div>
                                        <div class="setup-runts-live-copy">
                                            <p class="setup-runts-status muted" data-setup-runts-status></p>
                                            <div class="setup-runts-progress" data-setup-runts-progress hidden>
                                                <div class="setup-runts-progress-bar" data-setup-runts-progress-bar></div>
                                            </div>
                                            <p class="setup-runts-elapsed muted" data-setup-runts-elapsed hidden></p>
                                        </div>
                                    </div>
                                </div>
                                <?php continue; ?>
                            <?php endif; ?>
                            <label class="setup-field<?= $fieldKey === 'legal_name' ? ' setup-field-legal' : ($fieldKey === 'currency' ? ' setup-field-currency' : ' setup-field-name') ?>">
                                <span><?= e(__($field['label_key'])) ?></span>
                                <?php if (($field['type'] ?? '') === 'select'): ?>
                                    <?php $isCurrency = $fieldKey === 'currency'; ?>
                                    <select name="<?= e($fieldKey) ?>" required maxlength="6" <?= $isCurrency ? '' : 'data-setup-legal-name' ?>>
                                        <?php if (!$isCurrency): ?>
                                            <option value="" disabled <?= (($value[$fieldKey] ?? '') === '') ? 'selected' : '' ?>><?= e(__('setup.legal_form_placeholder')) ?></option>
                                        <?php endif; ?>
                                        <?php foreach ($field['options'] as $opt): ?>
                                            <option
                                                value="<?= e($opt['value']) ?>"
                                                <?= (string) ($value[$fieldKey] ?? '') === $opt['value'] ? 'selected' : '' ?>
                                                <?php if (!$isCurrency): ?>data-meaning="<?= e(__($opt['label_key'])) ?>"<?php endif; ?>
                                            >
                                                <?= e($opt['value']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input
                                        type="text"
                                        name="<?= e($fieldKey) ?>"
                                        value="<?= e((string) ($value[$fieldKey] ?? '')) ?>"
                                        <?= !empty($field['required']) ? 'required' : '' ?>
                                        autocomplete="organization"
                                        data-setup-assoc-name
                                    >
                                    <?php if ($fieldKey === 'name'): ?>
                                        <p class="setup-legal-meaning muted" data-setup-legal-meaning hidden></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="setup-hint setup-full-name-preview muted" data-setup-full-name-preview hidden></p>
                    </div>
                <?php elseif ($stepType === 'field_group'): ?>
                    <div
                        class="setup-field-group"
                        data-setup-line
                        <?php if (($step['key'] ?? '') === 'association.tax_ids'): ?>
                            data-setup-tax-ids
                            data-msg-fiscal-invalid="<?= e(__('setup.tax_fiscal_invalid')) ?>"
                            data-msg-vat-invalid="<?= e(__('setup.tax_vat_invalid')) ?>"
                            data-msg-vat-matches="<?= e(__('setup.tax_vat_matches_fiscal')) ?>"
                        <?php elseif (($step['key'] ?? '') === 'association.contacts'): ?>
                            data-setup-contacts
                            data-msg-pec-invalid="<?= e(__('setup.contacts_pec_invalid')) ?>"
                            data-msg-email-invalid="<?= e(__('setup.contacts_email_invalid')) ?>"
                            data-msg-phone-invalid="<?= e(__('setup.contacts_phone_invalid')) ?>"
                        <?php endif; ?>
                    >
                        <?php foreach ($step['fields'] as $field): ?>
                            <?php $fieldType = (string) ($field['type'] ?? 'text'); ?>
                            <?php if ($fieldType === 'tel'): ?>
                                <div class="setup-field">
                                    <span><?= e(__($field['label_key'])) ?><?= !empty($field['required']) ? ' *' : '' ?></span>
                                    <?= view_partial('partials/phone_field', [
                                        'name' => (string) ($field['key'] ?? 'phone'),
                                        'value' => (string) ($value[$field['key']] ?? ''),
                                        'required' => !empty($field['required']),
                                    ]) ?>
                                </div>
                            <?php else: ?>
                            <label class="setup-field">
                                <span><?= e(__($field['label_key'])) ?><?= !empty($field['required']) ? ' *' : '' ?></span>
                                <input
                                    type="<?= e($fieldType === 'email' ? 'email' : 'text') ?>"
                                    name="<?= e($field['key']) ?>"
                                    value="<?= e((string) ($value[$field['key']] ?? '')) ?>"
                                    <?= !empty($field['required']) ? 'required' : '' ?>
                                    autocomplete="<?= e((string) ($field['autocomplete'] ?? 'off')) ?>"
                                    <?php if (($field['key'] ?? '') === 'fiscal_code'): ?>
                                        maxlength="16"
                                        inputmode="text"
                                        spellcheck="false"
                                        data-setup-fiscal-code
                                        placeholder="<?= e(__('setup.field_fiscal_code_placeholder')) ?>"
                                    <?php elseif (($field['key'] ?? '') === 'vat_number'): ?>
                                        maxlength="13"
                                        inputmode="numeric"
                                        spellcheck="false"
                                        data-setup-vat-number
                                        placeholder="<?= e(__('setup.field_vat_placeholder')) ?>"
                                    <?php elseif (($field['key'] ?? '') === 'pec'): ?>
                                        inputmode="email"
                                        spellcheck="false"
                                        data-setup-pec
                                        placeholder="<?= e(__('setup.field_pec_placeholder')) ?>"
                                    <?php elseif (($field['key'] ?? '') === 'email'): ?>
                                        inputmode="email"
                                        spellcheck="false"
                                        data-setup-email
                                        placeholder="<?= e(__('setup.field_email_placeholder')) ?>"
                                    <?php endif; ?>
                                >
                                <?php if (($field['key'] ?? '') === 'fiscal_code'): ?>
                                    <p class="setup-hint muted" data-setup-fiscal-hint hidden></p>
                                <?php elseif (($field['key'] ?? '') === 'vat_number'): ?>
                                    <p class="setup-hint muted" data-setup-vat-hint hidden></p>
                                <?php endif; ?>
                            </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($stepType === 'address_block'): ?>
                    <?= view_partial('partials/geo_address', [
                        'names' => [
                            'city' => 'city',
                            'postal_code' => 'postal_code',
                            'province' => 'province',
                            'address' => 'address',
                            'house_number' => 'house_number',
                        ],
                        'values' => [
                            'city' => (string) ($value['city'] ?? ''),
                            'postal_code' => (string) ($value['postal_code'] ?? ''),
                            'province' => (string) ($value['province'] ?? ''),
                            'address' => (string) ($value['address'] ?? ''),
                            'house_number' => (string) ($value['house_number'] ?? ''),
                        ],
                        'required' => [
                            'city' => true,
                            'postal_code' => true,
                            'province' => false,
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
                <?php elseif ($stepType === 'president'): ?>
                    <div class="setup-president" data-setup-line data-geo-scope>
                        <div class="setup-equal-row">
                            <label class="setup-field">
                                <span><?= e(__('setup.field_first_name')) ?> *</span>
                                <input type="text" name="first_name" value="<?= e((string) ($value['first_name'] ?? '')) ?>" required autocomplete="given-name" data-first-name>
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_last_name')) ?> *</span>
                                <input type="text" name="last_name" value="<?= e((string) ($value['last_name'] ?? '')) ?>" required autocomplete="family-name" data-last-name>
                            </label>
                        </div>
                        <div class="setup-equal-row">
                            <label class="setup-field">
                                <span><?= e(__('setup.field_birth_date')) ?> *</span>
                                <input type="date" name="birth_date" value="<?= e((string) ($value['birth_date'] ?? '')) ?>" required data-birth-date>
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_gender')) ?> *</span>
                                <select name="gender" required data-gender-input>
                                    <option value="">—</option>
                                    <option value="M" <?= (string) ($value['gender'] ?? '') === 'M' ? 'selected' : '' ?>><?= e(__('members.gender_m')) ?></option>
                                    <option value="F" <?= (string) ($value['gender'] ?? '') === 'F' ? 'selected' : '' ?>><?= e(__('members.gender_f')) ?></option>
                                </select>
                            </label>
                        </div>
                        <?= view_partial('partials/geo_birth_place', [
                            'name' => 'birth_place',
                            'value' => (string) ($value['birth_place'] ?? ''),
                            'required' => true,
                        ]) ?>
                        <label class="setup-field">
                            <span><?= e(__('setup.field_person_fiscal_code')) ?> *</span>
                            <div class="cf-row">
                                <input type="text" name="fiscal_code" value="<?= e((string) ($value['fiscal_code'] ?? '')) ?>" required maxlength="16" pattern="[A-Za-z0-9]{16}" autocomplete="off" data-fiscal-code placeholder="<?= e(__('members.cf_hint')) ?>">
                                <button type="button" class="btn btn-ghost" data-cf-generate><?= e(__('members.cf_generate')) ?></button>
                            </div>
                            <p class="setup-hint muted" data-cf-status
                               data-incomplete="<?= e(__('members.cf_incomplete')) ?>"
                               data-ready="<?= e(__('members.cf_ready')) ?>"
                               data-gender-other="<?= e(__('members.cf_gender_other')) ?>"
                               hidden></p>
                        </label>
                        <p class="setup-subhead"><?= e(__('setup.field_residence')) ?></p>
                        <?= view_partial('partials/geo_address', [
                            'names' => [
                                'city' => 'city',
                                'postal_code' => 'postal_code',
                                'address' => 'address',
                                'house_number' => 'house_number',
                            ],
                            'values' => [
                                'city' => (string) ($value['city'] ?? ''),
                                'postal_code' => (string) ($value['postal_code'] ?? ''),
                                'address' => (string) ($value['address'] ?? ''),
                                'house_number' => (string) ($value['house_number'] ?? ''),
                            ],
                            'required' => [
                                'city' => true,
                                'postal_code' => true,
                                'address' => true,
                                'house_number' => true,
                            ],
                            'with_scope' => true,
                        ]) ?>
                        <div class="setup-equal-row">
                            <label class="setup-field">
                                <span><?= e(__('setup.field_appointed_at')) ?> *</span>
                                <input type="date" name="appointed_at" value="<?= e((string) ($value['appointed_at'] ?? '')) ?>" required data-appointed-date max="<?= e(date('Y-m-d')) ?>">
                            </label>
                            <label class="setup-field">
                                <span><?= e(__('setup.field_mandate_ends_at')) ?> *</span>
                                <input type="date" name="mandate_ends_at" value="<?= e((string) ($value['mandate_ends_at'] ?? '')) ?>" required data-mandate-ends-date min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>">
                            </label>
                        </div>
                    </div>
                <?php elseif ($stepType === 'people_list'): ?>
                    <?php $peopleRole = (string) ($step['role'] ?? ''); ?>
                    <?php $showOrganType = $peopleRole === 'auditor'; ?>
                    <?php
                    $organTypes = [
                        'revisore_conti' => 'setup.organ_revisore_conti',
                        'collegio_revisori' => 'setup.organ_collegio_revisori',
                        'sindaco_unico' => 'setup.organ_sindaco_unico',
                        'collegio_sindacale' => 'setup.organ_collegio_sindacale',
                        'odv' => 'setup.organ_odv',
                        'altro' => 'setup.organ_altro',
                    ];
                    ?>
                    <div class="setup-people" data-setup-line data-people-list
                         data-msg-cf-mismatch="<?= e(__('setup.cf_name_mismatch')) ?>"
                         data-msg-cf-underage="<?= e(__('setup.cf_underage')) ?>"
                         data-msg-cf-continue="<?= e(__('setup.cf_continue_anyway')) ?>"
                         data-msg-cf-fix="<?= e(__('setup.cf_fix')) ?>">
                        <div class="setup-people-rows" data-people-rows>
                            <?php foreach ((array) $value as $i => $person): ?>
                                <div class="setup-people-row" data-people-row>
                                    <?php if ($showOrganType): ?>
                                    <label class="setup-field setup-field-organ-type">
                                        <span><?= e(__('setup.field_organ_type')) ?></span>
                                        <select name="people[<?= (int) $i ?>][organ_type]">
                                            <option value="">—</option>
                                            <?php foreach ($organTypes as $organKey => $organLabelKey): ?>
                                                <option value="<?= e($organKey) ?>" <?= (string) ($person['organ_type'] ?? $person['notes'] ?? '') === $organKey ? 'selected' : '' ?>>
                                                    <?= e(__($organLabelKey)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <?php endif; ?>
                                    <label class="setup-field">
                                        <span><?= e(__('setup.field_first_name')) ?></span>
                                        <input type="text" name="people[<?= (int) $i ?>][first_name]" value="<?= e((string) ($person['first_name'] ?? '')) ?>" autocomplete="given-name">
                                    </label>
                                    <label class="setup-field">
                                        <span><?= e(__('setup.field_last_name')) ?></span>
                                        <input type="text" name="people[<?= (int) $i ?>][last_name]" value="<?= e((string) ($person['last_name'] ?? '')) ?>" autocomplete="family-name">
                                    </label>
                                    <label class="setup-field">
                                        <span><?= e(__('setup.field_person_fiscal_code')) ?></span>
                                        <input type="text" name="people[<?= (int) $i ?>][fiscal_code]" value="<?= e((string) ($person['fiscal_code'] ?? '')) ?>" maxlength="16" autocomplete="off">
                                    </label>
                                    <button type="button" class="btn btn-ghost btn-sm" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-ghost" data-people-add data-i18n="setup.add_person"><?= e(__('setup.add_person')) ?></button>
                        <template data-people-template>
                            <div class="setup-people-row" data-people-row>
                                <?php if ($showOrganType): ?>
                                <label class="setup-field setup-field-organ-type">
                                    <span><?= e(__('setup.field_organ_type')) ?></span>
                                    <select name="people[__i__][organ_type]">
                                        <option value="">—</option>
                                        <?php foreach ($organTypes as $organKey => $organLabelKey): ?>
                                            <option value="<?= e($organKey) ?>"><?= e(__($organLabelKey)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <?php endif; ?>
                                <label class="setup-field">
                                    <span><?= e(__('setup.field_first_name')) ?></span>
                                    <input type="text" name="people[__i__][first_name]" value="" autocomplete="given-name">
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('setup.field_last_name')) ?></span>
                                    <input type="text" name="people[__i__][last_name]" value="" autocomplete="family-name">
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('setup.field_person_fiscal_code')) ?></span>
                                    <input type="text" name="people[__i__][fiscal_code]" value="" maxlength="16" autocomplete="off">
                                </label>
                                <button type="button" class="btn btn-ghost btn-sm" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                            </div>
                        </template>
                    </div>
                <?php elseif ($stepType === 'website'): ?>
                    <?php
                    $siteName = trim((string) ($assocName ?? ''));
                    $siteLegal = trim((string) ($assocLegal ?? ''));
                    ?>
                    <label class="setup-field" data-setup-line>
                        <span><?= e(__('setup.field_website')) ?></span>
                        <input
                            type="text"
                            name="value"
                            value="<?= e((string) $value) ?>"
                            placeholder="<?= e(__('setup.website_placeholder')) ?>"
                            autocomplete="url"
                            inputmode="url"
                            spellcheck="false"
                            data-setup-website-input
                        >
                    </label>
                    <div class="setup-scrape" data-setup-line data-setup-scrape hidden
                         data-scrape-url="<?= e(url('/setup/scrape')) ?>"
                         data-logo-upload-url="<?= e(url('/setup/logo')) ?>"
                         data-csrf="<?= e(csrf_token()) ?>"
                         data-assoc-name="<?= e($siteName) ?>"
                         data-assoc-legal="<?= e($siteLegal) ?>"
                         data-label-template="<?= e(__('setup.scrape_button')) ?>"
                         data-msg-need-url="<?= e(__('setup.scrape_need_url')) ?>"
                         data-msg-loading="<?= e(__('setup.scrape_loading')) ?>"
                         data-msg-empty="<?= e(__('setup.scrape_empty')) ?>"
                         data-msg-ok="<?= e(__('setup.scrape_ok')) ?>"
                         data-msg-fail="<?= e(__('setup.scrape_fail')) ?>"
                         data-msg-phase-connect="<?= e(__('setup.scrape_phase_connect')) ?>"
                         data-msg-phase-fetch="<?= e(__('setup.scrape_phase_fetch')) ?>"
                         data-msg-phase-pages="<?= e(__('setup.scrape_phase_pages')) ?>"
                         data-msg-phase-extract="<?= e(__('setup.scrape_phase_extract')) ?>"
                         data-msg-phase-apply="<?= e(__('setup.scrape_phase_apply')) ?>"
                         data-msg-found-title="<?= e(__('setup.scrape_found_title')) ?>"
                         data-msg-elapsed="<?= e(__('setup.scrape_elapsed')) ?>"
                         data-msg-logo-fail="<?= e(__('setup.scrape_logo_fail')) ?>"
                         data-msg-logo-guess="<?= e(__('setup.scrape_logo_guess_title')) ?>"
                         data-msg-logo-guess-hint="<?= e(__('setup.scrape_logo_guess_hint')) ?>"
                         data-msg-logo-none="<?= e(__('setup.scrape_logo_guess_none')) ?>"
                         data-msg-ask="<?= e(__('setup.scrape_ask')) ?>"
                         data-msg-ask-yes="<?= e(__('setup.scrape_ask_yes')) ?>"
                         data-msg-ask-no="<?= e(__('setup.scrape_ask_no')) ?>"
                    >
                        <button type="button" class="btn setup-scrape-btn" data-setup-scrape-btn hidden disabled aria-hidden="true">
                            <span data-setup-scrape-label><?= e(__('setup.scrape_button_fallback')) ?></span>
                        </button>
                        <div class="setup-scrape-live" data-setup-scrape-live hidden>
                            <div class="setup-scrape-spinner" aria-hidden="true"></div>
                            <div class="setup-scrape-live-copy">
                                <p class="setup-scrape-status" data-setup-scrape-status></p>
                                <p class="setup-scrape-elapsed muted" data-setup-scrape-elapsed></p>
                                <button type="button" class="btn btn-ghost setup-scrape-retry" data-setup-scrape-retry hidden>
                                    <?= e(__('setup.scrape_retry')) ?>
                                </button>
                            </div>
                        </div>
                        <div class="setup-scrape-results" data-setup-scrape-results hidden>
                            <div class="setup-scrape-results-head">
                                <p class="setup-scrape-results-title" data-setup-scrape-results-title></p>
                                <p class="setup-scrape-results-hint muted"><?= e(__('setup.scrape_found_hint')) ?></p>
                                <p class="setup-scrape-results-status muted" data-setup-scrape-results-status hidden></p>
                            </div>
                            <div class="setup-scrape-brand" data-setup-scrape-brand hidden>
                                <div class="setup-scrape-logo-picks" data-setup-scrape-logo-picks hidden>
                                    <span class="setup-scrape-brand-label"><?= e(__('setup.scrape_logo_guess_title')) ?></span>
                                    <p class="setup-hint muted setup-scrape-logo-hint"><?= e(__('setup.scrape_logo_guess_hint')) ?></p>
                                    <div class="setup-scrape-logo-pick-grid" data-setup-scrape-logo-pick-grid></div>
                                    <button type="button" class="btn btn-ghost btn-sm" data-setup-scrape-logo-none>
                                        <?= e(__('setup.scrape_logo_guess_none')) ?>
                                    </button>
                                </div>
                                <div class="setup-scrape-logo-card" data-setup-scrape-logo hidden>
                                    <span class="setup-scrape-brand-label"><?= e(__('setup.field_logo')) ?></span>
                                    <button type="button" class="setup-scrape-logo-frame is-replaceable" data-setup-scrape-logo-pick
                                            title="<?= e(__('setup.scrape_logo_change')) ?>"
                                            aria-label="<?= e(__('setup.scrape_logo_change')) ?>">
                                        <img src="" alt="<?= e(__('setup.field_logo')) ?>" data-setup-scrape-logo-img>
                                        <span class="setup-scrape-logo-overlay"><?= e(__('setup.scrape_logo_change')) ?></span>
                                    </button>
                                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
                                           class="setup-scrape-logo-file" data-setup-scrape-logo-input>
                                    <p class="setup-hint muted setup-scrape-logo-hint"><?= e(__('setup.scrape_logo_change_hint')) ?></p>
                                </div>
                            </div>
                            <div class="setup-scrape-groups" data-setup-scrape-groups>
                                <section class="setup-scrape-group" data-scrape-group="contact" hidden>
                                    <h3 class="setup-scrape-group-title"><?= e(__('setup.scrape_group_contact')) ?></h3>
                                    <ul class="setup-scrape-found" data-scrape-group-list="contact"></ul>
                                </section>
                                <section class="setup-scrape-group" data-scrape-group="seat" hidden>
                                    <h3 class="setup-scrape-group-title"><?= e(__('setup.scrape_group_seat')) ?></h3>
                                    <ul class="setup-scrape-found" data-scrape-group-list="seat" data-seat-label="<?= e(__('setup.field_street')) ?>"></ul>
                                </section>
                                <section class="setup-scrape-group" data-scrape-group="ids" hidden>
                                    <h3 class="setup-scrape-group-title"><?= e(__('setup.scrape_group_ids')) ?></h3>
                                    <ul class="setup-scrape-found" data-scrape-group-list="ids"></ul>
                                </section>
                                <section class="setup-scrape-group" data-scrape-group="people" hidden>
                                    <h3 class="setup-scrape-group-title"><?= e(__('setup.scrape_group_people')) ?></h3>
                                    <ul class="setup-scrape-found" data-scrape-group-list="people"></ul>
                                </section>
                                <section class="setup-scrape-group" data-scrape-group="other" hidden>
                                    <h3 class="setup-scrape-group-title"><?= e(__('setup.scrape_group_other')) ?></h3>
                                    <ul class="setup-scrape-found" data-scrape-group-list="other"></ul>
                                </section>
                            </div>
                        </div>
                    </div>
                <?php elseif ($stepType === 'select'): ?>
                    <?php
                        $mailReadyForSelect = true;
                        try {
                            $mailReadyForSelect = app(\Socly\Services\MailService::class)->isReady();
                        } catch (\Throwable) {
                            $mailReadyForSelect = false;
                        }
                        $isLocaleStep = ($step['key'] ?? '') === 'app.locale';
                    ?>
                    <?php if ($isLocaleStep): ?>
                    <fieldset class="setup-locale-grid" data-setup-line data-setup-locale-picker>
                        <legend class="setup-field-label" data-i18n="<?= e((string) ($step['title_key'] ?? '')) ?>"><?= e(__($step['title_key'])) ?></legend>
                        <?php foreach ($step['options'] as $opt): ?>
                            <?php $optVal = (string) ($opt['value'] ?? ''); ?>
                            <label class="setup-locale-card">
                                <input type="radio" name="value" value="<?= e($optVal) ?>" data-setup-locale-radio <?= (string) $value === $optVal ? 'checked' : '' ?> required>
                                <img src="<?= e(locale_flag_url($optVal)) ?>" width="28" height="21" alt="" loading="lazy" decoding="async">
                                <span data-i18n="<?= e((string) ($opt['label_key'] ?? '')) ?>"><?= e(__($opt['label_key'])) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php elseif (($step['key'] ?? '') === 'membership.enrollment_validation'): ?>
                    <div class="setup-enrollment" data-setup-enrollment data-setup-line>
                        <label class="setup-field">
                            <span><?= e(__($step['title_key'])) ?></span>
                            <select name="value" required data-enrollment-select>
                                <?php foreach ($step['options'] as $opt): ?>
                                    <?php
                                        $optVal = (string) ($opt['value'] ?? '');
                                        $otpBlocked = $optVal === 'otp_email' && !$mailReadyForSelect;
                                    ?>
                                    <option value="<?= e($optVal) ?>" <?= (string) $value === $optVal ? 'selected' : '' ?> <?= $otpBlocked ? 'disabled' : '' ?>><?= e(__($opt['label_key'])) ?><?= $otpBlocked ? ' — ' . e(__('setup.mail_required_short')) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php foreach ($step['options'] as $opt): ?>
                            <?php $optVal = (string) ($opt['value'] ?? ''); ?>
                            <template data-enrollment-detail-for="<?= e($optVal) ?>"><?= e(__('setup.enrollment_detail_' . $optVal)) ?></template>
                        <?php endforeach; ?>
                        <p class="setup-enrollment-detail muted" data-enrollment-detail aria-live="polite" style="white-space:pre-line"></p>
                    </div>
                    <?php if (!$mailReadyForSelect): ?>
                        <p class="setup-hint muted" data-setup-line><?= e(__('setup.mail_required_for_otp')) ?></p>
                    <?php endif; ?>
                    <?php else: ?>
                    <label class="setup-field" data-setup-line>
                        <span><?= e(__($step['title_key'])) ?></span>
                        <select name="value" required>
                            <?php foreach ($step['options'] as $opt): ?>
                                <?php
                                    $optVal = (string) ($opt['value'] ?? '');
                                    $otpBlocked = $optVal === 'otp_email' && !$mailReadyForSelect;
                                ?>
                                <option value="<?= e($optVal) ?>" <?= (string) $value === $optVal ? 'selected' : '' ?> <?= $otpBlocked ? 'disabled' : '' ?>><?= e(__($opt['label_key'])) ?><?= $otpBlocked ? ' — ' . e(__('setup.mail_required_short')) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php endif; ?>
                <?php elseif ($stepType === 'checkbox'): ?>
                    <label class="checkbox-row setup-check" data-setup-line>
                        <input type="checkbox" name="value" value="1" <?= !empty($value) ? 'checked' : '' ?>>
                        <span data-i18n="setup.gdpr_label"><?= e(__('setup.gdpr_label')) ?></span>
                    </label>
                <?php elseif ($stepType === 'textarea'): ?>
                    <?php
                    $isPrivacyStep = ($step['key'] ?? '') === 'legal.privacy';
                    $legalPrefix = $isPrivacyStep ? 'privacy' : 'statute';
                    $legalValues = is_array($value) ? $value : ['it' => (string) $value, 'de' => '', 'en' => ''];
                    $legalPlaceholder = $isPrivacyStep
                        ? __('setup.privacy_sample_note')
                        : __('setup.step_statute_desc');
                    ?>
                    <?php if ($isPrivacyStep): ?>
                        <p class="setup-hint muted" data-setup-line data-i18n="setup.privacy_sample_note"><?= e(__('setup.privacy_sample_note')) ?></p>
                    <?php endif; ?>
                    <div class="setup-legal-upload" data-setup-line data-setup-legal-pdf
                         data-upload-url="<?= e(url('/setup/legal-pdf')) ?>"
                         data-csrf="<?= e(csrf_token()) ?>"
                         data-target-prefix="<?= e($legalPrefix) ?>"
                         data-msg-reading="<?= e(__('setup.legal_pdf_reading')) ?>"
                         data-msg-fail="<?= e(__('setup.legal_pdf_fail')) ?>"
                         data-msg-ok="<?= e(__('setup.legal_pdf_ok')) ?>">
                        <label class="btn btn-ghost file-btn">
                            <input type="file" accept="application/pdf,.pdf" hidden data-setup-legal-pdf-input>
                            <span data-i18n="setup.legal_pdf_upload"><?= e(__('setup.legal_pdf_upload')) ?></span>
                        </label>
                        <p class="setup-hint muted" data-setup-legal-pdf-status hidden></p>
                    </div>
                    <div data-setup-line>
                        <?php
                        $namePrefix = $legalPrefix;
                        $values = $legalValues;
                        $placeholder = (string) $legalPlaceholder;
                        require base_path('resources/views/partials/legal_doc_editor.php');
                        ?>
                    </div>
                    <p class="setup-hint muted" data-setup-line data-i18n="setup.legal_min_chars_hint"><?= e(__('setup.legal_min_chars_hint', ['min' => '50'])) ?></p>
                <?php elseif ($stepType === 'member_types'): ?>
                    <?php
                    $types = is_array($value['types'] ?? null) ? $value['types'] : [];
                    $singleType = !empty($value['single_type']) || count($types) === 1;
                    $currencyCode = (string) ($value['currency'] ?? 'EUR');
                    $currencyDisplay = (string) ($value['currency_display'] ?? '€');
                    $langFlags = [
                        'it' => ['flag' => locale_flag_url('it'), 'label' => 'Italiano'],
                        'de' => ['flag' => locale_flag_url('de'), 'label' => 'Deutsch'],
                        'en' => ['flag' => locale_flag_url('en'), 'label' => 'English'],
                    ];
                    ?>
                    <div class="setup-membership" data-setup-line data-setup-member-types
                         data-translate-url="<?= e(url('/api/translate')) ?>"
                         data-currency="<?= e($currencyCode) ?>"
                         data-currency-display="<?= e($currencyDisplay) ?>"
                         data-msg-free-title="<?= e(__('setup.type_free_confirm')) ?>"
                         data-msg-free-confirm="<?= e(__('setup.type_free_confirm_ok')) ?>"
                         data-msg-free-cancel="<?= e(__('common.cancel')) ?>">
                        <?php if ($types !== []): ?>
                            <p class="setup-hint muted"><?= e(__('setup.types_edit_hint')) ?></p>
                            <div class="setup-membership-list">
                                <?php foreach ($types as $typeRow): ?>
                                    <?php $tid = (int) ($typeRow['id'] ?? 0); ?>
                                    <div class="setup-membership-card">
                                        <div class="setup-equal-row setup-langs-row">
                                            <?php foreach ($langFlags as $langCode => $langMeta): ?>
                                            <label class="setup-field">
                                                <span class="setup-lang-flag-label">
                                                    <img src="<?= e($langMeta['flag']) ?>" width="18" height="14" alt="" loading="lazy" decoding="async">
                                                    <?= e($langMeta['label']) ?><?= $langCode === 'it' ? ' *' : '' ?>
                                                </span>
                                                <input type="text" name="types[<?= $tid ?>][name_<?= e($langCode) ?>]" value="<?= e((string) ($typeRow['name_' . $langCode] ?? '')) ?>" <?= $langCode === 'it' ? 'required data-type-name-it' : 'data-type-name-' . e($langCode) ?>>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="setup-equal-row">
                                            <label class="setup-field">
                                                <span><?= e(__('install.type_price')) ?> (<?= e($currencyDisplay) ?>) *</span>
                                                <div class="setup-price-with-currency">
                                                    <input type="number" step="0.01" min="0" name="types[<?= $tid ?>][price]" value="<?= e((string) ($typeRow['price'] ?? '0')) ?>" required data-type-price data-type-name-ref="types[<?= $tid ?>][name_it]">
                                                    <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                                                </div>
                                            </label>
                                            <label class="checkbox-row setup-check setup-check-inline">
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
                        <?php if ($types !== []): ?>
                        <h3 class="setup-subhead"><?= e(__('settings.add_type')) ?></h3>
                        <?php endif; ?>
                        <div class="setup-membership-card setup-membership-card-new">
                            <div class="setup-equal-row setup-langs-row">
                                <?php foreach ($langFlags as $langCode => $langMeta): ?>
                                <label class="setup-field">
                                    <span class="setup-lang-flag-label">
                                        <img src="<?= e($langMeta['flag']) ?>" width="18" height="14" alt="" loading="lazy" decoding="async">
                                        <?= e($langMeta['label']) ?><?= $langCode === 'it' && $types === [] ? ' *' : '' ?>
                                    </span>
                                    <input type="text" name="name_<?= e($langCode) ?>" value="<?= e((string) ($value['name_' . $langCode] ?? '')) ?>" <?= $langCode === 'it' && $types === [] ? 'required' : '' ?> <?= $langCode === 'it' ? 'data-type-name-it' : 'data-type-name-' . e($langCode) ?> placeholder="<?= e(__('setup.type_name_placeholder')) ?>">
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="setup-equal-row">
                                <label class="setup-field">
                                    <span><?= e(__('install.type_price')) ?> (<?= e($currencyDisplay) ?>)<?= $types === [] ? ' *' : '' ?></span>
                                    <div class="setup-price-with-currency">
                                        <input type="number" step="0.01" min="0" name="price" value="<?= e((string) ($value['price'] ?? '')) ?>" <?= $types === [] ? 'required' : '' ?> placeholder="0.00" data-type-price data-type-name-ref="name_it">
                                        <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                                    </div>
                                </label>
                                <?php if ($types !== []): ?>
                                <label class="checkbox-row setup-check setup-check-inline">
                                    <input type="checkbox" name="is_active" value="1" <?= !empty($value['is_active']) ? 'checked' : '' ?>>
                                    <span><?= e(__('settings.is_active')) ?></span>
                                </label>
                                <?php else: ?>
                                    <input type="hidden" name="is_active" value="1">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm" data-type-translate-now><?= e(__('settings.legal_translate')) ?></button>
                        </div>
                    </div>
                <?php elseif ($stepType === 'membership_periods'): ?>
                    <?php $periods = is_array($value['periods'] ?? null) ? $value['periods'] : []; ?>
                    <div class="setup-membership" data-setup-line data-setup-membership-periods>
                        <?php if (!empty($value['needs_current_year'])): ?>
                            <p class="setup-hint setup-hint-warn"><?= e(__('setup.periods_need_current_year')) ?></p>
                        <?php endif; ?>
                        <?php if ($periods !== []): ?>
                            <div class="setup-membership-list">
                                <?php foreach ($periods as $period): ?>
                                    <?php $pid = (int) ($period['id'] ?? 0); ?>
                                    <div class="setup-membership-card<?= !empty($period['is_current']) ? ' is-current-period' : '' ?>">
                                        <p class="setup-period-label muted"><?= e((string) ($period['label'] ?? '')) ?><?= !empty($period['is_current']) ? ' · ' . e(__('settings.is_current')) : '' ?></p>
                                        <div class="setup-equal-row">
                                            <label class="setup-field">
                                                <span><?= e(__('install.starts_on')) ?> *</span>
                                                <input type="date" name="periods[<?= $pid ?>][starts_on]" value="<?= e((string) ($period['starts_on'] ?? '')) ?>" required data-period-start>
                                            </label>
                                            <label class="setup-field">
                                                <span><?= e(__('install.ends_on')) ?> *</span>
                                                <input type="date" name="periods[<?= $pid ?>][ends_on]" value="<?= e((string) ($period['ends_on'] ?? '')) ?>" required data-period-end>
                                            </label>
                                        </div>
                                        <label class="checkbox-row setup-check">
                                            <input type="checkbox" name="periods[<?= $pid ?>][is_current]" value="1" <?= !empty($period['is_current']) ? 'checked' : '' ?>>
                                            <span><?= e(__('settings.is_current')) ?></span>
                                        </label>
                                        <?php if (!empty($period['is_current']) && !empty($value['next_period_starts_on']) && !empty($value['next_period_ends_on'])): ?>
                                            <p class="setup-period-next muted" data-setup-next-period>
                                                <?= e(__('setup.periods_next_period', [
                                                    'from' => date('d/m/Y', strtotime((string) $value['next_period_starts_on'])),
                                                    'to' => date('d/m/Y', strtotime((string) $value['next_period_ends_on'])),
                                                ])) ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($periods !== []): ?>
                        <h3 class="setup-subhead"><?= e(!empty($value['needs_current_year']) ? __('setup.periods_add_year') : __('settings.add_period')) ?></h3>
                        <?php endif; ?>
                        <div class="setup-membership-card setup-membership-card-new">
                            <div class="setup-equal-row">
                                <label class="setup-field">
                                    <span><?= e(__('install.starts_on')) ?><?= ($periods === [] || !empty($value['needs_current_year'])) ? ' *' : '' ?></span>
                                    <input type="date" name="starts_on" value="<?= e((string) ($value['starts_on'] ?? '')) ?>" <?= ($periods === [] || !empty($value['needs_current_year'])) ? 'required' : '' ?> data-period-start>
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('install.ends_on')) ?><?= ($periods === [] || !empty($value['needs_current_year'])) ? ' *' : '' ?></span>
                                    <input type="date" name="ends_on" value="<?= e((string) ($value['ends_on'] ?? '')) ?>" <?= ($periods === [] || !empty($value['needs_current_year'])) ? 'required' : '' ?> data-period-end>
                                </label>
                            </div>
                            <label class="checkbox-row setup-check">
                                <input type="checkbox" name="is_current" value="1" <?= !empty($value['is_current']) ? 'checked' : '' ?>>
                                <span><?= e(__('settings.is_current')) ?></span>
                            </label>
                        </div>
                    </div>
                <?php elseif ($stepType === 'member_fields'): ?>
                    <?php
                        $fields = is_array($value['fields'] ?? null) ? $value['fields'] : [];
                        $formSteps = is_array($value['form_steps'] ?? null) ? $value['form_steps'] : [];
                        $typeOptions = is_array($value['type_options'] ?? null) ? $value['type_options'] : \Socly\Support\MemberFieldTypes::keys();
                    ?>
                    <div class="setup-fields" data-setup-line>
                        <?= view_partial('partials/member_fields_editor', [
                            'fields' => $fields,
                            'formSteps' => $formSteps,
                            'typeOptions' => $typeOptions,
                            'allowTypeEdit' => true,
                            'setupMode' => true,
                            'autosaveUrl' => url('/setup/fields/autosave'),
                        ]) ?>

                        <div class="setup-membership-card setup-membership-card-new setup-fields-add">
                            <h3 class="setup-subhead"><?= e(__('settings.add_field')) ?></h3>
                            <div class="setup-equal-row">
                                <label class="setup-field setup-field-grow">
                                    <span><?= e(__('setup.fields_new_label')) ?></span>
                                    <input type="text" name="new_label" value="<?= e((string) ($value['new_label'] ?? '')) ?>" placeholder="<?= e(__('setup.fields_new_label_ph')) ?>" data-new-field-label>
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('setup.fields_col_type')) ?> *</span>
                                    <select name="new_type" data-new-field-type>
                                        <?php foreach ($typeOptions as $opt): ?>
                                            <option value="<?= e($opt) ?>" <?= (string) ($value['new_type'] ?? 'text') === $opt ? 'selected' : '' ?>><?= e(\Socly\Support\MemberFieldTypes::label($opt)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div class="setup-equal-row">
                                <label class="checkbox-row setup-check setup-check-inline">
                                    <input type="checkbox" name="new_enabled" value="1" data-new-field-enabled <?= !empty($value['new_enabled']) ? 'checked' : '' ?>>
                                    <span><?= e(__('setup.fields_col_enabled')) ?></span>
                                </label>
                                <label class="checkbox-row setup-check setup-check-inline">
                                    <input type="checkbox" name="new_required" value="1" data-new-field-required <?= !empty($value['new_required']) ? 'checked' : '' ?>>
                                    <span><?= e(__('setup.fields_col_required')) ?></span>
                                </label>
                            </div>
                            <button
                                type="button"
                                class="btn btn-ghost"
                                data-fields-add-now
                                data-msg-need-label="<?= e(__('setup.fields_need_label')) ?>"
                                data-msg-adding="<?= e(__('setup.fields_adding')) ?>"
                            ><?= e(__('setup.fields_add_now')) ?></button>
                        </div>
                    </div>
                <?php elseif ($stepType === 'smtp_config'): ?>
                    <?php
                        $mailEnc = (string) ($value['encryption'] ?? 'tls');
                        $mailReady = !empty($value['last_test_ok']);
                        $showManual = !empty($value['show_manual']);
                        $mailSkipped = !empty($value['outbound_disabled']);
                        $assocEmail = (string) ($value['test_to'] ?? $value['from_address'] ?? '');
                    ?>
                    <div
                        class="setup-smtp"
                        data-setup-smtp
                        data-setup-line
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
                        data-has-password="<?= !empty($value['has_password']) ? '1' : '0' ?>"
                    >
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
                                <div class="setup-equal-row setup-smtp-credentials">
                                    <label class="setup-field setup-field-grow">
                                        <span><?= e(__('mail.from_address')) ?> *</span>
                                        <input type="email" name="from_address" value="<?= e((string) ($value['from_address'] ?? '')) ?>" required autocomplete="off" placeholder="noreply@tuodominio.it" data-smtp-from>
                                        <p class="field-hint setup-field-hint" data-smtp-from-hint hidden></p>
                                    </label>
                                    <?= view_partial('partials/password_input', [
                                        'name' => 'password',
                                        'label' => (string) __('mail.password'),
                                        'required' => empty($value['has_password']),
                                        'placeholder' => !empty($value['has_password']) ? (string) __('mail.password_keep') : '',
                                        'autocomplete' => 'new-password',
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
                                    <label class="setup-field">
                                        <span><?= e(__('mail.host')) ?> *</span>
                                        <input type="text" name="host" value="<?= e((string) ($value['host'] ?? '')) ?>" placeholder="smtp.example.com" autocomplete="off" data-smtp-host>
                                    </label>
                                    <label class="setup-field">
                                        <span><?= e(__('mail.port')) ?> *</span>
                                        <input type="number" name="port" value="<?= e((string) ($value['port'] ?? '587')) ?>" min="1" max="65535" data-smtp-port>
                                    </label>
                                </div>
                                <label class="setup-field">
                                    <span><?= e(__('mail.encryption')) ?> *</span>
                                    <select name="encryption" data-smtp-encryption>
                                        <option value="tls" <?= $mailEnc === 'tls' ? 'selected' : '' ?>><?= e(__('mail.encryption_tls')) ?></option>
                                        <option value="ssl" <?= $mailEnc === 'ssl' ? 'selected' : '' ?>><?= e(__('mail.encryption_ssl')) ?></option>
                                        <option value="none" <?= $mailEnc === 'none' ? 'selected' : '' ?>><?= e(__('mail.encryption_none')) ?></option>
                                    </select>
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('mail.username')) ?> *</span>
                                    <input type="text" name="username" value="<?= e((string) ($value['username'] ?? '')) ?>" autocomplete="off" placeholder="<?= e(__('mail.username_default_hint')) ?>" data-smtp-username>
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('mail.from_name')) ?></span>
                                    <input type="text" name="from_name" value="<?= e((string) ($value['from_name'] ?? '')) ?>" autocomplete="organization" data-smtp-from-name>
                                </label>
                                <div class="setup-smtp-actions">
                                    <button type="button" class="btn btn-ghost" data-smtp-verify-btn><?= e(__('setup.mail_verify_btn')) ?></button>
                                </div>
                                <p class="setup-hint muted" data-smtp-verify-status hidden></p>
                            </div>

                            <div class="setup-smtp-section setup-smtp-test" data-smtp-test-section <?= $mailReady ? '' : 'hidden' ?>>
                                <label class="setup-field">
                                    <span><?= e(__('mail.test_to')) ?> *</span>
                                    <input type="email" name="test_to" value="<?= e($assocEmail) ?>" <?= $mailReady ? 'required' : '' ?> autocomplete="off" data-smtp-test-to <?= $mailReady ? '' : 'disabled' ?>>
                                </label>
                                <div class="setup-smtp-actions">
                                    <button type="button" class="btn btn-ghost" data-smtp-test-btn <?= $mailReady ? '' : 'disabled' ?>><?= e(__('setup.mail_test_btn')) ?></button>
                                </div>
                                <p class="setup-hint muted" data-smtp-test-status <?= $mailReady ? '' : 'hidden' ?>><?= $mailReady ? e(__('mail.test_ok_badge')) : '' ?></p>
                                <p class="setup-hint muted"><?= e(__('setup.mail_save_hint')) ?></p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($stepType === 'component_select'): ?>
                    <?php
                        $components = is_array($value['components'] ?? null) ? $value['components'] : [];
                    ?>
                    <div class="setup-components" data-setup-line>
                        <p class="setup-hint muted"><?= e(__('setup.step_components_hint')) ?></p>
                        <div class="setup-component-list">
                            <?php foreach ($components as $component): ?>
                                <?php
                                    $cKey = (string) ($component['key'] ?? '');
                                    $locked = in_array($cKey, ['members', 'org_roles'], true);
                                    $checked = $locked || !empty($component['enabled']);
                                ?>
                                <label class="setup-component-row<?= $checked ? ' is-selected' : '' ?><?= $locked ? ' setup-component-row-locked' : '' ?>">
                                    <input
                                        type="checkbox"
                                        name="components[]"
                                        value="<?= e($cKey) ?>"
                                        <?= $checked ? 'checked' : '' ?>
                                        <?= $locked ? 'disabled' : '' ?>
                                    >
                                    <?php if ($locked): ?>
                                        <input type="hidden" name="components[]" value="<?= e($cKey) ?>">
                                    <?php endif; ?>
                                    <span class="setup-component-row-body">
                                        <span class="setup-component-row-top">
                                            <strong class="setup-component-name"><?= e(__((string) ($component['name'] ?? ''))) ?></strong>
                                            <span class="setup-component-price"><?= e(__('components.price_included')) ?></span>
                                        </span>
                                        <span class="setup-component-desc"><?= e(__((string) ($component['description'] ?? ''))) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($errors['components'])): ?>
                            <p class="setup-field-error"><?= e(is_array($errors['components']) ? implode(', ', $errors['components']) : (string) $errors['components']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php elseif ($stepType === 'platform_consents'): ?>
                    <?php
                        $mailReady = !empty($value['mail_ready']);
                        $anyPlatform = !empty($value['news_opt_in']) || !empty($value['usage_stats_opt_in']) || !empty($value['showcase_consent']);
                    ?>
                    <div class="setup-platform-consents" data-setup-line data-platform-consents data-mail-ready="<?= $mailReady ? '1' : '0' ?>">
                        <p class="setup-hint muted"><?= e(__('setup.platform_hint')) ?></p>
                        <label class="checkbox-row setup-check">
                            <input type="checkbox" name="news_opt_in" value="1" data-platform-opt <?= !empty($value['news_opt_in']) ? 'checked' : '' ?>>
                            <span><?= e(__('setup.platform_news')) ?></span>
                        </label>
                        <label class="checkbox-row setup-check">
                            <input type="checkbox" name="usage_stats_opt_in" value="1" data-platform-opt <?= !empty($value['usage_stats_opt_in']) ? 'checked' : '' ?>>
                            <span><?= e(__('setup.platform_stats')) ?></span>
                        </label>
                        <label class="checkbox-row setup-check">
                            <input type="checkbox" name="showcase_consent" value="1" data-platform-opt <?= !empty($value['showcase_consent']) ? 'checked' : '' ?>>
                            <span><?= e(__('setup.platform_showcase')) ?></span>
                        </label>
                        <p class="setup-hint muted"><?= e(__('setup.platform_showcase_hint')) ?></p>
                        <div class="setup-platform-confirm" data-platform-confirm <?= $anyPlatform ? '' : 'hidden' ?>>
                            <p class="setup-hint"><?= e(__('setup.platform_confirm_hint')) ?></p>
                            <div class="setup-equal-row">
                                <label class="setup-field">
                                    <span><?= e(__('setup.platform_confirm_first')) ?> *</span>
                                    <input type="text" name="confirm_first_name" value="" autocomplete="off" data-platform-confirm-input placeholder="<?= e((string) ($value['president_first_placeholder'] ?? '')) ?>" <?= $anyPlatform ? 'required' : '' ?>>
                                </label>
                                <label class="setup-field">
                                    <span><?= e(__('setup.platform_confirm_last')) ?> *</span>
                                    <input type="text" name="confirm_last_name" value="" autocomplete="off" data-platform-confirm-input placeholder="<?= e((string) ($value['president_last_placeholder'] ?? '')) ?>" <?= $anyPlatform ? 'required' : '' ?>>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php elseif ($stepType === 'admin_account'): ?>
                    <div class="setup-admin-account" data-setup-line>
                        <p class="setup-hint muted"><?= e(__('setup.admin_access_hint')) ?></p>
                        <label class="setup-field">
                            <span><?= e(__('setup.field_admin_email')) ?> *</span>
                            <input type="email" name="email" value="<?= e((string) ($value['email'] ?? '')) ?>" required autocomplete="email">
                        </label>
                        <div class="setup-equal-row">
                            <?= view_partial('partials/password_input', [
                                'name' => 'password',
                                'label' => (string) __('setup.field_admin_password'),
                                'required' => true,
                                'autocomplete' => 'new-password',
                                'minlength' => 8,
                            ]) ?>
                            <?= view_partial('partials/password_input', [
                                'name' => 'password_confirmation',
                                'label' => (string) __('setup.field_admin_password_confirmation'),
                                'required' => true,
                                'autocomplete' => 'new-password',
                                'minlength' => 8,
                            ]) ?>
                        </div>
                        <div class="setup-password-tools">
                            <button type="button" class="btn btn-ghost btn-sm" data-password-generate><?= e(__('setup.admin_password_generate')) ?></button>
                            <span class="setup-hint muted"><?= e(__('setup.admin_password_rules')) ?></span>
                        </div>
                        <div class="setup-password-strength">
                            <p class="setup-field-label"><?= e(__('setup.admin_password_strength')) ?></p>
                            <div class="password-complexity" data-password-complexity aria-live="polite">
                                <span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                        <label class="setup-field setup-admin-locale" data-setup-admin-locale>
                            <span><?= e(__('setup.field_admin_locale')) ?></span>
                            <div class="setup-locale-grid setup-locale-grid-compact" role="radiogroup" aria-label="<?= e(__('setup.field_admin_locale')) ?>">
                                <?php foreach (['it' => 'Italiano', 'de' => 'Deutsch', 'en' => 'English'] as $loc => $locLabel): ?>
                                    <label class="setup-locale-card">
                                        <input type="radio" name="locale" value="<?= e($loc) ?>" <?= (string) ($value['locale'] ?? 'it') === $loc ? 'checked' : '' ?> required>
                                        <img src="<?= e(locale_flag_url($loc)) ?>" width="28" height="21" alt="" loading="lazy" decoding="async">
                                        <span><?= e($locLabel) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </label>
                    </div>
                <?php else: ?>
                    <label class="setup-field" data-setup-line>
                        <span><?= e(__($step['title_key'])) ?></span>
                        <input
                            type="<?= e($stepType === 'tel' ? 'tel' : ($stepType === 'email' ? 'email' : 'text')) ?>"
                            name="value"
                            value="<?= e((string) $value) ?>"
                            <?= !empty($step['required']) ? 'required' : '' ?>
                            autocomplete="off"
                        >
                    </label>
                <?php endif; ?>

                <?php
                $setupWhyText = '';
                if (!empty($step['description_key'])) {
                    $setupWhyKey = preg_replace('/_desc/', '_why', (string) $step['description_key']) ?? '';
                    if ($setupWhyKey !== '' && $setupWhyKey !== (string) $step['description_key']) {
                        $candidate = __($setupWhyKey);
                        if ($candidate !== $setupWhyKey) {
                            $setupWhyText = $candidate;
                        }
                    }
                }
                ?>
                <?php if ($setupWhyText !== ''): ?>
                    <div class="setup-why-note" data-setup-line>
                        <p class="setup-why-title" data-i18n="setup.why_title"><?= e(__('setup.why_title')) ?></p>
                        <p class="setup-why-text muted" data-i18n="<?= e($setupWhyKey ?? '') ?>"><?= e($setupWhyText) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (in_array($stepType, ['president', 'people_list', 'textarea', 'smtp_config'], true)): ?>
                    <p class="setup-defer-wrap" data-setup-line>
                        <button type="button" class="setup-defer-link" data-setup-defer-step data-i18n="setup.defer_step"><?= e(__('setup.defer_step')) ?></button>
                    </p>
                <?php endif; ?>

                <div class="setup-actions" data-setup-line>
                    <?php if (!empty($backHref)): ?>
                        <a class="btn btn-ghost setup-back" href="<?= e((string) $backHref) ?>" data-i18n="common.back"><?= e(__('common.back')) ?></a>
                    <?php endif; ?>
                    <button class="btn setup-cta" type="submit" data-i18n="<?= !empty($isLast) ? 'setup.finish' : 'setup.next' ?>">
                        <?= e(!empty($isLast) ? __('setup.finish') : __('setup.next')) ?>
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/setup/exit')) ?>" class="setup-logout-form" data-setup-logout hidden>
        <?= csrf_field() ?>
    </form>
    <form method="post" action="<?= e(url('/setup/discard')) ?>" class="setup-logout-form" data-setup-discard hidden>
        <?= csrf_field() ?>
    </form>

    <dialog class="setup-runts-doc-dialog" data-setup-runts-doc-dialog>
        <div class="setup-runts-doc-shell">
            <div class="setup-runts-doc-head">
                <h2 class="setup-runts-doc-title" data-setup-runts-doc-title><?= e(__('setup.runts_doc_view')) ?></h2>
                <button type="button" class="btn btn-ghost btn-sm" data-setup-runts-doc-close><?= e(__('common.close')) ?></button>
            </div>
            <div class="setup-runts-doc-frame-wrap">
                <iframe class="setup-runts-doc-frame" data-setup-runts-doc-frame title="<?= e(__('setup.runts_doc_view')) ?>"></iframe>
            </div>
        </div>
    </dialog>

    <dialog class="setup-exit-dialog" data-setup-exit-dialog>
        <div class="setup-exit-shell">
            <?php if (!empty($isIncremental)): ?>
                <p class="setup-exit-text"><?= e(__('setup.exit_incremental')) ?></p>
                <div class="setup-exit-actions">
                    <button type="button" class="btn btn-ghost" data-setup-exit-cancel><?= e(__('common.cancel')) ?></button>
                    <button
                        type="button"
                        class="btn btn-ghost setup-exit-discard-all"
                        data-setup-exit-discard
                        data-confirm="<?= e(__('setup.exit_discard_all_confirm')) ?>"
                    ><?= e(__('setup.exit_discard_all')) ?></button>
                    <button type="button" class="btn" data-setup-exit-keep><?= e(__('setup.exit_continue_later')) ?></button>
                </div>
            <?php else: ?>
                <p class="setup-exit-text"><?= e(__('setup.exit_keep_or_discard')) ?></p>
                <div class="setup-exit-actions">
                    <button
                        type="button"
                        class="btn btn-ghost setup-exit-discard-all"
                        data-setup-exit-discard
                        data-confirm="<?= e(__('setup.exit_discard_all_confirm')) ?>"
                    ><?= e(__('setup.exit_discard')) ?></button>
                    <button type="button" class="btn" data-setup-exit-keep><?= e(__('setup.exit_keep')) ?></button>
                </div>
            <?php endif; ?>
        </div>
    </dialog>

    <dialog class="setup-exit-dialog" data-setup-scrape-ask-dialog>
        <div class="setup-exit-shell">
            <p class="setup-exit-text" data-setup-scrape-ask-text><?= e(__('setup.scrape_ask')) ?></p>
            <div class="setup-exit-actions">
                <button type="button" class="btn btn-ghost" data-setup-scrape-ask-no><?= e(__('setup.scrape_ask_no')) ?></button>
                <button type="button" class="btn" data-setup-scrape-ask-yes><?= e(__('setup.scrape_ask_yes')) ?></button>
            </div>
        </div>
    </dialog>
</div>
