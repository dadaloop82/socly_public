<?php
$step = (int)($step ?? 1);
$data = $data ?? [];
$recovery = !empty($recovery);
$labels = [
    1 => __('install.db'),
    2 => __('install.association'),
    3 => __('install.admin'),
    4 => __('install.membership'),
    5 => __('install.fields'),
    6 => __('install.confirm'),
];
?>
<p class="hero-mark"><?= e($recovery ? __('install.recovery_title') : ($labels[$step] ?? __('install.title'))) ?></p>
<?php if ($recovery): ?>
    <p class="muted"><?= e(__('install.recovery_text')) ?></p>
<?php else: ?>
    <p class="muted"><?= e(__('install.step', ['step' => (string)$step])) ?></p>
    <div class="steps">
        <?php foreach ($labels as $n => $label): ?>
            <span class="step-pill <?= $n === $step ? 'active' : '' ?>"><?= e((string)$n . '. ' . $label) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/install')) ?>">
    <?= csrf_field() ?>
    <?= field('hidden', ['name' => 'step', 'value' => (string) $step]) ?>

    <?php if ($step === 1): ?>
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
                    'value' => (string) old('db_database', $data['db_database'] ?? 'socly'),
                    'required' => true,
                ]) ?>
            </div>
            <div>
                <?= field('text', [
                    'name' => 'db_username',
                    'label' => (string) __('install.db_username'),
                    'value' => (string) old('db_username', $data['db_username'] ?? 'root'),
                    'required' => true,
                ]) ?>
            </div>
            <div>
                <?= field('password', [
                    'name' => 'db_password',
                    'label' => (string) __('install.db_password'),
                    'required' => false,
                    'value' => (string) old('db_password', $data['db_password'] ?? ''),
                    'autocomplete' => 'new-password',
                ]) ?>
            </div>
        </div>
    <?php elseif ($step === 2): ?>
        <?= field('text', [
            'name' => 'association_name',
            'label' => (string) __('install.association_name'),
            'value' => (string) old('association_name', $data['association_name'] ?? ''),
            'required' => true,
        ]) ?>
        <div class="grid-2">
            <div>
                <?= field('email', ['name' => 'association_email', 'label' => 'Email', 'value' => (string) old('association_email', $data['association_email'] ?? '')]) ?>
            </div>
            <div>
                <?= field('phone', ['name' => 'association_phone', 'label' => 'Phone', 'value' => (string) old('association_phone', $data['association_phone'] ?? '')]) ?>
            </div>
        </div>
        <?= field('text', [
            'name' => 'association_address',
            'label' => 'Address',
            'value' => (string) old('association_address', $data['association_address'] ?? ''),
        ]) ?>
        <div class="grid-3">
            <div>
                <?= field('color', [
                    'name' => 'primary',
                    'label' => (string) __('install.primary'),
                    'value' => (string) old('primary', $data['primary'] ?? '#0D6E66'),
                ]) ?>
            </div>
            <div>
                <?= field('color', [
                    'name' => 'accent',
                    'label' => (string) __('install.accent'),
                    'value' => (string) old('accent', $data['accent'] ?? '#B84A1B'),
                ]) ?>
            </div>
            <div>
                <?= field('select', [
                    'name' => 'locale',
                    'label' => (string) __('install.locale'),
                    'value' => (string) old('locale', $data['locale'] ?? 'it'),
                    'include_empty' => false,
                    'options' => [
                        ['value' => 'it', 'label' => '🇮🇹 Italiano'],
                        ['value' => 'de', 'label' => '🇩🇪 Deutsch'],
                        ['value' => 'en', 'label' => '🇬🇧 English'],
                    ],
                ]) ?>
            </div>
        </div>
        <?= field('checkbox', [
            'name' => 'gdpr_enabled',
            'label' => (string) __('install.gdpr'),
            'checked' => !empty(old('gdpr_enabled', $data['gdpr_enabled'] ?? false)),
            'label_class' => 'checkbox-row',
        ]) ?>
    <?php elseif ($step === 3): ?>
        <?= field('text', [
            'name' => 'admin_name',
            'label' => (string) __('install.admin_name'),
            'value' => (string) old('admin_name', $data['admin_name'] ?? ''),
            'required' => true,
        ]) ?>
        <?= field('email', [
            'name' => 'admin_email',
            'label' => (string) __('install.admin_email'),
            'value' => (string) old('admin_email', $data['admin_email'] ?? ''),
            'required' => true,
        ]) ?>
        <div class="grid-2">
            <div>
                <?= field('password', [
                    'name' => 'admin_password',
                    'label' => (string) __('install.admin_password'),
                    'required' => true,
                    'autocomplete' => 'new-password',
                ]) ?>
            </div>
            <div>
                <?= field('password', [
                    'name' => 'admin_password_confirmation',
                    'label' => (string) __('install.admin_password_confirmation'),
                    'required' => true,
                    'autocomplete' => 'new-password',
                ]) ?>
            </div>
        </div>
    <?php elseif ($step === 4): ?>
        <?= field('text', [
            'name' => 'period_label',
            'label' => (string) __('install.period_label'),
            'value' => (string) old('period_label', $data['period_label'] ?? date('Y')),
            'required' => true,
        ]) ?>
        <div class="grid-2">
            <div>
                <?= field('date', [
                    'name' => 'starts_on',
                    'label' => (string) __('install.starts_on'),
                    'value' => (string) old('starts_on', $data['starts_on'] ?? date('Y-01-01')),
                    'required' => true,
                ]) ?>
            </div>
            <div>
                <?= field('date', [
                    'name' => 'ends_on',
                    'label' => (string) __('install.ends_on'),
                    'value' => (string) old('ends_on', $data['ends_on'] ?? date('Y-12-31')),
                    'required' => true,
                ]) ?>
            </div>
        </div>
        <?= field('text', [
            'name' => 'type_name_it',
            'label' => (string) __('install.type_name') . ' (IT)',
            'value' => (string) old('type_name_it', $data['type_name_it'] ?? 'Ordinario'),
            'required' => true,
        ]) ?>
        <div class="grid-2">
            <div>
                <?= field('text', [
                    'name' => 'type_name_de',
                    'label' => (string) __('install.type_name') . ' (DE)',
                    'value' => (string) old('type_name_de', $data['type_name_de'] ?? 'Ordentlich'),
                ]) ?>
            </div>
            <div>
                <?= field('text', [
                    'name' => 'type_name_en',
                    'label' => (string) __('install.type_name') . ' (EN)',
                    'value' => (string) old('type_name_en', $data['type_name_en'] ?? 'Ordinary'),
                ]) ?>
            </div>
        </div>
        <?= field('number', [
            'name' => 'type_price',
            'label' => (string) __('install.type_price'),
            'value' => (string) old('type_price', $data['type_price'] ?? '50'),
            'required' => true,
            'step' => '0.01',
        ]) ?>
    <?php elseif ($step === 5): ?>
        <?php foreach ($fields as $field): ?>
            <div class="panel" style="box-shadow:none;margin-bottom:0.7rem">
                <strong><?= e(localized($field['label'])) ?></strong>
                <?= field('checkbox', [
                    'name' => 'fields[]',
                    'check_value' => (string) $field['key'],
                    'label' => (string) __('install.field_enabled'),
                    'checked' => in_array($field['key'], old('fields', $data['fields_enabled'] ?? array_column($fields, 'key')), true),
                    'label_class' => 'checkbox-row',
                ]) ?>
                <?= field('checkbox', [
                    'name' => 'required[]',
                    'check_value' => (string) $field['key'],
                    'label' => (string) __('install.field_required'),
                    'checked' => in_array($field['key'], old('required', $data['fields_required'] ?? ['first_name', 'last_name']), true),
                    'label_class' => 'checkbox-row',
                ]) ?>
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
        <button class="btn" type="submit"><?= e(
            $recovery ? __('install.recovery_save') : ($step === 6 ? __('install.finish') : __('install.next'))
        ) ?></button>
    </div>
</form>
