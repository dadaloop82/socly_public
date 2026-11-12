<?php
/**
 * “Creato da … il …” / “Modificato da … il …” under an editable (or detail) panel.
 * The data comes from the audit log through record_trail(); when the record has no trail nothing is
 * printed, so the partial is safe to include everywhere.
 *
 * @var string $entity
 * @var int $id
 * @var array{created:array{name:string,at:string}|null,updated:array{name:string,at:string}|null}|null $trail
 */
$entity = (string) ($entity ?? '');
$id = (int) ($id ?? 0);
if ($entity === '' || $id < 1) {
    return;
}
$trail = is_array($trail ?? null) ? $trail : record_trail($entity, $id);
$created = is_array($trail['created'] ?? null) ? $trail['created'] : null;
$updated = is_array($trail['updated'] ?? null) ? $trail['updated'] : null;
if ($created === null && $updated === null) {
    return;
}
$line = static function (string $key, string $fallbackKey, array $entry): string {
    $name = trim((string) ($entry['name'] ?? ''));
    $date = trim((string) ($entry['at'] ?? ''));
    if ($name !== '') {
        return (string) __($key, ['user' => $name, 'date' => $date]);
    }

    return (string) __($fallbackKey, ['date' => $date]);
};
?>
<p class="record-trail muted">
    <?php if ($created !== null): ?>
        <span class="record-trail-item" data-record-created>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.42 0-8 2.24-8 5v1h16v-1c0-2.76-3.58-5-8-5Z"/></svg>
            <?= e($line('audit.created_by', 'audit.created_on', $created)) ?>
        </span>
    <?php endif; ?>
    <?php if ($updated !== null): ?>
        <span class="record-trail-item" data-record-updated>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25ZM20.7 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83Z"/></svg>
            <?= e($line('audit.updated_by', 'audit.updated_on', $updated)) ?>
        </span>
    <?php endif; ?>
</p>
