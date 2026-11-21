<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 */

/**
 * Annual budget statement printable sheet.
 *
 * @var array<string,mixed>|null $annual_report
 * @var int $year
 * @var \Socly\Services\CurrencyService $currency
 * @var string $assocName
 * @var string $assocLegalCode
 * @var string $assocLegalLabel
 * @var string $assocFiscalCode
 * @var string $assocCity
 * @var string|null $brandPrimary
 * @var string|null $brandAccent
 * @var string $generatedAt
 */
$report = is_array($annual_report ?? null) ? $annual_report : [];
$assocName = trim((string) ($assocName ?? '')) !== '' ? (string) $assocName : 'SOCLY';
$assocLegalCode = strtoupper(trim((string) ($assocLegalCode ?? '')));
$assocLegalLabel = trim((string) ($assocLegalLabel ?? ''));
$brandStyle = brand_root_style_decls($brandPrimary ?? null, $brandAccent ?? null);
$money = static fn (float $value): string => $currency->format($value);
$planned = is_array($report['planned'] ?? null) ? $report['planned'] : ['expense' => 0.0, 'income' => 0.0];
$actual = is_array($report['actual'] ?? null) ? $report['actual'] : ['expense' => 0.0, 'income' => 0.0];
$categories = is_array($report['categories'] ?? null) ? $report['categories'] : ['expense' => [], 'income' => []];
$accounts = is_array($report['accounts'] ?? null) ? $report['accounts'] : [];
$runtsSections = is_array($report['runts_sections'] ?? null) ? $report['runts_sections'] : [];
$history = is_array($report['history'] ?? null) ? $report['history'] : [];
$budget = is_array($report['budget'] ?? null) ? $report['budget'] : null;
$result = (float) ($report['result'] ?? 0);
$coverage = (float) ($report['coverage'] ?? 0);
$fees = (float) ($report['fees'] ?? 0);
$openingBalance = (float) ($report['opening_balance'] ?? 0);
$closingBalance = (float) ($report['closing_balance'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('budget.annual_pdf_title', ['year' => (string) $year])) ?> — <?= e($assocName) ?></title>
    <style>
        :root { <?= $brandStyle ?> }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px 24px 28px; font: 12.5px/1.45 "Segoe UI", Helvetica, Arial, sans-serif; color: #16181d; background: #f4f1ef; }
        .sheet { max-width: 1100px; margin: 0 auto; background: #fff; border: 1px solid color-mix(in srgb, var(--brand-primary) 18%, #d8d5d2); border-radius: 16px; padding: 1.15rem 1.35rem 1.5rem; }
        .toolbar { max-width: 1100px; margin: 0 auto 0.85rem; display: flex; justify-content: flex-end; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .toolbar button, .toolbar a { font: inherit; font-weight: 600; border-radius: 10px; border: 1px solid color-mix(in srgb, var(--brand-primary) 35%, #ccc); background: #fff; color: #16181d; padding: 0.45rem 0.9rem; cursor: pointer; text-decoration: none; }
        .doc-head { border-bottom: 2px solid var(--brand-primary); padding-bottom: 0.75rem; margin-bottom: 1rem; }
        .doc-assoc { font-size: 1.05rem; font-weight: 700; }
        .doc-meta { color: #555; font-size: 0.85rem; }
        h1 { font-size: 1.2rem; margin: 0.45rem 0 0.15rem; }
        h2 { font-size: 0.98rem; margin: 1.1rem 0 0.45rem; text-transform: uppercase; letter-spacing: 0.03em; }
        p.lede { margin: 0.2rem 0 0; color: #5b5f66; }
        table { width: 100%; border-collapse: collapse; margin: 0.35rem 0 0.6rem; }
        th, td { border: 1px solid #d9d5d1; padding: 0.3rem 0.45rem; vertical-align: top; text-align: left; }
        th { background: #f6f3f1; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.group td { background: #efeae7; font-weight: 700; }
        tr.section-total td { background: #faf8f7; font-weight: 600; }
        tr.grand-total td { background: color-mix(in srgb, var(--brand-primary) 10%, #fff); font-weight: 700; }
        .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.65rem; margin: 0.75rem 0 0.4rem; }
        .kpi { border: 1px solid #dcd7d4; border-radius: 14px; padding: 0.8rem 0.85rem; background: #fff; }
        .kpi .label { font-size: 0.76rem; color: #666; text-transform: uppercase; letter-spacing: 0.04em; }
        .kpi .value { font-size: 1.05rem; font-weight: 700; margin-top: 0.2rem; }
        .kpi .hint { font-size: 0.76rem; color: #7b7f85; margin-top: 0.2rem; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
        .section { margin-top: 0.9rem; }
        .bar-cell { width: 38%; }
        .bar { height: 0.56rem; border-radius: 999px; background: #e8e3df; overflow: hidden; margin-top: 0.2rem; }
        .bar > span { display: block; height: 100%; border-radius: inherit; background: color-mix(in srgb, var(--brand-primary) 78%, #1a635d); }
        .signature-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 2rem; margin-top: 2rem; }
        .signature { border-top: 1px solid #333; padding-top: 0.4rem; font-size: 0.82rem; }
        .signature small { display: block; color: #666; margin-top: 0.2rem; }
        .note { margin-top: 0.6rem; font-size: 0.78rem; color: #666; }
        .empty-state { padding: 0.8rem 0.9rem; border: 1px dashed #d9d5d1; border-radius: 12px; color: #666; background: #faf8f7; }
        @media screen and (max-width: 860px) {
            body { padding: 10px 8px 18px; }
            .sheet { padding: 0.85rem 0.7rem 1rem; border-radius: 12px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .kpis, .grid-2, .signature-grid { grid-template-columns: 1fr; }
            table { min-width: 44rem; }
        }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { border: none; border-radius: 0; padding: 0; max-width: none; }
            .toolbar { display: none; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" data-print><?= e(__('budget.annual_print')) ?></button>
    <a href="<?= e(url('/budgets?tab=bilancio&year=' . (int) $year)) ?>"><?= e(__('budget.tab_annual')) ?></a>
</div>
<div class="sheet">
    <header class="doc-head">
        <div class="doc-assoc"><?= e($assocName) ?><?= $assocLegalCode !== '' ? ' — ' . e($assocLegalCode) : '' ?></div>
        <div class="doc-meta">
            <?= e($assocLegalLabel) ?>
            <?= $assocFiscalCode !== '' ? ' · ' . e(__('runts.cf')) . ' ' . e($assocFiscalCode) : '' ?>
            <?php if (trim((string) $assocCity) !== ''): ?>
                · <?= e((string) $assocCity) ?>
            <?php endif; ?>
        </div>
        <h1><?= e(__('budget.annual_pdf_title', ['year' => (string) $year])) ?></h1>
        <p class="lede"><?= e(__('budget.annual_lede')) ?></p>
        <div class="doc-meta"><?= e(__('budget.rendiconto_generated', ['date' => (string) $generatedAt])) ?></div>
    </header>

    <section>
        <div class="kpis">
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_income')) ?></div>
                <div class="value"><?= e($money((float) ($actual['income'] ?? 0))) ?></div>
                <div class="hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $year])) ?></div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_expense')) ?></div>
                <div class="value"><?= e($money((float) ($actual['expense'] ?? 0))) ?></div>
                <div class="hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $year])) ?></div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_result')) ?></div>
                <div class="value"><?= e($money($result)) ?></div>
                <div class="hint"><?= e(__('budget.annual_coverage')) ?>: <?= e(number_format($coverage, 1, ',', '.')) ?>%</div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_fees')) ?></div>
                <div class="value"><?= e($money($fees)) ?></div>
                <div class="hint"><?= e(__('budget.annual_opening')) ?>: <?= e($money($openingBalance)) ?></div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_closing')) ?></div>
                <div class="value"><?= e($money($closingBalance)) ?></div>
                <div class="hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $year])) ?></div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_opening')) ?></div>
                <div class="value"><?= e($money($openingBalance)) ?></div>
                <div class="hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $year])) ?></div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_coverage')) ?></div>
                <div class="value"><?= e(number_format($coverage, 1, ',', '.')) ?>%</div>
                <div class="hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $year])) ?></div>
            </div>
            <div class="kpi">
                <div class="label"><?= e(__('budget.annual_history')) ?></div>
                <div class="value"><?= e((string) count($history)) ?></div>
                <div class="hint"><?= e(__('budget.annual_year_hint', ['year' => (string) $year])) ?></div>
            </div>
        </div>
    </section>

    <section class="section">
        <h2><?= e(__('budget.annual_planned_vs_actual')) ?></h2>
        <table>
            <thead>
            <tr>
                <th><?= e(__('runts.year')) ?></th>
                <th class="num"><?= e(__('budget.planned_expense')) ?></th>
                <th class="num"><?= e(__('budget.actual_expense')) ?></th>
                <th class="num"><?= e(__('budget.planned_income')) ?></th>
                <th class="num"><?= e(__('budget.actual_income')) ?></th>
                <th class="num"><?= e(__('budget.annual_result')) ?></th>
                <th class="num"><?= e(__('budget.annual_coverage')) ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><strong><?= e((string) $year) ?></strong></td>
                <td class="num"><?= e($money((float) ($planned['expense'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($actual['expense'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($planned['income'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($actual['income'] ?? 0))) ?></td>
                <td class="num"><?= e($money($result)) ?></td>
                <td class="num"><?= e(number_format($coverage, 1, ',', '.')) ?>%</td>
            </tr>
            </tbody>
        </table>
    </section>

    <section class="section">
        <div class="grid-2">
            <div>
                <h2><?= e(__('budget.annual_categories')) ?></h2>
                <?php if (($categories['expense'] ?? []) === []): ?>
                    <div class="empty-state">—</div>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th><?= e(__('budget.category_label')) ?></th>
                            <th class="num"><?= e(__('budget.amount')) ?></th>
                            <th class="num">%</th>
                            <th class="bar-cell"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($categories['expense'] as $cat): ?>
                            <?php $percent = (float) ($cat['percent'] ?? 0); ?>
                            <tr>
                                <td><?= e((string) ($cat['label'] ?? '')) ?></td>
                                <td class="num"><?= e($money((float) ($cat['amount'] ?? 0))) ?></td>
                                <td class="num"><?= e(number_format($percent, 1, ',', '.')) ?>%</td>
                                <td class="bar-cell"><div class="bar"><span style="width: <?= e((string) max(0, min(100, $percent))) ?>%"></span></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div>
                <h2><?= e(__('budget.annual_categories_income')) ?></h2>
                <?php if (($categories['income'] ?? []) === []): ?>
                    <div class="empty-state">—</div>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th><?= e(__('budget.category_label')) ?></th>
                            <th class="num"><?= e(__('budget.amount')) ?></th>
                            <th class="num">%</th>
                            <th class="bar-cell"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($categories['income'] as $cat): ?>
                            <?php $percent = (float) ($cat['percent'] ?? 0); ?>
                            <tr>
                                <td><?= e((string) ($cat['label'] ?? '')) ?></td>
                                <td class="num"><?= e($money((float) ($cat['amount'] ?? 0))) ?></td>
                                <td class="num"><?= e(number_format($percent, 1, ',', '.')) ?>%</td>
                                <td class="bar-cell"><div class="bar"><span style="width: <?= e((string) max(0, min(100, $percent))) ?>%"></span></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="section">
        <h2><?= e(__('budget.annual_accounts')) ?></h2>
        <?php if ($accounts === []): ?>
            <div class="empty-state">—</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= e(__('treasury.account')) ?></th>
                    <th class="num"><?= e(__('budget.annual_income')) ?></th>
                    <th class="num"><?= e(__('budget.annual_expense')) ?></th>
                    <th class="num"><?= e(__('budget.transfer_in')) ?></th>
                    <th class="num"><?= e(__('budget.transfer_out')) ?></th>
                    <th class="num"><?= e(__('treasury.balance')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr>
                        <td><?= e((string) ($account['label'] ?? $account['key'] ?? '')) ?></td>
                        <td class="num"><?= e($money((float) ($account['income'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($account['expense'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($account['transfer_in'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($account['transfer_out'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($account['balance'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2><?= e(__('budget.annual_runts_summary')) ?></h2>
        <?php if ($runtsSections === []): ?>
            <div class="empty-state">—</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= e(__('setup.runts_field_section')) ?></th>
                    <th class="num"><?= e(__('budget.annual_expense')) ?></th>
                    <th class="num"><?= e(__('budget.annual_income')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($runtsSections as $section): ?>
                    <tr>
                        <td><?= e((string) ($section['label'] ?? $section['letter'] ?? '')) ?></td>
                        <td class="num"><?= e($money((float) ($section['expense'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($section['income'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="section">
        <h2><?= e(__('budget.annual_history')) ?></h2>
        <?php if ($history === []): ?>
            <div class="empty-state">—</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= e(__('runts.year')) ?></th>
                    <th class="num"><?= e(__('budget.planned_expense')) ?></th>
                    <th class="num"><?= e(__('budget.actual_expense')) ?></th>
                    <th class="num"><?= e(__('budget.planned_income')) ?></th>
                    <th class="num"><?= e(__('budget.actual_income')) ?></th>
                    <th class="num"><?= e(__('budget.annual_result')) ?></th>
                    <th class="num"><?= e(__('budget.annual_coverage')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><strong><?= e((string) ($row['year'] ?? '')) ?></strong></td>
                        <td class="num"><?= e($money((float) ($row['planned_expense'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($row['actual_expense'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($row['planned_income'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($row['actual_income'] ?? 0))) ?></td>
                        <td class="num"><?= e($money((float) ($row['actual_income'] ?? 0) - (float) ($row['actual_expense'] ?? 0))) ?></td>
                        <td class="num"><?= e(number_format((float) ($row['coverage'] ?? 0), 1, ',', '.')) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <div class="signature-grid">
        <div class="signature">
            <?= e(__('budget.annual_signature_legal')) ?>
            <small><?= e(trim((string) $assocCity) !== '' ? (string) $assocCity : '________________') ?>, <?= e(date('d/m/Y')) ?></small>
        </div>
        <div class="signature">
            <?= e(__('budget.annual_signature_treasurer')) ?>
            <small>________________</small>
        </div>
    </div>

    <p class="note"><?= e(__('budget.annual_lede')) ?></p>
</div>
<script src="<?= e(asset('js/print.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/print.js')) ?: time())) ?>"></script>
</body>
</html>
