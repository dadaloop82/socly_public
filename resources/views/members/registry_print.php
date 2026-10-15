<?php
/**
 * Libro Soci — stampa / esportazione PDF (stessa veste della Dichiarazione cariche).
 *
 * @var array{name:string,fiscal_code:string,address:string,legal_code?:string,legal_label?:string} $association
 * @var list<array<string,mixed>> $rows
 * @var list<array{name:string,card:string,missing:list<string>}> $incomplete
 * @var array{total:int,active:int} $summary
 * @var string $generated_at
 * @var int $total
 * @var string|null $brand_primary
 * @var string|null $brand_accent
 */
$brandStyle = brand_root_style_decls(
    isset($brand_primary) ? (string) $brand_primary : null,
    isset($brand_accent) ? (string) $brand_accent : null
);
$assocName = trim((string) ($association['name'] ?? ''));
$assocName = $assocName !== '' ? $assocName : 'SOCLY';
$assocLegalCode = strtoupper(trim((string) ($association['legal_code'] ?? '')));
$assocLegalLabel = trim((string) ($association['legal_label'] ?? ''));
$assocFiscalCode = trim((string) ($association['fiscal_code'] ?? ''));
$assocAddress = trim((string) ($association['address'] ?? ''));
$incomplete = is_array($incomplete ?? null) ? $incomplete : [];
$summary = is_array($summary ?? null) ? $summary : ['total' => (int) $total, 'active' => 0];
$generatedAt = trim((string) ($generated_at ?? ''));
$generatedAt = $generatedAt !== '' ? $generatedAt : date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('members.registry_title')) ?> — <?= e($assocName) ?></title>
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
          max-width: 1280px;
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
        .col-prog { width: 4.2%; }
        .col-card { width: 6.5%; }
        .col-member { width: 20%; }
        .col-birth { width: 15%; }
        .col-res { width: 20%; }
        .col-adm { width: 16%; }
        .col-type { width: 18.3%; }
        .cell-stack { display: block; }
        .cell-muted {
          display: block;
          margin-top: 0.15rem;
          color: #555;
          font-size: 0.92em;
        }
        .cell-label { font-weight: 700; color: #333; }
        tr.row-incomplete td { background: #fffdf5; }
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
        @page {
          size: A4 landscape;
          margin: 10mm 10mm 12mm;
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
          tr.row-incomplete td { background: transparent; }
          thead { display: table-header-group; }
          tfoot { display: table-footer-group; }
          tr { page-break-inside: avoid; }
          header { break-after: avoid; }
          h2 { break-after: avoid; }
          a { color: inherit; text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="toolbar no-print">
        <button type="button" class="btn btn-ghost" onclick="window.close()"><?= e(__('common.close')) ?></button>
        <button type="button" class="btn btn-primary" onclick="window.print()"><?= e(__('members.registry_print')) ?></button>
    </div>

    <?php if ($incomplete !== []): ?>
        <div class="alert no-print" role="alert">
            <strong class="alert-title"><?= e(__('members.registry_missing_title')) ?></strong>
            <p class="alert-lede"><?= e(__('members.registry_missing_lede')) ?></p>
            <ul class="alert-list">
                <?php foreach ($incomplete as $item): ?>
                    <li>
                        <span class="alert-person"><?= e((string) $item['name']) ?></span>
                        <?php if (trim((string) ($item['card'] ?? '')) !== ''): ?>
                            · <?= e(__('members.registry_col_card')) ?> <?= e((string) $item['card']) ?>
                        <?php endif; ?>
                        — <?= e(implode(', ', $item['missing'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <header>
        <h1><?= e(__('members.registry_title')) ?></h1>
        <p class="assoc">
            <strong><?= e($assocName) ?></strong>
            <?php if ($assocLegalCode !== ''): ?>
                · <?= e($assocLegalCode) ?><?= $assocLegalLabel !== '' ? ' (' . e($assocLegalLabel) . ')' : '' ?>
            <?php endif; ?>
        </p>
        <?php if ($assocFiscalCode !== ''): ?>
            <p class="assoc-meta"><?= e(__('members.registry_assoc_fiscal')) ?>: <?= e($assocFiscalCode) ?></p>
        <?php endif; ?>
        <?php if ($assocAddress !== ''): ?>
            <p class="assoc-meta"><?= e($assocAddress) ?></p>
        <?php endif; ?>
    </header>

    <p class="lede">
        <?= e(__('members.registry_subtitle', ['count' => (string) $total])) ?>
        <span class="lede-count"><?= e(__('members.registry_total_active', ['count' => (string) ($summary['total'] ?? $total), 'active' => (string) ($summary['active'] ?? 0)])) ?></span>
    </p>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(__('members.registry_empty')) ?></p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th class="col-prog"><?= e(__('members.registry_col_progressive')) ?></th>
                <th class="col-card"><?= e(__('members.registry_col_card')) ?></th>
                <th class="col-member"><?= e(__('members.registry_col_member')) ?></th>
                <th class="col-birth"><?= e(__('members.registry_col_birth')) ?></th>
                <th class="col-res"><?= e(__('members.registry_col_residence')) ?></th>
                <th class="col-adm"><?= e(__('members.registry_col_admission')) ?></th>
                <th class="col-type"><?= e(__('members.registry_col_type_status')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $fullName = trim((string) ($row['last_name'] ?? '') . ' ' . (string) ($row['first_name'] ?? ''));
                $fiscal = trim((string) ($row['fiscal_code'] ?? ''));
                $birth = trim((string) ($row['birth'] ?? ''));
                $residence = trim((string) ($row['residence'] ?? ''));
                $applied = trim((string) ($row['applied_at'] ?? ''));
                $admitted = trim((string) ($row['admitted_at'] ?? ''));
                ?>
                <tr<?= empty($row['missing']) ? '' : ' class="row-incomplete" title="' . e(implode(', ', (array) $row['missing'])) . '"' ?>>
                    <td><?= (int) ($row['progressive'] ?? 0) ?></td>
                    <td><?= e((string) ($row['member_number'] ?? '')) ?></td>
                    <td>
                        <span class="cell-stack"><?= e($fullName !== '' ? $fullName : '—') ?></span>
                        <?php if ($fiscal !== ''): ?>
                            <span class="cell-muted"><?= e(__('members.registry_cf_prefix')) ?>: <?= e($fiscal) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($birth !== '' ? $birth : '—') ?></td>
                    <td><?= e($residence !== '' ? $residence : '—') ?></td>
                    <td>
                        <span class="cell-stack">
                            <span class="cell-label"><?= e(__('members.registry_applied_short')) ?>:</span>
                            <?= e($applied !== '' ? $applied : '—') ?>
                        </span>
                        <span class="cell-muted">
                            <span class="cell-label"><?= e(__('members.registry_admitted_short')) ?>:</span>
                            <?= e($admitted !== '' ? $admitted : '—') ?>
                        </span>
                    </td>
                    <td>
                        <span class="cell-stack"><?= e((string) ($row['type_label'] ?? '—')) ?></span>
                        <span class="cell-muted"><?= e((string) ($row['status_label'] ?? '')) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <div class="signature">
        <p class="signature-place"><?= e(__('members.registry_place_date')) ?> ______________________</p>
        <p class="signature-line"><?= e(__('members.registry_signature')) ?></p>
    </div>

    <p class="meta"><?= e(__('members.registry_generated', ['date' => $generatedAt])) ?></p>
    <p class="legal"><?= e(__('members.registry_legal_note')) ?></p>
</div>

</body>
</html>
