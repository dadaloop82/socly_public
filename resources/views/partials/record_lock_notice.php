<?php
/*
 * Socly Project Header
 *
 *   ███████╗ ██████╗  ██████╗ ██╗   ██╗
 *   ██╔════╝██╔═══██╗██╔════╝ ██║   ██║
 *   ███████╗██║   ██║██║      ██║   ██║
 *   ╚════██║██║   ██║██║      ██║   ██║
 *   ███████║╚██████╔╝╚██████╗ ╚██████╔╝
 *   ╚══════╝ ╚═════╝  ╚═════╝  ╚═════╝
 *
 *   Socly — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

/**
 * Competition between operators: hidden version, conflict warning when saving, "card updated"
 * banner (polling), "modifying" indicator and "just created/modified by …" box.
 *
 * @var string $entity
 * @var array<string,mixed>|null $row
 * @var int|null $version       versione da inviare (default: quella della riga)
 * @var int|null $recordId
 * @var array{user?:string,at?:string,current?:int,reload_url?:string}|null $conflict
 * @var array{kind?:string,user?:string,at?:string}|null $activity
 */
$entity = (string) ($entity ?? '');
$row = is_array($row ?? null) ? $row : null;
$recordId = (int) ($recordId ?? ($row['id'] ?? 0));
$version = (int) ($version ?? ($row['row_version'] ?? 0));
$conflict = is_array($conflict ?? null) ? $conflict : null;
$activity = is_array($activity ?? null) ? $activity : null;
$activityText = '';
if ($activity !== null) {
    $activityText = (string) __(
        (string) ($activity['kind'] ?? '') === 'created' ? 'concurrency.activity_created' : 'concurrency.activity_updated',
        ['user' => (string) ($activity['user'] ?? ''), 'at' => (string) ($activity['at'] ?? '')]
    );
}
$reloadUrl = (string) ($conflict['reload_url'] ?? '');
if ($reloadUrl === '') {
    $reloadUrl = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $reloadUrl = preg_replace('/[?&](conflict|v)=[^&]*/', '', $reloadUrl) ?? $reloadUrl;
}
?>
<input type="hidden" name="row_version" value="<?= $version ?>">
<?php if ($recordId > 0): ?>
    <input type="hidden" name="record_id" value="<?= $recordId ?>">
<?php endif; ?>
<?php if ($conflict !== null): ?>
    <div class="alert alert-warn record-conflict" role="alert" data-record-conflict>
        <strong><?= e(__('concurrency.conflict_title')) ?></strong>
        <p><?= e(__('concurrency.conflict_text', [
            'user' => (string) ($conflict['user'] ?? __('presence.unknown_user')),
            'at' => (string) ($conflict['at'] ?? ''),
        ])) ?></p>
        <p class="record-conflict-hint"><?= e(__('concurrency.conflict_hint')) ?></p>
        <div class="record-conflict-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e($reloadUrl) ?>"><?= e(__('concurrency.reload')) ?></a>
            <button class="btn btn-sm" type="submit" name="force" value="1"><?= e(__('concurrency.overwrite')) ?></button>
        </div>
    </div>
<?php endif; ?>
<div class="alert alert-warn record-stale" role="alert" hidden data-freshness-banner></div>
<div class="alert alert-info record-activity" role="status" data-recent-activity data-activity-kind="<?= e((string) ($activity['kind'] ?? '')) ?>"<?= $activity === null ? ' hidden' : '' ?>>
    <strong><?= e(__('concurrency.activity_title')) ?></strong>
    <p data-recent-activity-text><?= e($activityText) ?></p>
</div>
<div class="record-editing" role="status" hidden data-editing-notice></div>
