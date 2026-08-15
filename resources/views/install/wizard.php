<?php
$step = (int)($step ?? 1);
$data = $data ?? [];
$labels = [
    1 => __('install.db'),
    2 => __('install.association'),
    3 => __('install.admin'),
    4 => __('install.membership'),
    5 => __('install.fields'),
    6 => __('install.confirm'),
];
?>
<p class="hero-mark"><?= e($labels[$step] ?? __('install.title')) ?></p>
<p class="muted"><?= e(__('install.step', ['step' => (string)$step])) ?></p>
<div class="steps">
    <?php foreach ($labels as $n => $label): ?>
        <span class="step-pill <?= $n === $step ? 'active' : '' ?>"><?= e((string)$n . '. ' . $label) ?></span>
    <?php endforeach; ?>
</div>

<form method="post" action="<?= e(url('/install')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="<?= $step ?>">

    <?php if ($step === 1): ?>
        <div class="grid-2">
            <div>
                <label><?= e(__('install.app_url')) ?></label>
                <input type="url" name="app_url" value="<?= e((string)old('app_url', $data['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')))) ?>" required>
            </div>
            <div>
                <label><?= e(__('install.db_host')) ?></label>
                <input type="text" name="db_host" value="<?= e((string)old('db_host', $data['db_host'] ?? '127.0.0.1')) ?>" required>
            </div>
            <div>
                <label><?= e(__('install.db_port')) ?></label>
                <input type="number" name="db_port" value="<?= e((string)old('db_port', $data['db_port'] ?? '3306')) ?>" required>
            </div>
            <div>
                <label><?= e(__('install.db_database')) ?></label>
                <input type="text" name="db_database" value="<?= e((string)old('db_database', $data['db_database'] ?? 'socly')) ?>" required>
            </div>
            <div>
                <label><?= e(__('install.db_username')) ?></label>
                <input type="text" name="db_username" value="<?= e((string)old('db_username', $data['db_username'] ?? 'root')) ?>" required>
            </div>
            <div>
                <?= view_partial('partials/password_input', [
                    'name' => 'db_password',
                    'label' => (string) __('install.db_password'),
                    'required' => false,
                    'value' => (string) old('db_password', $data['db_password'] ?? ''),
                    'autocomplete' => 'new-password',
                    'wrapper_class' => 'password-field',
                ]) ?>
            </div>
        </div>
    <?php elseif ($step === 2): ?>
        <label><?= e(__('install.association_name')) ?></label>
        <input type="text" name="association_name" value="<?= e((string)old('association_name', $data['association_name'] ?? '')) ?>" required>
        <div class="grid-2">
            <div>
                <label>Email</label>
                <input type="email" name="association_email" value="<?= e((string)old('association_email', $data['association_email'] ?? '')) ?>">
            </div>
            <div>
                <label>Phone</label>
                <input type="text" name="association_phone" value="<?= e((string)old('association_phone', $data['association_phone'] ?? '')) ?>">
            </div>
        </div>
        <label>Address</label>
        <input type="text" name="association_address" value="<?= e((string)old('association_address', $data['association_address'] ?? '')) ?>">
        <div class="grid-3">
            <div>
                <label><?= e(__('install.primary')) ?></label>
                <input type="color" name="primary" value="<?= e((string)old('primary', $data['primary'] ?? '#0D6E66')) ?>">
            </div>
            <div>
                <label><?= e(__('install.accent')) ?></label>
                <input type="color" name="accent" value="<?= e((string)old('accent', $data['accent'] ?? '#B84A1B')) ?>">
            </div>
            <div>
                <label><?= e(__('install.locale')) ?></label>
                <select name="locale">
                    <?php foreach (['it','de','en'] as $loc): ?>
                        <option value="<?= $loc ?>" <?= old('locale', $data['locale'] ?? 'it') === $loc ? 'selected' : '' ?>><?= strtoupper($loc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <label class="checkbox-row"><input type="checkbox" name="gdpr_enabled" value="1" <?= !empty(old('gdpr_enabled', $data['gdpr_enabled'] ?? false)) ? 'checked' : '' ?>> <?= e(__('install.gdpr')) ?></label>
    <?php elseif ($step === 3): ?>
        <label><?= e(__('install.admin_name')) ?></label>
        <input type="text" name="admin_name" value="<?= e((string)old('admin_name', $data['admin_name'] ?? '')) ?>" required>
        <label><?= e(__('install.admin_email')) ?></label>
        <input type="email" name="admin_email" value="<?= e((string)old('admin_email', $data['admin_email'] ?? '')) ?>" required>
        <div class="grid-2">
            <div>
                <?= view_partial('partials/password_input', [
                    'name' => 'admin_password',
                    'label' => (string) __('install.admin_password'),
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'wrapper_class' => 'password-field',
                ]) ?>
            </div>
            <div>
                <?= view_partial('partials/password_input', [
                    'name' => 'admin_password_confirmation',
                    'label' => (string) __('install.admin_password_confirmation'),
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'wrapper_class' => 'password-field',
                ]) ?>
            </div>
        </div>
    <?php elseif ($step === 4): ?>
        <label><?= e(__('install.period_label')) ?></label>
        <input type="text" name="period_label" value="<?= e((string)old('period_label', $data['period_label'] ?? date('Y'))) ?>" required>
        <div class="grid-2">
            <div>
                <label><?= e(__('install.starts_on')) ?></label>
                <input type="date" name="starts_on" value="<?= e((string)old('starts_on', $data['starts_on'] ?? date('Y-01-01'))) ?>" required>
            </div>
            <div>
                <label><?= e(__('install.ends_on')) ?></label>
                <input type="date" name="ends_on" value="<?= e((string)old('ends_on', $data['ends_on'] ?? date('Y-12-31'))) ?>" required>
            </div>
        </div>
        <label><?= e(__('install.type_name')) ?> (IT)</label>
        <input type="text" name="type_name_it" value="<?= e((string)old('type_name_it', $data['type_name_it'] ?? 'Ordinario')) ?>" required>
        <div class="grid-2">
            <div>
                <label><?= e(__('install.type_name')) ?> (DE)</label>
                <input type="text" name="type_name_de" value="<?= e((string)old('type_name_de', $data['type_name_de'] ?? 'Ordentlich')) ?>">
            </div>
            <div>
                <label><?= e(__('install.type_name')) ?> (EN)</label>
                <input type="text" name="type_name_en" value="<?= e((string)old('type_name_en', $data['type_name_en'] ?? 'Ordinary')) ?>">
            </div>
        </div>
        <label><?= e(__('install.type_price')) ?></label>
        <input type="number" step="0.01" name="type_price" value="<?= e((string)old('type_price', $data['type_price'] ?? '50')) ?>" required>
    <?php elseif ($step === 5): ?>
        <?php foreach ($fields as $field): ?>
            <div class="panel" style="box-shadow:none;margin-bottom:0.7rem">
                <strong><?= e(localized($field['label'])) ?></strong>
                <div class="checkbox-row"><input type="checkbox" name="fields[]" value="<?= e($field['key']) ?>" <?= in_array($field['key'], old('fields', $data['fields_enabled'] ?? array_column($fields,'key')), true) ? 'checked' : '' ?>> <?= e(__('install.field_enabled')) ?></div>
                <div class="checkbox-row"><input type="checkbox" name="required[]" value="<?= e($field['key']) ?>" <?= in_array($field['key'], old('required', $data['fields_required'] ?? ['first_name','last_name']), true) ? 'checked' : '' ?>> <?= e(__('install.field_required')) ?></div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p><?= e(__('install.ready')) ?></p>
        <ul>
            <li><?= e($data['association_name'] ?? '') ?></li>
            <li><?= e($data['admin_email'] ?? '') ?></li>
            <li><?= e(($data['db_host'] ?? '') . ' / ' . ($data['db_database'] ?? '')) ?></li>
        </ul>
    <?php endif; ?>

    <div class="actions">
        <button class="btn" type="submit"><?= e($step === 6 ? __('install.finish') : __('install.next')) ?></button>
    </div>
</form>
