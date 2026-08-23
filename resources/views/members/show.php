<?php
$issues = is_array($member['compliance_issues'] ?? null) ? $member['compliance_issues'] : [];
$enrollmentMethod = (string) ($enrollmentMethod ?? 'none');
$enrollmentArtifact = is_array($enrollmentArtifact ?? null) ? $enrollmentArtifact : null;
$displayName = trim(($member['fields']['first_name'] ?? '') . ' ' . ($member['fields']['last_name'] ?? ''));
$totalPaid = array_sum(array_map(static fn (array $payment): float => (float) ($payment['amount'] ?? 0), (array) ($payments ?? [])));
$balanceDue = (float) ($member['balance_due'] ?? 0);
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.scheda')) ?> #<?= e($member['member_number']) ?></h1>
        <p class="page-lede"><?= e($displayName !== '' ? $displayName : __('members.show_lede')) ?></p>
    </div>
    <div class="actions">
        <?php if (can('members.manage')): ?>
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
    <?php if (can('members.manage')): ?>
        <a class="btn btn-sm" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.anomaly_fix')) ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel member-scheda">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.overview')) ?></h2>
            <p class="section-lede"><?= e(__('members.overview_lede')) ?></p>
        </div>
    </div>

    <div class="member-scheda-top">
        <?php if (!empty($member['fields']['photo'])): ?>
            <div class="member-photo-hero">
                <img src="<?= e(url('/members/'.$member['id'].'/photo')) ?>" alt="">
            </div>
        <?php else: ?>
            <div class="member-photo-hero is-empty" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="detail-grid member-scheda-core">
            <div class="detail-item">
                <div class="label"><?= e(__('members.member_number')) ?></div>
                <div class="value"><?= e($member['member_number']) ?></div>
            </div>
            <div class="detail-item">
                <div class="label"><?= e(__('members.status')) ?></div>
                <div class="value"><span class="badge<?= ($member['status'] ?? '') === 'pending' ? ' badge-warn' : '' ?>"><?= e(__('members.status_'.$member['status'])) ?></span></div>
            </div>
            <?php if (!empty($member['gdpr_badges'])): ?>
            <div class="detail-item detail-item-wide">
                <div class="label"><?= e(__('members.gdpr_column')) ?></div>
                <div class="value member-gdpr-badges">
                    <?php foreach ($member['gdpr_badges'] as $badge): ?>
                        <span class="badge <?= !empty($badge['ok']) ? 'badge-ok' : 'badge-due' ?>"><?= e((string) ($badge['label'] ?? '')) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($member['admitted_at'])): ?>
            <div class="detail-item">
                <div class="label"><?= e(__('members.admitted_at')) ?></div>
                <div class="value"><?= e(format_date((string) $member['admitted_at'])) ?></div>
            </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="label"><?= e(__('members.payment')) ?></div>
                <div class="value member-payment-summary">
                    <span class="badge <?= $member['payment_status']==='paid'?'badge-ok':($member['payment_status']==='due'?'badge-due':'badge-warn') ?>"><?= e(__('members.payment_'.$member['payment_status'])) ?></span>
                    <?php if ($totalPaid > 0): ?>
                        <span><?= e(__('members.amount_paid')) ?>: <strong><?= e(number_format($totalPaid, 2, ',', '.')) ?> €</strong></span>
                    <?php endif; ?>
                    <?php if ($balanceDue > 0): ?>
                        <span><?= e(__('members.balance_due')) ?>: <strong><?= e(number_format($balanceDue, 2, ',', '.')) ?> €</strong></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="detail-item">
                <div class="label"><?= e(__('members.type')) ?></div>
                <div class="value"><?= e(localized($member['type_name_json'])) ?></div>
            </div>
            <div class="detail-item">
                <div class="label"><?= e(__('members.period')) ?></div>
                <div class="value"><?= e($member['period_label']) ?></div>
            </div>
        </div>
    </div>

    <h3 class="setup-subhead" style="margin-top:1.5rem"><?= e(__('members.scheda_fields')) ?></h3>
    <div class="detail-grid">
        <?php foreach ($fieldDefs as $def): if (!(int)$def['is_enabled']) continue; if ($def['field_type'] === 'photo') continue; ?>
            <?php
            $key = (string) ($def['key'] ?? '');
            $raw = (string) ($member['fields'][$key] ?? '');
            $isMissing = false;
            foreach ($issues as $issue) {
                if (($issue['field'] ?? '') === $key) {
                    $isMissing = true;
                    break;
                }
            }
            ?>
            <div class="detail-item<?= $isMissing ? ' is-anomaly' : '' ?>">
                <div class="label"><?= e(localized($def['label_json'])) ?><?= $isMissing ? ' · ' . e(__('members.anomaly_short')) : '' ?></div>
                <div class="value">
                    <?php
                    if ($def['field_type'] === 'checkbox') {
                        echo e($raw === '1' ? __('members.yes') : __('members.no'));
                    } elseif ($key === 'gender') {
                        echo e(match ($raw) {
                            'M' => __('members.gender_m'),
                            'F' => __('members.gender_f'),
                            'X' => __('members.gender_x'),
                            default => $raw !== '' ? $raw : '—',
                        });
                    } elseif ($key === 'preferred_language') {
                        echo e(match ($raw) {
                            'it' => __('members.lang_it'),
                            'de' => __('members.lang_de'),
                            'en' => __('members.lang_en'),
                            'other' => __('members.lang_other'),
                            default => $raw !== '' ? $raw : '—',
                        });
                    } else {
                        echo e($raw !== '' ? $raw : '—');
                    }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($member['notes'])): ?>
        <p style="margin-top:1.25rem"><strong><?= e(__('members.notes')) ?>:</strong> <?= e($member['notes']) ?></p>
    <?php endif; ?>
</div>

<div class="panel">
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
                <div class="value"><?= e((string) ($enrollmentArtifact['created_at'] ?? '—')) ?></div>
            </div>
        </div>
        <?php if (!empty($enrollmentArtifact['storage_path'])): ?>
            <div class="member-enrollment-preview" style="margin-top:1rem">
                <?php
                $path = (string) $enrollmentArtifact['storage_path'];
                $isPdf = str_ends_with(strtolower($path), '.pdf');
                ?>
                <?php if ($isPdf): ?>
                    <a class="btn btn-ghost" href="<?= e(url('/members/'.$member['id'].'/enrollment')) ?>" target="_blank" rel="noopener"><?= e(__('members.enrollment_open_file')) ?></a>
                <?php else: ?>
                    <img src="<?= e(url('/members/'.$member['id'].'/enrollment')) ?>" alt="<?= e(__('members.enrollment_sign')) ?>" style="max-width:min(100%,28rem);border:1px solid var(--line);border-radius:12px;background:#fff">
                <?php endif; ?>
            </div>
        <?php elseif (($enrollmentArtifact['method'] ?? '') === 'otp_email'): ?>
            <p class="muted"><?= e(__('members.enrollment_otp_recorded')) ?></p>
        <?php endif; ?>
    <?php elseif ($enrollmentMethod === 'print_scan'): ?>
        <p class="muted"><?= e(__('members.enrollment_missing_on_file')) ?></p>
        <div class="actions">
            <a class="btn btn-sm" href="<?= e(url('/members/'.$member['id'].'/enrollment-form')) ?>" target="_blank" rel="noopener"><?= e(__('members.enrollment_print_form')) ?></a>
            <?php if (can('members.manage')): ?>
                <a class="btn btn-sm btn-ghost" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.anomaly_fix')) ?></a>
            <?php endif; ?>
        </div>
    <?php elseif ($enrollmentMethod !== 'none'): ?>
        <p class="muted"><?= e(__('members.enrollment_missing_on_file')) ?></p>
        <?php if (can('members.manage')): ?>
            <a class="btn btn-sm" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.anomaly_fix')) ?></a>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted"><?= e(__('members.enrollment_not_required')) ?></p>
    <?php endif; ?>
</div>

<?php if ($payments): ?>
<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('payments.title')) ?></h2>
            <p class="section-lede"><?= e(__('payments.history_lede')) ?></p>
        </div>
    </div>
    <div class="table-wrap embedded">
        <table>
            <thead><tr><th><?= e(__('payments.amount')) ?></th><th><?= e(__('payments.method')) ?></th><th><?= e(__('payments.type')) ?></th><th><?= e(__('members.enrollment_recorded_at')) ?></th></tr></thead>
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
</div>
<?php endif; ?>
