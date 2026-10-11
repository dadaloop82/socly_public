<?php
/**
 * Enrollment validation method picker (radio cards).
 *
 * @var string $name Input name
 * @var string $value Current value
 * @var bool $mailReady Outbound mail ready for OTP
 * @var string|null $mailSetupHref Optional link to configure outbound mail
 * @var bool $showFieldLabel
 * @var string|null $fieldLabel
 */
$name = (string) ($name ?? 'value');
$value = (string) ($value ?? 'none');
$mailReady = !empty($mailReady);
$mailSetupHref = isset($mailSetupHref) ? trim((string) $mailSetupHref) : '';
$showFieldLabel = !isset($showFieldLabel) || (bool) $showFieldLabel;
$fieldLabel = trim((string) ($fieldLabel ?? ''));
if ($fieldLabel === '') {
    $fieldLabel = (string) __('setup.step_enrollment_title');
}

$cards = [
    'none' => [
        'title' => __('setup.enrollment_card_none_title'),
        'desc' => __('setup.enrollment_card_none_desc'),
        'icon' => 'doc',
    ],
    'print_scan' => [
        'title' => __('setup.enrollment_card_print_title'),
        'desc' => __('setup.enrollment_card_print_desc'),
        'icon' => 'print',
    ],
    'tablet_sign' => [
        'title' => __('setup.enrollment_card_tablet_title'),
        'desc' => __('setup.enrollment_card_tablet_desc'),
        'note' => __('setup.enrollment_tablet_note'),
        'icon' => 'pen',
    ],
    'otp_email' => [
        'title' => __('setup.enrollment_card_otp_title'),
        'desc' => __('setup.enrollment_card_otp_desc'),
        'icon' => 'mail',
    ],
];

if ($value === 'otp_email' && !$mailReady) {
    $value = 'none';
}

$icons = [
    'doc' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm0 2.5L18.5 9H14zM8 12h8v1.5H8zm0 3h8v1.5H8zm0 3h5V19.5H8z"/></svg>',
    'print' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M19 8h-1V3H6v5H5a3 3 0 0 0-3 3v6h4v4h12v-4h4v-6a3 3 0 0 0-3-3zM8 5h8v3H8zm8 14H8v-4h8zm3-4h-1.5v-1.5H19zm-14 0H3.5V13.5H5z"/></svg>',
    'pen' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75z"/></svg>',
    'mail' => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5L4 8V6l8 5 8-5z"/></svg>',
];
?>
<div
    class="setup-enrollment"
    data-setup-enrollment
    data-mail-ready="<?= $mailReady ? '1' : '0' ?>"
>
    <?php if ($showFieldLabel): ?>
        <p class="setup-field-label"><?= e($fieldLabel) ?></p>
    <?php endif; ?>

    <div class="setup-enrollment-cards" role="radiogroup" aria-label="<?= e($fieldLabel) ?>">
        <?php
        $requiredAttached = false;
        foreach ($cards as $optVal => $card):
            $otpBlocked = $optVal === 'otp_email' && !$mailReady;
            $checked = $value === $optVal && !$otpBlocked;
            $icon = $icons[$card['icon']] ?? '';
            $attachRequired = !$otpBlocked && !$requiredAttached;
            if ($attachRequired) {
                $requiredAttached = true;
            }
            ?>
            <label
                class="setup-enrollment-card<?= $checked ? ' is-selected' : '' ?><?= $otpBlocked ? ' is-disabled' : '' ?>"
                data-enrollment-card
                data-enrollment-value="<?= e($optVal) ?>"
            >
                <input
                    type="radio"
                    name="<?= e($name) ?>"
                    value="<?= e($optVal) ?>"
                    data-enrollment-radio
                    <?= $checked ? 'checked' : '' ?>
                    <?= $otpBlocked ? 'disabled' : '' ?>
                    <?= $attachRequired ? 'required' : '' ?>
                >
                <span class="setup-enrollment-card-icon" aria-hidden="true"><?= $icon ?></span>
                <span class="setup-enrollment-card-body">
                    <span class="setup-enrollment-card-title"><?= e($card['title']) ?></span>
                    <span class="setup-enrollment-card-desc"><?= e($card['desc']) ?></span>
                    <?php if (!empty($card['note'])): ?>
                        <span class="setup-enrollment-card-note"><?= e($card['note']) ?></span>
                    <?php endif; ?>
                    <?php if ($otpBlocked): ?>
                        <span class="setup-enrollment-card-lock"><?= e(__('setup.enrollment_otp_unavailable')) ?></span>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <?php if (!$mailReady): ?>
        <p class="setup-hint muted setup-enrollment-otp-hint" data-enrollment-otp-hint>
            <?= e(__('setup.mail_required_for_otp')) ?>
            <?php if ($mailSetupHref !== ''): ?>
                <a href="<?= e($mailSetupHref) ?>" class="setup-enrollment-mail-link"><?= e(__('setup.enrollment_otp_go_mail')) ?></a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php foreach (array_keys($cards) as $optVal): ?>
        <template data-enrollment-detail-for="<?= e($optVal) ?>"><?= e(__('setup.enrollment_detail_' . $optVal)) ?></template>
    <?php endforeach; ?>
    <p class="setup-enrollment-detail muted" data-enrollment-detail aria-live="polite" style="white-space:pre-line"></p>
</div>
