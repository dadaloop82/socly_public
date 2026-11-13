<?php
/** @var list<array<string,mixed>> $installed */
/** @var list<array<string,mixed>> $available */
/** @var array{count:int,items:list<array<string,string>>} $updates */
/** @var array<string,mixed> $auto */
/** @var \Socly\Core\Addon\Entitlement|null $entitlement */
/** @var array<string,mixed> $entitlement_payload */
/** @var bool $license_key_present */
/** @var bool $revoked */
/** @var string $instance_code */
/** @var string $host */
/** @var string $core_version */
/** @var string $request_code */
/** @var list<string> $request_ids */
/** @var string $license_kid */
/** @var string $package_kid */
/** @var bool $keys_ready */
/** @var string $incoming_dir */
$available = is_array($available ?? null) ? $available : [];
$updates = is_array($updates ?? null) ? $updates : ['count' => 0, 'items' => []];
$licenseId = (string) ($entitlement?->licenseId() ?? '');
$licensedAddons = $entitlement?->addonIds() ?? [];
$expiresAt = $entitlement?->expiresAt();
$enabledCount = count(array_filter($installed, static fn (array $row): bool => !empty($row['is_enabled'])));
$auto = is_array($auto ?? null) ? $auto : [];
$autoInstalled = is_array($auto['installed'] ?? null) ? $auto['installed'] : [];
$pendingCore = is_array($auto['pending'] ?? null) ? $auto['pending'] : [];
?>
<div class="page-header">
    <div class="titles">
        <h1 class="page-title"><?= e(__('addons.title')) ?></h1>
        <p class="page-lede"><?= e(__('addons.lede')) ?></p>
    </div>
    <div class="actions">
        <a class="btn btn-ghost" href="<?= e(url('/settings')) ?>"><?= e(__('nav.settings')) ?></a>
        <a class="btn btn-ghost" href="<?= e(url('/audit')) ?>"><?= e(__('audit.nav')) ?></a>
    </div>
</div>

<?php if (!$keys_ready): ?>
    <div class="alert alert-warn">
        <span class="alert-body"><?= e(__('addons.keys_missing')) ?></span>
    </div>
<?php endif; ?>
<?php if ($revoked): ?>
    <div class="alert alert-error">
        <span class="alert-body"><?= e(__('addons.revoked_banner')) ?></span>
    </div>
<?php endif; ?>
<?php if ($autoInstalled !== []): ?>
    <div class="alert alert-success">
        <span class="alert-body"><?= e(__('addons.auto_installed', ['list' => implode(', ', array_map(
            static fn (array $row): string => (string) ($row['id'] ?? '') . ' ' . (string) ($row['to'] ?? ''),
            $autoInstalled
        ))])) ?></span>
    </div>
<?php endif; ?>
<?php if ($pendingCore !== []): ?>
    <div class="alert alert-warn">
        <span class="alert-body"><?= e(__('addons.next_update_core', [
            'min' => (string) ($pendingCore[0]['min_core'] ?? ''),
            'core' => $core_version,
        ])) ?></span>
    </div>
<?php endif; ?>


<div class="stats stats-context-members members-summary-stats">
    <div class="stat">
        <div class="label"><?= e(__('addons.license')) ?></div>
        <div class="value"><?= e($licenseId !== '' ? $licenseId : __('addons.status_no_license')) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('addons.licensed_addons')) ?></div>
        <div class="value"><?= (int) $enabledCount ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('addons.core_version')) ?></div>
        <div class="value"><?= e($core_version) ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.license_section')) ?></h2>
            <p class="section-lede"><?= e(__('addons.license_section_lede')) ?></p>
        </div>
    </div>
    <?php if ($licenseId !== ''): ?>
        <p class="muted">
            <?= e(__('addons.license_active_line', [
                'id' => $licenseId,
                'addons' => $licensedAddons !== [] ? implode(', ', $licensedAddons) : '—',
            ])) ?>
        </p>
        <?php if ($expiresAt !== null): ?>
            <p class="muted"><?= e(__('addons.license_expires', ['date' => format_datetime(date('Y-m-d H:i:s', $expiresAt))])) ?></p>
        <?php endif; ?>
        <?php if ($entitlement?->isTrial()): ?>
            <p class="muted"><?= e(__('addons.license_trial')) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/settings/addons/activate')) ?>" class="panel embedded">
        <?= csrf_field() ?>
        <h3 class="section-title"><?= e(__('addons.activate_title')) ?></h3>
        <p class="muted"><?= e(__('addons.activate_lede')) ?></p>
        <?= field('text', [
            'name' => 'license_key',
            'label' => (string) __('addons.license_key'),
            'placeholder' => 'SOCLY-KEY-…',
            'autocomplete' => 'off',
        ]) ?>
        <button class="btn btn-sm" type="submit"><?= e(__('addons.activate_button')) ?></button>
        <p class="setup-hint muted" style="margin:0.6rem 0 0"><?= e(__('addons.activate_offline_hint')) ?></p>
    </form>

    <details class="config-accordion" style="margin-top:1rem">
        <summary><?= e(__('addons.advanced_title')) ?></summary>
        <p class="muted"><?= e(__('addons.advanced_lede')) ?></p>
        <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr))">
            <form method="post" action="<?= e(url('/settings/addons/import')) ?>" class="panel embedded">
                <?= csrf_field() ?>
                <h3 class="section-title"><?= e(__('addons.offline_title')) ?></h3>
                <p class="muted"><?= e(__('addons.offline_lede')) ?></p>
                <?= field('textarea', [
                    'name' => 'code',
                    'label' => (string) __('addons.code_label'),
                    'rows' => 4,
                    'required' => true,
                    'attrs' => ['spellcheck' => 'false', 'autocomplete' => 'off'],
                ]) ?>
                <button class="btn btn-sm" type="submit"><?= e(__('addons.import_button')) ?></button>
            </form>
            <form method="post" action="<?= e(url('/settings/addons/install')) ?>" enctype="multipart/form-data" class="panel embedded">
                <?= csrf_field() ?>
                <h3 class="section-title"><?= e(__('addons.install_title')) ?></h3>
                <p class="muted"><?= e(__('addons.install_lede')) ?></p>
                <?= field('file', [
                    'name' => 'package',
                    'label' => (string) __('addons.package_file'),
                    'required' => true,
                    'attrs' => ['accept' => '.soclyaddon,application/zip'],
                ]) ?>
                <button class="btn btn-sm" type="submit"><?= e(__('addons.install_button')) ?></button>
            </form>
        </div>
        <details class="config-accordion" style="margin-top:1rem">
            <summary><?= e(__('addons.request_title')) ?></summary>
            <p class="muted"><?= e(__('addons.request_hint')) ?></p>
            <div class="setup-hint muted">
                <?= e(__('addons.request_identity', ['host' => $host !== '' ? $host : '—', 'code' => $instance_code])) ?>
            </div>
            <textarea class="sf-field__control" rows="4" readonly onclick="this.select()"><?= e($request_code) ?></textarea>
            <form method="get" action="<?= e(url('/settings/addons')) ?>" class="inline-form" style="margin-top:0.5rem">
                <?= field('text', [
                    'name' => 'request',
                    'label' => (string) __('addons.request_ids_label'),
                    'value' => implode(',', $request_ids),
                    'attrs' => ['placeholder' => 'advanced_reports, altra_estensione'],
                ]) ?>
                <button class="btn btn-ghost btn-sm" type="submit"><?= e(__('addons.request_regenerate')) ?></button>
            </form>
        </details>
        <p class="setup-hint muted">
            <?= e(__('addons.incoming_dir', ['dir' => (string) $incoming_dir])) ?>
            · <?= e(__('addons.incoming_hint')) ?>
        </p>
        <p class="muted setup-hint"><?= e(__('addons.technical_details', ['code' => $instance_code])) ?></p>
        <p class="muted setup-hint"><?= e(__('addons.footer_note', ['license_kid' => $license_kid, 'package_kid' => $package_kid])) ?></p>
    </details>
</div>

<div data-addons-panels>

    <?= view_partial('addons/dynamic', [

        'available' => $available,

        'updates' => $updates,

        'installed' => $installed,

        'core_version' => $core_version,

        'license_key_present' => $license_key_present,

        'licensedAddons' => $licensedAddons,

    ]) ?>

</div>
