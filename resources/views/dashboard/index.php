<?php
/**
 * @var array<string,mixed> $stats
 * @var array<string,mixed> $widgets
 * @var array{members:bool,treasury:bool,deadlines:bool,documents:bool,org:bool} $enabled
 * @var string $chartsJson
 * @var string $chartI18n
 * @var string $treasuryChartsJson
 * @var string $treasuryChartI18n
 */
$charts = $stats['charts'] ?? [];
$enabled = $enabled ?? [
    'members' => false,
    'treasury' => false,
    'deadlines' => false,
    'documents' => false,
    'org' => false,
];
$hasMembers = (int) ($stats['members_total'] ?? 0) > 0;
$hasCollections = array_sum($charts['collections']['values'] ?? []) > 0;
$hasTypes = array_sum($charts['by_type']['values'] ?? []) > 0;
$treasuryCharts = $widgets['treasury']['charts'] ?? [];
$treasuryFlowValues = $treasuryCharts['flow']['values'] ?? [0, 0];
$hasTreasuryFlow = ((float) ($treasuryFlowValues[0] ?? 0) + (float) ($treasuryFlowValues[1] ?? 0)) > 0;
$hasTreasuryExpenseCats = array_sum($treasuryCharts['expense_by_category']['values'] ?? []) > 0;
$hasTreasuryIncomeCats = array_sum($treasuryCharts['income_by_category']['values'] ?? []) > 0;
$treasuryWidget = is_array($widgets['treasury'] ?? null) ? $widgets['treasury'] : null;
$hasTreasuryData = $treasuryWidget !== null && (
    !empty($treasuryWidget['recent'])
    || abs((float) ($treasuryWidget['balance'] ?? 0)) > 0.001
    || (float) ($treasuryWidget['income'] ?? 0) > 0
    || (float) ($treasuryWidget['expense'] ?? 0) > 0
);
$deadlineWidget = is_array($widgets['deadlines'] ?? null) ? $widgets['deadlines'] : null;
$deadlineCounts = $deadlineWidget['counts'] ?? [];
$hasDeadlines = $deadlineWidget !== null && (
    !empty($deadlineWidget['items'])
    || ((int) ($deadlineCounts['overdue'] ?? 0) + (int) ($deadlineCounts['due_soon'] ?? 0) + (int) ($deadlineCounts['open'] ?? 0)) > 0
);
$documentWidget = is_array($widgets['documents'] ?? null) ? $widgets['documents'] : null;
$hasDocuments = $documentWidget !== null && (int) ($documentWidget['total'] ?? 0) > 0;
$needsChartJs = (!empty($enabled['members']) && $hasMembers) || (!empty($enabled['treasury']) && $hasTreasuryData);
$assocName = (string) (app()->branding()['name'] ?? 'SOCLY');
$showAssocInTitle = $assocName !== '' && strcasecmp($assocName, 'SOCLY') !== 0;
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));

$tabs = [];
if (!empty($enabled['members'])) {
    $tabs[] = ['id' => 'members', 'label' => __('nav.members')];
}
if (!empty($enabled['treasury'])) {
    $tabs[] = ['id' => 'treasury', 'label' => __('nav.treasury')];
}
if (!empty($enabled['deadlines'])) {
    $tabs[] = ['id' => 'deadlines', 'label' => __('nav.deadlines')];
}
if (!empty($enabled['documents'])) {
    $tabs[] = ['id' => 'documents', 'label' => __('nav.documents')];
}
if (!empty($enabled['org'])) {
    $tabs[] = ['id' => 'org', 'label' => __('nav.org')];
}
$defaultTab = $tabs[0]['id'] ?? '';
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title page-title-brand">
            <?php if ($showAssocInTitle): ?>
                <span class="title-lead title-lead-desktop"><?= e(__('dashboard.title_of')) ?></span>
                <span class="title-lead title-lead-mobile"><?= e(__('dashboard.title')) ?></span>
                <?= assoc_lockup_html(['class' => 'assoc-lockup-title']) ?>
            <?php else: ?>
                <span class="title-lead"><?= e(__('dashboard.title')) ?></span>
            <?php endif; ?>
        </h1>
        <p class="page-lede"><?= e(__('dashboard.lede')) ?></p>
    </div>
</div>

<?php if ($tabs === []): ?>
    <div class="panel">
        <div class="empty-state">
            <strong><?= e(__('dashboard.no_modules_title')) ?></strong>
            <?= e(__('dashboard.no_modules_text')) ?>
            <?php if (can('settings.manage')): ?>
                <div style="margin-top:1rem">
                    <a class="btn" href="<?= e(url('/settings#components')) ?>"><?= e(__('dashboard.open_components')) ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<div class="dashboard-tabs" data-dashboard-tabs data-default-tab="<?= e($defaultTab) ?>">
    <div class="dashboard-tablist-wrap" data-dashboard-tablist-wrap>
        <button type="button" class="dashboard-tablist-nav dashboard-tablist-nav-prev" data-dashboard-tablist-prev hidden aria-label="<?= e(__('dashboard.tabs_scroll_prev')) ?>">
            <span aria-hidden="true"></span>
        </button>
        <div class="dashboard-tablist" role="tablist" aria-label="<?= e(__('dashboard.title')) ?>" data-dashboard-tablist>
            <?php foreach ($tabs as $i => $tab): ?>
                <button
                    type="button"
                    class="dashboard-tab"
                    role="tab"
                    id="dashboard-tab-<?= e($tab['id']) ?>"
                    data-dashboard-tab="<?= e($tab['id']) ?>"
                    aria-controls="dashboard-panel-<?= e($tab['id']) ?>"
                    aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                    tabindex="<?= $i === 0 ? '0' : '-1' ?>"
                >
                    <?= e($tab['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="dashboard-tablist-nav dashboard-tablist-nav-next" data-dashboard-tablist-next hidden aria-label="<?= e(__('dashboard.tabs_scroll_next')) ?>">
            <span aria-hidden="true"></span>
        </button>
    </div>

    <?php if (!empty($enabled['members'])): ?>
        <section
            class="dashboard-panel"
            id="dashboard-panel-members"
            role="tabpanel"
            data-dashboard-panel="members"
            aria-labelledby="dashboard-tab-members"
            <?= $defaultTab === 'members' ? '' : 'hidden' ?>
        >
            <div class="panel-header dashboard-panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('nav.members')) ?></h2>
                    <p class="section-lede"><?= e(__('dashboard.tab_members_lede')) ?></p>
                </div>
                <div class="actions">
                    <?php if (can('members.manage')): ?>
                        <a class="btn btn-sm" href="<?= e(url('/members/create')) ?>"><?= e(__('members.create')) ?></a>
                    <?php endif; ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/members')) ?>"><?= e(__('dashboard.open_members')) ?></a>
                </div>
            </div>

            <?php if ($hasMembers): ?>
            <div class="stats stats-context-members" data-stats-context="members">
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.members_total')) ?></div>
                    <div class="value"><?= (int) ($stats['members_total'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.overdue_count')) ?></div>
                    <div class="value"><?= (int) ($stats['overdue_count'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.collected_month')) ?></div>
                    <div class="value"><?= e($currency->format((float) ($stats['collected_month'] ?? 0))) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.members_active')) ?></div>
                    <div class="value"><?= (int) ($stats['members_active'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.members_settled')) ?></div>
                    <div class="value"><?= (int) ($stats['members_settled'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.collected_year')) ?></div>
                    <div class="value"><?= e($currency->format((float) ($stats['collected_year'] ?? 0))) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.members_expired')) ?></div>
                    <div class="value"><?= (int) ($stats['members_expired'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.members_suspended')) ?></div>
                    <div class="value"><?= (int) ($stats['members_suspended'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.new_members_year')) ?></div>
                    <div class="value"><?= (int) ($stats['new_members_year'] ?? 0) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$hasMembers): ?>
                <div class="panel">
                    <div class="empty-state">
                        <strong><?= e(__('dashboard.empty_title')) ?></strong>
                        <p><?= e(__('dashboard.empty_text')) ?></p>
                        <?php if (can('members.manage')): ?>
                            <div style="margin-top:1rem">
                                <a class="btn" href="<?= e(url('/members/create')) ?>"><?= e(__('dashboard.empty_create_member')) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="charts" data-dashboard-charts="<?= e($chartsJson) ?>" data-chart-i18n="<?= e($chartI18n) ?>">
                    <div class="panel chart-panel chart-panel-wide chart-panel-primary">
                        <div class="panel-header">
                            <div>
                                <h2 class="section-title"><?= e(__('dashboard.chart_collections_title')) ?></h2>
                                <p class="section-lede"><?= e(__('dashboard.chart_collections_lede')) ?></p>
                            </div>
                        </div>
                        <?php if (!$hasCollections && array_sum($charts['new_members']['values'] ?? []) === 0): ?>
                            <div class="empty-state compact"><?= e(__('dashboard.chart_empty')) ?></div>
                        <?php else: ?>
                            <div class="chart-frame">
                                <canvas id="chart-collections" aria-label="<?= e(__('dashboard.chart_collections_title')) ?>"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="panel chart-panel">
                        <div class="panel-header">
                            <div>
                                <h2 class="section-title"><?= e(__('dashboard.chart_types_title')) ?></h2>
                                <p class="section-lede"><?= e(__('dashboard.chart_types_lede')) ?></p>
                            </div>
                        </div>
                        <?php if (!$hasTypes): ?>
                            <div class="empty-state compact"><?= e(__('dashboard.chart_empty')) ?></div>
                        <?php else: ?>
                            <div class="chart-frame chart-frame-donut">
                                <canvas id="chart-types" aria-label="<?= e(__('dashboard.chart_types_title')) ?>"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="panel chart-panel">
                        <div class="panel-header">
                            <div>
                                <h2 class="section-title"><?= e(__('dashboard.chart_standing_title')) ?></h2>
                                <p class="section-lede"><?= e(__('dashboard.chart_standing_lede')) ?></p>
                            </div>
                        </div>
                        <div class="chart-frame chart-frame-donut">
                            <canvas id="chart-standing" aria-label="<?= e(__('dashboard.chart_standing_title')) ?>"></canvas>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($enabled['treasury']) && is_array($widgets['treasury'] ?? null)): ?>
        <section
            class="dashboard-panel"
            id="dashboard-panel-treasury"
            role="tabpanel"
            data-dashboard-panel="treasury"
            aria-labelledby="dashboard-tab-treasury"
            <?= $defaultTab === 'treasury' ? '' : 'hidden' ?>
        >
            <div class="panel-header dashboard-panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('nav.treasury')) ?></h2>
                    <p class="section-lede"><?= e(__('dashboard.tab_treasury_lede')) ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/treasury')) ?>"><?= e(__('dashboard.open_treasury')) ?></a>
            </div>

            <?php if ($hasTreasuryData): ?>
            <div class="stats stats-context-treasury" data-stats-context="treasury">
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.treasury_balance')) ?></div>
                    <div class="value"><?= e($currency->format((float) ($widgets['treasury']['balance'] ?? 0))) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.treasury_income')) ?></div>
                    <div class="value stat-positive"><?= e($currency->format((float) ($widgets['treasury']['income'] ?? 0))) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.treasury_expense')) ?></div>
                    <div class="value stat-negative"><?= e($currency->format((float) ($widgets['treasury']['expense'] ?? 0))) ?></div>
                </div>
            </div>

            <div
                class="charts charts-treasury"
                data-treasury-charts="<?= e($treasuryChartsJson ?? '{}') ?>"
                data-treasury-chart-i18n="<?= e($treasuryChartI18n ?? '{}') ?>"
            >
                <div class="panel chart-panel chart-panel-primary">
                    <div class="panel-header">
                        <div>
                            <h3 class="section-title"><?= e(__('dashboard.treasury_chart_flow_title')) ?></h3>
                            <p class="section-lede"><?= e(__('dashboard.treasury_chart_flow_lede')) ?></p>
                        </div>
                    </div>
                    <?php if (!$hasTreasuryFlow): ?>
                        <div class="empty-state compact"><?= e(__('dashboard.chart_empty')) ?></div>
                    <?php else: ?>
                        <div class="chart-frame chart-frame-donut">
                            <canvas id="chart-treasury-flow" aria-label="<?= e(__('dashboard.treasury_chart_flow_title')) ?>"></canvas>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel chart-panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="section-title"><?= e(__('dashboard.treasury_chart_expense_title')) ?></h3>
                            <p class="section-lede"><?= e(__('dashboard.treasury_chart_expense_lede')) ?></p>
                        </div>
                    </div>
                    <?php if (!$hasTreasuryExpenseCats): ?>
                        <div class="empty-state compact"><?= e(__('dashboard.treasury_chart_expense_empty')) ?></div>
                    <?php else: ?>
                        <div class="chart-frame chart-frame-donut">
                            <canvas id="chart-treasury-expense" aria-label="<?= e(__('dashboard.treasury_chart_expense_title')) ?>"></canvas>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel chart-panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="section-title"><?= e(__('dashboard.treasury_chart_income_title')) ?></h3>
                            <p class="section-lede"><?= e(__('dashboard.treasury_chart_income_lede')) ?></p>
                        </div>
                    </div>
                    <?php if (!$hasTreasuryIncomeCats): ?>
                        <div class="empty-state compact"><?= e(__('dashboard.treasury_chart_income_empty')) ?></div>
                    <?php else: ?>
                        <div class="chart-frame chart-frame-donut">
                            <canvas id="chart-treasury-income" aria-label="<?= e(__('dashboard.treasury_chart_income_title')) ?>"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="section-title"><?= e(__('dashboard.treasury_title')) ?></h3>
                        <p class="section-lede"><?= e(__('dashboard.treasury_lede')) ?></p>
                    </div>
                </div>
                <?php if (empty($widgets['treasury']['recent'])): ?>
                    <div class="empty-state compact"><?= e(__('dashboard.treasury_empty')) ?></div>
                <?php else: ?>
                    <ul class="dashboard-list">
                        <?php foreach ($widgets['treasury']['recent'] as $row): ?>
                            <?php
                            $isIncome = ($row['direction'] ?? '') === 'income';
                            $amount = (float) ($row['amount'] ?? 0);
                            $desc = trim((string) ($row['description'] ?? ''));
                            if ($desc === '') {
                                $desc = $isIncome ? __('treasury.direction_income') : __('treasury.direction_expense');
                            }
                            $rowId = (int) ($row['id'] ?? 0);
                            $href = can('treasury.manage')
                                ? url('/treasury/' . $rowId . '/edit')
                                : url('/treasury');
                            ?>
                            <li>
                                <a class="dashboard-list-link" href="<?= e($href) ?>">
                                    <span class="dashboard-list-main">
                                        <strong><?= e($desc) ?></strong>
                                        <span class="muted"><?= e(format_date($row['movement_date'] ?? null) ?: '—') ?></span>
                                    </span>
                                    <span class="<?= $isIncome ? 'amount-income' : 'amount-expense' ?>">
                                        <?= $isIncome ? '+' : '−' ?><?= e($currency->format($amount)) ?>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php else: ?>
                <div class="panel">
                    <div class="empty-state">
                        <strong><?= e(__('dashboard.treasury_empty_title')) ?></strong>
                        <p><?= e(__('dashboard.treasury_empty_text')) ?></p>
                        <?php if (can('treasury.manage')): ?>
                            <div style="margin-top:1rem">
                                <a class="btn" href="<?= e(url('/treasury')) ?>"><?= e(__('dashboard.treasury_empty_create')) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($enabled['deadlines']) && is_array($widgets['deadlines'] ?? null)): ?>
        <section
            class="dashboard-panel"
            id="dashboard-panel-deadlines"
            role="tabpanel"
            data-dashboard-panel="deadlines"
            aria-labelledby="dashboard-tab-deadlines"
            <?= $defaultTab === 'deadlines' ? '' : 'hidden' ?>
        >
            <div class="panel-header dashboard-panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('nav.deadlines')) ?></h2>
                    <p class="section-lede"><?= e(__('dashboard.tab_deadlines_lede')) ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/deadlines')) ?>"><?= e(__('dashboard.open_deadlines')) ?></a>
            </div>

            <?php if ($hasDeadlines): ?>
            <div class="stats stats-context-deadlines" data-stats-context="deadlines">
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.deadlines_overdue')) ?></div>
                    <div class="value stat-negative"><?= (int) ($widgets['deadlines']['counts']['overdue'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.deadlines_soon')) ?></div>
                    <div class="value"><?= (int) ($widgets['deadlines']['counts']['due_soon'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.deadlines_open')) ?></div>
                    <div class="value"><?= (int) ($widgets['deadlines']['counts']['open'] ?? 0) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="section-title"><?= e(__('dashboard.deadlines_title')) ?></h3>
                        <p class="section-lede"><?= e(__('dashboard.deadlines_lede')) ?></p>
                    </div>
                </div>
                <?php if (!$hasDeadlines): ?>
                    <div class="empty-state">
                        <strong><?= e(__('dashboard.deadlines_empty_title')) ?></strong>
                        <p><?= e(__('dashboard.deadlines_empty_text')) ?></p>
                        <?php if (can('deadlines.manage')): ?>
                            <div style="margin-top:1rem">
                                <a class="btn" href="<?= e(url('/deadlines')) ?>"><?= e(__('dashboard.deadlines_empty_create')) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif (empty($widgets['deadlines']['items'])): ?>
                    <div class="empty-state compact"><?= e(__('dashboard.deadlines_empty')) ?></div>
                <?php else: ?>
                    <ul class="deadline-list compact">
                        <?php foreach ($widgets['deadlines']['items'] as $item): ?>
                            <?php
                            $due = (string) ($item['due_date'] ?? '');
                            $state = 'valid';
                            if ($due !== '' && $due < $today) {
                                $state = 'overdue';
                            } elseif ($due !== '' && $due <= $soon) {
                                $state = 'soon';
                            }
                            $itemId = (int) ($item['id'] ?? 0);
                            $href = can('deadlines.manage')
                                ? url('/deadlines/' . $itemId . '/edit')
                                : url('/deadlines');
                            ?>
                            <li class="deadline-item deadline-<?= e($state) ?>">
                                <a class="deadline-link" href="<?= e($href) ?>">
                                    <div class="deadline-main">
                                        <span class="deadline-badge deadline-badge-<?= e($state) ?>"><?= e(__('deadlines.badge_' . $state)) ?></span>
                                        <strong><?= e((string) ($item['title'] ?? '')) ?></strong>
                                    </div>
                                    <div class="deadline-meta"><span><?= e(format_date($due) ?: '—') ?></span></div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($enabled['documents']) && is_array($widgets['documents'] ?? null)): ?>
        <section
            class="dashboard-panel"
            id="dashboard-panel-documents"
            role="tabpanel"
            data-dashboard-panel="documents"
            aria-labelledby="dashboard-tab-documents"
            <?= $defaultTab === 'documents' ? '' : 'hidden' ?>
        >
            <div class="panel-header dashboard-panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('nav.documents')) ?></h2>
                    <p class="section-lede"><?= e(__('dashboard.tab_documents_lede')) ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/documents')) ?>"><?= e(__('dashboard.open_documents')) ?></a>
            </div>

            <?php if ($hasDocuments): ?>
            <div class="stats stats-context-documents" data-stats-context="documents">
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.documents_total')) ?></div>
                    <div class="value"><?= (int) ($widgets['documents']['total'] ?? 0) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="section-title"><?= e(__('dashboard.documents_title')) ?></h3>
                        <p class="section-lede"><?= e(__('dashboard.documents_lede', ['count' => (string) (int) ($widgets['documents']['total'] ?? 0)])) ?></p>
                    </div>
                </div>
                <?php if (!$hasDocuments): ?>
                    <div class="empty-state">
                        <strong><?= e(__('dashboard.documents_empty_title')) ?></strong>
                        <p><?= e(__('dashboard.documents_empty_text')) ?></p>
                        <?php if (can('documents.manage')): ?>
                            <div style="margin-top:1rem">
                                <a class="btn" href="<?= e(url('/documents')) ?>"><?= e(__('dashboard.documents_empty_create')) ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif (empty($widgets['documents']['recent'])): ?>
                    <div class="empty-state compact"><?= e(__('dashboard.documents_empty')) ?></div>
                <?php else: ?>
                    <ul class="dashboard-list">
                        <?php foreach ($widgets['documents']['recent'] as $doc): ?>
                            <?php
                            $docId = (int) ($doc['id'] ?? 0);
                            $href = can('documents.manage')
                                ? url('/documents/' . $docId . '/edit')
                                : url('/documents');
                            $cat = trim((string) ($doc['category'] ?? ''));
                            $catLabel = $cat !== '' ? __('documents.category_' . $cat) : '';
                            if ($catLabel === 'documents.category_' . $cat) {
                                $catLabel = $cat;
                            }
                            ?>
                            <li>
                                <a class="dashboard-list-link" href="<?= e($href) ?>">
                                    <span class="dashboard-list-main">
                                        <strong><?= e((string) ($doc['title'] ?? '')) ?></strong>
                                        <span class="muted">
                                            <?= e(format_date($doc['document_date'] ?? null) ?: '—') ?>
                                            <?php if ($catLabel !== ''): ?> · <?= e($catLabel) ?><?php endif; ?>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($enabled['org']) && is_array($widgets['org'] ?? null)): ?>
        <?php
        $president = $widgets['org']['president'] ?? null;
        $presidentName = $president
            ? trim((string) (($president['first_name'] ?? '') . ' ' . ($president['last_name'] ?? '')))
            : '';
        ?>
        <section
            class="dashboard-panel"
            id="dashboard-panel-org"
            role="tabpanel"
            data-dashboard-panel="org"
            aria-labelledby="dashboard-tab-org"
            <?= $defaultTab === 'org' ? '' : 'hidden' ?>
        >
            <div class="panel-header dashboard-panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('nav.org')) ?></h2>
                    <p class="section-lede"><?= e(__('dashboard.tab_org_lede')) ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('/org')) ?>"><?= e(__('dashboard.open_org')) ?></a>
            </div>

            <div class="stats stats-context-org" data-stats-context="org">
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.org_people')) ?></div>
                    <div class="value"><?= (int) ($widgets['org']['people_count'] ?? 0) ?></div>
                </div>
                <div class="stat">
                    <div class="label"><?= e(__('dashboard.org_vacant')) ?></div>
                    <div class="value"><?= (int) ($widgets['org']['vacant_roles'] ?? 0) ?> / <?= (int) ($widgets['org']['roles_count'] ?? 0) ?></div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="section-title"><?= e(__('dashboard.org_title')) ?></h3>
                        <p class="section-lede"><?= e(__('dashboard.org_lede')) ?></p>
                    </div>
                </div>
                <div class="dashboard-org-summary">
                    <div class="dashboard-org-row">
                        <span class="muted"><?= e(__('dashboard.org_president')) ?></span>
                        <strong><?= e($presidentName !== '' ? $presidentName : __('org.vacant')) ?></strong>
                    </div>
                    <div class="dashboard-org-row">
                        <span class="muted"><?= e(__('dashboard.org_people')) ?></span>
                        <strong><?= (int) ($widgets['org']['people_count'] ?? 0) ?></strong>
                    </div>
                    <div class="dashboard-org-row">
                        <span class="muted"><?= e(__('dashboard.org_vacant')) ?></span>
                        <strong><?= (int) ($widgets['org']['vacant_roles'] ?? 0) ?> / <?= (int) ($widgets['org']['roles_count'] ?? 0) ?></strong>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php if (!empty($needsChartJs)): ?>
    <script src="<?= e(asset('js/chart.umd.min.js')) ?>"></script>
<?php endif; ?>

<?php endif; ?>
