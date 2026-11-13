<?php

declare(strict_types=1);

/**
 * Add-ons dynamic region: available list + installed list. Re-rendered by the
 * “Controlla aggiornamenti” / “Installa tutto” buttons through /settings/addons/check
 * and /settings/addons/auto-install (JSON), so the page does not reload.
 *
 * @var list<array<string,mixed>> $available
 * @var array{count:int,items:list<array<string,string>>} $updates
 * @var list<array<string,mixed>> $installed
 * @var string $core_version
 * @var bool $license_key_present
 * @var list<string> $licensedAddons
 */
?>
<div class="panel" data-addons-available>
    <div class="panel-header">
        <div>
            <h2 class="section-title"><?= e(__('addons.available_title')) ?></h2>
            <p class="section-lede"><?= e(__('addons.available_lede')) ?></p>
        </div>
    </div>
    <?php if ((int) ($updates['count'] ?? 0) > 0): ?>
        <div class="alert alert-warn" style="margin:0.75rem 0 0">
            <span class="alert-body">
                <strong><?= e(__('addons.updates_available_title')) ?></strong>
                <?= e(implode(' · ', array_map(
                    static fn (array $row): string => trim(
                        (string) ($row['name'] ?? $row['id'] ?? '')
                        . ' ' . (string) ($row['from'] ?? '')
                        . ' → ' . (string) ($row['to'] ?? '')
                    ),
                    is_array($updates['items'] ?? null) ? $updates['items'] : []
                ))) ?>
                · <?= e(__('addons.updates_available_lede')) ?>
            </span>
        </div>
    <?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin:0.75rem 0 0.6rem">
        <form
            method="post"
            action="<?= e(url('/settings/addons/check')) ?>"
            data-addons-json
            data-busy="<?= e(__('addons.checking')) ?>"
            data-error="<?= e(__('addons.error_online')) ?>"
        >
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-ghost" type="submit"><?= e(__('addons.available_refresh')) ?></button>
        </form>
        <?php if ($license_key_present): ?>
            <form
                method="post"
                action="<?= e(url('/settings/addons/auto-install')) ?>"
                data-addons-json
                data-busy="<?= e(__('addons.installing')) ?>"
                data-error="<?= e(__('addons.error_generic')) ?>"
            >
                <?= csrf_field() ?>
                <button class="btn btn-sm" type="submit"><?= e(__('addons.available_install_all')) ?></button>
            </form>
        <?php endif; ?>
    </div>
    <p class="addons-status" data-addons-status role="status" aria-live="polite" hidden></p>
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
