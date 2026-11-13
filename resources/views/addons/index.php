<?php
/** @var list<array<string,mixed>> $installed */
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
/** @var list<array<string,mixed>> $incoming */
/** @var string $incoming_dir */
/** @var array{ok:bool,rows:list<array<string,mixed>>} $catalogue */
$incoming = is_array($incoming ?? null) ? $incoming : [];
$catalogue = is_array($catalogue ?? null) ? $catalogue : ['ok' => false, 'rows' => []];
$catalogueRows = is_array($catalogue['rows'] ?? null) ? $catalogue['rows'] : [];
$licenseId = (string) ($entitlement?->licenseId() ?? '');
$licensedAddons = $entitlement?->addonIds() ?? [];
$expiresAt = $entitlement?->expiresAt();
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

<div class="stats stats-context-members members-summary-stats">
    <div class="stat">
        <div class="label"><?= e(__('addons.license')) ?></div>
        <div class="value"><?= e($licenseId !== '' ? $licenseId : __('addons.status_no_license')) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('addons.licensed_addons')) ?></div>
        <div class="value"><?= (int) count($licensedAddons) ?></div>
    </div>
    <div class="stat">
        <div class="label"><?= e(__('addons.instance_code')) ?></div>
        <div class="value audit-ip"><?= e($instance_code) ?></div>
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

    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr));margin-top:1rem">
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
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.incoming_title')) ?></h2>
            <p class="section-lede"><?= e(__('addons.incoming_lede')) ?></p>
        </div>
    </div>
    <p class="muted" style="font-size:0.85rem">
        <?= e(__('addons.incoming_dir', ['dir' => (string) $incoming_dir])) ?>
        · <?= e(__('addons.incoming_hint')) ?>
    </p>
    <?php if ($incoming === []): ?>
        <div class="empty-state">
            <strong><?= e(__('addons.incoming_empty')) ?></strong>
            <p><?= e(__('addons.incoming_empty_hint')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('addons.col_addon')) ?></th>
                    <th><?= e(__('addons.col_version')) ?></th>
                    <th><?= e(__('addons.col_license')) ?></th>
                    <th><?= e(__('addons.col_status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($incoming as $entry): ?>
                    <?php
                    $entryOk = !empty($entry['ok']);
                    $entryId = (string) ($entry['id'] ?? '');
                    $entryFile = (string) ($entry['file'] ?? '');
                    $entryInstalled = (string) ($entry['installed_version'] ?? '');
                    $entryCanInstall = !empty($entry['can_install']);
                    $entryUpdate = !empty($entry['update']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($entry['name'] !== '' ? $entry['name'] : $entryFile)) ?></strong><br>
                            <span class="muted audit-ip"><?= e($entryId !== '' ? $entryId : $entryFile) ?></span>
                        </td>
                        <td>
                            <?= e((string) (($entry['version'] ?? '') !== '' ? $entry['version'] : '—')) ?>
                            <?php if ($entryInstalled !== ''): ?>
                                <br><span class="muted"><?= e(__('addons.catalogue_installed', ['version' => $entryInstalled])) ?></span>
                            <?php endif; ?>
                            <?php if ($entryOk && (string) ($entry['min_core'] ?? '') !== ''): ?>
                                <br><span class="muted">core ≥ <?= e((string) $entry['min_core']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$entryOk): ?>
                                <span class="muted">—</span>
                            <?php elseif (!empty($entry['licensed'])): ?>
                                <?= e(__('addons.status_licensed')) ?>
                            <?php else: ?>
                                <span class="muted"><?= e(__('addons.incoming_licensed_no')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$entryOk): ?>
                                <span class="badge"><?= e(__('addons.incoming_invalid')) ?></span>
                                <br><span class="muted" style="font-size:0.78rem"><?= e((string) ($entry['error'] ?? '')) ?></span>
                            <?php elseif ($entryCanInstall): ?>
                                <span class="badge"><?= e($entryUpdate ? __('addons.incoming_update') : __('addons.incoming_install')) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= e(__('addons.incoming_current')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($entryCanInstall): ?>
                                <form method="post" action="<?= e(url('/settings/addons/install-incoming')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="file" value="<?= e($entryFile) ?>">
                                    <button class="btn btn-sm" type="submit"><?= e($entryUpdate ? __('addons.incoming_update') : __('addons.install_button')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($license_key_present)): ?>
<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.catalogue_title')) ?></h2>
            <p class="section-lede"><?= e(__('addons.catalogue_lede')) ?></p>
        </div>
    </div>
    <?php if ($catalogueRows === []): ?>
        <div class="empty-state">
            <strong><?= e(__('addons.catalogue_empty')) ?></strong>
            <p><?= e(__('addons.catalogue_hint')) ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap embedded">
            <table>
                <thead>
                <tr>
                    <th><?= e(__('addons.col_addon')) ?></th>
                    <th><?= e(__('addons.col_version')) ?></th>
                    <th><?= e(__('addons.col_license')) ?></th>
                    <th><?= e(__('addons.col_status')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($catalogueRows as $row): ?>
                    <?php
                    $rowId = (string) ($row['id'] ?? '');
                    $latest = (string) ($row['latest'] ?? '');
                    $installedVersion = (string) ($row['installed_version'] ?? '');
                    $canInstall = !empty($row['can_install']);
                    $isUpdate = !empty($row['update']);
                    $compatible = !empty($row['compatible']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($rowId) ?></strong>
                            <br><span class="muted audit-ip"><?= e(__('addons.catalogue_source')) ?></span>
                        </td>
                        <td>
                            <?= e($latest) ?>
                            <?php if ($installedVersion !== ''): ?>
                                <br><span class="muted"><?= e(__('addons.catalogue_installed', ['version' => $installedVersion])) ?></span>
                            <?php endif; ?>
                            <?php if ((string) ($row['min_core'] ?? '') !== ''): ?>
                                <br><span class="muted">core ≥ <?= e((string) $row['min_core']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(__('addons.status_licensed')) ?></td>
                        <td>
                            <?php if (!$compatible): ?>
                                <span class="badge"><?= e(__('addons.error_min_core', ['min' => (string) ($row['min_core'] ?? ''), 'core' => $core_version])) ?></span>
                            <?php elseif ($canInstall): ?>
                                <span class="badge"><?= e($isUpdate ? __('addons.catalogue_update') : __('addons.incoming_install')) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= e(__('addons.incoming_current')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canInstall): ?>
                                <form method="post" action="<?= e(url('/settings/addons/install-licensed')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="addon_id" value="<?= e($rowId) ?>">
                                    <input type="hidden" name="version" value="<?= e($latest) ?>">
                                    <button class="btn btn-sm" type="submit"><?= e($isUpdate ? __('addons.catalogue_update') : __('addons.catalogue_install')) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.package_section')) ?></h2>
            <p class="section-lede"><?= e(__('addons.package_lede')) ?></p>
        </div>
    </div>
    <details class="config-accordion" style="margin-bottom:1rem">
        <summary><?= e(__('addons.manual_title')) ?></summary>
        <p class="muted"><?= e(__('addons.manual_lede')) ?></p>
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
    </details>
    <form method="post" action="<?= e(url('/settings/addons/check')) ?>" class="panel embedded">
        <?= csrf_field() ?>
        <h3 class="section-title"><?= e(__('addons.updates_title')) ?></h3>
        <p class="muted"><?= e(__('addons.updates_lede')) ?></p>
        <?php if (!$license_key_present): ?>
            <p class="setup-hint muted"><?= e(__('addons.offline_updates_hint')) ?></p>
        <?php endif; ?>
        <button class="btn btn-sm" type="submit"><?= e(__('addons.check_button')) ?></button>
    </form>
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
                    <th><?= e(__('addons.col_license')) ?></th>
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
                            <strong><?= e((string) ($row['name'] ?? $id)) ?></strong><br>
                            <span class="muted audit-ip"><?= e($id) ?></span>
                        </td>
                        <td>
                            <?= e((string) ($row['version'] ?? '—')) ?>
                            <?php if (count($versions) > 1): ?>
                                <br><span class="muted"><?= e(__('addons.versions_kept', ['versions' => implode(', ', $versions)])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($reasonText) ?>
                            <?php if (!empty($row['license_id'])): ?>
                                <br><span class="muted audit-ip"><?= e((string) $row['license_id']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($isEnabled ? __('addons.status_active') : __('addons.status_disabled')) ?>
                            <?php if (!$licensed): ?>
                                <br><span class="muted"><?= e(__('addons.license_required')) ?></span>
                            <?php endif; ?>
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

<p class="muted setup-hint"><?= e(__('addons.footer_note', ['license_kid' => $license_kid, 'package_kid' => $package_kid])) ?></p>
