<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Concurrency between operators: row versions and “who changed what”.
 *
 * Editable tables carry `row_version`; `Database::updateVersioned()` bumps it and
 * fails when someone else saved meanwhile. Here live the entity → table map, the
 * descrizione dell'ultimo autore (dall'audit log) e il confronto usato dai form.
 */
final class VersionService
{
    /** Entità ammesse al controllo (allowlist: mai nomi di tabella dal client). */
    public const TABLES = [
        'member' => 'members',
        'treasury' => 'treasury_movements',
        'budget' => 'budgets',
        'budget_item' => 'budget_items',
        'document' => 'association_documents',
        'deadline' => 'deadline_items',
        'person' => 'association_people',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function tableFor(string $entity): ?string
    {
        return self::TABLES[$entity] ?? null;
    }

    /** Versione corrente della riga; null se l'entità non è gestita o la riga non esiste. */
    public function current(string $entity, int $id): ?int
    {
        $table = self::tableFor($entity);
        if ($table === null || $id < 1) {
            return null;
        }
        $row = $this->db->fetch(
            sprintf('SELECT row_version FROM `%s` WHERE id = :id', $table),
            ['id' => $id]
        );
        return $row === null ? null : (int) $row['row_version'];
    }

    /**
     * Ultimo autore della modifica (dall'audit log), per l'avviso “modificato da …”.
     *
     * @return array{name:string,at:string}
     */
    public function lastEditor(string $entity, int $id): array
    {
        if (self::tableFor($entity) === null || $id < 1) {
            return ['name' => '', 'at' => ''];
        }
        $row = $this->db->fetch(
            "SELECT a.created_at, u.first_name, u.last_name, u.name, u.email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :type AND a.entity_id = :id
             ORDER BY a.id DESC
             LIMIT 1",
            ['type' => $entity, 'id' => (string) $id]
        );
        if ($row === null) {
            return ['name' => '', 'at' => ''];
        }
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = trim((string) ($row['name'] ?? ''));
        }
        if ($name === '') {
            $name = (string) ($row['email'] ?? '');
        }
        $at = trim((string) ($row['created_at'] ?? ''));
        return [
            'name' => $name !== '' ? $name : (string) __('presence.unknown_user'),
            'at' => $at !== '' ? (string) (format_datetime($at) ?: $at) : '',
        ];
    }

    /**
     * Esito del confronto fra la versione inviata dal form e quella a database.
     *
     * @return array{ok:bool,current:int,editor:array{name:string,at:string}}
     */
    public function compare(string $entity, int $id, int $expected): array
    {
        $current = $this->current($entity, $id);
        if ($current === null) {
            return ['ok' => false, 'current' => 0, 'editor' => ['name' => '', 'at' => '']];
        }
        return [
            'ok' => $expected <= 0 || $current === $expected,
            'current' => $current,
            'editor' => $this->lastEditor($entity, $id),
        ];
    }
}
