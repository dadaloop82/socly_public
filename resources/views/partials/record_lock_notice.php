<?php
/**
 * Competition between operators: hidden version, conflict warning when saving, "card updated"
 * banner (polling) and "modifying" indicator.
 *
 * @var string $entity
 * @var array<string,mixed>|null $row
 * @var int|null $version       versione da inviare (default: quella della riga)
 * @var int|null $recordId
 * @var array{user?:string,at?:string,current?:int,reload_url?:string}|null $conflict
 */
$entity = (string) ($entity ?? '');
$row = is_array($row ?? null) ? $row : null;
$recordId = (int) ($recordId ?? ($row['id'] ?? 0));
$version = (int) ($version ?? ($row['row_version'] ?? 0));
$conflict = is_array($conflict ?? null) ? $conflict : null;
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
<div class="record-editing" role="status" hidden data-editing-notice></div>
