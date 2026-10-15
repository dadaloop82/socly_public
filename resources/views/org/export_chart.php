<?php
/**
 * Organigramma stampabile (nuova pagina, stampa → PDF).
 *
 * @var array{directors:list<array<string,mixed>>,control:list<array<string,mixed>>,commissions:list<array<string,mixed>>} $tiers
 * @var list<array{name:string,role:string,fields:list<string>}> $incomplete
 * @var int $totalOfficers
 * @var int $votingMembersCount
 * @var string $assocName
 * @var string $assocLegalCode
 * @var string $assocLegalLabel
 * @var string $assocFiscalCode
 * @var string $assocAddress
 * @var string|null $brandPrimary
 * @var string|null $brandAccent
 * @var string $generatedAt
 * @var string $boardLabel
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
$tiers = is_array($tiers ?? null) ? $tiers : ['directors' => [], 'control' => [], 'commissions' => []];
$incomplete = is_array($incomplete ?? null) ? $incomplete : [];
$totalOfficers = (int) ($totalOfficers ?? 0);
$votingMembersCount = (int) ($votingMembersCount ?? 0);
$boardLabel = trim((string) ($boardLabel ?? '')) !== '' ? (string) $boardLabel : __('org.board_members_section');
$boardLede = trim((string) ($boardLede ?? ''));
$generatedAt = trim((string) ($generatedAt ?? ''));
$generatedAt = $generatedAt !== '' ? $generatedAt : date('d/m/Y H:i');

$directorsMonocratic = [];
$directorsBoard = [];
foreach ($tiers['directors'] as $group) {
    if (!empty($group['is_board'])) {
        $directorsBoard[] = $group;
    } else {
        $directorsMonocratic[] = $group;
    }
}

/** Mandate line for a person node ("Data di nomina: … · Scadenza mandato: …"). */
$personMeta = static function (array $person): string {
    $bits = [];
    $from = format_date($person['appointed_at'] ?? null);
    $to = format_date($person['mandate_ends_at'] ?? null);
    if ($from !== '') {
        $bits[] = __('org.export_col_appointed') . ': ' . $from;
    }
    if ($to !== '') {
        $bits[] = __('org.export_col_mandate_end') . ': ' . $to;
    }
    return implode(' · ', $bits);
};
$personName = static function (array $person): string {
    $name = trim((string) ($person['last_name'] ?? '') . ' ' . (string) ($person['first_name'] ?? ''));
    return $name !== '' ? $name : '—';
};
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('org.export_chart_title')) ?> — <?= e($assocName) ?></title>
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
          max-width: 960px;
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

        /* ── Diagramma ─────────────────────────────────────────────── */
        .chart { display: block; }
        .chart-link {
          width: 2px;
          height: 18px;
          margin: 0 auto;
          background: color-mix(in srgb, var(--brand-primary) 35%, #cbd2d0);
        }
        .chart-tier { width: 100%; }
        .tier-title {
          margin: 0 0 0.3rem;
          text-align: center;
          font-size: 0.9rem;
          font-weight: 800;
          text-transform: uppercase;
          letter-spacing: 0.04em;
          color: var(--brand-primary-deep, #111);
        }
        .tier-lede {
          margin: 0 auto 0.55rem;
          max-width: 40rem;
          text-align: center;
          color: #555;
          font-size: 0.82rem;
        }
        .group-title {
          margin: 0.75rem 0 0.4rem;
          text-align: center;
          font-size: 0.78rem;
          font-weight: 800;
          text-transform: uppercase;
          letter-spacing: 0.05em;
          color: #333;
          break-after: avoid;
        }
        .nodes {
          display: flex;
          flex-wrap: wrap;
          justify-content: center;
          gap: 0.6rem;
        }
        .cnode {
          flex: 1 1 12.5rem;
          min-width: 10rem;
          max-width: 17rem;
          padding: 0.55rem 0.65rem;
          border: 1px solid #cbd2d0;
          border-radius: 12px;
          background: #fbfbfa;
          break-inside: avoid;
        }
        .cnode--root {
          flex: 0 1 auto;
          max-width: 34rem;
          text-align: center;
          background: color-mix(in srgb, var(--brand-primary) 6%, #fff);
          border-color: color-mix(in srgb, var(--brand-primary) 28%, #cbd2d0);
        }
        .cnode--assembly {
          flex: 0 1 auto;
          max-width: 26rem;
          text-align: center;
          background: color-mix(in srgb, var(--brand-primary) 3%, #fff);
        }
        .cnode--vacant { background: #f7f7f6; border-style: dashed; }
        .cnode-role {
          margin: 0 0 0.2rem;
          font-size: 0.72rem;
          font-weight: 800;
          text-transform: uppercase;
          letter-spacing: 0.05em;
          color: var(--brand-primary-deep, #111);
        }
        .cnode-role--person {
          color: #6b7573;
        }
        .cnode-name {
          display: block;
          font-size: 0.93rem;
          font-weight: 700;
        }
        .cnode-person + .cnode-person {
          margin-top: 0.45rem;
          padding-top: 0.45rem;
          border-top: 1px dashed #dfe3e1;
        }
        .cnode-meta {
          display: block;
          margin-top: 0.12rem;
          color: #555;
          font-size: 0.74rem;
        }
        .cnode-minutes {
          display: block;
          color: #666;
          font-size: 0.72rem;
          font-style: italic;
        }
        .cnode-empty {
          margin: 0;
          color: #999;
          font-size: 0.8rem;
          font-style: italic;
        }
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
          .chart-tier, .nodes, .cnode { break-inside: avoid; }
          .tier-title, header { break-after: avoid; }
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
            <strong class="alert-title"><?= e(__('org.export_missing_title')) ?></strong>
            <p class="alert-lede"><?= e(__('org.export_missing_lede')) ?></p>
            <ul class="alert-list">
                <?php foreach ($incomplete as $item): ?>
                    <li>
                        <span class="alert-person"><?= e((string) $item['name']) ?></span>
                        · <?= e((string) $item['role']) ?> — <?= e(implode(', ', $item['fields'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <header>
        <h1><?= e(__('org.export_chart_title')) ?></h1>
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
        <?= e(__('org.export_chart_lede', ['date' => format_date(date('Y-m-d'))])) ?>
        <span class="lede-count"><?= e(__('org.export_total', ['count' => (string) $totalOfficers])) ?></span>
    </p>

    <?php if ($totalOfficers === 0): ?>
        <p class="empty"><?= e(__('org.export_empty')) ?></p>
    <?php endif; ?>

    <div class="chart">
        <div class="chart-tier">
            <article class="cnode cnode--root">
                <p class="cnode-role"><?= e(__('org.association')) ?></p>
                <span class="cnode-name"><?= e($assocName) ?></span>
                <?php if ($assocLegalCode !== ''): ?>
                    <span class="cnode-meta"><?= e($assocLegalCode) ?><?= $assocLegalLabel !== '' ? ' — ' . e($assocLegalLabel) : '' ?></span>
                <?php endif; ?>
                <?php if ($assocAddress !== ''): ?>
                    <span class="cnode-meta"><?= e($assocAddress) ?></span>
                <?php endif; ?>
            </article>
        </div>

        <div class="chart-link" aria-hidden="true"></div>

        <div class="chart-tier">
            <article class="cnode cnode--assembly">
                <p class="cnode-role"><?= e(__('org.general_assembly')) ?></p>
                <span class="cnode-name"><?= e(__('org.general_assembly_title')) ?></span>
                <span class="cnode-meta"><?= e($votingMembersCount > 0
                    ? __('org.voting_members_count', ['count' => (string) $votingMembersCount])
                    : __('org.voting_members_empty')) ?></span>
            </article>
        </div>

        <div class="chart-link" aria-hidden="true"></div>

        <section class="chart-tier">
            <h2 class="tier-title"><?= e(__('org.board_of_directors')) ?></h2>
            <?php if ($boardLede !== ''): ?>
                <p class="tier-lede"><?= e($boardLede) ?></p>
            <?php endif; ?>
            <div class="nodes">
                <?php foreach ($directorsMonocratic as $group): ?>
                    <?php $people = is_array($group['people'] ?? null) ? $group['people'] : []; ?>
                    <article class="cnode<?= $people === [] ? ' cnode--vacant' : '' ?>">
                        <p class="cnode-role"><?= e((string) $group['label']) ?></p>
                        <?php if ($people === []): ?>
                            <p class="cnode-empty"><?= e(__('org.vacant')) ?></p>
                        <?php else: ?>
                            <?php foreach ($people as $person): ?>
                                <div class="cnode-person">
                                    <span class="cnode-name"><?= e($personName($person)) ?></span>
                                    <?php $meta = $personMeta($person); ?>
                                    <?php if ($meta !== ''): ?>
                                        <span class="cnode-meta"><?= e($meta) ?></span>
                                    <?php endif; ?>
                                    <?php $minutes = trim((string) ($person['appointment_minutes'] ?? '')); ?>
                                    <?php if ($minutes !== ''): ?>
                                        <span class="cnode-minutes"><?= e($minutes) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php
            $boardPeople = [];
            foreach ($directorsBoard as $group) {
                foreach ((array) ($group['people'] ?? []) as $person) {
                    $boardPeople[] = ['label' => (string) $group['label'], 'person' => $person];
                }
            }
            ?>
            <?php if ($boardPeople !== []): ?>
                <h3 class="group-title"><?= e($boardLabel) ?></h3>
                <div class="nodes">
                    <?php foreach ($boardPeople as $entry): ?>
                        <article class="cnode">
                            <p class="cnode-role cnode-role--person"><?= e($entry['label']) ?></p>
                            <span class="cnode-name"><?= e($personName($entry['person'])) ?></span>
                            <?php $meta = $personMeta($entry['person']); ?>
                            <?php if ($meta !== ''): ?>
                                <span class="cnode-meta"><?= e($meta) ?></span>
                            <?php endif; ?>
                            <?php $minutes = trim((string) ($entry['person']['appointment_minutes'] ?? '')); ?>
                            <?php if ($minutes !== ''): ?>
                                <span class="cnode-minutes"><?= e($minutes) ?></span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($tiers['control'] !== []): ?>
            <div class="chart-link" aria-hidden="true"></div>
            <section class="chart-tier">
                <h2 class="tier-title"><?= e(__('org.independent_organs')) ?></h2>
                <div class="nodes">
                    <?php foreach ($tiers['control'] as $group): ?>
                        <?php $people = is_array($group['people'] ?? null) ? $group['people'] : []; ?>
                        <article class="cnode<?= $people === [] ? ' cnode--vacant' : '' ?>">
                            <p class="cnode-role"><?= e((string) $group['label']) ?></p>
                            <?php if ($people === []): ?>
                                <p class="cnode-empty"><?= e(__('org.vacant')) ?></p>
                            <?php else: ?>
                                <?php foreach ($people as $person): ?>
                                    <div class="cnode-person">
                                        <span class="cnode-name"><?= e($personName($person)) ?></span>
                                        <?php $meta = $personMeta($person); ?>
                                        <?php if ($meta !== ''): ?>
                                            <span class="cnode-meta"><?= e($meta) ?></span>
                                        <?php endif; ?>
                                        <?php $minutes = trim((string) ($person['appointment_minutes'] ?? '')); ?>
                                        <?php if ($minutes !== ''): ?>
                                            <span class="cnode-minutes"><?= e($minutes) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($tiers['commissions'] !== []): ?>
            <div class="chart-link" aria-hidden="true"></div>
            <section class="chart-tier">
                <h2 class="tier-title"><?= e(__('org.operational_commissions')) ?></h2>
                <div class="nodes">
                    <?php foreach ($tiers['commissions'] as $group): ?>
                        <article class="cnode">
                            <p class="cnode-role"><?= e((string) $group['label']) ?></p>
                            <?php foreach ((array) ($group['people'] ?? []) as $person): ?>
                                <div class="cnode-person">
                                    <span class="cnode-name"><?= e($personName($person)) ?></span>
                                    <?php $meta = $personMeta($person); ?>
                                    <?php if ($meta !== ''): ?>
                                        <span class="cnode-meta"><?= e($meta) ?></span>
                                    <?php endif; ?>
                                    <?php $minutes = trim((string) ($person['appointment_minutes'] ?? '')); ?>
                                    <?php if ($minutes !== ''): ?>
                                        <span class="cnode-minutes"><?= e($minutes) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div class="signature">
        <p class="signature-place"><?= e(__('org.export_place_date')) ?> ______________________</p>
        <p class="signature-line"><?= e(__('org.export_signature')) ?></p>
    </div>

    <p class="meta"><?= e(__('org.export_generated', ['date' => $generatedAt])) ?></p>
    <p class="legal"><?= e(__('org.export_legal_note')) ?></p>
</div>


</body>
</html>
