<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

final class AuditService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        mixed $before = null,
        mixed $after = null,
        ?string $ip = null
    ): void {
        $userId = auth_user()['id'] ?? null;
        if ($userId !== null) {
            $alive = $this->db->fetch('SELECT id FROM users WHERE id = :id LIMIT 1', ['id' => (int) $userId]);
            if (!$alive) {
                $userId = null;
            }
        }
        $this->db->insert('audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($this->redact($before), JSON_UNESCAPED_UNICODE),
            'after_json' => $after === null ? null : json_encode($this->redact($after), JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
        ]);
    }

    /**
     * Paginated audit trail: “who did what, when”.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int,filters:array<string,string>}
     */
    public function search(array $filters, int $page = 1, int $perPage = 50): array
    {
        $filters = $this->normalizeFilters($filters);
        $perPage = max(1, min(200, $perPage));
        [$where, $params] = $this->whereClause($filters);
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $countRow = $this->db->fetch(
            'SELECT COUNT(*) AS total FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id' . $whereSql,
            $params
        );
        $total = (int) ($countRow['total'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->fetchAll(
            'SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.ip, a.user_id,'
            . ' LEFT(a.before_json, 4000) AS before_json, LEFT(a.after_json, 4000) AS after_json,'
            . ' u.name, u.first_name, u.last_name, u.email'
            . ' FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id'
            . $whereSql
            . ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );
        foreach ($rows as $index => $row) {
            $rows[$index]['actor'] = $this->actorLabel($row);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'filters' => $filters,
        ];
    }

    /**
     * Values for the filter selects: only actors/areas actually present in the log.
     *
     * @return array{users:list<array{id:string,label:string}>,entities:list<string>}
     */
    public function filterOptions(): array
    {
        $users = [];
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.name, u.first_name, u.last_name, u.email
             FROM users u
             WHERE EXISTS (SELECT 1 FROM audit_logs a WHERE a.user_id = u.id)
             ORDER BY COALESCE(NULLIF(u.name, ''), NULLIF(CONCAT_WS(' ', u.first_name, u.last_name), ''), u.email)"
        );
        foreach ($rows as $row) {
            $users[] = ['id' => (string) (int) $row['id'], 'label' => $this->actorLabel($row)];
        }
        $systemRow = $this->db->fetch('SELECT COUNT(*) AS total FROM audit_logs WHERE user_id IS NULL');
        if ((int) ($systemRow['total'] ?? 0) > 0) {
            // “system” = operations without a session (login failures, install, demo seed).
            $users[] = ['id' => 'system', 'label' => ''];
        }

        $entities = [];
        foreach ($this->db->fetchAll('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type') as $row) {
            $entity = trim((string) ($row['entity_type'] ?? ''));
            if ($entity !== '') {
                $entities[] = $entity;
            }
        }

        return ['users' => $users, 'entities' => $entities];
    }

    /**
     * Header counters of the audit screen.
     *
     * @return array{total:int,today:int,week:int,actors:int}
     */
    public function summary(): array
    {
        $row = $this->db->fetch(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(a.created_at >= CURDATE()), 0) AS today,
                    COALESCE(SUM(a.created_at >= (NOW() - INTERVAL 7 DAY)), 0) AS week,
                    COUNT(DISTINCT a.user_id) AS actors
             FROM audit_logs a'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'today' => (int) ($row['today'] ?? 0),
            'week' => (int) ($row['week'] ?? 0),
            'actors' => (int) ($row['actors'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{q:string,user_id:string,entity_type:string,date_from:string,date_to:string}
     */
    private function normalizeFilters(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if (mb_strlen($q) > 120) {
            $q = mb_substr($q, 0, 120);
        }
        $userId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId !== 'system' && ($userId === '' || !ctype_digit($userId) || (int) $userId < 1)) {
            $userId = '';
        }
        $entityType = trim((string) ($filters['entity_type'] ?? ''));
        if (mb_strlen($entityType) > 100) {
            $entityType = '';
        }

        return [
            'q' => $q,
            'user_id' => $userId,
            'entity_type' => $entityType,
            'date_from' => $this->normalizeDate($filters['date_from'] ?? null),
            'date_to' => $this->normalizeDate($filters['date_to'] ?? null),
        ];
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:list<string>,1:array<string,string>}
     */
    private function whereClause(array $filters): array
    {
        $where = [];
        $params = [];
        if ($filters['q'] !== '') {
            $like = '%' . $this->escapeLike($filters['q']) . '%';
            $where[] = "(a.action LIKE :q_action OR a.entity_type LIKE :q_entity"
                . " OR a.entity_id LIKE :q_id OR a.ip LIKE :q_ip"
                . " OR CONCAT_WS(' ', u.first_name, u.last_name, u.name, u.email) LIKE :q_user)";
            $params['q_action'] = $like;
            $params['q_entity'] = $like;
            $params['q_id'] = $like;
            $params['q_ip'] = $like;
            $params['q_user'] = $like;
        }
        if ($filters['user_id'] === 'system') {
            $where[] = 'a.user_id IS NULL';
        } elseif ($filters['user_id'] !== '') {
            $where[] = 'a.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if ($filters['entity_type'] !== '') {
            $where[] = 'a.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if ($filters['date_from'] !== '') {
            // Index-friendly range (no DATE() on the column) on the audit_created index.
            $where[] = 'a.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'a.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return [$where, $params];
    }

    private function normalizeDate(mixed $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param array<string,mixed> $row */
    private function actorLabel(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        }
        if ($name === '') {
            $name = trim((string) ($row['email'] ?? ''));
        }

        return $name;
    }

    private function redact(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }
        foreach (['password', 'password_confirmation', 'admin_password', 'app_key', '_token'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }
        return $payload;
    }
}
