<?php
/**
 * Detailed statistics (read-only aggregates).
 *
 * @var array<string,mixed> $overview
 * @var array<string,mixed> $demographics
 * @var array<string,mixed> $registrations
 * @var array<string,mixed> $cards
 * @var array<string,mixed> $finances
 * @var \Socly\Services\CurrencyService $currency
 * @var string $chartsJson
 */
$membersTotal = (int) ($overview['members_total'] ?? 0);
$new30 = (int) ($overview['members_new_30'] ?? 0);
$growthPct = $membersTotal > 0 ? round($new30 * 100 / $membersTotal, 1) : 0.0;
$cardsTotal = (int) ($overview['cards_total'] ?? 0);
$cardsActive = (int) ($overview['cards_active'] ?? 0);
$cardsActivePct = $cardsTotal > 0 ? round($cardsActive * 100 / $cardsTotal, 1) : 0.0;
$settled = (int) ($overview['members_settled'] ?? 0);
$settledPct = $membersTotal > 0 ? round($settled * 100 / $membersTotal, 1) : 0.0;
$hasMembers = $membersTotal > 0;
$topMonths = $registrations['top_months'] ?? ['labels' => [], 'values' => [], 'percents' => []];
// Shared chart card renderer: one canvas per chart key, fed by chartsJson.
$chartCard = static function (string $id, string $titleKey, string $ledeKey, bool $donut = true, bool $wide = false): void {
    ?>
    <div class="panel chart-panel<?= $wide ? ' chart-panel-wide chart-panel-primary' : '' ?>">
        <div class="panel-header">
            <div>
                <h2 class="section-title"><?= e(__($titleKey)) ?></h2>
                <p class="section-lede"><?= e(__($ledeKey)) ?></p>
            </div>
        </div>
        <div class="chart-frame<?= $donut ? ' chart-frame-donut' : '' ?>">
            <canvas id="stat-chart-<?= e($id) ?>" aria-label="<?= e(__($titleKey)) ?>"></canvas>
        </div>
    </div>
    <?php
};
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('statistics.title')) ?></h1>
        <p class="page-lede"><?= e(__('statistics.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/members')) ?>"><?= e(__('dashboard.open_members')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/dashboard')) ?>"><?= e(__('statistics.back_dashboard')) ?></a>
    </div>
</div>

<?php if (!$hasMembers): ?>
    <div class="panel">
        <div class="empty-state">
            <strong><?= e(__('statistics.empty_title')) ?></strong>
            <p><?= e(__('statistics.empty_text')) ?></p>
        </div>
    </div>
<?php else: ?>

<div class="stats stats-context-members statistics-kpis">
    <div class="stat">
        <div class="label"><?= e(__('statistics.kpi_members')) ?></div>
        <div class="value"><?= (int) $membersTotal ?></div>
        <div class="stat-hint<?= $new30 > 0 ? ' is-up' : '' ?>">
            <?= e(__('statistics.kpi_members_hint', [
                'count' => (string) $new30,
                'percent' => number_format($growthPct, 1, ',', '.'),
            ])) ?>
        </div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('statistics.kpi_active')) ?></div>
        <div class="value"><?= (int) ($overview['members_active'] ?? 0) ?></div>
        <div class="stat-hint"><?= e(__('statistics.kpi_active_hint')) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('statistics.kpi_cards')) ?></div>
        <div class="value"><?= (int) $cardsTotal ?></div>
        <div class="stat-hint">
            <?= e(__('statistics.kpi_cards_hint', [
                'count' => (string) (int) ($overview['cards_issued_12m'] ?? 0),
            ])) ?>
        </div>
    </div>
    <div class="stat<?= ((int) ($overview['cards_expired'] ?? 0)) > 0 ? ' is-warn' : '' ?>">
        <div class="label"><?= e(__('statistics.kpi_cards_active')) ?></div>
        <div class="value"><?= (int) $cardsActive ?></div>
        <div class="stat-hint">
            <?= e(__('statistics.kpi_cards_active_hint', [
                'percent' => number_format($cardsActivePct, 1, ',', '.'),
            ])) ?>
        </div>
    </div>
    <div class="stat<?= ((int) ($overview['cards_expiring_soon'] ?? 0)) > 0 ? ' is-warn' : '' ?>">
        <div class="label"><?= e(__('statistics.kpi_cards_expiring')) ?></div>
        <div class="value"><?= (int) ($overview['cards_expiring_soon'] ?? 0) ?></div>
        <div class="stat-hint"><?= e(__('statistics.kpi_cards_expiring_hint')) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('statistics.kpi_collected')) ?></div>
        <div class="value"><?= e($currency->format((float) ($overview['collected_total'] ?? 0))) ?></div>
        <div class="stat-hint">
            <?= e(__('statistics.kpi_collected_hint', [
                'amount' => $currency->format((float) ($overview['collected_year'] ?? 0)),
            ])) ?>
        </div>
    </div>
    <div class="stat<?= ((int) ($overview['members_overdue'] ?? 0)) > 0 ? ' is-alert' : '' ?>">
        <div class="label"><?= e(__('statistics.kpi_overdue')) ?></div>
        <div class="value"><?= (int) ($overview['members_overdue'] ?? 0) ?></div>
        <div class="stat-hint">
            <?= e(__('statistics.kpi_overdue_hint', [
                'amount' => $currency->format((float) ($overview['balance_due_total'] ?? 0)),
            ])) ?>
        </div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('statistics.kpi_settled')) ?></div>
        <div class="value"><?= e(number_format($settledPct, 1, ',', '.')) ?>%</div>
        <div class="stat-hint">
            <?= e(__('statistics.kpi_settled_hint', ['count' => (string) $settled])) ?>
        </div>
    </div>
</div>


<div class="dashboard-tabs" data-statistics-tabs data-default-tab="demographics">
    <div class="dashboard-tablist-wrap">
        <div class="dashboard-tablist" role="tablist" aria-label="<?= e(__('statistics.title')) ?>">
            <button type="button" class="dashboard-tab" role="tab" data-statistics-tab="demographics"
                aria-controls="statistics-panel-demographics" aria-selected="true" tabindex="0">
                <?= e(__('statistics.tab_demographics')) ?>
            </button>
            <button type="button" class="dashboard-tab" role="tab" data-statistics-tab="registrations"
                aria-controls="statistics-panel-registrations" aria-selected="false" tabindex="-1">
                <?= e(__('statistics.tab_registrations')) ?>
            </button>
            <button type="button" class="dashboard-tab" role="tab" data-statistics-tab="cards"
                aria-controls="statistics-panel-cards" aria-selected="false" tabindex="-1">
                <?= e(__('statistics.tab_cards')) ?>
            </button>
            <button type="button" class="dashboard-tab" role="tab" data-statistics-tab="finance"
                aria-controls="statistics-panel-finance" aria-selected="false" tabindex="-1">
                <?= e(__('statistics.tab_finance')) ?>
            </button>
        </div>
    </div>

    <section class="dashboard-panel" id="statistics-panel-demographics" role="tabpanel"
        data-statistics-panel="demographics" aria-labelledby="statistics-tab-demographics">
        <div class="charts" data-statistics-charts="<?= e($chartsJson) ?>">
            <?php
            $chartCard('gender', 'statistics.chart_gender', 'statistics.chart_gender_lede');
            $chartCard('age_bands', 'statistics.chart_age', 'statistics.chart_age_lede', false);
            ?>
        </div>

        <div class="panel chart-panel chart-panel-wide statistics-map-panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title"><?= e(__('statistics.map_title')) ?></h2>
                    <p class="section-lede"><?= e(__('statistics.map_lede', [
                        'located' => (string) (int) ($cityMap['located'] ?? 0),
                        'total' => (string) (int) ($cityMap['total'] ?? 0),
                    ])) ?></p>
                </div>
            </div>
            <?php if (($cityMap['clusters'] ?? []) === []): ?>
                <div class="empty-state compact"><?= e(__('statistics.map_empty')) ?></div>
            <?php else: ?>
                <?php $clusterMax = max(1, (int) ($cityMap['max'] ?? 0)); ?>
                <div class="statistics-map" role="group" aria-label="<?= e(__('statistics.map_title')) ?>">
                    <?php if (($italySvg ?? '') !== ''): ?>
                        <div class="statistics-map-bg" aria-hidden="true"><?= $italySvg ?></div>
                    <?php endif; ?>
                    <?php foreach ($cityMap['clusters'] as $cluster): ?>
                        <?php
                        $clusterSize = 30 + (int) round(34 * ((int) $cluster['count'] / $clusterMax));
                        $clusterNames = implode(' · ', array_map('strval', (array) $cluster['cities']));
                        ?>
                        <span class="map-cluster"
                            style="left:<?= e((string) $cluster['x']) ?>%;top:<?= e((string) $cluster['y']) ?>%;--cluster-size:<?= (int) $clusterSize ?>px"
                            title="<?= e($clusterNames) ?>">
                            <span class="map-cluster-count"><?= (int) $cluster['count'] ?></span>
                            <span class="map-cluster-label"><?= e((string) $cluster['label']) ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (($cityMap['cities'] ?? []) !== []): ?>
                <div class="table-wrap embedded statistics-map-table">
                    <table class="data-table statistics-table">
                        <thead>
                            <tr>
                                <th><?= e(__('statistics.col_city')) ?></th>
                                <th><?= e(__('statistics.col_members')) ?></th>
                                <th><?= e(__('statistics.col_percent')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($cityMap['cities'], 0, 12) as $cityRow): ?>
                                <?php
                                $cityTotal = (int) ($cityMap['total'] ?? 0);
                                $cityCount = (int) $cityRow['count'];
                                ?>
                                <tr>
                                    <td>
                                        <?= e((string) $cityRow['city']) ?>
                                        <?php if (($cityRow['x'] ?? null) === null): ?>
                                            <span class="muted">· <?= e(__('statistics.not_on_map')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $cityCount ?></td>
                                    <td><?= e(number_format($cityTotal > 0 ? $cityCount * 100 / $cityTotal : 0, 1, ',', '.')) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="dashboard-panel" id="statistics-panel-registrations" role="tabpanel"
        data-statistics-panel="registrations" aria-labelledby="statistics-tab-registrations" hidden>
        <div class="charts">
            <?php
            $chartCard('registrations_monthly', 'statistics.chart_monthly', 'statistics.chart_monthly_lede', false, true);
            $chartCard('seasons', 'statistics.chart_seasons', 'statistics.chart_seasons_lede');
            $chartCard('registrations_weekday', 'statistics.chart_weekday', 'statistics.chart_weekday_lede', false);
            ?>
            <div class="panel chart-panel chart-panel-wide">
                <div class="panel-header">
                    <div>
                        <h2 class="section-title"><?= e(__('statistics.top_months_title')) ?></h2>
                        <p class="section-lede"><?= e(__('statistics.top_months_lede')) ?></p>
                    </div>
                </div>
                <?php if ($topMonths['labels'] === []): ?>
                    <div class="empty-state compact"><?= e(__('dashboard.chart_empty')) ?></div>
                <?php else: ?>
                    <div class="table-wrap embedded">
                        <table class="data-table statistics-table">
                            <thead>
                                <tr>
                                    <th><?= e(__('statistics.col_month')) ?></th>
                                    <th><?= e(__('statistics.col_registrations')) ?></th>
                                    <th><?= e(__('statistics.col_percent')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topMonths['labels'] as $i => $label): ?>
                                    <tr>
                                        <td><?= e((string) $label) ?></td>
                                        <td><?= (int) ($topMonths['values'][$i] ?? 0) ?></td>
                                        <td><?= e(number_format((float) ($topMonths['percents'][$i] ?? 0), 1, ',', '.')) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="statistics-facts">
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_avg_month')) ?></span>
                <strong><?= e(number_format((float) ($registrations['average_per_month'] ?? 0), 1, ',', '.')) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_peak_month')) ?></span>
                <strong><?= e((string) ($registrations['peak']['label'] ?? '')) ?> · <?= (int) ($registrations['peak']['count'] ?? 0) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_best_season')) ?></span>
                <strong><?= e((string) ($registrations['seasons']['best'] ?? '')) ?> · <?= (int) ($registrations['seasons']['best_count'] ?? 0) ?> (<?= e(number_format((float) ($registrations['seasons']['best_percent'] ?? 0), 1, ',', '.')) ?>%)</strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_best_weekday')) ?></span>
                <strong><?= e((string) ($registrations['weekdays']['best'] ?? '')) ?> · <?= (int) ($registrations['weekdays']['best_count'] ?? 0) ?></strong>
            </div>
        </div>
    </section>

    <section class="dashboard-panel" id="statistics-panel-cards" role="tabpanel"
        data-statistics-panel="cards" aria-labelledby="statistics-tab-cards" hidden>
        <div class="charts" data-statistics-charts>
            <?php
            $chartCard('cards_status', 'statistics.chart_cards_status', 'statistics.chart_cards_status_lede');
            $chartCard('cards_type', 'statistics.chart_cards_type', 'statistics.chart_cards_type_lede');
            $chartCard('cards_year', 'statistics.chart_cards_year', 'statistics.chart_cards_year_lede', false);
            ?>
        </div>
        <div class="statistics-facts">
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_cards_total')) ?></span>
                <strong><?= (int) ($cards['total'] ?? 0) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_cards_active')) ?></span>
                <strong><?= (int) ($cards['active'] ?? 0) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_cards_expired')) ?></span>
                <strong><?= (int) ($cards['expired'] ?? 0) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_cards_expiring')) ?></span>
                <strong><?= (int) ($cards['expiring_soon'] ?? 0) ?></strong>
            </div>
        </div>
    </section>

    <section class="dashboard-panel" id="statistics-panel-finance" role="tabpanel"
        data-statistics-panel="finance" aria-labelledby="statistics-tab-finance" hidden>
        <div class="charts" data-statistics-charts>
            <?php
            $chartCard('finance_monthly', 'statistics.chart_finance_monthly', 'statistics.chart_finance_monthly_lede', false, true);
            $chartCard('finance_method', 'statistics.chart_finance_method', 'statistics.chart_finance_method_lede');
            $chartCard('finance_type', 'statistics.chart_finance_type', 'statistics.chart_finance_type_lede');
            ?>
        </div>
        <div class="statistics-facts">
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_collected_12m')) ?></span>
                <strong><?= e($currency->format((float) ($finances['months_total'] ?? 0))) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_balance_due')) ?></span>
                <strong><?= e($currency->format((float) ($finances['balance_due_total'] ?? 0))) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_average_payment')) ?></span>
                <strong><?= e($currency->format((float) ($overview['average_payment'] ?? 0))) ?></strong>
            </div>
            <div class="statistics-fact">
                <span class="label"><?= e(__('statistics.fact_collected_month')) ?></span>
                <strong><?= e($currency->format((float) ($overview['collected_month'] ?? 0))) ?></strong>
            </div>
        </div>
    </section>
</div>
<?php if ($hasMembers): ?>
    <script src="<?= e(asset('js/chart.umd.min.js')) ?>"></script>
<?php endif; ?>
<?php endif; ?>

