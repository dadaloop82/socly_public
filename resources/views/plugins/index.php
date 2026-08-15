<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('plugins.title')) ?></h1>
        <p class="page-lede"><?= e(__('plugins.lede')) ?></p>
    </div>
</div>

<?php if (!$catalog): ?>
    <div class="panel">
        <div class="empty-state">
            <strong><?= e(__('plugins.none')) ?></strong>
            <?= e(__('plugins.none_hint')) ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($catalog as $plugin): ?>
<div class="form-card">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e($plugin['name']) ?></h2>
            <p class="section-lede"><?= e($plugin['description']) ?> · <?= e(__('plugins.version')) ?> <?= e($plugin['version']) ?></p>
        </div>
        <form method="post" action="<?= e(url('/plugins/'.$plugin['id'].'/'.($plugin['is_enabled']?'disable':'enable'))) ?>">
            <?= csrf_field() ?>
            <button class="btn <?= $plugin['is_enabled']?'btn-ghost':'' ?>" type="submit">
                <?= e($plugin['is_enabled'] ? __('plugins.disable') : __('plugins.enable')) ?>
            </button>
        </form>
    </div>
    <?php if ($plugin['is_enabled'] && $plugin['settings']): ?>
        <h3 class="section-title"><?= e(__('plugins.settings')) ?></h3>
        <form method="post" action="<?= e(url('/plugins/'.$plugin['id'].'/settings')) ?>">
            <?= csrf_field() ?>
            <?php foreach ($plugin['settings'] as $key => $def): ?>
                <label><?= e($def['label'] ?? $key) ?></label>
                <input type="<?= !empty($def['encrypted']) ? 'password' : 'text' ?>" name="<?= e($key) ?>" value="<?= e((string)($plugin['values'][$key] ?? '')) ?>">
            <?php endforeach; ?>
            <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
        </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
