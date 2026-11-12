<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Concurrency between operators: row versions and “who changed what”.
 * Editable tables carry `row_version`; `Database::updateVersioned()` bumps it and fails when
 * someone else saved meanwhile. Here live the entity → table map, the description of the last
 * author (from the audit log) and the comparison used by the forms.
 */
final class VersionService
{
    /**
     * Entities admitted to control (allowlist: never table names from the client).
     */
    public const TABLES = [
        'member' => 'members',
        'treasury' => 'treasury_movements',
        'budget' => 'budgets',
        'budget_item' => 'budget_items',
        'document' => 'association_documents',
        'deadline' => 'deadline_items',
        'person' => 'association_people',
    ];

    /**
     * Entities with a printable author trail: wider than TABLES because users, payments and member
     * types are not versioned but still show “created / modified by”.
     */
    private const TRAIL_TABLES = [
        'member' => 'members',
        'treasury' => 'treasury_movements',
        'budget' => 'budgets',
        'budget_item' => 'budget_items',
        'document' => 'association_documents',
        'deadline' => 'deadline_items',
        'person' => 'association_people',
        'user' => 'users',
        'payment' => 'payments',
        'member_type' => 'member_types',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public static function tableFor(string $entity): ?string
    {
        return self::TABLES[$entity] ?? null;
    }

    /**
     * Current version of the line; null if the entity is unmanaged or the row does not exist.
     */
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
     * Last author of the modification (from the audit log), for the “modified by …” notice.
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

        return $this->auditAuthor($row);
    }

    /**
     * “Created by … on …” / “Modified by … on …” of a record: taken from the audit log, with the row
     * timestamps as fallback for data saved before the audit existed.
     *
     * @return array{created:array{name:string,at:string}|null,updated:array{name:string,at:string}|null}
     */
    public function trail(string $entity, int $id): array
    {
        $table = self::TRAIL_TABLES[$entity] ?? null;
        if ($table === null || $id < 1) {
            return ['created' => null, 'updated' => null];
        }
        $params = ['type' => $entity, 'id' => (string) $id];
        $createdRow = $this->db->fetch(
            "SELECT a.id, a.created_at, u.first_name, u.last_name, u.name, u.email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :type AND a.entity_id = :id AND a.action LIKE '%created%'
             ORDER BY a.id ASC
             LIMIT 1",
            $params
        );
        $lastRow = $this->db->fetch(
            "SELECT a.id, a.created_at, u.first_name, u.last_name, u.name, u.email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.entity_type = :type AND a.entity_id = :id
             ORDER BY a.id DESC
             LIMIT 1",
            $params
        );
        $created = $createdRow === null ? null : $this->auditAuthor($createdRow);
        $updated = null;
        if ($lastRow !== null
            && ($createdRow === null || (int) ($lastRow['id'] ?? 0) !== (int) ($createdRow['id'] ?? 0))) {
            $updated = $this->auditAuthor($lastRow);
        }
        if ($created !== null && $updated !== null) {
            return ['created' => $created, 'updated' => $updated];
        }
        // Fallback: the row timestamps (and author) for data saved before the audit existed.
        try {
            $row = $this->db->fetch(
                sprintf('SELECT created_at, updated_at, created_by FROM `%s` WHERE id = :id', $table),
                ['id' => $id]
            );
        } catch (\Throwable) {
            try {
                $row = $this->db->fetch(
                    sprintf('SELECT created_at, updated_at FROM `%s` WHERE id = :id', $table),
                    ['id' => $id]
                );
            } catch (\Throwable) {
                $row = null;
            }
        }
        if ($row !== null) {
            $createdAt = trim((string) ($row['created_at'] ?? ''));
            $updatedAt = trim((string) ($row['updated_at'] ?? ''));
            if ($created === null && $createdAt !== '') {
                $created = [
                    'name' => (int) ($row['created_by'] ?? 0) > 0 ? $this->userName((int) $row['created_by']) : '',
                    'at' => (string) (format_datetime($createdAt) ?: $createdAt),
                ];
            }
            if ($updated === null && $updatedAt !== '' && $updatedAt !== $createdAt) {
                $updated = ['name' => '', 'at' => (string) (format_datetime($updatedAt) ?: $updatedAt)];
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Name and moment of an audit row (user names, then the legacy `name`, then the email).
     *
     * @param array<string,mixed> $row
     * @return array{name:string,at:string}
     */
    private function auditAuthor(array $row): array
    {
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
     * Display name of a user (empty when it cannot be resolved).
     */
    private function userName(int $id): string
    {
        if ($id < 1) {
            return '';
        }
        try {
            $row = $this->db->fetch(
                'SELECT first_name, last_name, name, email FROM users WHERE id = :id LIMIT 1',
                ['id' => $id]
            );
        } catch (\Throwable) {
            return '';
        }

        return $row === null ? '' : (string) ($this->auditAuthor($row)['name'] ?? '');
    }

    /**
     * Result of the comparison between the version sent by the form and the one in the database.
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
