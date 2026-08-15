<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.show')) ?> #<?= e($member['member_number']) ?></h1>
        <p class="page-lede"><?= e(trim(($member['fields']['first_name'] ?? '') . ' ' . ($member['fields']['last_name'] ?? '')) ?: __('members.show_lede')) ?></p>
    </div>
    <div class="actions">
        <?php if (can('members.manage')): ?><a class="btn" href="<?= e(url('/members/'.$member['id'].'/edit')) ?>"><?= e(__('members.edit')) ?></a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/members')) ?>"><?= e(__('common.back')) ?></a>
        <?php if (can('members.delete')): ?>
        <form method="post" action="<?= e(url('/members/'.$member['id'].'/delete')) ?>" onsubmit="return confirm('<?= e(__('members.confirm_delete')) ?>')">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit"><?= e(__('members.delete')) ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.overview')) ?></h2>
            <p class="section-lede"><?= e(__('members.overview_lede')) ?></p>
        </div>
    </div>

    <?php if (!empty($member['fields']['photo'])): ?>
        <div class="member-photo-hero">
            <img src="<?= e(url('/members/'.$member['id'].'/photo')) ?>" alt="">
        </div>
    <?php endif; ?>

    <div class="detail-grid">
        <div class="detail-item">
            <div class="label"><?= e(__('members.status')) ?></div>
            <div class="value"><span class="badge"><?= e(__('members.status_'.$member['status'])) ?></span></div>
        </div>
        <div class="detail-item">
            <div class="label"><?= e(__('members.payment')) ?></div>
            <div class="value"><span class="badge <?= $member['payment_status']==='paid'?'badge-ok':($member['payment_status']==='due'?'badge-due':'badge-warn') ?>"><?= e(__('members.payment_'.$member['payment_status'])) ?></span> — <?= e(number_format((float)$member['balance_due'], 2, ',', '.')) ?></div>
        </div>
        <div class="detail-item">
            <div class="label"><?= e(__('members.type')) ?></div>
            <div class="value"><?= e(localized($member['type_name_json'])) ?></div>
        </div>
        <div class="detail-item">
            <div class="label"><?= e(__('members.period')) ?></div>
            <div class="value"><?= e($member['period_label']) ?></div>
        </div>
        <?php foreach ($fieldDefs as $def): if (!(int)$def['is_enabled']) continue; if ($def['field_type'] === 'photo') continue; ?>
            <div class="detail-item">
                <div class="label"><?= e(localized($def['label_json'])) ?></div>
                <div class="value">
                    <?php
                    $raw = (string) ($member['fields'][$def['key']] ?? '');
                    if ($def['field_type'] === 'checkbox') {
                        echo e($raw === '1' ? __('members.yes') : __('members.no'));
                    } elseif ($def['key'] === 'gender') {
                        echo e(match ($raw) {
                            'M' => __('members.gender_m'),
                            'F' => __('members.gender_f'),
                            'X' => __('members.gender_x'),
                            default => $raw !== '' ? $raw : '—',
                        });
                    } elseif ($def['key'] === 'preferred_language') {
                        echo e(match ($raw) {
                            'it' => __('members.lang_it'),
                            'de' => __('members.lang_de'),
                            'en' => __('members.lang_en'),
                            'other' => __('members.lang_other'),
                            default => $raw !== '' ? $raw : '—',
                        });
                    } else {
                        echo e($raw !== '' ? $raw : '—');
                    }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($member['notes'])): ?>
        <p style="margin-top:1.25rem"><strong><?= e(__('members.notes')) ?>:</strong> <?= e($member['notes']) ?></p>
    <?php endif; ?>
</div>

<?php if ($payments): ?>
<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('payments.title')) ?></h2>
            <p class="section-lede"><?= e(__('payments.history_lede')) ?></p>
        </div>
    </div>
    <div class="table-wrap embedded">
        <table>
            <thead><tr><th><?= e(__('payments.amount')) ?></th><th><?= e(__('payments.method')) ?></th><th><?= e(__('payments.type')) ?></th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e(number_format((float)$p['amount'], 2, ',', '.')) ?></td>
                    <td><?= e(__('payments.'.$p['method'])) ?></td>
                    <td><?= e(__('payments.'.$p['type'])) ?></td>
                    <td><?= e($p['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
