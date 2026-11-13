<?php
/** @var array<string,mixed> $filters */
/** @var int $page */
/** @var array{active_count:int,balance_due_total:float,pending_count:int} $summary */
$filters = is_array($filters ?? null) ? $filters : [];
$filters += ['q' => '', 'status' => '', 'member_type_id' => '', 'payment' => '', 'sort' => '', 'dir' => '', 'issues' => ''];
$redirectQuery = http_build_query(array_filter(array_merge($filters, ['page' => $page > 1 ? $page : null])));
// Local path, never an absolute URL: redirect() prefixes the base, so “https://host/members?q=…”
// became “https://host/https://host/members?q=…” after a bulk action.
$redirectBack = '/members' . ($redirectQuery !== '' ? '?' . $redirectQuery : '');
$canManage = can('members.manage');
$canPayments = can('payments.manage');
$sortKey = (string) ($filters['sort'] ?? 'member_number');
$sortDir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$sortUrl = static function (string $key) use ($filters, $sortKey, $sortDir): string {
    $next = ($sortKey === $key && $sortDir === 'asc') ? 'desc' : 'asc';
    $params = $filters;
    $params['sort'] = $key;
    $params['dir'] = $next;
    return url('/members?' . http_build_query(array_filter($params, static fn ($v): bool => $v !== '' && $v !== null)));
};
$sortHeader = static function (string $key, string $label) use ($sortKey, $sortDir, $sortUrl): string {
    $active = $sortKey === $key;
    $icon = $active ? ($sortDir === 'asc' ? '↑' : '↓') : '↕';
    $aria = $active
        ? ($sortDir === 'asc' ? __('members.sort_desc_hint') : __('members.sort_asc_hint'))
        : __('members.sort_hint');
    return '<th class="th-sort' . ($active ? ' is-sorted' : '') . '">'
        . '<a href="' . e($sortUrl($key)) . '" title="' . e($aria) . '" aria-label="' . e($label . ' — ' . $aria) . '">'
        . e($label) . ' <span class="th-sort-icon" aria-hidden="true">' . $icon . '</span></a></th>';
};
$today = new DateTimeImmutable('today');
$perPage = (int) ($result['per_page'] ?? 25);
$totalPages = max(1, (int) ceil(((int) $result['total']) / max(1, $perPage)));
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.title')) ?></h1>
        <p class="page-lede"><?= e(__('members.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if ($canManage): ?>
            <a class="btn<?= empty($hasAnyMembers) ? ' is-first-cta' : '' ?>" href="<?= e(url('/members/create')) ?>"><?= e(__('members.create')) ?></a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/statistics')) ?>"><?= e(__('statistics.open')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/members/export/registry?' . http_build_query(array_filter($filters)))) ?>" target="_blank" rel="noopener"><?= e(__('members.registry_export')) ?></a>
        <?php if ($canManage): ?>
            <a class="btn-action-icon" href="<?= e(url('/members/import')) ?>" aria-label="<?= e(__('members.import.button')) ?>" title="<?= e(__('members.import.button')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3l5 5h-3v6h-4V8H7l5-5zM5 19h14v2H5v-2z"/></svg>
            </a>
        <?php endif; ?>
        <a class="btn-action-icon" href="<?= e(url('/members/export?' . http_build_query(array_filter($filters)))) ?>" aria-label="<?= e(__('members.export')) ?>" title="<?= e(__('members.export')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 21l-5-5h3v-6h4v6h3l-5 5zM5 3h14v2H5V3z"/></svg>
        </a>
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
        <div class="value"><?= e(number_format((float) ($summary['balance_due_total'] ?? 0), 2, ',', '.')) ?>&nbsp;€</div>
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
                    <button type="button" class="btn btn-sm btn-ghost is-danger" data-members-bulk-action="delete" data-confirm="<?= e(__('members.bulk_delete_confirm')) ?>">
                        <?= row_action_icon_svg('delete') ?>
                        <span><?= e(__('members.bulk_delete')) ?></span>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // Banner: members with missing mandatory data — fix in bulk (only derivable values) or filter.
        $issuesCount = (int) ($result['issues_count'] ?? 0);
        $issuesActive = !empty($filters['issues']);
        $autofixCount = (int) ($autofixCount ?? 0);
        $issuesAutofix = $canManage && $autofixCount > 0;
        $issuesClearUrl = url('/members?' . http_build_query(array_filter(array_merge($filters, ['issues' => null]))));
        $issuesFilterUrl = url('/members?' . http_build_query(array_filter(array_merge($filters, ['issues' => '1', 'page' => null]))));
        ?>
        <?php if ($issuesActive || $issuesCount > 0): ?>
            <div class="alert alert-error member-issues-banner" role="status">
                <span><?= e($issuesActive
                    ? __('members.issues_filter_active', ['count' => (string) $issuesCount])
                    : __('members.issues_banner', ['count' => (string) $issuesCount])) ?></span>
                <div class="member-issues-banner-actions">
                    <?php if ($issuesAutofix): ?>
                        <form method="post" action="<?= e(url('/members/autofix-all')) ?>" class="member-issues-autofix">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm" type="submit"><?= e(__('members.autofix_all_button', ['count' => (string) $autofixCount])) ?></button>
                        </form>
                    <?php elseif ($canManage): ?>
                        <button class="btn btn-sm" type="button" disabled title="<?= e(__('members.autofix_all_disabled')) ?>"><?= e(__('members.autofix_all_disabled')) ?></button>
                    <?php endif; ?>
                    <?php if ($issuesActive): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e($issuesClearUrl) ?>"><?= e(__('members.issues_filter_clear')) ?></a>
                    <?php else: ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e($issuesFilterUrl) ?>"><?= e(__('members.issues_filter_link')) ?></a>
                    <?php endif; ?>
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
                    <?= $sortHeader('name', (string) __('members.column_member')) ?>
                    <?= $sortHeader('type', (string) __('members.type')) ?>
                    <?= $sortHeader('status', (string) __('members.status')) ?>
                    <?php if (!empty($gdprEnabled)): ?>
                        <th><?= e(__('members.gdpr_column')) ?></th>
                    <?php endif; ?>
                    <?= $sortHeader('payment', (string) __('members.payment')) ?>
                    <th><?= e(__('members.situation_column')) ?></th>
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
                    // Under the name: gender, updated age and cake on the birthday.
                    $genderRaw = strtoupper(trim((string) ($item['fields']['gender'] ?? '')));
                    $genderLabel = match ($genderRaw) {
                        'M' => (string) __('members.gender_m'),
                        'F' => (string) __('members.gender_f'),
                        'X' => (string) __('members.gender_x'),
                        default => '',
                    };
                    $birthRaw = trim((string) ($item['fields']['birth_date'] ?? ''));
                    $age = null;
                    $isBirthday = false;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthRaw) === 1) {
                        try {
                            $birthDt = new DateTimeImmutable($birthRaw);
                            $age = (int) $birthDt->diff($today)->y;
                            $isBirthday = $birthDt->format('m-d') === $today->format('m-d');
                        } catch (Throwable) {
                            $age = null;
                        }
                    }
                    $metaBits = [];
                    if ($genderLabel !== '') {
                        $metaBits[] = $genderLabel;
                    }
                    if ($age !== null) {
                        $metaBits[] = (string) __('members.age_years', ['age' => (string) $age]);
                    }
                    ?>
                    <tr
                        class="clickable-row<?= $issueLabels !== [] ? ' member-row-anomaly' : '' ?>"
                        data-member-row="<?= (int) $item['id'] ?>"
                        data-href="<?= e(url('/members/'.$item['id'])) ?>"
                        tabindex="0"
                        role="link"
                        aria-label="<?= e(($fullName !== '' ? $fullName : (string) $item['member_number']) . ' — ' . __('members.scheda')) ?>"
                    >
                        <?php if ($canManage): ?>
                        <td class="members-col-check" data-label="" data-stop-row-nav>
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
                        <td data-label="<?= e(__('members.column_member')) ?>">
                            <div class="member-list-identity">
                                <?php if ($issueLabels !== []): ?>
                                    <span
                                        class="member-anomaly-mark"
                                        tabindex="0"
                                        role="img"
                                        data-stop-row-nav
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
                                        <?php if ($isBirthday): ?>
                                            <span class="member-birthday" title="<?= e(__('members.birthday_today')) ?>" aria-label="<?= e(__('members.birthday_today')) ?>">
                                                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false">
                                                    <path fill="currentColor" d="M12 2.2c.9 1.1 1.6 2 1.6 2.9a1.6 1.6 0 0 1-3.2 0c0-.9.7-1.8 1.6-2.9zM5 9h14a1 1 0 0 1 1 1v3H4v-3a1 1 0 0 1 1-1zm-1 6h16v5.3a.7.7 0 0 1-.7.7H4.7a.7.7 0 0 1-.7-.7V15zm2.2-1.6c.9 0 1.6-.6 2-1.2.4.6 1.1 1.2 2 1.2s1.6-.6 2-1.2c.4.6 1.1 1.2 2 1.2s1.6-.6 2-1.2c.4.6 1.1 1.2 2 1.2V15h-12v-1.6z"/>
                                                </svg>
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                    <?php if ($metaBits !== []): ?>
                                        <div class="muted member-list-meta"><?= e(implode(' · ', $metaBits)) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td data-label="<?= e(__('members.type')) ?>">
                            <div><?= e(localized($item['type_name_json'])) ?></div>
                            <?php if ($periodEndsOn !== ''): ?>
                                <div class="muted members-type-expiry"><?= e(__('members.card_expiry_short', ['date' => $periodEndsOn])) ?></div>
                            <?php elseif ($periodLabel !== ''): ?>
                                <div class="muted members-type-expiry"><?= e($periodLabel) ?></div>
                            <?php endif; ?>
                        </td>
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
                        <?php
                        $lastPaidAmount = $item['last_payment_amount'] ?? null;
                        $lastPaidAt = (string) ($item['last_payment_at'] ?? '');
                        $paymentLabel = __('members.payment_' . $item['payment_status']);
                        if ($lastPaidAmount !== null && $lastPaidAmount !== '' && $lastPaidAt !== '') {
                            $paymentLabel .= ' ' . __('members.payment_last_suffix', [
                                'amount' => number_format((float) $lastPaidAmount, 2, ',', '.'),
                                'date' => (string) format_date($lastPaidAt),
                            ]);
                        }
                        ?>
                        <td data-label="<?= e(__('members.payment')) ?>"><span class="badge <?= $item['payment_status']==='paid'?'badge-ok':($item['payment_status']==='due'?'badge-due':'badge-warn') ?>"><?= e($paymentLabel) ?></span></td>
                        <?php
                        // "Situazione": small anomaly column replacing the plain balance amount.
                        $situationBadges = [];
                        $typePrice = (float) ($item['type_price'] ?? 0);
                        $paidTotal = (float) ($item['paid_total'] ?? 0);
                        $creditAmount = $typePrice > 0 ? round($paidTotal - $typePrice, 2) : 0.0;
                        if ($balanceDue > 0.009) {
                            $situationBadges[] = [
                                'class' => 'badge-due',
                                'label' => __('members.situation_debt', ['amount' => number_format($balanceDue, 2, ',', '.')]),
                            ];
                        } elseif ($creditAmount > 0.009) {
                            $situationBadges[] = [
                                'class' => 'badge-ok',
                                'label' => __('members.situation_credit', ['amount' => number_format($creditAmount, 2, ',', '.')]),
                            ];
                        }
                        $periodEndSince = !empty($item['period_ends_on']) ? strtotime((string) $item['period_ends_on']) : false;
                        if ($periodEndSince !== false
                            && $periodEndSince < strtotime('today')
                            && !in_array($memberStatus, ['expired', 'cancelled'], true)) {
                            $situationBadges[] = [
                                'class' => 'badge-warn',
                                'label' => __('members.situation_card_expired'),
                            ];
                        }
                        if ($situationBadges === []) {
                            $situationBadges[] = ['class' => 'badge-ok', 'label' => __('members.situation_ok')];
                        }
                        ?>
                        <td data-label="<?= e(__('members.situation_column')) ?>">
                            <div class="member-situation">
                                <?php foreach ($situationBadges as $situationBadge): ?>
                                    <span class="badge <?= e((string) $situationBadge['class']) ?>"><?= e((string) $situationBadge['label']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td data-label="<?= e(__('members.actions')) ?>" data-stop-row-nav>
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
        <?php if ($totalPages > 1): ?>
            <?php
            // Pager: window of pages around the current one + first/last.
            $pageWindow = [];
            $pageWindow[] = 1;
            for ($p = max(2, $page - 1); $p <= min($totalPages - 1, $page + 1); $p++) {
                $pageWindow[] = $p;
            }
            if ($totalPages > 1) {
                $pageWindow[] = $totalPages;
            }
            $pageWindow = array_values(array_unique($pageWindow));
            sort($pageWindow);
            $pageUrl = static function (int $target) use ($filters): string {
                $params = array_filter(array_merge($filters, ['page' => $target > 1 ? $target : null]), static fn ($v): bool => $v !== '' && $v !== null);
                return url('/members?' . http_build_query($params));
            };
            ?>
            <nav class="list-pager" aria-label="<?= e(__('members.pager_aria')) ?>">
                <?php if ($page > 1): ?>
                    <a class="list-pager-step" href="<?= e($pageUrl($page - 1)) ?>" rel="prev">← <?= e(__('members.pager_prev')) ?></a>
                <?php else: ?>
                    <span class="list-pager-step is-disabled">← <?= e(__('members.pager_prev')) ?></span>
                <?php endif; ?>
                <div class="list-pager-pages">
                    <?php $previous = 0; foreach ($pageWindow as $p): ?>
                        <?php if ($previous > 0 && $p > $previous + 1): ?>
                            <span class="list-pager-gap" aria-hidden="true">…</span>
                        <?php endif; ?>
                        <a class="list-pager-page<?= $p === $page ? ' is-current' : '' ?>"
                           href="<?= e($pageUrl($p)) ?>"
                           <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                        <?php $previous = $p; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($page < $totalPages): ?>
                    <a class="list-pager-step" href="<?= e($pageUrl($page + 1)) ?>" rel="next"><?= e(__('members.pager_next')) ?> →</a>
                <?php else: ?>
                    <span class="list-pager-step is-disabled"><?= e(__('members.pager_next')) ?> →</span>
                <?php endif; ?>
                <span class="muted list-pager-info"><?= e(__('members.pager_info', ['page' => (string) $page, 'pages' => (string) $totalPages])) ?></span>
            </nav>
        <?php endif; ?>
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
            <?= field('date', [
                'name' => 'paid_on',
                'label' => (string) __('payments.paid_on'),
                'context' => 'member',
                'required' => true,
                'value' => date('Y-m-d'),
            ]) ?>
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
