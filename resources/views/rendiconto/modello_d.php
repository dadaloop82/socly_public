<?php
/**
 * Cash flow statement - Model D (DM 18 April 2020), printable sheet (print browser → PDF).
 *
 * @var array<string,mixed>|null $report
 * @var int $year
 * @var float $income
 * @var float $limit
 * @var bool $can_generate
 * @var \Socly\Services\CurrencyService $currency
 * @var string $assocName
 * @var string $assocLegalCode
 * @var string $assocLegalLabel
 * @var string $assocFiscalCode
 * @var string $assocAddress
 * @var string $assocCity
 * @var string|null $brandPrimary
 * @var string|null $brandAccent
 * @var string $generatedAt
 */
$report = is_array($report ?? null) ? $report : null;
$assocName = trim((string) ($assocName ?? '')) !== '' ? (string) $assocName : 'SOCLY';
$assocLegalCode = strtoupper(trim((string) ($assocLegalCode ?? '')));
$assocLegalLabel = trim((string) ($assocLegalLabel ?? ''));
$brandStyle = brand_root_style_decls($brandPrimary ?? null, $brandAccent ?? null);
$money = static fn (float $value): string => $currency->format($value);
$sections = $report['sections'] ?? [];
$prospect = $report['prospect'] ?? ['opening' => [], 'closing' => []];
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('runts.year_title', ['year' => (string) $year])) ?> — <?= e($assocName) ?></title>
    <style>
        :root { <?= $brandStyle ?> }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px 24px 28px; font: 12.5px/1.45 "Segoe UI", Helvetica, Arial, sans-serif; color: #16181d; background: #f4f1ef; }
        .sheet { max-width: 900px; margin: 0 auto; background: #fff; border: 1px solid color-mix(in srgb, var(--brand-primary) 18%, #d8d5d2); border-radius: 16px; padding: 1.15rem 1.35rem 1.5rem; }
        .toolbar { max-width: 900px; margin: 0 auto 0.85rem; display: flex; justify-content: flex-end; gap: 0.5rem; }
        .toolbar button, .toolbar a { font: inherit; font-weight: 600; border-radius: 10px; border: 1px solid color-mix(in srgb, var(--brand-primary) 35%, #ccc); background: #fff; color: #16181d; padding: 0.45rem 0.9rem; cursor: pointer; text-decoration: none; }
        .doc-head { border-bottom: 2px solid var(--brand-primary); padding-bottom: 0.7rem; margin-bottom: 1rem; }
        .doc-assoc { font-size: 1.05rem; font-weight: 700; }
        .doc-meta { color: #555; font-size: 0.85rem; }
        h1 { font-size: 1.15rem; margin: 0.5rem 0 0.15rem; }
        h2 { font-size: 0.98rem; margin: 1.1rem 0 0.45rem; text-transform: uppercase; letter-spacing: 0.03em; }
        table { width: 100%; border-collapse: collapse; margin: 0.35rem 0 0.6rem; }
        th, td { border: 1px solid #d9d5d1; padding: 0.3rem 0.45rem; vertical-align: top; text-align: left; }
        th { background: #f6f3f1; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.group td { background: #efeae7; font-weight: 700; }
        tr.section-total td { background: #faf8f7; font-weight: 600; }
        tr.grand-total td { background: color-mix(in srgb, var(--brand-primary) 10%, #fff); font-weight: 700; }
        .manual { border: 1px solid #e0b4a8; background: #fdf3f0; border-radius: 12px; padding: 0.85rem 1rem; margin: 0.9rem 0; }
        .manual h2 { margin: 0 0 0.35rem; text-transform: none; letter-spacing: 0; font-size: 0.98rem; }
        .notice { border: 1px solid #e5d7a8; background: #fdfaf0; border-radius: 12px; padding: 0.7rem 0.9rem; margin: 0.6rem 0; font-size: 0.85rem; }
        .sign { margin-top: 2.2rem; display: flex; justify-content: space-between; gap: 2rem; }
        .sign div { flex: 1; border-top: 1px solid #333; padding-top: 0.4rem; font-size: 0.82rem; }
        .doc-note { margin-top: 1.2rem; font-size: 0.72rem; color: #666; }
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
    <button type="button" data-print><?= e(__('runts.print')) ?></button>
    <a href="<?= e(url('/rendiconto')) ?>"><?= e(__('runts.back')) ?></a>
</div>
<div class="sheet">
    <header class="doc-head">
        <div class="doc-assoc"><?= e($assocName) ?><?= $assocLegalCode !== '' ? ' — ' . e($assocLegalCode) : '' ?></div>
        <div class="doc-meta">
            <?= e($assocLegalLabel) ?>
            <?= $assocFiscalCode !== '' ? ' · ' . e(__('runts.cf')) . ' ' . e($assocFiscalCode) : '' ?>
            <?php if (trim((string) $assocAddress) !== '' || trim((string) $assocCity) !== ''): ?>
                · <?= e(trim((string) $assocAddress . ' ' . (string) $assocCity)) ?>
            <?php endif; ?>
        </div>
        <h1><?= e(__('runts.model_title')) ?> — <?= e(__('runts.year_title', ['year' => (string) $year])) ?></h1>
        <div class="doc-meta"><?= e(__('runts.generated', ['date' => (string) $generatedAt])) ?></div>
    </header>

    <?php if (!$can_generate || $report === null): ?>
        <section class="manual">
            <h2><?= e(__('runts.manual_title')) ?></h2>
            <p><?= e(__('runts.manual_text', [
                'income' => $money((float) $income),
                'limit' => $money((float) $limit),
            ])) ?></p>
        </section>
    <?php else: ?>
        <?php foreach (['expense' => 'runts.expenses', 'income' => 'runts.incomes'] as $dir => $headingKey): ?>
            <h2><?= e(__($headingKey)) ?></h2>
            <table>
                <thead>
                <tr>
                    <th><?= e(__('runts.model_title')) ?></th>
                    <th class="num"><?= e(__('budget.amount')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sections as $letter => $section): ?>
                    <tr class="group"><td colspan="2"><?= e((string) ($section['label'] ?? '')) ?></td></tr>
                    <?php foreach (($section[$dir] ?? []) as $lineKey => $lineLabel): ?>
                        <tr>
                            <td><?= e((string) $lineLabel) ?></td>
                            <td class="num"><?= e($money((float) (($section['lines'][$dir][$lineKey] ?? 0)))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="section-total">
                        <td><?= e(__('runts.total')) . ' ' . e((string) $letter) ?></td>
                        <td class="num"><?= e($money((float) ($section['totals'][$dir] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="grand-total">
                    <td><?= e($dir === 'income' ? __('runts.total_income') : __('runts.total_expense')) ?></td>
                    <td class="num"><?= e($money((float) ($dir === 'income' ? ($report['total_income'] ?? 0) : ($report['total_expense'] ?? 0)))) ?></td>
                </tr>
                </tbody>
            </table>
        <?php endforeach; ?>

        <?php if ((float) ($report['internal'] ?? 0) > 0): ?>
            <p class="notice"><?= e(__('runts.internal', ['amount' => $money((float) $report['internal'])])) ?></p>
        <?php endif; ?>

        <?php if (($report['unclassified'] ?? []) !== []): ?>
            <section class="notice">
                <strong><?= e(__('runts.unclassified_title')) ?></strong>
                <p><?= e(__('runts.unclassified_text')) ?></p>
                <ul>
                    <?php foreach ($report['unclassified'] as $row): ?>
                        <li>
                            <?= e(($row['direction'] ?? '') === 'income' ? __('runts.incomes') : __('runts.expenses')) ?>
                            · <?= e((string) ($row['category'] ?? '')) ?> — <?= e($money((float) ($row['amount'] ?? 0))) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <h2><?= e(__('runts.prospect_title')) ?></h2>
        <table>
            <thead>
            <tr>
                <th></th>
                <th class="num"><?= e(__('runts.cash')) ?></th>
                <th class="num"><?= e(__('runts.bank')) ?></th>
                <th class="num"><?= e(__('runts.total')) ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><?= e(__('runts.opening')) ?></td>
                <td class="num"><?= e($money((float) ($prospect['opening']['cash'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($prospect['opening']['bank'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($prospect['opening']['total'] ?? 0))) ?></td>
            </tr>
            <tr class="section-total">
                <td colspan="3"><?= e(__('runts.balance')) ?></td>
                <td class="num"><?= e($money((float) ($report['balance'] ?? 0))) ?></td>
            </tr>
            <tr class="grand-total">
                <td><?= e(__('runts.closing')) ?></td>
                <td class="num"><?= e($money((float) ($prospect['closing']['cash'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($prospect['closing']['bank'] ?? 0))) ?></td>
                <td class="num"><?= e($money((float) ($prospect['closing']['total'] ?? 0))) ?></td>
            </tr>
            </tbody>
        </table>

        <h2><?= e(__('runts.figurative_title')) ?></h2>
        <table>
            <thead>
            <tr>
                <th></th>
                <th class="num"><?= e(__('budget.amount')) ?></th>
            </tr>
            </thead>
            <tbody>
            <tr><td><?= e(__('runts.figurative_costs')) ?></td><td class="num">—</td></tr>
            <tr><td><?= e(__('runts.figurative_income')) ?></td><td class="num">—</td></tr>
            </tbody>
        </table>
        <p class="doc-note"><?= e(__('runts.figurative_note')) ?></p>

        <div class="sign">
            <div>
                <?= e(__('runts.signature_place')) ?>:
                <?= e(trim((string) $assocCity) !== '' ? (string) $assocCity : '________________') ?>,
                <?= e(date('d/m/Y')) ?>
            </div>
            <div><?= e(__('runts.signature_role')) ?></div>
        </div>
    <?php endif; ?>
</div>
<script src="<?= e(asset('js/print.js')) ?>?v=<?= e((string) (@filemtime(base_path('public/assets/js/print.js')) ?: time())) ?>"></script>
</body>
</html>
