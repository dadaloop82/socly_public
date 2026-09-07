<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('users.title')) ?></h1>
        <p class="page-lede"><?= e(__('users.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn" href="<?= e(url('/users/create')) ?>"><?= e(__('users.create')) ?></a>
    </div>
</div>
<div class="panel">
    <div class="table-wrap embedded">
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th><?= e(__('users.locale')) ?></th>
                <th><?= e(__('users.active')) ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?><?= !empty($u['is_system_admin']) ? ' ★ ' . e(__('users.system_admin')) : '' ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e(strtoupper($u['locale'])) ?></td>
                    <td><?= $u['is_active'] ? __('common.yes') : __('common.no') ?></td>
                    <td class="doc-row-actions">
                        <?= row_actions([
                            [
                                'icon' => 'edit',
                                'label' => __('users.edit'),
                                'href' => url('/users/' . (int) $u['id'] . '/edit'),
                            ],
                        ]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
