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
            <p class="setup-copy setup-copy-reassure setup-copy-reassure-eta" data-setup-line><?= setup_eta_reassure_line((int) $missingCount, true) ?></p>
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
                data-msg-field-ok="<?= e(__('validation.field_ok')) ?>"
                data-msg-field-required="<?= e(__('validation.required')) ?>"
            >
                <?= csrf_field() ?>
                <?= field('hidden', ['name' => 'step_index', 'value' => (string) (int) $stepIndex]) ?>
                <?= field('hidden', ['name' => 'step_key', 'value' => (string) ($step['key'] ?? '')]) ?>
                <?= field('hidden', ['name' => 'setup_exit', 'value' => '0', 'attrs' => ['data-setup-exit-flag' => true]]) ?>
                <?= field('hidden', ['name' => 'setup_defer', 'value' => '0', 'attrs' => ['data-setup-defer-flag' => true]]) ?>

                <div class="setup-form-scroll">

                <?php if ($stepType === 'colors'): ?>
                    <?php
                    $palettes = is_array($value['palettes'] ?? null) ? $value['palettes'] : [];
                    unset($value['palettes'], $value['logo_url']);
                    ?>
                    <?php
                    $previewPrimary = (string) ($value['primary'] ?? '#0D6E66');
                    $previewAccent = (string) ($value['accent'] ?? '#B84A1B');
                    ?>
                    <div class="setup-brand-preview" data-setup-line data-brand-preview
                         style="<?= e(brand_root_style_decls($previewPrimary, $previewAccent)) ?>">
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
                                $isSelected = $pPrimary === strtoupper($previewPrimary)
                                    && $pAccent === strtoupper($previewAccent);
                                $source = (string) ($palette['source'] ?? '');
                                ?>
                                <button type="button" class="setup-palette-card<?= $isSelected ? ' is-selected' : '' ?><?= in_array($source, ['preset', 'default'], true) ? ' is-preset' : '' ?>" data-palette-pick
                                        data-primary="<?= e((string) ($palette['primary'] ?? '')) ?>"
                                        data-accent="<?= e((string) ($palette['accent'] ?? '')) ?>"
                                        data-source="<?= e($source) ?>">
                                    <span class="setup-palette-swatch" style="--swatch-a: <?= e((string) ($palette['primary'] ?? '#0D6E66')) ?>; --swatch-b: <?= e((string) ($palette['accent'] ?? '#B84A1B')) ?>;"></span>
                                    <span class="setup-palette-name"><?= e((string) ($palette['name'] ?? __('setup.palette_custom'))) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="setup-colors setup-color-picker-grid" data-setup-line>
                        <?php foreach ($step['fields'] as $field): ?>
                            <?php $colorKey = (string) ($field['key'] ?? ''); ?>
                            <?= field('color', [
                                'name' => $colorKey,
                                'label' => (string) __($field['label_key']),
                                'value' => (string) ($value[$colorKey] ?? '#0D6E66'),
                                'required' => true,
                                'setup_brand' => true,
                                'brand_key' => $colorKey,
                            ]) ?>
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
                            <?= field('file', [
                                'name' => '',
                                'button_label' => (string) __('setup.logo_upload'),
                                'accept' => 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml',
                                'context' => 'setup',
                                'button_class' => 'btn btn-ghost file-btn',
                                'icon' => 'upload',
                                'attrs' => ['data-setup-logo-input' => true],
                            ]) ?>
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
                                <div class="setup-runts-block<?= !empty($runtsExhausted) ? ' is-exhausted' : '' ?>" data-setup-runts
                                     data-runts-url="<?= e(url('/setup/runts-lookup')) ?>"
                                     data-csrf="<?= e(csrf_token()) ?>"
                                     data-runts-max="<?= (int) ($runtsAttemptsMax ?? 3) ?>"
                                     data-runts-left="<?= (int) ($runtsAttemptsLeft ?? 3) ?>"
                                     data-runts-cooldown="<?= (int) ($runtsCooldownSeconds ?? 8) ?>"
                                     <?= !empty($runtsExhausted) ? 'data-runts-exhausted="1"' : '' ?>
                                     data-label-template="<?= e(__('setup.runts_button')) ?>"
                                     data-msg-need="<?= e(__('setup.runts_need_number')) ?>"
                                     data-msg-loading="<?= e(__('setup.runts_loading')) ?>"
                                     data-msg-ok="<?= e(__('setup.runts_ok')) ?>"
                                     data-msg-fail="<?= e(__('setup.runts_fail')) ?>"
                                     data-msg-not-found="<?= e(__('setup.runts_not_found')) ?>"
                                     data-msg-timeout="<?= e(__('setup.runts_timeout')) ?>"
                                     data-msg-elapsed="<?= e(__('setup.runts_elapsed')) ?>"
                                     data-msg-attempts-left="<?= e(__('setup.runts_attempts_left')) ?>"
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
                                     data-msg-people-heading-short="<?= e(__('setup.runts_people_heading_short')) ?>"
                                     data-msg-docs-heading-short="<?= e(__('setup.runts_docs_heading_short')) ?>"
                                     data-msg-docs-pending="<?= e(__('setup.runts_docs_pending')) ?>"
                                     data-msg-docs-background="<?= e(__('setup.runts_docs_background')) ?>"
                                     data-msg-legal-heading="<?= e(__('setup.runts_legal_heading')) ?>"
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
                                    <div class="setup-runts-unavailable" data-setup-runts-unavailable <?= empty($runtsExhausted) ? 'hidden' : '' ?>>
                                        <p class="setup-runts-unavailable-text" data-i18n="setup.runts_unavailable"><?= e(__('setup.runts_unavailable')) ?></p>
                                    </div>
                                    <div class="setup-runts-panel" data-setup-runts-panel <?= !empty($runtsExhausted) ? 'hidden' : '' ?>>
                                    <div class="setup-runts-callout" data-setup-runts-callout>
                                        <span class="setup-runts-callout-icon setup-runts-callout-icon-promo" aria-hidden="true">⚡</span>
                                        <span class="setup-runts-callout-icon setup-runts-callout-icon-ok" aria-hidden="true">✓</span>
                                        <p class="setup-runts-callout-text setup-runts-callout-promo" data-i18n="setup.runts_callout"><?= e(__('setup.runts_callout')) ?></p>
                                        <p class="setup-runts-callout-text setup-runts-callout-ok" data-i18n="setup.runts_callout_found" hidden><?= e(__('setup.runts_callout_found')) ?></p>
                                    </div>
                                    <?= field('text', [
                                        'id' => 'setup-runts-number',
                                        'name' => 'runts',
                                        'label' => (string) __($field['label_key']),
                                        'value' => (string) ($value['runts'] ?? ''),
                                        'context' => 'setup',
                                        'wrapper_class' => 'setup-field-runts',
                                        'inputmode' => 'numeric',
                                        'autocomplete' => 'off',
                                        'maxlength' => 6,
                                        'placeholder' => (string) __('setup.field_runts_placeholder'),
                                        'attrs' => [
                                            'data-setup-runts-input' => true,
                                            'spellcheck' => 'false',
                                        ],
                                        'hint' => (string) __('setup.runts_hint'),
                                        'hint_attrs' => ['class' => 'setup-runts-hint', 'data-setup-runts-hint' => true],
                                    ]) ?>
                                    <div class="setup-runts-actions">
                                        <button type="button" class="btn setup-scrape-btn" data-setup-runts-btn hidden disabled aria-hidden="true">
                                            <span data-setup-runts-label><?= e(__('setup.runts_button_fallback')) ?></span>
                                        </button>
                                        <p class="setup-runts-attempts muted" data-setup-runts-attempts-msg hidden></p>
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
                                    </div><!-- .setup-runts-panel -->
                                </div>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php
                            $pairWrapper = $fieldKey === 'legal_name' ? 'setup-field-legal' : ($fieldKey === 'currency' ? 'setup-field-currency' : ($fieldKey === 'name_de' ? 'setup-field-name-de' : 'setup-field-name'));
                            $pairLabel = (string) __($field['label_key']);
                            if (empty($field['required']) && $fieldKey === 'name_de') {
                                $pairLabel .= ' (' . __('setup.optional_short') . ')';
                            }
                            ?>
                            <?php if (($field['type'] ?? '') === 'select'): ?>
                                <?php
                                $isCurrency = $fieldKey === 'currency';
                                $selectOptions = [];
                                foreach ($field['options'] as $opt) {
                                    $optVal = (string) ($opt['value'] ?? '');
                                    $selectOptions[] = [
                                        'value' => $optVal,
                                        'label' => $isCurrency ? $optVal : \Socly\Setup\AssociationLegalForms::displayCode($optVal),
                                        'attrs' => $isCurrency ? [] : ['data-meaning' => \Socly\Setup\AssociationLegalForms::bilingualMeaning($optVal)],
                                    ];
                                }
                                ?>
                                <?= field('select', [
                                    'name' => $fieldKey,
                                    'label' => $pairLabel,
                                    'value' => (string) ($value[$fieldKey] ?? ''),
                                    'required' => true,
                                    'context' => 'setup',
                                    'wrapper_class' => $pairWrapper,
                                    'options' => $selectOptions,
                                    'include_empty' => !$isCurrency,
                                    'empty_label' => (string) __('setup.legal_form_placeholder'),
                                    'attrs' => array_filter([
                                        'data-setup-legal-name' => $isCurrency ? false : true,
                                        'maxlength' => '6',
                                    ]),
                                ]) ?>
                            <?php else: ?>
                                <?= field('text', [
                                    'name' => $fieldKey,
                                    'label' => $pairLabel,
                                    'value' => (string) ($value[$fieldKey] ?? ''),
                                    'required' => !empty($field['required']),
                                    'context' => 'setup',
                                    'wrapper_class' => $pairWrapper,
                                    'autocomplete' => 'organization',
                                    'attrs' => array_filter([
                                        'data-setup-assoc-name' => $fieldKey === 'name' ? true : false,
                                        'data-setup-assoc-name-de' => $fieldKey === 'name_de' ? true : false,
                                    ]),
                                    'hint' => $fieldKey === 'name_de' ? (string) __('setup.field_assoc_name_de_hint') : '',
                                ]) ?>
                                <?php if ($fieldKey === 'name'): ?>
                                    <p class="setup-legal-meaning muted" data-setup-legal-meaning hidden></p>
                                <?php endif; ?>
                            <?php endif; ?>
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
                            <?php
                            $fgKey = (string) ($field['key'] ?? '');
                            $fgLabel = (string) __($field['label_key']);
                            $fgRequired = !empty($field['required']);
                            $fgValue = (string) ($value[$fgKey] ?? '');
                            ?>
                            <?php if ($fieldType === 'tel'): ?>
                                <?= field('phone', [
                                    'name' => $fgKey !== '' ? $fgKey : 'phone',
                                    'label' => $fgLabel,
                                    'value' => $fgValue,
                                    'required' => $fgRequired,
                                    'context' => 'setup',
                                ]) ?>
                            <?php elseif ($fieldType === 'email' || $fgKey === 'pec'): ?>
                                <?= field('email', [
                                    'name' => $fgKey,
                                    'label' => $fgLabel,
                                    'value' => $fgValue,
                                    'required' => $fgRequired,
                                    'context' => 'setup',
                                    'autocomplete' => (string) ($field['autocomplete'] ?? 'off'),
                                    'placeholder' => $fgKey === 'pec'
                                        ? (string) __('setup.field_pec_placeholder')
                                        : (string) __('setup.field_email_placeholder'),
                                    'attrs' => [
                                        'data-setup-' . ($fgKey === 'pec' ? 'pec' : 'email') => true,
                                        'spellcheck' => 'false',
                                    ],
                                ]) ?>
                            <?php elseif ($fgKey === 'fiscal_code'): ?>
                                <?= field('text', [
                                    'name' => $fgKey,
                                    'label' => $fgLabel,
                                    'value' => $fgValue,
                                    'required' => $fgRequired,
                                    'context' => 'setup',
                                    'maxlength' => 16,
                                    'minlength' => 11,
                                    'placeholder' => (string) __('setup.field_fiscal_code_placeholder'),
                                    'attrs' => [
                                        'data-setup-fiscal-code' => true,
                                        'inputmode' => 'text',
                                        'spellcheck' => 'false',
                                        'autocapitalize' => 'characters',
                                        'title' => (string) __('setup.tax_fiscal_invalid'),
                                    ],
                                ]) ?>
                                <p class="setup-hint muted" data-setup-fiscal-hint hidden></p>
                            <?php elseif ($fgKey === 'vat_number'): ?>
                                <?= field('text', [
                                    'name' => $fgKey,
                                    'label' => $fgLabel,
                                    'value' => $fgValue,
                                    'required' => $fgRequired,
                                    'context' => 'setup',
                                    'maxlength' => 13,
                                    'placeholder' => (string) __('setup.field_vat_placeholder'),
                                    'attrs' => [
                                        'data-setup-vat-number' => true,
                                        'inputmode' => 'numeric',
                                        'spellcheck' => 'false',
                                        'title' => (string) __('setup.field_vat_placeholder'),
                                    ],
                                ]) ?>
                                <p class="setup-hint muted" data-setup-vat-hint hidden></p>
                            <?php else: ?>
                                <?= field('text', [
                                    'name' => $fgKey,
                                    'label' => $fgLabel,
                                    'value' => $fgValue,
                                    'required' => $fgRequired,
                                    'context' => 'setup',
                                    'autocomplete' => (string) ($field['autocomplete'] ?? 'off'),
                                ]) ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($stepType === 'address_block'): ?>
                    <?= field('address', [
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
                        'required_map' => [
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
                    <?php
                    $presResidenceFilled = trim((string) ($value['city'] ?? '')) !== ''
                        || trim((string) ($value['address'] ?? '')) !== ''
                        || trim((string) ($value['postal_code'] ?? '')) !== '';
                    $presMandateFilled = trim((string) ($value['appointed_at'] ?? '')) !== ''
                        || trim((string) ($value['mandate_ends_at'] ?? '')) !== '';
                    ?>
                    <div class="setup-president" data-setup-line data-geo-scope data-setup-president-sections>
                        <?= view_partial('partials/geo_foreign_bar') ?>

                        <details class="setup-section" data-setup-section="identity" open>
                            <summary class="setup-section-summary">
                                <span class="setup-section-title"><?= e(__('setup.president_section_identity')) ?></span>
                                <span class="setup-section-hint muted"><?= e(__('setup.president_section_identity_hint')) ?></span>
                            </summary>
                            <div class="setup-section-body">
                                <div class="setup-equal-row">
                                    <?= field('text', [
                                        'name' => 'first_name',
                                        'label' => (string) __('setup.field_first_name'),
                                        'value' => (string) ($value['first_name'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                        'autocomplete' => 'given-name',
                                        'attrs' => ['data-first-name' => true],
                                    ]) ?>
                                    <?= field('text', [
                                        'name' => 'last_name',
                                        'label' => (string) __('setup.field_last_name'),
                                        'value' => (string) ($value['last_name'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                        'autocomplete' => 'family-name',
                                        'attrs' => ['data-last-name' => true],
                                    ]) ?>
                                </div>
                                <div class="setup-equal-row">
                                    <?= field('gender', [
                                        'name' => 'gender',
                                        'label' => (string) __('setup.field_gender'),
                                        'value' => (string) ($value['gender'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                    ]) ?>
                                    <?= field('birth_date', [
                                        'name' => 'birth_date',
                                        'label' => (string) __('setup.field_birth_date'),
                                        'value' => (string) ($value['birth_date'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                        'min' => date('Y-m-d', strtotime('-100 years')),
                                        'max' => date('Y-m-d', strtotime('-18 years')),
                                        'attrs' => ['data-birth-date' => true],
                                    ]) ?>
                                </div>
                                <?= field('birth_place', [
                                    'name' => 'birth_place',
                                    'value' => (string) ($value['birth_place'] ?? ''),
                                    'required' => true,
                                    'foreign_toggle' => false,
                                    'with_scope' => false,
                                ]) ?>
                                <?= field('fiscal_code', [
                                    'name' => 'fiscal_code',
                                    'label' => (string) __('setup.field_person_fiscal_code'),
                                    'value' => (string) ($value['fiscal_code'] ?? ''),
                                    'required' => true,
                                    'context' => 'setup',
                                    'placeholder' => (string) __('setup.field_person_fiscal_code_placeholder'),
                                    'pattern' => '[A-Za-z0-9]{16}',
                                ]) ?>
                                <p class="setup-hint muted" data-cf-status
                                   data-incomplete="<?= e(__('members.cf_incomplete')) ?>"
                                   data-ready="<?= e(__('members.cf_ready')) ?>"
                                   data-gender-other="<?= e(__('members.cf_gender_other')) ?>"
                                   hidden></p>
                            </div>
                        </details>

                        <details class="setup-section" data-setup-section="residence"<?= $presResidenceFilled ? ' open' : '' ?>>
                            <summary class="setup-section-summary">
                                <span class="setup-section-title"><?= e(__('setup.field_residence')) ?></span>
                                <span class="setup-section-hint muted"><?= e(__('setup.president_section_residence_hint')) ?></span>
                            </summary>
                            <div class="setup-section-body">
                                <?= field('address', [
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
                                    'required_map' => [
                                        'city' => true,
                                        'postal_code' => true,
                                        'address' => true,
                                        'house_number' => true,
                                    ],
                                    'with_scope' => false,
                                    'foreign_toggle' => false,
                                ]) ?>
                            </div>
                        </details>

                        <details class="setup-section" data-setup-section="mandate"<?= $presMandateFilled ? ' open' : '' ?>>
                            <summary class="setup-section-summary">
                                <span class="setup-section-title"><?= e(__('setup.president_section_mandate')) ?></span>
                                <span class="setup-section-hint muted"><?= e(__('setup.president_section_mandate_hint')) ?></span>
                            </summary>
                            <div class="setup-section-body">
                                <div class="setup-equal-row">
                                    <?= field('date', [
                                        'name' => 'appointed_at',
                                        'label' => (string) __('setup.field_appointed_at'),
                                        'value' => (string) ($value['appointed_at'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                        'min' => date('Y-m-d', strtotime('-50 years')),
                                        'max' => date('Y-m-d'),
                                        'attrs' => ['data-appointed-date' => true],
                                    ]) ?>
                                    <?= field('date', [
                                        'name' => 'mandate_ends_at',
                                        'label' => (string) __('setup.field_mandate_ends_at'),
                                        'value' => (string) ($value['mandate_ends_at'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                        'min' => date('Y-m-d', strtotime('+1 day')),
                                        'max' => date('Y-m-d', strtotime('+50 years')),
                                        'attrs' => ['data-mandate-ends-date' => true],
                                    ]) ?>
                                </div>
                            </div>
                        </details>
                    </div>
                <?php elseif ($stepType === 'people_list'): ?>
                    <?php $peopleRole = (string) ($step['role'] ?? ''); ?>
                    <?php $isBoardStep = $peopleRole === 'board'; ?>
                    <?php $showOrganType = $peopleRole === 'auditor'; ?>
                    <?php $showOfficeRole = $isBoardStep; ?>
                    <?php
                    $organTypes = [
                        'organo_controllo_monocratico' => 'setup.organ_controllo_monocratico',
                        'collegio_sindacale' => 'setup.organ_collegio_sindacale',
                        'revisore_legale' => 'setup.organ_revisore_legale',
                        'collegio_probiviri' => 'setup.organ_collegio_probiviri',
                        'odv' => 'setup.organ_odv',
                        'altro' => 'setup.organ_altro',
                    ];
                    $normalizeOrganType = static function (string $raw) use ($organTypes): string {
                        $aliases = [
                            'sindaco_unico' => 'organo_controllo_monocratico',
                            'revisore_conti' => 'revisore_legale',
                            'collegio_revisori' => 'collegio_sindacale',
                        ];
                        $raw = $aliases[$raw] ?? $raw;
                        return array_key_exists($raw, $organTypes) ? $raw : $raw;
                    };
                    $officeRoles = [
                        'vice_president' => 'association.role_vice_president',
                        'secretary' => 'association.role_secretary',
                        'treasurer' => 'association.role_treasurer',
                        'board' => 'association.role_board',
                    ];
                    $peopleRows = $isBoardStep && is_array($value['people'] ?? null)
                        ? (array) $value['people']
                        : (array) $value;
                    $boardPresident = $isBoardStep && is_array($value['president'] ?? null) ? $value['president'] : null;
                    $runtsImport = $isBoardStep && is_array($value['runts_import'] ?? null) ? $value['runts_import'] : [];
                    $cfPlaceholder = __('setup.field_person_fiscal_code_placeholder');
                    $organOptions = [['value' => '', 'label' => '—']];
                    foreach ($organTypes as $organKey => $organLabelKey) {
                        $organOptions[] = ['value' => $organKey, 'label' => (string) __($organLabelKey)];
                    }
                    $officeOptions = [];
                    foreach ($officeRoles as $officeKey => $officeLabelKey) {
                        $officeOptions[] = ['value' => $officeKey, 'label' => (string) __($officeLabelKey)];
                    }
                    ?>
                    <div class="setup-people" data-setup-line data-people-list
                         data-people-role="<?= e($peopleRole) ?>"
                         data-msg-cf-mismatch="<?= e(__('setup.cf_name_mismatch')) ?>"
                         data-msg-cf-underage="<?= e(__('setup.cf_underage')) ?>"
                         data-msg-cf-continue="<?= e(__('setup.cf_continue_anyway')) ?>"
                         data-msg-cf-fix="<?= e(__('setup.cf_fix')) ?>"
                         data-msg-cf-invalid="<?= e(__('validation.fiscal_code')) ?>"
                         data-msg-cf-incomplete="<?= e(__('members.cf_incomplete')) ?>"
                         data-msg-cf-calc="<?= e(__('members.cf_generate')) ?>"
                         data-msg-cf-calc-title="<?= e(__('setup.cf_calc_title')) ?>"
                         data-msg-cf-calc-apply="<?= e(__('setup.cf_calc_apply')) ?>">
                        <?php if ($isBoardStep && is_array($boardPresident) && trim((string) ($boardPresident['first_name'] ?? '')) !== ''): ?>
                            <div class="setup-people-president" data-setup-board-president>
                                <div class="setup-people-president-avatar" aria-hidden="true">
                                    <?= e(mb_strtoupper(mb_substr((string) $boardPresident['first_name'], 0, 1) . mb_substr((string) ($boardPresident['last_name'] ?? ''), 0, 1))) ?>
                                </div>
                                <div class="setup-people-president-body">
                                    <p class="setup-people-president-name">
                                        <?= e(trim((string) $boardPresident['first_name'] . ' ' . (string) ($boardPresident['last_name'] ?? ''))) ?>
                                    </p>
                                    <p class="setup-people-president-meta muted">
                                        <?= e(__('setup.board_president_included')) ?>
                                    </p>
                                </div>
                                <span class="setup-people-president-badge"><?= e(__('setup.board_already_included')) ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($isBoardStep && $runtsImport !== []): ?>
                            <div class="setup-runts-import" data-setup-runts-board-import
                                 data-runts-people="<?= e(json_encode(array_values($runtsImport), JSON_UNESCAPED_UNICODE) ?: '[]') ?>">
                                <p class="setup-runts-import-text">
                                    <?= e(__('setup.board_runts_import_lede', ['count' => (string) count($runtsImport)])) ?>
                                </p>
                                <ul class="setup-runts-import-list">
                                    <?php foreach ($runtsImport as $cand): ?>
                                        <li><?= e(trim((string) ($cand['first_name'] ?? '') . ' ' . (string) ($cand['last_name'] ?? ''))) ?>
                                            <span class="muted">· <?= e((string) ($cand['role_label'] ?? '')) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <button type="button" class="btn btn-ghost btn-sm" data-setup-runts-board-import-btn>
                                    <?= e(__('setup.board_runts_import_btn')) ?>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if ($isBoardStep): ?>
                            <p class="setup-subhead"><?= e(__('setup.board_other_members')) ?></p>
                        <?php endif; ?>

                        <div class="setup-people-rows" data-people-rows>
                            <?php foreach ($peopleRows as $i => $person): ?>
                                <?php if (!is_array($person)) continue; ?>
                                <div class="setup-people-row" data-people-row>
                                    <?php if ($showOrganType): ?>
                                    <?php $organSelected = $normalizeOrganType((string) ($person['organ_type'] ?? $person['notes'] ?? '')); ?>
                                    <?= field('select', [
                                        'name' => 'people[' . (int) $i . '][organ_type]',
                                        'label' => (string) __('setup.field_organ_type'),
                                        'value' => $organSelected,
                                        'context' => 'setup',
                                        'wrapper_class' => 'setup-field-organ-type',
                                        'include_empty' => false,
                                        'options' => $organOptions,
                                    ]) ?>
                                    <?php endif; ?>
                                    <?php if ($showOfficeRole): ?>
                                    <?= field('select', [
                                        'name' => 'people[' . (int) $i . '][office_role]',
                                        'label' => (string) __('setup.field_board_office'),
                                        'value' => (string) ($person['office_role'] ?? 'board'),
                                        'context' => 'setup',
                                        'wrapper_class' => 'setup-field-office-role',
                                        'include_empty' => false,
                                        'options' => $officeOptions,
                                        'attrs' => ['data-people-office' => true],
                                    ]) ?>
                                    <?php endif; ?>
                                    <div class="setup-people-main<?= $showOfficeRole ? ' is-with-office' : '' ?>">
                                        <?= field('text', [
                                            'name' => 'people[' . (int) $i . '][first_name]',
                                            'label' => (string) __('setup.field_first_name'),
                                            'value' => (string) ($person['first_name'] ?? ''),
                                            'context' => 'setup',
                                            'autocomplete' => 'given-name',
                                            'attrs' => ['data-people-first' => true],
                                        ]) ?>
                                        <?= field('text', [
                                            'name' => 'people[' . (int) $i . '][last_name]',
                                            'label' => (string) __('setup.field_last_name'),
                                            'value' => (string) ($person['last_name'] ?? ''),
                                            'context' => 'setup',
                                            'autocomplete' => 'family-name',
                                            'attrs' => ['data-people-last' => true],
                                        ]) ?>
                                        <label class="setup-field setup-field-people-cf">
                                            <span data-people-cf-label><?= e(__('setup.field_person_fiscal_code')) ?><span data-people-cf-star hidden> *</span></span>
                                            <div class="setup-people-cf-row">
                                                <?= field('text', [
                                                    'name' => 'people[' . (int) $i . '][fiscal_code]',
                                                    'label' => '',
                                                    'value' => (string) ($person['fiscal_code'] ?? ''),
                                                    'context' => 'inline',
                                                    'wrapper_class' => 'sf-field--people-cf-inline',
                                                    'maxlength' => 16,
                                                    'minlength' => 16,
                                                    'pattern' => '[A-Za-z]{6}[0-9LmnpqrstuvLMNPQRSTUV]{2}[A-Ea-eHhLlMmPpRrSsTt][0-9LmnpqrstuvLMNPQRSTUV]{2}[A-Za-z][0-9LmnpqrstuvLMNPQRSTUV]{3}[A-Za-z]',
                                                    'placeholder' => $cfPlaceholder,
                                                    'attrs' => [
                                                        'data-people-cf' => true,
                                                        'autocomplete' => 'off',
                                                        'title' => (string) __('validation.fiscal_code'),
                                                    ],
                                                ]) ?>
                                                <button type="button" class="btn btn-ghost btn-sm" data-people-cf-calc><?= e(__('members.cf_generate')) ?></button>
                                                <button type="button" class="btn btn-ghost btn-sm setup-people-remove" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-ghost" data-people-add data-i18n="setup.add_person"><?= e(__('setup.add_person')) ?></button>
                        <template data-people-template>
                            <div class="setup-people-row" data-people-row>
                                <?php if ($showOrganType): ?>
                                <?= field('select', [
                                    'name' => 'people[__i__][organ_type]',
                                    'label' => (string) __('setup.field_organ_type'),
                                    'value' => '',
                                    'context' => 'setup',
                                    'wrapper_class' => 'setup-field-organ-type',
                                    'include_empty' => false,
                                    'options' => $organOptions,
                                ]) ?>
                                <?php endif; ?>
                                <?php if ($showOfficeRole): ?>
                                <?= field('select', [
                                    'name' => 'people[__i__][office_role]',
                                    'label' => (string) __('setup.field_board_office'),
                                    'value' => 'board',
                                    'context' => 'setup',
                                    'wrapper_class' => 'setup-field-office-role',
                                    'include_empty' => false,
                                    'options' => $officeOptions,
                                    'attrs' => ['data-people-office' => true],
                                ]) ?>
                                <?php endif; ?>
                                <div class="setup-people-main<?= $showOfficeRole ? ' is-with-office' : '' ?>">
                                    <?= field('text', [
                                        'name' => 'people[__i__][first_name]',
                                        'label' => (string) __('setup.field_first_name'),
                                        'value' => '',
                                        'context' => 'setup',
                                        'autocomplete' => 'given-name',
                                        'attrs' => ['data-people-first' => true],
                                    ]) ?>
                                    <?= field('text', [
                                        'name' => 'people[__i__][last_name]',
                                        'label' => (string) __('setup.field_last_name'),
                                        'value' => '',
                                        'context' => 'setup',
                                        'autocomplete' => 'family-name',
                                        'attrs' => ['data-people-last' => true],
                                    ]) ?>
                                    <label class="setup-field setup-field-people-cf">
                                        <span data-people-cf-label><?= e(__('setup.field_person_fiscal_code')) ?><span data-people-cf-star hidden> *</span></span>
                                        <div class="setup-people-cf-row">
                                            <?= field('text', [
                                                'name' => 'people[__i__][fiscal_code]',
                                                'label' => '',
                                                'value' => '',
                                                'context' => 'inline',
                                                'wrapper_class' => 'sf-field--people-cf-inline',
                                                'maxlength' => 16,
                                                'minlength' => 16,
                                                'pattern' => '[A-Za-z]{6}[0-9LmnpqrstuvLMNPQRSTUV]{2}[A-Ea-eHhLlMmPpRrSsTt][0-9LmnpqrstuvLMNPQRSTUV]{2}[A-Za-z][0-9LmnpqrstuvLMNPQRSTUV]{3}[A-Za-z]',
                                                'placeholder' => $cfPlaceholder,
                                                'attrs' => [
                                                    'data-people-cf' => true,
                                                    'autocomplete' => 'off',
                                                    'title' => (string) __('validation.fiscal_code'),
                                                ],
                                            ]) ?>
                                            <button type="button" class="btn btn-ghost btn-sm" data-people-cf-calc><?= e(__('members.cf_generate')) ?></button>
                                            <button type="button" class="btn btn-ghost btn-sm setup-people-remove" data-people-remove aria-label="<?= e(__('setup.remove_person')) ?>">×</button>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </template>
                    </div>
                <?php elseif ($stepType === 'website'): ?>
                    <?php
                    $siteName = trim((string) ($assocName ?? ''));
                    $siteLegal = trim((string) ($assocLegal ?? ''));
                    ?>
                    <?= field('text', [
                        'name' => 'value',
                        'label' => (string) __('setup.field_website'),
                        'value' => (string) $value,
                        'context' => 'setup',
                        'wrapper_class' => 'setup-field',
                        'placeholder' => (string) __('setup.website_placeholder'),
                        'autocomplete' => 'url',
                        'inputmode' => 'url',
                        'attrs' => [
                            'data-setup-line' => true,
                            'data-setup-website-input' => true,
                            'spellcheck' => 'false',
                        ],
                    ]) ?>
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
                                    <?= field('file', [
                                        'name' => '',
                                        'as_button' => false,
                                        'accept' => 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml',
                                        'input_class' => 'setup-scrape-logo-file',
                                        'context' => 'inline',
                                        'label' => '',
                                        'attrs' => ['data-setup-scrape-logo-input' => true],
                                    ]) ?>
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
                    <?php
                        $setupLocaleCardOptions = [];
                        foreach ($step['options'] as $opt) {
                            $optVal = (string) ($opt['value'] ?? '');
                            $labelKey = (string) ($opt['label_key'] ?? '');
                            $setupLocaleCardOptions[] = [
                                'value' => $optVal,
                                'label' => $labelKey !== '' ? (string) __($labelKey) : $optVal,
                                'flag' => locale_flag_url($optVal),
                                'label_attrs' => $labelKey !== '' ? ['data-i18n' => $labelKey] : [],
                            ];
                        }
                    ?>
                    <fieldset data-setup-line data-setup-locale-picker>
                        <legend class="setup-field-label" data-i18n="<?= e((string) ($step['title_key'] ?? '')) ?>"><?= e(__($step['title_key'])) ?></legend>
                        <?= field('locale_cards', [
                            'name' => 'value',
                            'value' => (string) $value,
                            'options' => $setupLocaleCardOptions,
                            'attrs' => ['data-setup-locale-radio' => true],
                            'required' => true,
                        ]) ?>
                    </fieldset>
                    <?php elseif (($step['key'] ?? '') === 'membership.enrollment_validation'): ?>
                    <div data-setup-line>
                        <?= view_partial('partials/enrollment_method_picker', [
                            'name' => 'value',
                            'value' => (string) $value,
                            'mailReady' => $mailReadyForSelect,
                            'mailSetupHref' => url('/setup?step_key=mail.smtp'),
                            'showFieldLabel' => false,
                        ]) ?>
                    </div>
                    <?php else: ?>
                    <?php
                    $genericSelectOptions = [];
                    foreach ($step['options'] as $opt) {
                        $genericSelectOptions[] = [
                            'value' => (string) ($opt['value'] ?? ''),
                            'label' => (string) __($opt['label_key']),
                        ];
                    }
                    ?>
                    <?= field('select', [
                        'name' => 'value',
                        'label' => (string) __($step['title_key']),
                        'value' => (string) $value,
                        'required' => true,
                        'context' => 'setup',
                        'include_empty' => false,
                        'options' => $genericSelectOptions,
                        'wrap_attrs' => ['data-setup-line' => true],
                    ]) ?>
                    <?php endif; ?>
                <?php elseif ($stepType === 'checkbox'): ?>
                    <?php if (($step['key'] ?? '') === 'gdpr.enabled'): ?>
                        <?php
                        $gdprEnabled = is_array($value) ? !empty($value['enabled']) : !empty($value);
                        $gdprImage = is_array($value)
                            ? !empty($value['require_image_consent'])
                            : true;
                        $gdprComms = is_array($value)
                            ? !empty($value['require_comms_consent'])
                            : true;
                        ?>
                        <div class="setup-gdpr-options" data-setup-line data-setup-gdpr-options>
                            <?= field('checkbox', [
                                'name' => 'value',
                                'label' => (string) __('setup.gdpr_label'),
                                'checked' => $gdprEnabled,
                                'context' => 'setup',
                                'label_class' => 'checkbox-row setup-check setup-check-prominent',
                                'attrs' => ['data-gdpr-master' => true, 'data-i18n' => 'setup.gdpr_label'],
                            ]) ?>
                            <p class="setup-gdpr-model-note" data-gdpr-extras>
                                <span class="setup-gdpr-model-note-icon" aria-hidden="true">ℹ</span>
                                <span data-i18n="setup.gdpr_model_note"><?= e(__('setup.gdpr_model_note')) ?></span>
                            </p>
                            <div class="setup-gdpr-extras" data-gdpr-extras>
                                <?= field('checkbox', [
                                    'name' => 'require_image_consent',
                                    'label' => (string) __('setup.gdpr_image_consent'),
                                    'checked' => $gdprImage,
                                    'context' => 'setup',
                                    'label_class' => 'checkbox-row setup-check',
                                    'attrs' => ['data-gdpr-extra' => true, 'data-i18n' => 'setup.gdpr_image_consent'],
                                ]) ?>
                                <?= field('checkbox', [
                                    'name' => 'require_comms_consent',
                                    'label' => (string) __('setup.gdpr_comms_consent'),
                                    'checked' => $gdprComms,
                                    'context' => 'setup',
                                    'label_class' => 'checkbox-row setup-check',
                                    'attrs' => ['data-gdpr-extra' => true, 'data-i18n' => 'setup.gdpr_comms_consent'],
                                ]) ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <?= field('checkbox', [
                            'name' => 'value',
                            'label' => (string) __('setup.gdpr_label'),
                            'checked' => !empty($value),
                            'context' => 'setup',
                            'label_class' => 'checkbox-row setup-check',
                            'wrap_attrs' => ['data-setup-line' => true],
                            'attrs' => ['data-i18n' => 'setup.gdpr_label'],
                        ]) ?>
                    <?php endif; ?>
                <?php elseif ($stepType === 'textarea'): ?>
                    <?php
                    $isPrivacyStep = ($step['key'] ?? '') === 'legal.privacy';
                    $legalPrefix = $isPrivacyStep ? 'privacy' : 'statute';
                    $legalValues = is_array($value) ? $value : ['it' => (string) $value, 'de' => '', 'en' => ''];
                    $showRuntsBanner = !empty($legalValues['_from_runts'])
                        || preg_match('/^\[Bozza da PDF RUNTS/u', (string) ($legalValues['it'] ?? '')) === 1;
                    unset($legalValues['_from_runts']);
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
                        <?= field('file', [
                            'name' => '',
                            'button_label' => (string) __('setup.legal_pdf_upload'),
                            'accept' => 'application/pdf,.pdf',
                            'context' => 'setup',
                            'button_class' => 'btn btn-ghost file-btn',
                            'pick_label_attrs' => ['data-i18n' => 'setup.legal_pdf_upload'],
                            'attrs' => ['data-setup-legal-pdf-input' => true],
                        ]) ?>
                        <p class="setup-hint muted" data-setup-legal-pdf-status hidden></p>
                    </div>
                    <div data-setup-line>
                        <?= view_partial('partials/legal_doc_editor', [
                            'namePrefix' => $legalPrefix,
                            'values' => $legalValues,
                            'placeholder' => (string) $legalPlaceholder,
                            'showRuntsBanner' => $showRuntsBanner,
                        ]) ?>
                    </div>
                    <p class="setup-hint muted" data-setup-line data-i18n="setup.legal_min_chars_hint"><?= e(__('setup.legal_min_chars_hint', ['min' => '50'])) ?></p>
                <?php elseif ($stepType === 'member_types'): ?>
                    <?php
                    $types = is_array($value['types'] ?? null) ? $value['types'] : [];
                    $draftNew = is_array($value['new_types'] ?? null) ? $value['new_types'] : null;
                    $singleType = !empty($value['single_type']) || count($types) === 1;
                    $currencyCode = (string) ($value['currency'] ?? 'EUR');
                    $currencyDisplay = (string) ($value['currency_display'] ?? '€');
                    $langFlags = [
                        'it' => ['flag' => locale_flag_url('it'), 'label' => 'Italiano'],
                        'de' => ['flag' => locale_flag_url('de'), 'label' => 'Deutsch'],
                        'en' => ['flag' => locale_flag_url('en'), 'label' => 'English'],
                    ];
                    if ($draftNew === null) {
                        $draftNew = $types === []
                            ? [[
                                'name_it' => (string) ($value['name_it'] ?? 'Ordinaria'),
                                'name_de' => (string) ($value['name_de'] ?? 'Ordentlich'),
                                'name_en' => (string) ($value['name_en'] ?? 'Ordinary'),
                                'price' => (string) ($value['price'] ?? '0'),
                                'is_active' => true,
                            ]]
                            : [[
                                'name_it' => (string) ($value['name_it'] ?? ''),
                                'name_de' => (string) ($value['name_de'] ?? ''),
                                'name_en' => (string) ($value['name_en'] ?? ''),
                                'price' => (string) ($value['price'] ?? ''),
                                'is_active' => array_key_exists('is_active', $value) ? !empty($value['is_active']) : true,
                            ]];
                        // When editing existing types, start with one empty "add" card only if draft empty names.
                        if ($types !== [] && trim((string) ($draftNew[0]['name_it'] ?? '')) === '' && trim((string) ($draftNew[0]['price'] ?? '')) === '') {
                            // keep one empty card for adding
                        }
                    }
                    if ($draftNew === []) {
                        $draftNew = [['name_it' => '', 'name_de' => '', 'name_en' => '', 'price' => '', 'is_active' => true]];
                    }
                    ?>
                    <div class="setup-membership" data-setup-line data-setup-member-types
                         data-translate-url="<?= e(url('/api/translate')) ?>"
                         data-currency="<?= e($currencyCode) ?>"
                         data-currency-display="<?= e($currencyDisplay) ?>"
                         data-msg-free-title="<?= e(__('setup.type_free_confirm')) ?>"
                         data-msg-free-confirm="<?= e(__('setup.type_free_confirm_ok')) ?>"
                         data-msg-free-cancel="<?= e(__('common.cancel')) ?>"
                         data-has-existing="<?= $types !== [] ? '1' : '0' ?>">
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
                                                <?= view_partial('partials/member_type_field', [
                                                    'kind' => 'name',
                                                    'name' => 'types[' . $tid . '][name_' . $langCode . ']',
                                                    'value' => (string) ($typeRow['name_' . $langCode] ?? ''),
                                                    'required' => $langCode === 'it',
                                                    'langCode' => $langCode,
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
                                <?php
                                $ni = (int) $ni;
                                $requireNew = $types === [];
                                ?>
                                <div class="setup-membership-card setup-membership-card-new" data-member-type-new-row>
                                    <div class="setup-membership-card-head">
                                        <button type="button" class="btn btn-ghost btn-sm setup-membership-remove" data-member-type-remove aria-label="<?= e(__('setup.remove_type')) ?>" <?= ($requireNew && count($draftNew) <= 1) ? 'hidden' : '' ?>>×</button>
                                    </div>
                                    <div class="setup-equal-row setup-langs-row">
                                        <?php foreach ($langFlags as $langCode => $langMeta): ?>
                                        <label class="setup-field">
                                            <span class="setup-lang-flag-label">
                                                <img src="<?= e($langMeta['flag']) ?>" width="18" height="14" alt="" loading="lazy" decoding="async">
                                                <?= e($langMeta['label']) ?><?= $langCode === 'it' && $requireNew ? ' *' : '' ?>
                                            </span>
                                            <?= view_partial('partials/member_type_field', [
                                                'kind' => 'name',
                                                'name' => 'new_types[' . $ni . '][name_' . $langCode . ']',
                                                'value' => (string) ($newRow['name_' . $langCode] ?? ''),
                                                'required' => $langCode === 'it' && $requireNew,
                                                'langCode' => $langCode,
                                                'placeholder' => (string) __('setup.type_name_placeholder'),
                                            ]) ?>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="setup-equal-row">
                                        <label class="setup-field">
                                            <span><?= e(__('install.type_price')) ?><?= $requireNew ? ' *' : '' ?></span>
                                            <div class="setup-price-with-currency">
                                                <?= view_partial('partials/member_type_field', [
                                                    'kind' => 'price',
                                                    'name' => 'new_types[' . $ni . '][price]',
                                                    'value' => (string) ($newRow['price'] ?? ''),
                                                    'required' => $requireNew,
                                                    'currencyDisplay' => $currencyDisplay,
                                                    'typeNameRef' => 'new_types[' . $ni . '][name_it]',
                                                ]) ?>
                                                <span class="setup-price-currency" aria-hidden="true"><?= e($currencyDisplay) ?></span>
                                            </div>
                                        </label>
                                        <?php if ($types !== []): ?>
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
                                    <?php foreach ($langFlags as $langCode => $langMeta): ?>
                                    <label class="setup-field">
                                        <span class="setup-lang-flag-label">
                                            <img src="<?= e($langMeta['flag']) ?>" width="18" height="14" alt="" loading="lazy" decoding="async">
                                            <?= e($langMeta['label']) ?>
                                        </span>
                                        <?= view_partial('partials/member_type_field', [
                                            'kind' => 'name',
                                            'name' => 'new_types[__i__][name_' . $langCode . ']',
                                            'value' => '',
                                            'langCode' => $langCode,
                                            'placeholder' => (string) __('setup.type_name_placeholder'),
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
                                    <?php if ($types !== []): ?>
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
                    </div>
                <?php elseif ($stepType === 'membership_periods'): ?>
                    <?php
                    $periods = is_array($value['periods'] ?? null) ? $value['periods'] : [];
                    $requireNewPeriod = $periods === [] || !empty($value['needs_current_year']);
                    ?>
                    <div class="setup-membership" data-setup-line data-setup-membership-periods
                         data-msg-next-period="<?= e(__('setup.periods_next_period_auto')) ?>">
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
                                            <?= field('date', [
                                                'name' => 'periods[' . $pid . '][starts_on]',
                                                'label' => (string) __('install.starts_on'),
                                                'value' => (string) ($period['starts_on'] ?? ''),
                                                'required' => true,
                                                'context' => 'setup',
                                                'attrs' => ['data-period-start' => true],
                                            ]) ?>
                                            <?= field('date', [
                                                'name' => 'periods[' . $pid . '][ends_on]',
                                                'label' => (string) __('install.ends_on'),
                                                'value' => (string) ($period['ends_on'] ?? ''),
                                                'required' => true,
                                                'context' => 'setup',
                                                'attrs' => ['data-period-end' => true],
                                            ]) ?>
                                        </div>
                                        <?php if (!empty($period['is_current'])): ?>
                                            <?= field('hidden', ['name' => 'periods[' . $pid . '][is_current]', 'value' => '1']) ?>
                                            <?php if (!empty($value['next_period_starts_on']) && !empty($value['next_period_ends_on'])): ?>
                                                <p class="setup-period-next muted" data-setup-next-period>
                                                    <?= e(__('setup.periods_next_period', [
                                                        'from' => date('d/m/Y', strtotime((string) $value['next_period_starts_on'])),
                                                        'to' => date('d/m/Y', strtotime((string) $value['next_period_ends_on'])),
                                                    ])) ?>
                                                </p>
                                            <?php endif; ?>
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
                                <?= field('date', [
                                    'name' => 'starts_on',
                                    'label' => (string) __('install.starts_on'),
                                    'value' => (string) ($value['starts_on'] ?? ''),
                                    'required' => $requireNewPeriod,
                                    'context' => 'setup',
                                    'attrs' => ['data-period-start' => true],
                                ]) ?>
                                <?= field('date', [
                                    'name' => 'ends_on',
                                    'label' => (string) __('install.ends_on'),
                                    'value' => (string) ($value['ends_on'] ?? ''),
                                    'required' => $requireNewPeriod,
                                    'context' => 'setup',
                                    'attrs' => ['data-period-end' => true],
                                ]) ?>
                            </div>
                            <?php if ($requireNewPeriod): ?>
                                <?= field('hidden', ['name' => 'is_current', 'value' => '1']) ?>
                            <?php endif; ?>
                            <p class="setup-period-next muted" data-setup-next-period-preview hidden></p>
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
                            'wizardMode' => true,
                            'autosaveUrl' => url('/setup/fields/autosave'),
                        ]) ?>

                        <div class="setup-membership-card setup-membership-card-new setup-fields-add">
                            <h3 class="setup-subhead"><?= e(__('settings.add_field')) ?></h3>
                            <div class="setup-equal-row">
                                <?= field('text', [
                                    'name' => 'new_label',
                                    'label' => (string) __('setup.fields_new_label'),
                                    'value' => (string) ($value['new_label'] ?? ''),
                                    'context' => 'setup',
                                    'wrapper_class' => 'setup-field-grow',
                                    'placeholder' => (string) __('setup.fields_new_label_ph'),
                                    'attrs' => ['data-new-field-label' => true],
                                ]) ?>
                                <?php
                                $newFieldTypeOptions = [];
                                foreach ($typeOptions as $opt) {
                                    $newFieldTypeOptions[] = ['value' => $opt, 'label' => \Socly\Support\MemberFieldTypes::label($opt)];
                                }
                                ?>
                                <?= field('select', [
                                    'name' => 'new_type',
                                    'label' => (string) __('setup.fields_col_type'),
                                    'value' => (string) ($value['new_type'] ?? 'text'),
                                    'required' => true,
                                    'context' => 'setup',
                                    'include_empty' => false,
                                    'options' => $newFieldTypeOptions,
                                    'attrs' => ['data-new-field-type' => true],
                                ]) ?>
                            </div>
                            <div class="setup-equal-row">
                                <?= field('checkbox', [
                                    'name' => 'new_enabled',
                                    'label' => (string) __('setup.fields_col_enabled'),
                                    'checked' => !empty($value['new_enabled']),
                                    'context' => 'setup',
                                    'label_class' => 'checkbox-row setup-check setup-check-inline',
                                    'attrs' => ['data-new-field-enabled' => true],
                                ]) ?>
                                <?= field('checkbox', [
                                    'name' => 'new_required',
                                    'label' => (string) __('setup.fields_col_required'),
                                    'checked' => !empty($value['new_required']),
                                    'context' => 'setup',
                                    'label_class' => 'checkbox-row setup-check setup-check-inline',
                                    'attrs' => ['data-new-field-required' => true],
                                ]) ?>
                            </div>
                            <button
                                type="button"
                                class="btn btn-ghost setup-fields-add-now-btn"
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
                        data-test-ok-to="<?= e(__('mail.test_ok_to')) ?>"
                        data-verify-fail="<?= e(__('mail.verify_fail')) ?>"
                        data-verify-busy="<?= e(__('setup.mail_verify_busy')) ?>"
                        data-password-required="<?= e(__('mail.password_required')) ?>"
                        data-email-invalid="<?= e(__('validation.email')) ?>"
                        data-has-password="<?= !empty($value['has_password']) ? '1' : '0' ?>"
                    >
                        <?= field('checkbox', [
                            'name' => 'outbound_disabled',
                            'label' => (string) __('setup.mail_skip_outbound'),
                            'checked' => $mailSkipped,
                            'context' => 'setup',
                            'label_class' => 'checkbox-row setup-check setup-smtp-skip',
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
                                        'value' => (string) ($value['from_address'] ?? ''),
                                        'required' => !$mailSkipped,
                                        'disabled' => $mailSkipped,
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
                                        'required' => !$mailSkipped && empty($value['has_password']),
                                        'disabled' => $mailSkipped,
                                        'placeholder' => !empty($value['has_password']) ? (string) __('mail.password_keep') : '',
                                        'autocomplete' => 'new-password',
                                        'context' => 'setup',
                                        'input_attrs' => 'data-smtp-password' . ($mailSkipped ? ' disabled' : ''),
                                        'hint_attrs' => 'data-smtp-password-hint',
                                    ]) ?>
                                </div>
                                <div class="setup-smtp-discover-row" data-smtp-discover-row>
                                    <button type="button" class="btn btn-ghost setup-smtp-discover-btn" data-smtp-discover-btn>
                                        <span data-smtp-discover-label><?= e(__('setup.mail_discover_btn')) ?></span>
                                    </button>
                                </div>
                                <div class="setup-smtp-live" data-smtp-live hidden>
                                    <div class="setup-scrape-spinner" aria-hidden="true"></div>
                                    <p class="setup-hint muted setup-smtp-live-status setup-smtp-feedback" data-smtp-discover-status hidden role="status" aria-live="polite"></p>
                                </div>
                            </div>

                            <div class="setup-smtp-manual" data-smtp-manual <?= $showManual ? '' : 'hidden' ?>>
                                <p class="setup-smtp-manual-title"><?= e(__('setup.mail_manual_title')) ?></p>
                                <p class="setup-hint muted"><?= e(__('setup.mail_manual_hint')) ?></p>
                                <div class="setup-equal-row">
                                    <?= field('text', [
                                        'name' => 'host',
                                        'label' => (string) __('mail.host'),
                                        'value' => (string) ($value['host'] ?? ''),
                                        'required' => true,
                                        'context' => 'setup',
                                        'placeholder' => 'smtp.example.com',
                                        'autocomplete' => 'off',
                                        'attrs' => ['data-smtp-host' => true],
                                    ]) ?>
                                    <?= field('number', [
                                        'name' => 'port',
                                        'label' => (string) __('mail.port'),
                                        'value' => (string) ($value['port'] ?? '587'),
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
                                    'value' => (string) ($value['username'] ?? ''),
                                    'required' => true,
                                    'context' => 'setup',
                                    'autocomplete' => 'off',
                                    'placeholder' => (string) __('mail.username_default_hint'),
                                    'attrs' => ['data-smtp-username' => true],
                                ]) ?>
                                <?= field('text', [
                                    'name' => 'from_name',
                                    'label' => (string) __('mail.from_name'),
                                    'value' => (string) ($value['from_name'] ?? ''),
                                    'context' => 'setup',
                                    'autocomplete' => 'organization',
                                    'attrs' => ['data-smtp-from-name' => true],
                                ]) ?>
                                <div class="setup-smtp-actions">
                                    <button type="button" class="btn btn-ghost" data-smtp-verify-btn><?= e(__('setup.mail_verify_btn')) ?></button>
                                </div>
                                <p class="setup-hint muted setup-smtp-feedback" data-smtp-verify-status hidden role="status" aria-live="polite"></p>
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
                                <p class="setup-hint muted setup-smtp-feedback" data-smtp-test-status <?= $mailReady ? '' : 'hidden' ?> role="status" aria-live="polite"><?= $mailReady ? e(__('mail.test_ok_badge')) : '' ?></p>
                                <p class="setup-hint muted"><?= e(__('setup.mail_save_hint')) ?></p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($stepType === 'component_select'): ?>
                    <?php
                        $components = is_array($value['components'] ?? null) ? $value['components'] : [];
                        $coreKeys = ['members' => 0, 'org_roles' => 1];
                        usort($components, static function (array $a, array $b) use ($coreKeys): int {
                            $aKey = (string) ($a['key'] ?? '');
                            $bKey = (string) ($b['key'] ?? '');
                            $aOrd = $coreKeys[$aKey] ?? 100;
                            $bOrd = $coreKeys[$bKey] ?? 100;
                            if ($aOrd !== $bOrd) {
                                return $aOrd <=> $bOrd;
                            }
                            return 0;
                        });
                    ?>
                    <div class="setup-components" data-setup-line>
                        <p class="setup-hint muted"><?= e(__('setup.step_components_hint')) ?></p>
                        <div class="setup-component-list">
                            <?php foreach ($components as $component): ?>
                                <?php
                                    $cKey = (string) ($component['key'] ?? '');
                                    $locked = isset($coreKeys[$cKey]);
                                    $checked = $locked || !empty($component['enabled']);
                                ?>
                                <?php if ($locked): ?>
                                    <div class="setup-component-row is-selected setup-component-row-core" data-component-core>
                                        <span class="setup-component-core-mark" aria-hidden="true">✓</span>
                                        <?= field('hidden', ['name' => 'components[]', 'value' => $cKey]) ?>
                                        <span class="setup-component-row-body">
                                            <span class="setup-component-row-top">
                                                <strong class="setup-component-name"><?= e(__((string) ($component['name'] ?? ''))) ?></strong>
                                                <span class="setup-component-core-badge"><?= e(__('components.core_badge')) ?></span>
                                            </span>
                                            <span class="setup-component-desc"><?= e(__((string) ($component['description'] ?? ''))) ?></span>
                                        </span>
                                    </div>
                                <?php else: ?>
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
                                                <strong class="setup-component-name"><?= e(__((string) ($component['name'] ?? ''))) ?></strong>
                                                <span class="setup-component-price"><?= e(__('components.price_included')) ?></span>
                                            </span>
                                            <span class="setup-component-desc"><?= e(__((string) ($component['description'] ?? ''))) ?></span>
                                        </span>
                                    </label>
                                <?php endif; ?>
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
                    <?php
                        $presFirst = trim((string) ($value['president_first_placeholder'] ?? ''));
                        $presLast = trim((string) ($value['president_last_placeholder'] ?? ''));
                        $confirmPhFirst = $presFirst !== ''
                            ? __('setup.platform_confirm_ph_first', ['name' => $presFirst])
                            : __('setup.platform_confirm_first');
                        $confirmPhLast = $presLast !== ''
                            ? __('setup.platform_confirm_ph_last', ['name' => $presLast])
                            : __('setup.platform_confirm_last');
                    ?>
                    <div
                        class="setup-platform-consents"
                        data-setup-line
                        data-platform-consents
                        data-mail-ready="<?= $mailReady ? '1' : '0' ?>"
                        data-president-first="<?= e($presFirst) ?>"
                        data-president-last="<?= e($presLast) ?>"
                        data-msg-required="<?= e(__('setup.platform_confirm_required')) ?>"
                        data-msg-mismatch="<?= e(__('setup.platform_confirm_mismatch')) ?>"
                    >
                        <p class="setup-hint muted setup-platform-intro"><?= e(__('setup.platform_hint')) ?></p>
                        <?= field('checkbox', [
                            'name' => 'news_opt_in',
                            'label' => (string) __('setup.platform_news'),
                            'checked' => !empty($value['news_opt_in']),
                            'context' => 'setup',
                            'label_class' => 'checkbox-row setup-check',
                            'attrs' => ['data-platform-opt' => true],
                        ]) ?>
                        <?= field('checkbox', [
                            'name' => 'usage_stats_opt_in',
                            'label' => (string) __('setup.platform_stats'),
                            'checked' => !empty($value['usage_stats_opt_in']),
                            'context' => 'setup',
                            'label_class' => 'checkbox-row setup-check',
                            'attrs' => ['data-platform-opt' => true],
                        ]) ?>
                        <?= field('checkbox', [
                            'name' => 'showcase_consent',
                            'label' => (string) __('setup.platform_showcase'),
                            'checked' => !empty($value['showcase_consent']),
                            'context' => 'setup',
                            'label_class' => 'checkbox-row setup-check',
                            'attrs' => ['data-platform-opt' => true],
                        ]) ?>
                        <p class="setup-hint muted setup-platform-showcase-note"><?= e(__('setup.platform_showcase_hint')) ?></p>
                        <div class="setup-platform-confirm" data-platform-confirm <?= $anyPlatform ? '' : 'hidden' ?>>
                            <p class="setup-hint setup-platform-confirm-lede"><?= e(__('setup.platform_confirm_hint')) ?></p>
                            <div class="setup-equal-row">
                                <?= field('text', [
                                    'name' => 'confirm_first_name',
                                    'label' => (string) __('setup.platform_confirm_first'),
                                    'value' => '',
                                    'required' => $anyPlatform,
                                    'context' => 'setup',
                                    'autocomplete' => 'off',
                                    'placeholder' => $confirmPhFirst,
                                    'attrs' => ['data-platform-confirm-input' => true],
                                ]) ?>
                                <?= field('text', [
                                    'name' => 'confirm_last_name',
                                    'label' => (string) __('setup.platform_confirm_last'),
                                    'value' => '',
                                    'required' => $anyPlatform,
                                    'context' => 'setup',
                                    'autocomplete' => 'off',
                                    'placeholder' => $confirmPhLast,
                                    'attrs' => ['data-platform-confirm-input' => true],
                                ]) ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($stepType === 'admin_account'): ?>
                    <div class="setup-admin-account" data-setup-line>
                        <p class="setup-hint muted"><?= e(__('setup.admin_access_hint')) ?></p>
                        <?= field('email', [
                            'name' => 'email',
                            'label' => (string) __('setup.field_admin_email'),
                            'value' => (string) ($value['email'] ?? ''),
                            'required' => true,
                            'context' => 'setup',
                            'autocomplete' => 'email',
                        ]) ?>
                        <div class="setup-equal-row">
                            <?= field('password', [
                                'name' => 'password',
                                'label' => (string) __('setup.field_admin_password'),
                                'required' => true,
                                'autocomplete' => 'new-password',
                                'context' => 'setup',
                                'minlength' => 8,
                            ]) ?>
                            <?= field('password', [
                                'name' => 'password_confirmation',
                                'label' => (string) __('setup.field_admin_password_confirmation'),
                                'required' => true,
                                'autocomplete' => 'new-password',
                                'context' => 'setup',
                                'minlength' => 8,
                            ]) ?>
                        </div>
                        <div class="setup-password-tools">
                            <button type="button" class="btn btn-ghost btn-sm" data-password-generate><?= e(__('setup.admin_password_generate')) ?></button>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm setup-password-copy-btn"
                                data-password-copy
                                hidden
                                data-label="<?= e(__('setup.admin_password_copy')) ?>"
                                data-copied="<?= e(__('setup.admin_password_copied')) ?>"
                            ><?= e(__('setup.admin_password_copy')) ?></button>
                            <span class="setup-hint muted" data-password-copy-status hidden></span>
                            <span class="setup-hint muted" data-password-rules-hint><?= e(__('setup.admin_password_rules')) ?></span>
                        </div>
                        <div class="setup-password-strength">
                            <p class="setup-field-label"><?= e(__('setup.admin_password_strength')) ?></p>
                            <div class="password-complexity" data-password-complexity aria-live="polite">
                                <span></span><span></span><span></span><span></span>
                            </div>
                        </div>
                        <label class="setup-field setup-admin-locale" data-setup-admin-locale>
                            <span><?= e(__('setup.field_admin_locale')) ?></span>
                            <?= field('locale_cards', [
                                'name' => 'locale',
                                'value' => (string) ($value['locale'] ?? 'it'),
                                'locales' => ['it', 'de', 'en'],
                                'required' => true,
                                'grid_class' => 'setup-locale-grid-compact',
                            ]) ?>
                        </label>
                    </div>
                <?php else: ?>
                    <?php
                    $fallbackType = $stepType === 'email' ? 'email' : ($stepType === 'tel' ? 'phone' : 'text');
                    $fallbackProps = [
                        'name' => 'value',
                        'label' => (string) __($step['title_key']),
                        'value' => (string) $value,
                        'required' => !empty($step['required']),
                        'context' => 'setup',
                        'autocomplete' => 'off',
                        'wrap_attrs' => ['data-setup-line' => true],
                    ];
                    echo field($fallbackType, $fallbackProps);
                    ?>
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

                </div><!-- .setup-form-scroll -->

                <?php if (in_array($stepType, ['president', 'people_list', 'textarea', 'smtp_config'], true)): ?>
                    <?php
                    $isAuditorsDefer = $stepType === 'people_list' && (string) ($step['role'] ?? '') === 'auditor';
                    $deferLabelKey = $isAuditorsDefer ? 'setup.defer_step_skip' : 'setup.defer_step';
                    ?>
                    <p class="setup-defer-wrap" data-setup-line>
                        <button type="button" class="setup-defer-link" data-setup-defer-step data-i18n="<?= e($deferLabelKey) ?>"><?= e(__($deferLabelKey)) ?></button>
                        <?php if ($isAuditorsDefer): ?>
                            <span class="setup-defer-sep" aria-hidden="true"> · </span>
                            <button type="button" class="setup-defer-link" data-setup-defer-step data-i18n="setup.defer_step"><?= e(__('setup.defer_step')) ?></button>
                        <?php endif; ?>
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

    <dialog class="setup-exit-dialog setup-cf-calc-dialog" data-setup-people-cf-dialog
            data-cities-url="<?= e(url('/api/geo/cities')) ?>"
            data-addresses-url="<?= e(url('/api/geo/addresses')) ?>"
            data-cap-url="<?= e(url('/api/geo/cap')) ?>"
            data-provinces-url="<?= e(url('/api/geo/provinces')) ?>">
        <div class="setup-exit-shell setup-cf-calc-shell">
            <p class="setup-exit-text" data-setup-people-cf-title><?= e(__('setup.cf_calc_title')) ?></p>
            <div class="setup-cf-calc-fields" data-setup-people-cf-form>
                <div class="setup-equal-row">
                    <?= field('text', [
                        'name' => 'first_name',
                        'label' => (string) __('setup.field_first_name'),
                        'value' => '',
                        'required' => true,
                        'context' => 'setup',
                        'autocomplete' => 'given-name',
                        'attrs' => ['data-cf-dialog-first' => true],
                    ]) ?>
                    <?= field('text', [
                        'name' => 'last_name',
                        'label' => (string) __('setup.field_last_name'),
                        'value' => '',
                        'required' => true,
                        'context' => 'setup',
                        'autocomplete' => 'family-name',
                        'attrs' => ['data-cf-dialog-last' => true],
                    ]) ?>
                </div>
                <div class="setup-equal-row">
                    <?= field('gender', [
                        'name' => 'gender',
                        'label' => (string) __('setup.field_gender'),
                        'value' => '',
                        'required' => true,
                        'context' => 'setup',
                        'attrs' => ['data-cf-dialog-gender' => true],
                    ]) ?>
                    <?= field('birth_date', [
                        'name' => 'birth_date',
                        'label' => (string) __('setup.field_birth_date'),
                        'value' => '',
                        'required' => true,
                        'context' => 'setup',
                        'min' => date('Y-m-d', strtotime('-100 years')),
                        'max' => date('Y-m-d', strtotime('-18 years')),
                        'attrs' => [
                            'data-cf-dialog-birth' => true,
                            'data-birth-date' => true,
                        ],
                    ]) ?>
                </div>
                <?= field('birth_place', [
                    'name' => 'birth_place',
                    'value' => '',
                    'required' => true,
                    'foreign_toggle' => true,
                    'flag_name' => 'cf_dialog_foreign',
                ]) ?>
                <p class="setup-hint muted" data-setup-people-cf-status hidden></p>
            </div>
            <div class="setup-exit-actions">
                <button type="button" class="btn btn-ghost" data-setup-people-cf-cancel><?= e(__('common.cancel')) ?></button>
                <button type="button" class="btn" data-setup-people-cf-apply><?= e(__('setup.cf_calc_apply')) ?></button>
            </div>
        </div>
    </dialog>
</div>
