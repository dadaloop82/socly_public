<?php
/** @var array<string,string> $data */
/** @var bool $recovery */
$data = $data ?? [];
$recovery = !empty($recovery);
?>
<p class="hero-mark"><?= e($recovery ? __('install.recovery_title') : __('install.title')) ?></p>
<p class="muted"><?= e($recovery ? __('install.recovery_text') : __('install.db_intro')) ?></p>

<form method="post" action="<?= e(url('/install')) ?>">
    <?= csrf_field() ?>

    <div class="grid-2">
        <div>
            <?= field('text', [
                'name' => 'app_url',
                'label' => (string) __('install.app_url'),
                'value' => (string) old('app_url', $data['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))),
                'required' => true,
                'input_type' => 'url',
            ]) ?>
        </div>
        <div>
            <?= field('text', [
                'name' => 'db_host',
                'label' => (string) __('install.db_host'),
                'value' => (string) old('db_host', $data['db_host'] ?? '127.0.0.1'),
                'required' => true,
            ]) ?>
        </div>
        <div>
            <?= field('number', [
                'name' => 'db_port',
                'label' => (string) __('install.db_port'),
                'value' => (string) old('db_port', $data['db_port'] ?? '3306'),
                'required' => true,
            ]) ?>
        </div>
        <div>
            <?= field('text', [
                'name' => 'db_database',
                'label' => (string) __('install.db_database'),
                'value' => (string) old('db_database', $data['db_database'] ?? ''),
                'required' => true,
            ]) ?>
        </div>
        <div>
            <?= field('text', [
                'name' => 'db_username',
                'label' => (string) __('install.db_username'),
                'value' => (string) old('db_username', $data['db_username'] ?? ''),
                'required' => true,
            ]) ?>
        </div>
        <div>
            <?= field('password', [
                'name' => 'db_password',
                'label' => (string) __('install.db_password'),
                'required' => false,
                'placeholder' => (string) __('install.db_password_keep'),
                'autocomplete' => 'new-password',
            ]) ?>
        </div>
    </div>

    <div class="actions">
        <button class="btn" type="submit"><?= e($recovery ? __('install.recovery_save') : __('install.db_save')) ?></button>
    </div>
</form>

<?php if (!$recovery): ?>
    <p class="muted"><?= e(__('install.after_db')) ?></p>
<?php endif; ?>
