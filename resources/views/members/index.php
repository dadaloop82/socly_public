<?php
/** @var array<string,mixed> $filters */
/** @var int $page */
/** @var array{active_count:int,balance_due_total:float,pending_count:int} $summary */
$redirectBack = url('/members?' . http_build_query(array_filter(array_merge($filters, ['page' => $page > 1 ? $page : null]))));
$canManage = can('members.manage');
$canPayments = can('payments.manage');
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.title')) ?></h1>
        <p class="page-lede"><?= e(__('members.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if ($canManage): ?>
            <a class="btn" href="<?= e(url('/members/create')) ?>"><?= e(__('members.create')) ?></a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/members/export?' . http_build_query(array_filter($filters)))) ?>"><?= e(__('members.export')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/members/export/registry?' . http_build_query(array_filter($filters)))) ?>" target="_blank" rel="noopener"><?= e(__('members.registry_export')) ?></a>
    </div>
</div>

<?php if (!empty($hasAnyMembers)): ?>
<div class="stats stats-context-members members-summary-stats">
    <div class="stat">
        <div class="label"><?= e(__('members.summary_active')) ?></div>
        <div class="value"><?= (int) ($summary['active_count'] ?? 0) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.summary_due')) ?></div>
        <div class="value"><?= e(number_format((float) ($summary['balance_due_total'] ?? 0), 2, ',', '.')) ?> €</div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('members.summary_pending')) ?></div>
        <div class="value"><?= (int) ($summary['pending_count'] ?? 0) ?></div>
    </div>
</div>

<form class="panel filter-bar members-filter" method="get" data-members-filter>
    <?php
    $memberStatusOptions = [];
    foreach (\Socly\Services\MemberService::memberStatuses() as $st) {
        $memberStatusOptions[$st] = (string) __('members.status_' . $st);
    }
    $memberPaymentOptions = [];
    foreach (['paid', 'partial', 'due'] as $p) {
        $memberPaymentOptions[$p] = (string) __('members.payment_' . $p);
    }
    $memberTypeOptions = [];
    foreach ($types as $type) {
        $memberTypeOptions[(string) (int) $type['id']] = localized($type['name_json']);
    }
    ?>
    <div class="members-filter-row">
        <div class="members-filter-q">
            <?= field('search', [
                'name' => 'q',
                'id' => 'members-q',
                'label' => (string) __('members.search'),
                'value' => (string) $filters['q'],
                'context' => 'filter',
                'placeholder' => (string) __('members.search_placeholder'),
                'attrs' => [
                    'data-placeholder-desktop' => (string) __('members.search_placeholder'),
                    'data-placeholder-mobile' => (string) __('members.search_placeholder_mobile'),
                    'enterkeyhint' => 'search',
                    'autocomplete' => 'off',
                ],
            ]) ?>
        </div>
        <?= field('select', [
            'name' => 'status',
            'label' => (string) __('members.status'),
            'value' => (string) ($filters['status'] ?? ''),
            'context' => 'filter',
            'empty_label' => (string) __('members.filter_all'),
            'options' => $memberStatusOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'payment',
            'label' => (string) __('members.payment'),
            'value' => (string) ($filters['payment'] ?? ''),
            'context' => 'filter',
            'empty_label' => (string) __('members.filter_all'),
            'options' => $memberPaymentOptions,
        ]) ?>
        <?= field('select', [
            'name' => 'member_type_id',
            'label' => (string) __('members.type'),
            'value' => (string) ($filters['member_type_id'] ?? ''),
            'context' => 'filter',
            'empty_label' => (string) __('members.filter_all'),
            'options' => $memberTypeOptions,
        ]) ?>
    </div>
</form>
<?php endif; ?>

<div class="panel" data-members-list data-members-base="<?= e(rtrim(url('/members'), '/')) ?>">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.results')) ?></h2>
            <p class="section-lede"><?= (int)$result['total'] ?> · <?= e(__('members.page')) ?> <?= (int)$page ?></p>
        </div>
    </div>
    <?php if (empty($result['items'])): ?>
        <div class="empty-state">
            <strong><?= e(__('members.empty_title')) ?></strong>
            <?= e(__('members.empty_text')) ?>
        </div>
    <?php else: ?>
        <?php if ($canManage): ?>
        <div class="members-bulk-bar" data-members-bulk-bar aria-hidden="true">
            <div class="members-bulk-bar-inner">
                <span class="members-bulk-count" data-members-bulk-count>0</span>
                <span class="muted"><?= e(__('members.bulk_selected')) ?></span>
                <div class="members-bulk-actions">
                    <button type="button" class="btn btn-sm btn-ghost" data-members-bulk-action="payment_reminder" <?= empty($mailReady) ? 'disabled title="' . e(__('mail.required_for_reminder')) . '"' : '' ?>><?= e(__('members.bulk_remind')) ?></button>
                    <button type="button" class="btn btn-sm btn-ghost" data-members-bulk-action="group_email" <?= empty($mailReady) ? 'disabled title="' . e(__('mail.required_for_group')) . '"' : '' ?>><?= e(__('members.bulk_email')) ?></button>
                    <button type="button" class="btn btn-sm btn-ghost" data-members-bulk-action="mass_renewal" data-confirm="<?= e(__('members.bulk_renew_confirm')) ?>"><?= e(__('members.bulk_renew')) ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="table-wrap embedded members-table-wrap">
            <table class="members-table">
                <thead>
                <tr>
                    <?php if ($canManage): ?>
                        <th class="members-col-check"><?= field('checkbox', [
                            'name' => '',
                            'label' => '',
                            'context' => 'inline',
                            'wrapper_class' => 'members-col-check-toggle',
                            'attrs' => [
                                'data-members-select-all' => true,
                                'aria-label' => (string) __('members.bulk_select_all'),
                            ],
                        ]) ?></th>
                    <?php endif; ?>
                    <th><?= e(__('members.member_number')) ?></th>
                    <th><?= e(__('members.type')) ?></th>
                    <th><?= e(__('members.status')) ?></th>
                    <?php if (!empty($gdprEnabled)): ?>
                        <th><?= e(__('members.gdpr_column')) ?></th>
                    <?php endif; ?>
                    <th><?= e(__('members.payment')) ?></th>
                    <th><?= e(__('members.balance_due')) ?></th>
                    <th><?= e(__('members.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <?php
                    $issues = is_array($item['compliance_issues'] ?? null) ? $item['compliance_issues'] : [];
                    $issueLabels = array_values(array_filter(array_map(
                        static fn ($i) => trim((string) ($i['label'] ?? '')),
                        $issues
                    )));
                    $issueText = implode("\n", $issueLabels);
                    $fullName = trim(($item['fields']['first_name'] ?? '') . ' ' . ($item['fields']['last_name'] ?? ''));
                    $balanceDue = (float) ($item['balance_due'] ?? 0);
                    $canCollect = $canPayments && $balanceDue > 0;
                    $canRemind = $canManage && $balanceDue > 0 && !empty($mailReady);
                    $memberStatus = (string) ($item['status'] ?? '');
                    $statusBadgeClass = match ($memberStatus) {
                        'active' => 'badge-ok',
                        'pending' => 'badge-warn',
                        'suspended' => 'badge-due',
                        'expired', 'cancelled' => 'badge-muted',
                        default => '',
                    };
                    $periodEndsOn = format_date((string) ($item['period_ends_on'] ?? ''));
                    $periodLabel = trim((string) ($item['period_label'] ?? ''));
                    $metaBits = ['#' . (string) $item['member_number']];
                    if ($periodEndsOn !== '') {
                        $metaBits[] = __('members.expires_short', ['date' => $periodEndsOn]);
                    } elseif ($periodLabel !== '') {
                        $metaBits[] = $periodLabel;
                    }
                    ?>
                    <tr<?= $issueLabels !== [] ? ' class="member-row-anomaly"' : '' ?> data-member-row="<?= (int) $item['id'] ?>">
                        <?php if ($canManage): ?>
                        <td class="members-col-check" data-label="">
                            <?= field('checkbox', [
                                'name' => 'member_ids[]',
                                'check_value' => (string) (int) $item['id'],
                                'label' => '',
                                'context' => 'inline',
                                'attrs' => [
                                    'data-member-select' => true,
                                    'aria-label' => $fullName !== '' ? $fullName : (string) $item['member_number'],
                                ],
                            ]) ?>
                        </td>
                        <?php endif; ?>
                        <td data-label="<?= e(__('members.member_number')) ?>">
                            <div class="member-list-identity">
                                <?php if ($issueLabels !== []): ?>
                                    <span
                                        class="member-anomaly-mark"
                                        tabindex="0"
                                        role="img"
                                        aria-label="<?= e(__('members.anomaly_aria') . ': ' . implode('; ', $issueLabels)) ?>"
                                        title="<?= e($issueText) ?>"
                                    >
                                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                                            <path fill="currentColor" d="M12 3.2 22 20.5H2L12 3.2zm0 5.3c-.7 0-1.2.5-1.1 1.2l.4 5.1h1.4l.4-5.1c.1-.7-.4-1.2-1.1-1.2zm0 8.3a1.15 1.15 0 1 0 0 2.3 1.15 1.15 0 0 0 0-2.3z"/>
                                        </svg>
                                        <span class="member-anomaly-tooltip"><?= e(implode(' · ', $issueLabels)) ?></span>
                                    </span>
                                <?php endif; ?>
                                <div>
                                    <a class="member-list-name" href="<?= e(url('/members/'.$item['id'])) ?>">
                                        <?= e($fullName !== '' ? $fullName : $item['member_number']) ?>
                                    </a>
                                    <div class="muted member-list-meta"><?= e(implode(' · ', $metaBits)) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="<?= e(__('members.type')) ?>"><?= e(localized($item['type_name_json'])) ?></td>
                        <td data-label="<?= e(__('members.status')) ?>">
                            <span class="badge<?= $statusBadgeClass !== '' ? ' ' . e($statusBadgeClass) : '' ?>"><?= e(__('members.status_'.$memberStatus)) ?></span>
                        </td>
                        <?php
                        $gdprBadges = is_array($item['gdpr_badges'] ?? null) ? $item['gdpr_badges'] : [];
                        if (!empty($gdprEnabled)): ?>
                        <td data-label="<?= e(__('members.gdpr_column')) ?>">
                            <?php if ($gdprBadges !== []): ?>
                                <div class="member-gdpr-badges">
                                    <?php foreach ($gdprBadges as $badge): ?>
                                        <span class="badge <?= !empty($badge['ok']) ? 'badge-ok' : 'badge-due' ?>" title="<?= e((string) ($badge['label'] ?? '')) ?>">
                                            <?= e((string) ($badge['label'] ?? '')) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td data-label="<?= e(__('members.payment')) ?>"><span class="badge <?= $item['payment_status']==='paid'?'badge-ok':($item['payment_status']==='due'?'badge-due':'badge-warn') ?>"><?= e(__('members.payment_'.$item['payment_status'])) ?></span></td>
                        <td data-label="<?= e(__('members.balance_due')) ?>"><?= e(number_format($balanceDue, 2, ',', '.')) ?> €</td>
                        <td data-label="<?= e(__('members.actions')) ?>">
                            <?= row_actions([
                                [
                                    'icon' => 'collect',
                                    'label' => __('members.collect_short'),
                                    'show' => $canCollect,
                                    'type' => 'button',
                                    'attrs' => [
                                        'data-member-collect' => true,
                                        'data-member-id' => (int) $item['id'],
                                        'data-member-name' => $fullName !== '' ? $fullName : (string) $item['member_number'],
                                        'data-member-balance' => number_format($balanceDue, 2, '.', ''),
                                    ],
                                ],
                                [
                                    'icon' => 'mail',
                                    'label' => __('members.remind_short'),
                                    'show' => $canRemind,
                                    'form_action' => url('/members/' . (int) $item['id'] . '/remind-payment'),
                                    'hidden' => ['redirect' => $redirectBack],
                                ],
                                [
                                    'icon' => 'view',
                                    'label' => __('members.scheda'),
                                    'href' => url('/members/' . (int) $item['id']),
                                ],
                                [
                                    'icon' => 'edit',
                                    'label' => __('members.edit_short'),
                                    'show' => $canManage,
                                    'href' => url('/members/' . (int) $item['id'] . '/edit'),
                                ],
                            ], ['class' => 'member-row-actions']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage && !empty($result['items'])): ?>
<form method="post" action="<?= e(url('/members/bulk')) ?>" data-members-bulk-form hidden>
    <?= csrf_field() ?>
    <?= field('hidden', ['name' => 'action', 'value' => '', 'attrs' => ['data-members-bulk-action-input' => true]]) ?>
    <?= field('hidden', ['name' => 'redirect', 'value' => $redirectBack]) ?>
    <?= field('hidden', ['name' => 'subject', 'value' => '', 'attrs' => ['data-members-bulk-subject' => true]]) ?>
    <?= field('hidden', ['name' => 'body', 'value' => '', 'attrs' => ['data-members-bulk-body' => true]]) ?>
    <div data-members-bulk-id-host></div>
</form>

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

<dialog class="member-bulk-email-dialog" data-members-bulk-email-dialog>
    <div class="member-leave-shell">
        <h3 class="section-title"><?= e(__('members.bulk_email_title')) ?></h3>
        <p class="muted"><?= e(__('members.bulk_email_lede')) ?></p>
        <?= field('text', [
            'name' => '',
            'label' => (string) __('members.bulk_email_subject'),
            'context' => 'member',
            'required' => true,
            'maxlength' => 160,
            'attrs' => ['data-members-bulk-email-subject' => true],
        ]) ?>
        <?= field('textarea', [
            'name' => '',
            'label' => (string) __('members.bulk_email_body'),
            'context' => 'member',
            'required' => true,
            'rows' => 6,
            'attrs' => ['data-members-bulk-email-body' => true],
            'hint' => (string) __('members.bulk_email_vars'),
        ]) ?>
        <div class="member-leave-actions">
            <button type="button" class="btn btn-ghost" data-members-bulk-email-cancel><?= e(__('common.cancel')) ?></button>
            <button type="button" class="btn" data-members-bulk-email-send><?= e(__('members.bulk_email_send')) ?></button>
        </div>
    </div>
</dialog>
<?php endif; ?>
