<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('members.title')) ?></h1>
        <p class="page-lede"><?= e(__('members.lede')) ?></p>
    </div>
    <div class="actions">
        <?php if (can('members.manage')): ?>
            <a class="btn" href="<?= e(url('/members/create')) ?>"><?= e(__('members.create')) ?></a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e(url('/members/export?' . http_build_query(array_filter($filters)))) ?>"><?= e(__('members.export')) ?></a>
    </div>
</div>

<form class="panel filter-bar" method="get">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.search')) ?></h2>
            <p class="section-lede"><?= e(__('members.filter_lede')) ?></p>
        </div>
        <button class="btn btn-sm" type="submit"><?= e(__('members.search')) ?></button>
    </div>
    <div class="grid-3">
        <div>
            <label><?= e(__('members.search')) ?></label>
            <input type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(__('members.search_placeholder')) ?>">
        </div>
        <div>
            <label><?= e(__('members.status')) ?></label>
            <select name="status">
                <option value="">—</option>
                <?php foreach (['active','suspended','expired','cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $filters['status']===$st?'selected':'' ?>><?= e(__('members.status_'.$st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?= e(__('members.payment')) ?></label>
            <select name="payment">
                <option value="">—</option>
                <?php foreach (['paid','partial','due'] as $p): ?>
                    <option value="<?= $p ?>" <?= $filters['payment']===$p?'selected':'' ?>><?= e(__('members.payment_'.$p)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div>
        <label><?= e(__('members.type')) ?></label>
        <select name="member_type_id">
            <option value="">—</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= (int)$type['id'] ?>" <?= (string)$filters['member_type_id']===(string)$type['id']?'selected':'' ?>><?= e(localized($type['name_json'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('members.results')) ?></h2>
            <p class="section-lede"><?= (int)$result['total'] ?> · <?= e(__('members.page')) ?> <?= (int)$page ?></p>
        </div>
    </div>
    <?php if (empty($result['items'])): ?>
        <div class="empty-state">
            <strong><?= e(__('members.empty_title')) ?></strong>
            <?= e(__('members.empty_text')) ?>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('members.member_number')) ?></th>
                    <th><?= e(__('members.type')) ?></th>
                    <th><?= e(__('members.status')) ?></th>
                    <th><?= e(__('members.payment')) ?></th>
                    <th><?= e(__('members.balance_due')) ?></th>
                    <th><?= e(__('members.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/members/'.$item['id'])) ?>"><?= e($item['member_number']) ?></a>
                            <div class="muted"><?= e(trim(($item['fields']['first_name'] ?? '').' '.($item['fields']['last_name'] ?? ''))) ?></div>
                        </td>
                        <td><?= e(localized($item['type_name_json'])) ?></td>
                        <td><span class="badge"><?= e(__('members.status_'.$item['status'])) ?></span></td>
                        <td><span class="badge <?= $item['payment_status']==='paid'?'badge-ok':($item['payment_status']==='due'?'badge-due':'badge-warn') ?>"><?= e(__('members.payment_'.$item['payment_status'])) ?></span></td>
                        <td><?= e(number_format((float)$item['balance_due'], 2, ',', '.')) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="<?= e(url('/members/'.$item['id'].'/edit')) ?>"><?= e(__('members.edit')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
