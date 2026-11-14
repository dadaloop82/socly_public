<?php
/**
 * Model D help wizard for the movement form: at most two questions (area → line) and the chosen
 * line is written on the select by the client. The official lines of Model D stay in Italian by
 * law; questions and examples are translated.
 *
 * @var string $direction Current sign of the movement (income|expense|transfer)
 */
$wizardDirection = ((string) ($direction ?? 'income')) === 'expense' ? 'expense' : 'income';

/**
 * Plain-language example under each official line (the same wording is reused across the sections
 * that share the line text).
 *
 * @var array<string,array<string,string>>
 */
$wizardHintKeys = [
    'expense' => [
        'A-E1' => 'wizard_hint_merci',
        'A-E2' => 'wizard_hint_servizi',
        'A-E3' => 'wizard_hint_beni_terzi',
        'A-E4' => 'wizard_hint_personale',
        'A-E5' => 'wizard_hint_altre',
        'B-E1' => 'wizard_hint_merci',
        'B-E2' => 'wizard_hint_servizi',
        'B-E3' => 'wizard_hint_beni_terzi',
        'B-E4' => 'wizard_hint_personale',
        'B-E5' => 'wizard_hint_altre',
        'C-E1' => 'wizard_hint_c_abituale',
        'C-E2' => 'wizard_hint_c_occasionale',
        'C-E3' => 'wizard_hint_altre',
        'D-E1' => 'wizard_hint_d_banca',
        'D-E2' => 'wizard_hint_d_invest_finanziari',
        'D-E3' => 'wizard_hint_d_patrimonio_edilizio',
        'D-E4' => 'wizard_hint_d_altri_patrimoniali',
        'D-E5' => 'wizard_hint_altre',
        'E-E1' => 'wizard_hint_merci',
        'E-E2' => 'wizard_hint_servizi',
        'E-E3' => 'wizard_hint_beni_terzi',
        'E-E4' => 'wizard_hint_personale',
        'E-E5' => 'wizard_hint_altre',
        'INV-E1' => 'wizard_hint_inv_e_interesse',
        'INV-E2' => 'wizard_hint_inv_e_diverse',
        'INV-E3' => 'wizard_hint_inv_e_finanziarie',
        'INV-E4' => 'wizard_hint_inv_e_finanziamenti',
    ],
    'income' => [
        'A-I1' => 'wizard_hint_i_quote',
        'A-I2' => 'wizard_hint_i_mutualistici',
        'A-I3' => 'wizard_hint_i_prestazioni_associati',
        'A-I4' => 'wizard_hint_i_liberali',
        'A-I5' => 'wizard_hint_i_5mille',
        'A-I6' => 'wizard_hint_i_privati',
        'A-I7' => 'wizard_hint_i_corrispettivi',
        'A-I8' => 'wizard_hint_i_pubblici',
        'A-I9' => 'wizard_hint_i_contratti',
        'A-I10' => 'wizard_hint_i_altri',
        'B-I1' => 'wizard_hint_i_prestazioni_associati',
        'B-I2' => 'wizard_hint_i_privati',
        'B-I3' => 'wizard_hint_i_diverse',
        'B-I4' => 'wizard_hint_i_pubblici',
        'B-I5' => 'wizard_hint_i_contratti',
        'B-I6' => 'wizard_hint_i_altri',
        'C-I1' => 'wizard_hint_c_abituale',
        'C-I2' => 'wizard_hint_c_occasionale',
        'C-I3' => 'wizard_hint_i_raccolta_altre',
        'D-I1' => 'wizard_hint_i_banca',
        'D-I2' => 'wizard_hint_i_invest_finanziari',
        'D-I3' => 'wizard_hint_i_patrimonio_edilizio',
        'D-I4' => 'wizard_hint_i_altri_patrimoniali',
        'D-I5' => 'wizard_hint_i_altre_finanze',
        'E-I1' => 'wizard_hint_i_distacco',
        'E-I2' => 'wizard_hint_i_supporto',
        'INV-I1' => 'wizard_hint_inv_i_interesse',
        'INV-I2' => 'wizard_hint_inv_i_diverse',
        'INV-I3' => 'wizard_hint_inv_i_finanziarie',
        'INV-I4' => 'wizard_hint_inv_i_finanziamenti',
    ],
];

/**
 * Translated example for a line: the key itself means the translation is missing.
 */
$wizardLineHint = static function (string $lineDirection, string $line) use ($wizardHintKeys): string {
    $key = $wizardHintKeys[$lineDirection][$line] ?? '';
    if ($key === '') {
        return '';
    }
    $translated = (string) __('runts.' . $key);
    return $translated !== 'runts.' . $key ? $translated : '';
};

$wizardAreas = ['A', 'B', 'C', 'D', 'E', 'INV'];
$wizardCloseLabel = (string) __('common.close');
$wizardStepTemplate = (string) __('runts.wizard_step', ['current' => ':current', 'total' => ':total']);
?>
<dialog class="runts-wizard-dialog" data-runts-wizard
        data-msg-step="<?= e($wizardStepTemplate) ?>"
        data-msg-sign-income="<?= e(__('runts.wizard_sign_income')) ?>"
        data-msg-sign-expense="<?= e(__('runts.wizard_sign_expense')) ?>"
        aria-labelledby="runts-wizard-title" aria-describedby="runts-wizard-lede">
    <div class="runts-wizard-shell">
        <header class="runts-wizard-head">
            <div>
                <h3 class="runts-wizard-title" id="runts-wizard-title"><?= e(__('runts.wizard_title')) ?></h3>
                <p class="runts-wizard-lede" id="runts-wizard-lede"><?= e(__('runts.wizard_lede')) ?></p>
                <p class="runts-wizard-sign">
                    <span class="runts-wizard-sign-label"><?= e(__('runts.wizard_sign_label')) ?></span>
                    <strong class="runts-wizard-sign-value" data-runts-sign-text></strong>
                </p>
            </div>
            <button type="button" class="btn-icon runts-wizard-close" data-runts-wizard-close
                    aria-label="<?= e($wizardCloseLabel) ?>" title="<?= e($wizardCloseLabel) ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M6 6l12 12M18 6L6 18"/>
                </svg>
            </button>
        </header>
        <div class="runts-wizard-progress-row">
            <ol class="runts-wizard-progress" aria-hidden="true">
                <li class="is-active" data-runts-step-dot="area"></li>
                <li data-runts-step-dot="item"></li>
            </ol>
            <p class="runts-wizard-progress-label" data-runts-step-label role="status" aria-live="polite"></p>
        </div>

        <section class="runts-wizard-step" data-runts-step="area">
            <p class="runts-wizard-question"><?= e(__('runts.wizard_area_question')) ?></p>
            <div class="runts-wizard-options">
                <?php foreach ($wizardAreas as $wizardArea): ?>
                    <button type="button" class="runts-wizard-option" data-runts-choice
                            data-runts-area="<?= e($wizardArea) ?>">
                        <span class="runts-wizard-option-label"><?= e(\Socly\Services\RuntsReportService::sectionLabel($wizardArea)) ?></span>
                        <span class="runts-wizard-option-hint"><?= e(__('runts.wizard_area_' . strtolower($wizardArea))) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>


        <section class="runts-wizard-step" data-runts-step="item" hidden>
            <p class="runts-wizard-question">
                <?= e(__('runts.wizard_item_question')) ?>
                <span class="runts-wizard-question-area" data-runts-area-label></span>
            </p>
            <div class="runts-wizard-items">
                <?php foreach (['expense', 'income'] as $wizardSign): ?>
                    <div data-runts-items="<?= e($wizardSign) ?>" <?= $wizardSign !== $wizardDirection ? 'hidden' : '' ?>>
                        <?php foreach (\Socly\Services\RuntsReportService::selectGroups($wizardSign) as $wizardGroup): ?>
                            <?php $wizardGroupKey = (string) ($wizardGroup['key'] ?? ''); ?>
                            <div class="runts-wizard-group" data-runts-group="<?= e($wizardGroupKey) ?>" hidden>
                                <?php foreach (($wizardGroup['options'] ?? []) as $wizardOption): ?>
                                    <?php
                                    $wizardOptionValue = (string) ($wizardOption['value'] ?? '');
                                    $wizardOptionLabel = (string) ($wizardOption['label'] ?? '');
                                    $wizardOptionHint = $wizardLineHint($wizardSign, $wizardOptionValue);
                                    ?>
                                    <button type="button" class="runts-wizard-option" data-runts-choice
                                            data-runts-line="<?= e($wizardOptionValue) ?>"
                                            data-runts-line-label="<?= e($wizardOptionLabel) ?>">
                                        <span class="runts-wizard-option-label"><?= e($wizardOptionLabel) ?></span>
                                        <?php if ($wizardOptionHint !== ''): ?>
                                            <span class="runts-wizard-option-hint"><?= e($wizardOptionHint) ?></span>
                                        <?php endif; ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="runts-wizard-note"><?= e(__('runts.wizard_apply_hint')) ?></p>
        </section>

        <footer class="runts-wizard-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-runts-wizard-back hidden><?= e(__('common.back')) ?></button>
            <button type="button" class="btn btn-ghost btn-sm" data-runts-wizard-close><?= e(__('common.cancel')) ?></button>
        </footer>
    </div>
</dialog>
