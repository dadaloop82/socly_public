<?php
/** @var array<string,mixed> $member */
/** @var array{name:string,fiscal_code:string,vat_number:string,address:string,email:string,phone:string} $association */
/** @var list<array{label:string,value:string}> $fields */
/** @var string $type_label */
/** @var string $period_label */
/** @var string $privacy_text */
/** @var string $statute_text */
/** @var string $generated_at */
$memberName = trim(
    trim((string) ($member['fields']['first_name'] ?? '')) . ' ' . trim((string) ($member['fields']['last_name'] ?? ''))
);
?>
<!DOCTYPE html>
<html lang="<?= e(app_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('members.enrollment_print_form_title')) ?> — <?= e($association['name'] ?: 'SOCLY') ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            font: 14px/1.5 "Segoe UI", system-ui, sans-serif;
            color: #111;
            background: #fff;
        }
        .sheet {
            max-width: 780px;
            margin: 0 auto;
        }
        .toolbar {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .toolbar button {
            border: 1px solid #ccc;
            background: #f7f7f7;
            border-radius: 8px;
            padding: 0.45rem 0.85rem;
            cursor: pointer;
            font: inherit;
        }
        header {
            border-bottom: 2px solid #111;
            padding-bottom: 0.75rem;
            margin-bottom: 1.25rem;
        }
        header h1 {
            margin: 0 0 0.35rem;
            font-size: 1.15rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        header p { margin: 0.15rem 0; color: #444; }
        h2 {
            margin: 1.5rem 0 0.65rem;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.5rem 0 1rem;
        }
        th, td {
            border: 1px solid #bbb;
            padding: 0.45rem 0.55rem;
            vertical-align: top;
            text-align: left;
        }
        th { width: 34%; background: #f5f5f5; font-weight: 600; }
        .declaration {
            border: 1px solid #bbb;
            padding: 0.85rem;
            margin: 0.75rem 0;
            background: #fafafa;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .sign-box {
            min-height: 5.5rem;
            border-top: 1px solid #111;
            padding-top: 0.35rem;
            margin-top: 3rem;
        }
        .sign-label { font-size: 0.85rem; color: #444; }
        .meta { color: #666; font-size: 0.85rem; margin-top: 1.5rem; }
        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="toolbar">
        <button type="button" onclick="window.print()"><?= e(__('members.enrollment_print_form')) ?></button>
        <button type="button" onclick="window.close()"><?= e(__('common.cancel')) ?></button>
    </div>

    <header>
        <h1><?= e($association['name'] ?: '—') ?></h1>
        <?php if ($association['address'] !== ''): ?><p><?= e($association['address']) ?></p><?php endif; ?>
        <?php if ($association['fiscal_code'] !== ''): ?><p><?= e(__('members.enrollment_form_fiscal')) ?>: <?= e($association['fiscal_code']) ?></p><?php endif; ?>
        <?php if ($association['vat_number'] !== ''): ?><p><?= e(__('members.enrollment_form_vat')) ?>: <?= e($association['vat_number']) ?></p><?php endif; ?>
        <?php if ($association['email'] !== '' || $association['phone'] !== ''): ?>
            <p><?= e(trim($association['email'] . ($association['phone'] !== '' ? ' · ' . $association['phone'] : ''))) ?></p>
        <?php endif; ?>
    </header>

    <h2><?= e(__('members.enrollment_print_form_title')) ?></h2>
    <p><?= e(__('members.enrollment_form_intro', [
        'name' => $memberName !== '' ? $memberName : '________________________',
        'type' => $type_label !== '' ? $type_label : '—',
        'period' => $period_label !== '' ? $period_label : '—',
        'number' => (string) ($member['member_number'] ?? '—'),
    ])) ?></p>

    <h2><?= e(__('members.enrollment_form_data')) ?></h2>
    <table>
        <tbody>
        <?php foreach ($fields as $field): ?>
            <tr>
                <th><?= e($field['label']) ?></th>
                <td><?= e($field['value']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th><?= e(__('members.type')) ?></th>
            <td><?= e($type_label) ?></td>
        </tr>
        <tr>
            <th><?= e(__('members.period')) ?></th>
            <td><?= e($period_label) ?></td>
        </tr>
        <tr>
            <th><?= e(__('members.member_number')) ?></th>
            <td><?= e((string) ($member['member_number'] ?? '—')) ?></td>
        </tr>
        </tbody>
    </table>

    <h2><?= e(__('members.enrollment_form_declarations')) ?></h2>
    <?php if ($statute_text !== ''): ?>
        <div class="declaration">
            <strong><?= e(__('members.enrollment_form_statute_ack')) ?></strong>
            <p style="margin:0.5rem 0 0;white-space:pre-wrap"><?= e($statute_text) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($privacy_text !== ''): ?>
        <div class="declaration">
            <strong><?= e(__('members.enrollment_form_privacy_ack')) ?></strong>
            <p style="margin:0.5rem 0 0;white-space:pre-wrap"><?= e($privacy_text) ?></p>
        </div>
    <?php endif; ?>
    <p><?= e(__('members.enrollment_form_declaration_body')) ?></p>

    <div class="signatures">
        <div>
            <p class="sign-label"><?= e(__('members.enrollment_form_place_date')) ?></p>
            <p>________________________</p>
            <div class="sign-box">
                <span class="sign-label"><?= e(__('members.enrollment_form_signature_member')) ?></span>
            </div>
        </div>
        <div>
            <p class="sign-label"><?= e(__('members.enrollment_form_board_section')) ?></p>
            <p><?= e(__('members.enrollment_form_board_body')) ?></p>
            <div class="sign-box">
                <span class="sign-label"><?= e(__('members.enrollment_form_signature_assoc')) ?></span>
            </div>
        </div>
    </div>

    <p class="meta"><?= e(__('members.enrollment_form_generated', ['date' => $generated_at])) ?></p>
</div>
</body>
</html>
