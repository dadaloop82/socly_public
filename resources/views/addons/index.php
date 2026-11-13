<?php
/** @var list<array<string,mixed>> $installed */
/** @var list<array<string,mixed>> $available */
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

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.available_title')) ?></h2>
            <p class="section-lede"><?= e(__('addons.available_lede')) ?></p>
        </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin:0.75rem 0 1rem">
        <form method="post" action="<?= e(url('/settings/addons/check')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-ghost" type="submit"><?= e(__('addons.available_refresh')) ?></button>
        </form>
        <?php if ($license_key_present): ?>
            <form method="post" action="<?= e(url('/settings/addons/auto-install')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm" type="submit"><?= e(__('addons.available_install_all')) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (!$license_key_present): ?>
        <p class="setup-hint muted"><?= e(__('addons.available_offline_hint')) ?></p>
    <?php endif; ?>
    <?php if ($available === []): ?>
        <div class="empty-state">
            <strong><?= e(__('addons.available_empty')) ?></strong>
            <p><?= e(__('addons.available_empty_hint')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('addons.col_addon')) ?></th>
                    <th><?= e(__('addons.col_version')) ?></th>
                    <th><?= e(__('addons.col_status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($available as $row): ?>
                    <?php
                    $rowId = (string) ($row['id'] ?? '');
                    $rowName = (string) ($row['name'] ?? $rowId);
                    $action = (string) ($row['action'] ?? 'current');
                    $via = (string) ($row['via'] ?? '');
                    $version = (string) ($row['version'] ?? '');
                    $installedVersion = (string) ($row['installed_version'] ?? '');
                    $localFile = (string) ($row['local_file'] ?? '');
                    $canRun = in_array($action, ['install', 'update'], true);
                    $sourceText = match ($via) {
                        'catalogue' => __('addons.source_server'),
                        'package' => __('addons.source_package'),
                        default => __('addons.source_installed'),
                    };
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($rowName) ?></strong>
                            <?php if ($rowId !== '' && $rowId !== $rowName): ?>
                                <br><span class="muted audit-ip"><?= e($rowId) ?></span>
                            <?php endif; ?>
                            <br><span class="muted" style="font-size:0.8rem"><?= e($sourceText) ?></span>
                        </td>
                        <td>
                            <?= e($version !== '' ? $version : '—') ?>
                            <?php if ($installedVersion !== '' && $installedVersion !== $version): ?>
                                <br><span class="muted"><?= e(__('addons.catalogue_installed', ['version' => $installedVersion])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($action === 'invalid'): ?>
                                <span class="badge"><?= e(__('addons.incoming_invalid')) ?></span>
                                <?php if ((string) ($row['error'] ?? '') !== ''): ?>
                                    <br><span class="muted" style="font-size:0.78rem"><?= e((string) $row['error']) ?></span>
                                <?php endif; ?>
                            <?php elseif ($action === 'core'): ?>
                                <span class="badge"><?= e(__('addons.action_core', ['min' => (string) ($row['min_core'] ?? ''), 'core' => $core_version])) ?></span>
                            <?php elseif ($action === 'unlicensed'): ?>
                                <span class="muted"><?= e(__('addons.incoming_licensed_no')) ?></span>
                            <?php elseif ($action === 'enable'): ?>
                                <span class="badge"><?= e(__('addons.action_enable_state')) ?></span>
                            <?php elseif ($action === 'update'): ?>
                                <span class="badge"><?= e(__('addons.action_update_state')) ?></span>
                            <?php elseif ($action === 'install'): ?>
                                <span class="badge"><?= e(__('addons.action_install_state')) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= e(__('addons.action_current_state')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="doc-row-actions">
                            <?php if ($canRun && $via === 'catalogue'): ?>
                                <form method="post" action="<?= e(url('/settings/addons/install-licensed')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="addon_id" value="<?= e($rowId) ?>">
                                    <input type="hidden" name="version" value="<?= e($version) ?>">
                                    <button class="btn btn-sm" type="submit"><?= e($action === 'update' ? __('addons.action_update') : __('addons.action_install')) ?></button>
                                </form>
                            <?php elseif ($canRun && $localFile !== ''): ?>
                                <form method="post" action="<?= e(url('/settings/addons/install-incoming')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="file" value="<?= e($localFile) ?>">
                                    <button class="btn btn-sm" type="submit"><?= e($action === 'update' ? __('addons.action_update') : __('addons.action_install')) ?></button>
                                </form>
                            <?php elseif ($action === 'enable' && $rowId !== ''): ?>
                                <form method="post" action="<?= e(url('/settings/addons/' . rawurlencode($rowId) . '/enable')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm" type="submit"><?= e(__('addons.enable')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($licensedAddons !== []): ?>
        <p class="setup-hint muted"><?= e(__('addons.catalogue_licensed_list', ['addons' => implode(', ', $licensedAddons)])) ?></p>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.installed_title')) ?></h2>
            <p class="section-lede"><?= e(__('addons.installed_lede')) ?></p>
        </div>
    </div>
    <?php if ($installed === []): ?>
        <div class="empty-state">
            <strong><?= e(__('addons.installed_empty')) ?></strong>
            <p><?= e(__('addons.installed_empty_hint')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('addons.col_addon')) ?></th>
                    <th><?= e(__('addons.col_version')) ?></th>
                    <th><?= e(__('addons.col_status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($installed as $row): ?>
                    <?php
                    $id = (string) ($row['id'] ?? '');
                    $reason = (string) ($row['license_reason'] ?? 'missing');
                    $reasonText = match ($reason) {
                        'active' => __('addons.status_licensed'),
                        'expired' => __('addons.status_expired'),
                        'revoked' => __('addons.status_revoked'),
                        'not_licensed' => __('addons.status_not_licensed'),
                        'invalid' => __('addons.status_invalid'),
                        default => __('addons.status_no_license'),
                    };
                    $isEnabled = !empty($row['is_enabled']);
                    $licensed = !empty($row['licensed']);
                    $versions = is_array($row['versions'] ?? null) ? $row['versions'] : [];
                    ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($row['name'] ?? $id)) ?></strong>
                            <?php if ((string) ($row['name'] ?? '') !== $id): ?>
                                <br><span class="muted audit-ip"><?= e($id) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) ($row['version'] ?? '—')) ?>
                            <?php if (count($versions) > 1): ?>
                                <br><span class="muted"><?= e(__('addons.versions_kept', ['versions' => implode(', ', $versions)])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($isEnabled ? __('addons.status_active') : __('addons.status_disabled')) ?>
                            <br><span class="muted"><?= e($reasonText) ?></span>
                        </td>
                        <td class="doc-row-actions">
                            <div class="inline-form" style="display:flex;flex-wrap:wrap;gap:0.35rem;justify-content:flex-end">
                                <?php if ($licensed && !$isEnabled): ?>
                                    <form method="post" action="<?= e(url('/settings/addons/' . rawurlencode($id) . '/enable')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm" type="submit"><?= e(__('addons.enable')) ?></button>
                                    </form>
                                <?php elseif ($isEnabled): ?>
                                    <form method="post" action="<?= e(url('/settings/addons/' . rawurlencode($id) . '/disable')) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-ghost" type="submit"><?= e(__('addons.disable')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!$isEnabled): ?>
                                    <form method="post" action="<?= e(url('/settings/addons/' . rawurlencode($id) . '/remove')) ?>"
                                          data-confirm="<?= e(__('addons.remove_confirm', ['addon' => $id])) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-ghost is-danger" type="submit"><?= e(__('addons.remove')) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
