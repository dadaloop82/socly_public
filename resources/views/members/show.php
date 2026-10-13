<?php
$issues = is_array($member['compliance_issues'] ?? null) ? $member['compliance_issues'] : [];
$enrollmentMethod = (string) ($enrollmentMethod ?? 'none');
$enrollmentArtifact = is_array($enrollmentArtifact ?? null) ? $enrollmentArtifact : null;
$fields = is_array($member['fields'] ?? null) ? $member['fields'] : [];
$displayName = trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? ''));
$totalPaid = array_sum(array_map(static fn (array $payment): float => (float) ($payment['amount'] ?? 0), (array) ($payments ?? [])));
$balanceDue = (float) ($member['balance_due'] ?? 0);
$canManage = can('members.manage');
$canPayments = can('payments.manage');
$showStatus = (string) ($member['status'] ?? '');
$showStatusBadge = match ($showStatus) {
    'active' => 'badge-ok',
    'pending' => 'badge-warn',
    'suspended' => 'badge-due',
    'expired', 'cancelled' => 'badge-muted',
    default => '',
};
$typeLabel = localized($member['type_name_json'] ?? '');
$email = trim((string) ($fields['email'] ?? ''));
$phone = trim((string) ($fields['phone'] ?? ''));
$birthDateRaw = trim((string) ($fields['birth_date'] ?? ''));
$birthPlace = trim((string) ($fields['birth_place'] ?? ''));
$fiscalCode = trim((string) ($fields['fiscal_code'] ?? ''));
$age = null;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDateRaw)) {
    try {
        $birthDt = new DateTimeImmutable($birthDateRaw);
        $age = (int) $birthDt->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        $age = null;
    }
}
$street = trim((string) ($fields['address'] ?? $fields['street'] ?? ''));
$houseNumber = trim((string) ($fields['house_number'] ?? ''));
$postalCode = trim((string) ($fields['postal_code'] ?? ''));
$city = trim((string) ($fields['city'] ?? ''));
$province = trim((string) ($fields['province'] ?? ''));
$residenceParts = array_values(array_filter([
    trim($street . ($houseNumber !== '' ? ' ' . $houseNumber : '')),
    trim($postalCode . ($city !== '' ? ' ' . $city : '') . ($province !== '' ? ' (' . $province . ')' : '')),
]));
$residenceLine = implode(', ', $residenceParts);
$isChecked = static function (mixed $raw): bool {
    return $raw === true || $raw === 1 || $raw === '1' || $raw === 'on' || $raw === 'yes';
};
$consentItems = [];
$consentKeysUsed = [];
foreach ($fieldDefs as $def) {
    if (!(int) ($def['is_enabled'] ?? 0)) {
        continue;
    }
    $key = (string) ($def['key'] ?? '');
    if ($key === '' || (string) ($def['field_type'] ?? '') !== 'checkbox') {
        continue;
    }
    $consentKeysUsed[$key] = true;
    $consentItems[] = [
        'key' => $key,
        'label' => localized($def['label_json'] ?? ''),
        'ok' => $isChecked($fields[$key] ?? null),
    ];
}
if (!isset($consentKeysUsed['privacy_ack']) && array_key_exists('privacy_ack', $fields)) {
    array_unshift($consentItems, [
        'key' => 'privacy_ack',
        'label' => __('members.consent_privacy'),
        'ok' => $isChecked($fields['privacy_ack'] ?? null),
    ]);
}
if (!isset($consentKeysUsed['statute_ack']) && array_key_exists('statute_ack', $fields)) {
    $consentItems[] = [
        'key' => 'statute_ack',
        'label' => __('members.consent_statute'),
        'ok' => $isChecked($fields['statute_ack'] ?? null),
    ];
}
$sidebarSkipKeys = [
    'photo' => true,
    'first_name' => true,
    'last_name' => true,
    'email' => true,
    'phone' => true,
    'birth_date' => true,
    'birth_place' => true,
    'fiscal_code' => true,
    'address' => true,
    'street' => true,
    'house_number' => true,
    'postal_code' => true,
    'city' => true,
    'province' => true,
];
foreach (array_keys($consentKeysUsed) as $ck) {
    $sidebarSkipKeys[$ck] = true;
}
$extraProfileItems = [];
foreach ($fieldDefs as $def) {
    if (!(int) ($def['is_enabled'] ?? 0)) {
        continue;
    }
    $key = (string) ($def['key'] ?? '');
    if ($key === '' || isset($sidebarSkipKeys[$key]) || (string) ($def['field_type'] ?? '') === 'photo') {
        continue;
    }
    if ((string) ($def['field_type'] ?? '') === 'checkbox') {
        continue;
    }
    $raw = trim((string) ($fields[$key] ?? ''));
    if ($raw === '') {
        continue;
    }
    $value = $raw;
    if ($key === 'gender') {
        $value = match ($raw) {
            'M' => (string) __('members.gender_m'),
            'F' => (string) __('members.gender_f'),
            'X' => (string) __('members.gender_x'),
            default => $raw,
        };
    } elseif ($key === 'preferred_language') {
        $value = match ($raw) {
            'it' => (string) __('members.lang_it'),
            'de' => (string) __('members.lang_de'),
            'en' => (string) __('members.lang_en'),
            'other' => (string) __('members.lang_other'),
            default => $raw,
        };
    }
    $isMissing = false;
    foreach ($issues as $issue) {
        if (($issue['field'] ?? '') === $key) {
            $isMissing = true;
            break;
        }
    }
    $extraProfileItems[] = [
        'label' => localized($def['label_json'] ?? $key),
        'value' => $value,
        'missing' => $isMissing,
    ];
}
$birthSummary = '';
$birthDateFmt = $birthDateRaw !== '' ? format_date($birthDateRaw) : '';
if ($birthPlace !== '' && $birthDateFmt !== '') {
    $birthSummary = __('members.born_at_on', ['place' => $birthPlace, 'date' => $birthDateFmt]);
} elseif ($birthPlace !== '') {
    $birthSummary = __('members.born_in', ['place' => $birthPlace]);
} elseif ($birthDateFmt !== '') {
    $birthSummary = __('members.born_on', ['date' => $birthDateFmt]);
}
if ($birthSummary !== '' && $age !== null) {
    $birthSummary .= ' (' . __('members.age_years', ['age' => (string) $age]) . ')';
}
$metaBits = ['#' . (string) $member['member_number']];
if ($typeLabel !== '') {
    $metaBits[] = $typeLabel;
}
$periodEndsOn = format_date((string) ($member['period_ends_on'] ?? ''));
if ($periodEndsOn !== '') {
    $metaBits[] = __('members.expires_short', ['date' => $periodEndsOn]);
} elseif (!empty($member['period_label'])) {
    $metaBits[] = (string) $member['period_label'];
}
$redirectBack = url('/members/' . (int) $member['id']);
$hasPhoto = !empty($fields['photo']);
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.scheda')) ?></h1>
        <p class="page-lede"><?= e($displayName !== '' ? $displayName : __('members.show_lede')) ?></p>
    </div>
    <div class="actions">
        <?php if ($canManage): ?>
            <a class="btn" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/members')) ?>"><?= e(__('common.back')) ?></a>
        <?php if (can('members.delete')): ?>
        <form method="post" action="<?= e(url('/members/'.$member['id'].'/delete')) ?>" data-confirm="<?= e(__('members.confirm_delete')) ?>" data-confirm-danger="1">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit"><?= e(__('members.delete')) ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($issues !== []): ?>
<div class="alert alert-warn member-anomaly-banner" role="status">
    <div class="member-anomaly-banner-head">
        <span class="member-anomaly-mark is-static" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                <path fill="currentColor" d="M12 3.2 22 20.5H2L12 3.2zm0 5.3c-.7 0-1.2.5-1.1 1.2l.4 5.1h1.4l.4-5.1c.1-.7-.4-1.2-1.1-1.2zm0 8.3a1.15 1.15 0 1 0 0 2.3 1.15 1.15 0 0 0 0-2.3z"/>
            </svg>
        </span>
        <div>
            <strong><?= e(__('members.anomaly_banner_title')) ?></strong>
            <p class="muted" style="margin:0.25rem 0 0"><?= e(__('members.anomaly_banner_lede')) ?></p>
        </div>
    </div>
    <ul class="member-anomaly-list">
        <?php foreach ($issues as $issue): ?>
            <li><?= e((string) ($issue['label'] ?? '')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($canManage): ?>
        <a class="btn btn-sm" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.anomaly_fix')) ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="member-scheda-layout" data-members-list data-members-base="<?= e(rtrim(url('/members'), '/')) ?>">
    <aside class="panel member-scheda-sidebar">
        <div class="member-scheda-identity">
            <div class="member-scheda-avatar<?= $hasPhoto ? '' : ' is-empty' ?>">
                <?php if ($hasPhoto): ?>
                    <img src="<?= e(url('/members/'.$member['id'].'/photo')) ?>" alt="">
                <?php endif; ?>
                <?php if ($showStatus !== ''): ?>
                    <span class="member-scheda-status-badge badge<?= $showStatusBadge !== '' ? ' ' . e($showStatusBadge) : '' ?>">
                        <?= e(__('members.status_'.$showStatus)) ?>
                    </span>
                <?php endif; ?>
            </div>
            <h2 class="member-scheda-name"><?= e($displayName !== '' ? $displayName : '#' . $member['member_number']) ?></h2>
            <p class="muted member-scheda-meta"><?= e(implode(' · ', $metaBits)) ?></p>
        </div>

        <div class="member-scheda-contacts">
            <?php if ($email !== ''): ?>
                <a class="member-scheda-contact" href="mailto:<?= e($email) ?>">
                    <span class="member-scheda-contact-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" focusable="false"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5L4 8V6l8 5 8-5z"/></svg>
                    </span>
                    <span><?= e($email) ?></span>
                </a>
            <?php else: ?>
                <p class="member-scheda-contact is-empty muted"><?= e(__('members.contact_no_email')) ?></p>
            <?php endif; ?>
            <?php if ($phone !== ''): ?>
                <a class="member-scheda-contact" href="tel:<?= e(preg_replace('/\s+/', '', $phone) ?? $phone) ?>">
                    <span class="member-scheda-contact-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" focusable="false"><path fill="currentColor" d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24c1.1.37 2.3.57 3.5.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.2.2 2.4.57 3.5a1 1 0 0 1-.25 1z"/></svg>
                    </span>
                    <span><?= e($phone) ?></span>
                </a>
            <?php else: ?>
                <p class="member-scheda-contact is-empty muted"><?= e(__('members.contact_no_phone')) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($consentItems !== []): ?>
        <div class="member-scheda-consents">
            <h3 class="member-scheda-side-title"><?= e(__('members.consents_title')) ?></h3>
            <ul class="member-consent-checklist">
                <?php foreach ($consentItems as $item): ?>
                    <li class="<?= !empty($item['ok']) ? 'is-ok' : 'is-missing' ?>">
                        <span class="member-consent-mark" aria-hidden="true"><?= !empty($item['ok']) ? '✓' : '!' ?></span>
                        <span><?= e((string) $item['label']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php elseif (!empty($member['gdpr_badges'])): ?>
        <div class="member-scheda-consents">
            <h3 class="member-scheda-side-title"><?= e(__('members.consents_title')) ?></h3>
            <div class="member-gdpr-badges">
                <?php foreach ($member['gdpr_badges'] as $badge): ?>
                    <span class="badge <?= !empty($badge['ok']) ? 'badge-ok' : 'badge-due' ?>"><?= e((string) ($badge['label'] ?? '')) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="member-scheda-side-actions">
            <a class="btn btn-sm" href="<?= e(url('/members/'.$member['id'].'/enrollment-form')) ?>" target="_blank" rel="noopener">
                <?= e(__('members.enrollment_print_form')) ?>
            </a>
            <?php if ($canPayments): ?>
                <button
                    type="button"
                    class="btn btn-sm btn-ghost"
                    data-member-collect
                    data-member-id="<?= (int) $member['id'] ?>"
                    data-member-name="<?= e($displayName !== '' ? $displayName : (string) $member['member_number']) ?>"
                    data-member-balance="<?= e(number_format(max($balanceDue, 0.01), 2, '.', '')) ?>"
                ><?= e(__('members.collect_short')) ?></button>
            <?php endif; ?>
        </div>

        <div class="member-scheda-side-payment">
            <span class="badge <?= $member['payment_status']==='paid'?'badge-ok':($member['payment_status']==='due'?'badge-due':'badge-warn') ?>">
                <?= e(__('members.payment_'.$member['payment_status'])) ?>
            </span>
            <?php if ($totalPaid > 0): ?>
                <span class="muted"><?= e(__('members.amount_paid')) ?>: <strong><?= e(number_format($totalPaid, 2, ',', '.')) ?> €</strong></span>
            <?php endif; ?>
            <?php if ($balanceDue > 0): ?>
                <span class="muted"><?= e(__('members.balance_due')) ?>: <strong><?= e(number_format($balanceDue, 2, ',', '.')) ?> €</strong></span>
            <?php endif; ?>
        </div>
    </aside>

    <div class="member-scheda-main">
        <section class="panel member-scheda-card">
            <div class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.profile_card_title')) ?></h2>
                    <p class="section-lede"><?= e(__('members.profile_card_lede')) ?></p>
                </div>
            </div>
            <div class="member-scheda-facts">
                <?php if ($birthSummary !== '' || $fiscalCode !== ''): ?>
                <div class="member-scheda-fact">
                    <div class="label"><?= e(__('members.birth_block')) ?></div>
                    <div class="value">
                        <?php if ($birthSummary !== ''): ?>
                            <?= e($birthSummary) ?>
                        <?php endif; ?>
                        <?php if ($fiscalCode !== ''): ?>
                            <?= $birthSummary !== '' ? ' · ' : '' ?><span class="muted"><?= e(__('members.fiscal_code_short')) ?>:</span> <?= e($fiscalCode) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($residenceLine !== ''): ?>
                <div class="member-scheda-fact">
                    <div class="label"><?= e(__('members.residence_block')) ?></div>
                    <div class="value"><?= e($residenceLine) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($member['admitted_at'])): ?>
                <div class="member-scheda-fact">
                    <div class="label"><?= e(__('members.admitted_at')) ?></div>
                    <div class="value"><?= e(__('members.admitted_on', ['date' => format_date((string) $member['admitted_at'])])) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($member['guardian']) || !empty($needsGuardian)): ?>
                <div class="member-scheda-fact">
                    <div class="label"><?= e(__('members.guardian_label')) ?></div>
                    <div class="value">
                        <?php if (!empty($member['guardian'])): ?>
                            <a href="<?= e(url('/members/' . (int) $member['guardian']['id'])) ?>"><?= e((string) $member['guardian']['display_name']) ?></a>
                        <?php else: ?>
                            <span class="badge badge-due"><?= e(__('members.guardian_missing')) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php foreach ($extraProfileItems as $item): ?>
                <div class="member-scheda-fact<?= !empty($item['missing']) ? ' is-anomaly' : '' ?>">
                    <div class="label"><?= e((string) $item['label']) ?><?= !empty($item['missing']) ? ' · ' . e(__('members.anomaly_short')) : '' ?></div>
                    <div class="value"><?= e((string) $item['value']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($member['notes'])): ?>
                <p class="member-scheda-notes"><strong><?= e(__('members.notes')) ?>:</strong> <?= e($member['notes']) ?></p>
            <?php endif; ?>
        </section>

        <section class="panel member-scheda-card">
            <div class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('members.enrollment_section')) ?></h2>
                    <p class="section-lede"><?= e(__('members.enrollment_section_lede')) ?></p>
                </div>
            </div>
            <?php if ($enrollmentArtifact): ?>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="label"><?= e(__('members.enrollment_method')) ?></div>
                        <div class="value"><?= e(__('setup.enrollment_' . (string) ($enrollmentArtifact['method'] ?? 'none'))) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="label"><?= e(__('members.enrollment_recorded_at')) ?></div>
                        <div class="value"><?= e(format_datetime((string) ($enrollmentArtifact['created_at'] ?? '')) ?: '—') ?></div>
                    </div>
                </div>
                <?php if (!empty($enrollmentArtifact['storage_path'])): ?>
                    <div class="member-enrollment-preview">
                        <?php
                        $path = (string) $enrollmentArtifact['storage_path'];
                        $isPdf = str_ends_with(strtolower($path), '.pdf');
                        ?>
                        <?php if ($isPdf): ?>
                            <a class="btn" href="<?= e(url('/members/'.$member['id'].'/enrollment')) ?>" target="_blank" rel="noopener"><?= e(__('members.enrollment_open_file')) ?></a>
                        <?php else: ?>
                            <img src="<?= e(url('/members/'.$member['id'].'/enrollment')) ?>" alt="<?= e(__('members.enrollment_sign')) ?>">
                        <?php endif; ?>
                    </div>
                <?php elseif (($enrollmentArtifact['method'] ?? '') === 'otp_email'): ?>
                    <p class="muted"><?= e(__('members.enrollment_otp_recorded')) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($enrollmentMethod === 'none'): ?>
                    <p class="muted"><?= e(__('members.enrollment_not_required')) ?></p>
                <?php else: ?>
                    <p class="muted"><?= e(__('members.enrollment_missing_on_file')) ?></p>
                <?php endif; ?>
                <div class="member-scheda-enrollment-actions">
                    <a class="btn" href="<?= e(url('/members/'.$member['id'].'/enrollment-form')) ?>" target="_blank" rel="noopener"><?= e(__('members.enrollment_print_form')) ?></a>
                    <?php if ($canManage && in_array($enrollmentMethod, ['tablet_sign', 'otp_email'], true)): ?>
                        <a class="btn btn-ghost" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.anomaly_fix')) ?></a>
                    <?php endif; ?>
                </div>
                <?php if ($canManage): ?>
                    <form class="member-enrollment-dropzone" method="post" action="<?= e(url('/members/'.$member['id'].'/enrollment-scan')) ?>" enctype="multipart/form-data" data-enrollment-dropzone>
                        <?= csrf_field() ?>
                        <div class="member-enrollment-dropzone-ui">
                            <strong><?= e(__('members.enrollment_drop_title')) ?></strong>
                            <p class="muted"><?= e(__('members.enrollment_upload_hint')) ?></p>
                            <?= field('file', [
                                'name' => 'enrollment_scan',
                                'label' => '',
                                'accept' => 'application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png',
                                'required' => true,
                                'as_button' => true,
                                'button_label' => (string) __('members.enrollment_choose_file'),
                                'button_class' => 'btn btn-sm',
                                'attrs' => ['data-enrollment-file' => true],
                            ]) ?>
                            <p class="member-enrollment-file-name muted" data-enrollment-file-name hidden></p>
                        </div>
                        <button type="submit" class="btn btn-sm btn-ghost"><?= e(__('members.enrollment_upload_submit')) ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel member-scheda-card">
            <div class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('payments.title')) ?></h2>
                    <p class="section-lede"><?= e(__('payments.history_lede')) ?></p>
                </div>
                <?php if ($canPayments): ?>
                    <button
                        type="button"
                        class="btn btn-sm"
                        data-member-collect
                        data-member-id="<?= (int) $member['id'] ?>"
                        data-member-name="<?= e($displayName !== '' ? $displayName : (string) $member['member_number']) ?>"
                        data-member-balance="<?= e(number_format(max($balanceDue, 0.01), 2, '.', '')) ?>"
                    >+ <?= e(__('members.collect_short')) ?></button>
                <?php endif; ?>
            </div>
            <?php if (!empty($payments)): ?>
                <div class="table-wrap embedded">
                    <table>
                        <thead>
                        <tr>
                            <th><?= e(__('payments.amount')) ?></th>
                            <th><?= e(__('payments.method')) ?></th>
                            <th><?= e(__('payments.type')) ?></th>
                            <th><?= e(__('members.enrollment_recorded_at')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td><?= e(number_format((float) $p['amount'], 2, ',', '.')) ?> €</td>
                                <td><?= e(__('payments.' . $p['method'])) ?></td>
                                <td><?= e(__('payments.' . $p['type'])) ?></td>
                                <td><?= e(format_datetime((string) ($p['created_at'] ?? '')) ?: (string) ($p['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted"><?= e(__('members.payments_empty')) ?></p>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($canPayments): ?>
    <dialog class="member-collect-dialog" data-member-collect-dialog>
        <div class="member-leave-shell">
            <h3 class="section-title"><?= e(__('members.collect_title')) ?></h3>
            <p class="muted" data-member-collect-name></p>
            <form method="post" action="" data-member-collect-form>
                <?= csrf_field() ?>
                <?= field('hidden', ['name' => 'redirect', 'value' => $redirectBack]) ?>
                <?= field('hidden', ['name' => 'type', 'value' => 'membership']) ?>
                <div class="grid-2" style="margin-top:0.75rem">
                    <?= field('number', [
                        'name' => 'amount',
                        'label' => (string) __('payments.amount'),
                        'context' => 'member',
                        'required' => true,
                        'step' => '0.01',
                        'min' => '0.01',
                        'attrs' => ['data-member-collect-amount' => true],
                    ]) ?>
                    <?= field('select', [
                        'name' => 'method',
                        'label' => (string) __('payments.method'),
                        'context' => 'member',
                        'required' => true,
                        'include_empty' => false,
                        'options' => [
                            ['value' => 'cash', 'label' => (string) __('payments.cash')],
                            ['value' => 'bank', 'label' => (string) __('payments.bank')],
                            ['value' => 'other', 'label' => (string) __('payments.other')],
                        ],
                    ]) ?>
                </div>
                <?= field('text', [
                    'name' => 'note',
                    'label' => (string) __('payments.note'),
                    'context' => 'member',
                    'maxlength' => 500,
                ]) ?>
                <div class="member-leave-actions">
                    <button type="button" class="btn btn-ghost" data-member-collect-cancel><?= e(__('common.cancel')) ?></button>
                    <button type="submit" class="btn"><?= e(__('members.collect_confirm')) ?></button>
                </div>
            </form>
        </div>
    </dialog>
    <?php endif; ?>
</div>
