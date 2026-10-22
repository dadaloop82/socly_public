<?php
/**
 * Rendiconto finale — foglio stampabile (stampa browser → PDF) sul formato del
 * piano finanziario provinciale: preventivato, liquidato, pezze giustificative.
 *
 * @var array<string,mixed> $comparison
 * @var array<int,list<array<string,mixed>>> $evidence
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
$budget = is_array($comparison['budget'] ?? null) ? $comparison['budget'] : [];
$sections = is_array($comparison['sections'] ?? null) ? $comparison['sections'] : [];
$totals = is_array($comparison['totals'] ?? null) ? $comparison['totals'] : [];
$unallocated = is_array($comparison['unallocated'] ?? null) ? $comparison['unallocated'] : [];
$evidence = is_array($evidence ?? null) ? $evidence : [];
$assocName = trim((string) ($assocName ?? '')) !== '' ? (string) $assocName : 'SOCLY';
$assocLegalCode = strtoupper(trim((string) ($assocLegalCode ?? '')));
$assocLegalLabel = trim((string) ($assocLegalLabel ?? ''));
$brandStyle = brand_root_style_decls($brandPrimary ?? null, $brandAccent ?? null);
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('budget.rendiconto_title', ['year' => (string) ($budget['fiscal_year'] ?? '')])) ?> — <?= e($assocName) ?></title>
    <style>
        :root { <?= $brandStyle ?> }
        * { box-sizing: border-box; }
        body {
          margin: 0;
          padding: 20px 24px 28px;
          font: 12.5px/1.45 "Segoe UI", Helvetica, Arial, sans-serif;
          color: #16181d;
          background: #f4f1ef;
        }
        .sheet {
          max-width: 900px;
          margin: 0 auto;
          background: #fff;
          border: 1px solid color-mix(in srgb, var(--brand-primary) 18%, #d8d5d2);
          border-radius: 16px;
          padding: 1.15rem 1.35rem 1.5rem;
        }
        .toolbar {
          max-width: 900px;
          margin: 0 auto 0.85rem;
          display: flex;
          justify-content: flex-end;
          gap: 0.5rem;
        }
        .toolbar button, .toolbar a {
          font: inherit;
          font-weight: 600;
          border-radius: 10px;
          border: 1px solid color-mix(in srgb, var(--brand-primary) 35%, #ccc);
          background: #fff;
          color: #16181d;
          padding: 0.45rem 0.9rem;
          cursor: pointer;
          text-decoration: none;
        }
        .doc-head { border-bottom: 2px solid var(--brand-primary); padding-bottom: 0.7rem; margin-bottom: 1rem; }
        .doc-assoc { font-size: 1.05rem; font-weight: 700; }
        .doc-meta { color: #555; font-size: 0.85rem; }
        h1 { font-size: 1.15rem; margin: 0.5rem 0 0.15rem; }
        h2 { font-size: 0.98rem; margin: 1.1rem 0 0.45rem; }
        table { width: 100%; border-collapse: collapse; margin: 0.35rem 0 0.6rem; }
        th, td { border: 1px solid #d9d5d1; padding: 0.32rem 0.45rem; vertical-align: top; text-align: left; }
        th { background: #f6f3f1; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.section-total td { background: #faf8f7; font-weight: 600; }
        tr.grand-total td { background: color-mix(in srgb, var(--brand-primary) 10%, #fff); font-weight: 700; }
        .evidence { font-size: 0.78rem; color: #444; }
        .evidence span { display: block; }
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
    <button type="button" onclick="window.print()"><?= e(__('budget.print')) ?></button>
    <a href="?"><?= e(__('common.cancel')) ?></a>
</div>
<div class="sheet">
    <header class="doc-head">
        <div class="doc-assoc"><?= e($assocName) ?><?= $assocLegalCode !== '' ? ' — ' . e($assocLegalCode) : '' ?></div>
        <?php if ($assocLegalLabel !== '' || $assocFiscalCode !== ''): ?>
            <div class="doc-meta">
                <?= e($assocLegalLabel) ?>
                <?= $assocFiscalCode !== '' ? ' · ' . e(__('budget.cf_label')) . ' ' . e($assocFiscalCode) : '' ?>
            </div>
        <?php endif; ?>
        <h1><?= e(__('budget.rendiconto_title', ['year' => (string) ($budget['fiscal_year'] ?? '')])) ?></h1>
        <div class="doc-meta"><?= e(__('budget.rendiconto_generated', ['date' => (string) ($generatedAt ?? '')])) ?></div>
    </header>

    <?php foreach ($sections as $section): ?>
        <h2><?= e((string) ($section['title'] ?? '')) ?></h2>
        <table>
            <thead>
            <tr>
                <th><?= e(__('budget.item_label')) ?></th>
                <th class="num"><?= e(__('budget.planned')) ?></th>
                <th class="num"><?= e(__('budget.actual')) ?></th>
                <th class="num"><?= e(__('budget.residual')) ?></th>
                <th><?= e(__('budget.evidence')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($section['items'] as $item): ?>
                <tr>
                    <td><?= e((string) $item['label']) ?></td>
                    <td class="num"><?= e($currency->format((float) $item['planned'])) ?></td>
                    <td class="num"><?= e($currency->format((float) $item['actual'])) ?></td>
                    <td class="num"><?= e($currency->format((float) $item['residual'])) ?></td>
                    <td class="evidence">
                        <?php $rows = $evidence[(int) $item['id']] ?? []; ?>
                        <?php if ($rows === []): ?>
                            <span>—</span>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <span>
                                    <?= e(format_date((string) ($row['movement_date'] ?? '')) ?: (string) ($row['movement_date'] ?? '')) ?>
                                    <?php if ((string) ($row['invoice_number'] ?? '') !== ''): ?>
                                        · <?= e(__('treasury.invoice_number')) ?> <?= e((string) $row['invoice_number']) ?>
                                    <?php endif; ?>
                                    <?php if ((string) ($row['beneficiary'] ?? '') !== ''): ?>
                                        · <?= e((string) $row['beneficiary']) ?>
                                    <?php endif; ?>
                                    · <?= e($currency->format((float) ($row['amount'] ?? 0))) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr class="section-total">
                <td><?= e(__('budget.section_total_row')) ?></td>
                <td class="num"><?= e($currency->format((float) ($section['planned_need'] ?? 0))) ?></td>
                <td class="num"><?= e($currency->format((float) ($section['actual_need'] ?? 0))) ?></td>
                <td class="num"><?= e($currency->format((float) ($section['residual_need'] ?? 0))) ?></td>
                <td></td>
            </tr>
            </tbody>
        </table>
    <?php endforeach; ?>

    <?php if ($unallocated !== []): ?>
        <h2><?= e(__('budget.unallocated_title', ['year' => (string) ($budget['fiscal_year'] ?? '')])) ?></h2>
        <table>
            <thead>
            <tr>
                <th><?= e(__('treasury.category')) ?></th>
                <th><?= e(__('budget.direction')) ?></th>
                <th class="num"><?= e(__('budget.movements')) ?></th>
                <th class="num"><?= e(__('budget.actual')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($unallocated as $row): ?>
                <tr>
                    <td><?= e((string) $row['label']) ?></td>
                    <td><?= e((string) $row['direction'] === 'income' ? __('budget.direction_income') : __('budget.direction_expense')) ?></td>
                    <td class="num"><?= e((string) $row['movements']) ?></td>
                    <td class="num"><?= e($currency->format((float) $row['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <table>
        <tbody>
        <tr class="grand-total">
            <td><?= e(__('budget.planned_expense')) ?></td>
            <td class="num"><?= e($currency->format((float) ($totals['planned_expense'] ?? 0))) ?></td>
        </tr>
        <tr class="grand-total">
            <td><?= e(__('budget.actual_expense')) ?></td>
            <td class="num"><?= e($currency->format((float) ($totals['actual_expense_all'] ?? 0))) ?></td>
        </tr>
        <tr class="grand-total">
            <td><?= e(__('budget.planned_income')) ?></td>
            <td class="num"><?= e($currency->format((float) ($totals['planned_income'] ?? 0))) ?></td>
        </tr>
        <tr class="grand-total">
            <td><?= e(__('budget.actual_income')) ?></td>
            <td class="num"><?= e($currency->format((float) ($totals['actual_income_all'] ?? 0))) ?></td>
        </tr>
        <tr class="grand-total">
            <td><?= e(__('budget.planned_need')) ?> / <?= e(__('budget.actual_need')) ?></td>
            <td class="num">
                <?= e($currency->format((float) ($totals['planned_need'] ?? 0))) ?>
                /
                <?= e($currency->format((float) ($totals['actual_need_all'] ?? 0))) ?>
            </td>
        </tr>
        </tbody>
    </table>

    <div class="sign">
        <div>
            <?= e(__('budget.sign_place')) ?><?= trim((string) ($assocCity ?? '')) !== '' ? ': ' . e((string) $assocCity) : '' ?>
        </div>
        <div><?= e(__('budget.sign_role')) ?></div>
    </div>

    <p class="doc-note"><?= e(__('budget.rendiconto_note')) ?></p>
</div>
</body>
</html>

