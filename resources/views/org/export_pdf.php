<?php
/**
 * Dichiarazione delle cariche sociali — stampa / esportazione PDF.
 *
 * @var list<array{key:string,title:string,lede:string,rows:list<array{role:string,person:array<string,mixed>,missing:list<string>}>}> $groups
 * @var list<array{name:string,role:string,fields:list<string>}> $incomplete
 * @var int $totalOfficers
 * @var string $assocName
 * @var string $assocLegalCode
 * @var string $assocLegalLabel
 * @var string $assocFiscalCode
 * @var string $assocAddress
 * @var string|null $brandPrimary
 * @var string|null $brandAccent
 * @var string $generatedAt
 */
$brandStyle = brand_root_style_decls(
    isset($brandPrimary) ? (string) $brandPrimary : null,
    isset($brandAccent) ? (string) $brandAccent : null
);
$assocName = trim((string) ($assocName ?? ''));
$assocName = $assocName !== '' ? $assocName : 'SOCLY';
$assocLegalCode = strtoupper(trim((string) ($assocLegalCode ?? '')));
$assocLegalLabel = trim((string) ($assocLegalLabel ?? ''));
$assocFiscalCode = trim((string) ($assocFiscalCode ?? ''));
$assocAddress = trim((string) ($assocAddress ?? ''));
$groups = is_array($groups ?? null) ? $groups : [];
$incomplete = is_array($incomplete ?? null) ? $incomplete : [];
$totalOfficers = (int) ($totalOfficers ?? 0);
$generatedAt = trim((string) ($generatedAt ?? ''));
$generatedAt = $generatedAt !== '' ? $generatedAt : date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('org.export_doc_title')) ?> — <?= e($assocName) ?></title>
    <style>
        :root {
          <?= $brandStyle ?>
        }
        * { box-sizing: border-box; }
        body {
          margin: 0;
          padding: 20px 24px 28px;
          font: 12.5px/1.4 "Segoe UI", Helvetica, Arial, sans-serif;
          color: #111;
          background: #f4f1ef;
        }
        .sheet {
          max-width: 900px;
          margin: 0 auto;
          background: #fff;
          border: 1px solid color-mix(in srgb, var(--brand-primary) 18%, #d8d5d2);
          border-radius: 16px;
          padding: 1.1rem 1.25rem 1.35rem;
          box-shadow: 0 12px 32px color-mix(in srgb, var(--brand-primary) 10%, transparent);
        }
        .toolbar {
          display: flex;
          justify-content: flex-end;
          align-items: center;
          flex-wrap: wrap;
          gap: 0.55rem;
          margin-bottom: 1rem;
        }
        .btn {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.35rem;
          min-height: 2.45rem;
          padding: 0.55rem 1.1rem;
          border: 0;
          border-radius: 999px;
          font: inherit;
          font-weight: 700;
          cursor: pointer;
          text-decoration: none;
        }
        .btn-primary {
          background: var(--brand-primary);
          color: var(--brand-on-primary, #fff);
          box-shadow: 0 8px 18px color-mix(in srgb, var(--brand-primary) 28%, transparent);
        }
        .btn-primary:hover { background: var(--brand-primary-deep); }
        .btn-ghost {
          background: #fff;
          color: #44504d;
          border: 1px solid color-mix(in srgb, var(--brand-primary-ink, #44504d) 18%, #c9cecb);
        }
        .btn-ghost:hover {
          background: color-mix(in srgb, var(--brand-primary) 8%, #fff);
          border-color: color-mix(in srgb, var(--brand-primary) 30%, #c9cecb);
          color: var(--brand-primary-deep);
        }
        .alert {
          margin: 0 0 1rem;
          padding: 0.7rem 0.85rem;
          border: 1px solid #e2b64f;
          border-left: 4px solid #d99b1c;
          border-radius: 10px;
          background: #fff8e6;
          color: #5a4308;
        }
        .alert-title { display: block; margin-bottom: 0.2rem; font-size: 0.92rem; }
        .alert-lede { margin: 0 0 0.4rem; font-size: 0.86rem; }
        .alert-list { margin: 0; padding-left: 1.1rem; font-size: 0.84rem; }
        .alert-person { font-weight: 700; }
        header {
          border-bottom: 2px solid #111;
          padding-bottom: 0.7rem;
          margin-bottom: 0.85rem;
        }
        header h1 {
          margin: 0 0 0.3rem;
          font-size: 1.15rem;
          text-transform: uppercase;
          letter-spacing: 0.04em;
        }
        .assoc { margin: 0.15rem 0; }
        .assoc-meta { margin: 0.12rem 0; color: #444; }
        .lede { margin: 0 0 0.9rem; }
        .lede-count { color: #555; }
        .organ { margin: 0 0 1rem; }
        .organ h2 {
          margin: 0 0 0.35rem;
          font-size: 0.95rem;
          text-transform: uppercase;
          letter-spacing: 0.03em;
          color: var(--brand-primary-deep, #111);
        }
        .organ-lede { margin: 0 0 0.45rem; color: #555; font-size: 0.85rem; }
        table {
          width: 100%;
          border-collapse: collapse;
          font-size: 11.5px;
          table-layout: fixed;
        }
        th, td {
          border: 1px solid #9a9a9a;
          padding: 0.38rem 0.42rem;
          vertical-align: top;
          word-wrap: break-word;
        }
        th { background: #ececec; text-align: left; font-weight: 700; }
        .col-role { width: 16%; }
        .col-name { width: 24%; }
        .col-cf { width: 17%; }
        .col-date { width: 13%; }
        .col-minutes { width: 17%; }
        .empty {
          margin: 0 0 1rem;
          padding: 1rem;
          border: 1px dashed #bbb;
          border-radius: 10px;
          color: #555;
        }
        .signature {
          display: flex;
          justify-content: space-between;
          align-items: flex-end;
          gap: 1rem;
          margin-top: 1.6rem;
          font-size: 11.5px;
        }
        .signature-place { margin: 0; }
        .signature-line {
          margin: 0;
          min-width: 11rem;
          padding-top: 1.7rem;
          border-top: 1px solid #111;
          text-align: center;
        }
        .meta { margin-top: 1rem; color: #666; font-size: 11px; }
        .legal { margin-top: 0.5rem; font-size: 10.5px; color: #555; }
        @media screen {
          tr.row-incomplete td { background: #fffdf5; }
        }
        @page {
          size: A4 portrait;
          margin: 12mm 12mm 14mm;
        }
        @media print {
          body { padding: 0; background: #fff; }
          .no-print { display: none !important; }
          .toolbar { display: none !important; }
          .sheet {
            max-width: none;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            padding: 0;
          }
          thead { display: table-header-group; }
          tfoot { display: table-footer-group; }
          tr { page-break-inside: avoid; }
          header { break-after: avoid; }
          .organ h2 { break-after: avoid; }
          a { color: inherit; text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="toolbar no-print">
        <button type="button" class="btn btn-ghost" onclick="window.close()"><?= e(__('common.close')) ?></button>
        <button type="button" class="btn btn-primary" onclick="window.print()"><?= e(__('org.export_pdf')) ?></button>
    </div>

    <?php if ($incomplete !== []): ?>
        <div class="alert no-print" role="alert">
            <strong class="alert-title"><?= e(__('org.export_missing_title')) ?></strong>
            <p class="alert-lede"><?= e(__('org.export_missing_lede')) ?></p>
            <ul class="alert-list">
                <?php foreach ($incomplete as $item): ?>
                    <li>
                        <span class="alert-person"><?= e((string) $item['name']) ?></span>
                        · <?= e((string) $item['role']) ?> —
                        <?= e(implode(', ', $item['fields'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <header>
        <h1><?= e(__('org.export_doc_title')) ?></h1>
        <p class="assoc">
            <strong><?= e($assocName) ?></strong>
            <?php if ($assocLegalCode !== ''): ?>
                · <?= e($assocLegalCode) ?><?= $assocLegalLabel !== '' ? ' (' . e($assocLegalLabel) . ')' : '' ?>
            <?php endif; ?>
        </p>
        <?php if ($assocFiscalCode !== ''): ?>
            <p class="assoc-meta"><?= e(__('org.export_assoc_fiscal')) ?>: <?= e($assocFiscalCode) ?></p>
        <?php endif; ?>
        <?php if ($assocAddress !== ''): ?>
            <p class="assoc-meta"><?= e($assocAddress) ?></p>
        <?php endif; ?>
    </header>

    <p class="lede">
        <?= e(__('org.export_doc_lede', ['date' => format_date(date('Y-m-d'))])) ?>
        <span class="lede-count"><?= e(__('org.export_total', ['count' => (string) $totalOfficers])) ?></span>
    </p>

    <?php if ($groups === []): ?>
        <p class="empty"><?= e(__('org.export_empty')) ?></p>
    <?php else: ?>
        <?php foreach ($groups as $group): ?>
            <section class="organ">
                <h2><?= e((string) $group['title']) ?></h2>
                <?php if (trim((string) $group['lede']) !== ''): ?>
                    <p class="organ-lede"><?= e((string) $group['lede']) ?></p>
                <?php endif; ?>
                <table>
                    <thead>
                        <tr>
                            <th class="col-role"><?= e(__('org.export_col_role')) ?></th>
                            <th class="col-name"><?= e(__('org.export_col_name')) ?></th>
                            <th class="col-cf"><?= e(__('org.export_col_fiscal_code')) ?></th>
                            <th class="col-date"><?= e(__('org.export_col_appointed')) ?></th>
                            <th class="col-date"><?= e(__('org.export_col_mandate_end')) ?></th>
                            <th class="col-minutes"><?= e(__('org.appointment_minutes')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['rows'] as $row): ?>
                            <?php
                            $person = is_array($row['person'] ?? null) ? $row['person'] : [];
                            $fullName = trim(
                                (string) ($person['last_name'] ?? '') . ' ' . (string) ($person['first_name'] ?? '')
                            );
                            $fiscalCode = trim((string) ($person['fiscal_code'] ?? ''));
                            $minutes = trim((string) ($person['appointment_minutes'] ?? ''));
                            ?>
                            <tr<?= !empty($row['missing']) ? ' class="row-incomplete" title="' . e(implode(', ', (array) $row['missing'])) . '"' : '' ?>>
                                <td><?= e((string) $row['role']) ?></td>
                                <td><?= e($fullName !== '' ? $fullName : '—') ?></td>
                                <td><?= e($fiscalCode !== '' ? $fiscalCode : '—') ?></td>
                                <td><?= e(format_date($person['appointed_at'] ?? null) ?: '—') ?></td>
                                <td><?= e(format_date($person['mandate_ends_at'] ?? null) ?: '—') ?></td>
                                <td><?= e($minutes !== '' ? $minutes : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="signature">
        <p class="signature-place"><?= e(__('org.export_place_date')) ?> ______________________</p>
        <p class="signature-line"><?= e(__('org.export_signature')) ?></p>
    </div>

    <p class="meta"><?= e(__('org.export_generated', ['date' => $generatedAt])) ?></p>
    <p class="legal"><?= e(__('org.export_legal_note')) ?></p>
</div>

</body>
</html>

