<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Support\Permission;

/**
 * Presence of connected operators: one row per open session, refreshed by the client polling. It
 * feeds the initial dots in the header and the “X just connected” toast.
 */
final class PresenceService
{
    /**
     * Beyond this silence the user is shown as inactive (semi-transparent dot).
     */
    public const IDLE_SECONDS = 300;

    /**
     * A field held by another operator is released much sooner than the operator dot: a closed tab
     * (or a crash) must not keep a field locked for minutes.
     */
    public const FIELD_TTL_SECONDS = 45;

    /**
     * Session considered closed after this silence (aligned to SessionIdleMiddleware).
     */
    public const ONLINE_WINDOW = 900;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Record/refresh the current session (called by polling).
     *
     * `$editingField` is the form field the operator is working in and `$editingValue` the draft they
     * are typing: together they let everyone else outline and lock that field and see the changes live.
     */
    public function touch(?string $editingEntity = null, ?int $editingId = null, string $editingField = '', string $editingValue = ''): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);
        $sessionId = session_id();
        if ($userId < 1 || $sessionId === '') {
            return;
        }
        $entity = $editingEntity !== null && VersionService::tableFor($editingEntity) !== null ? $editingEntity : null;
        $hasRecord = $entity !== null && $editingId !== null && $editingId > 0;
        $field = $hasRecord ? self::normalizeFieldName($editingField) : '';
        $value = $field !== '' ? self::normalizeFieldValue($editingValue) : '';
        $this->db->query(
            'INSERT INTO user_presence (session_id, user_id, connected_at, last_seen, ended_at, editing_entity, editing_id, editing_field, editing_value)
             VALUES (:sid, :uid, NOW(), NOW(), NULL, :entity, :eid, :field, :value)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen = NOW(), ended_at = NULL,
                 editing_entity = VALUES(editing_entity), editing_id = VALUES(editing_id),
                 editing_field = VALUES(editing_field), editing_value = VALUES(editing_value)',
            [
                'sid' => $sessionId,
                'uid' => $userId,
                'entity' => $entity,
                'eid' => $hasRecord ? $editingId : null,
                'field' => $field !== '' ? $field : null,
                'value' => $value !== '' ? $value : null,
            ]
        );
        // Housekeeping: drop rows older than a day.
        $this->db->query('DELETE FROM user_presence WHERE last_seen < (NOW() - INTERVAL 1 DAY)');
    }

    /** Only a plain form-field name is stored (no free text from the client). */
    public static function normalizeFieldName(string $field): string
    {
        $field = trim($field);
        if ($field === '' || strlen($field) > 120 || preg_match('/^[A-Za-z0-9_\-\[\]]+$/', $field) !== 1) {
            return '';
        }

        return $field;
    }

    /**
     * Draft preview of the value being typed: short, single-spaced, no control characters. It is
     * rendered as text by the client, so it is never interpreted as markup.
     */
    public static function normalizeFieldValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, 300);
    }

    /** Closes the current presence (logout). */
    public function leave(): void
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            return;
        }
        $this->db->query(
            'UPDATE user_presence SET ended_at = NOW() WHERE session_id = :sid AND ended_at IS NULL',
            ['sid' => $sessionId]
        );
    }

    /**
     * Connected users (one entry per person, even with several open sessions).
     *
     * @return array{users:list<array<string,mixed>>,online:int,new:list<array<string,mixed>>,now:int}
     */
    public function snapshot(int $since = 0, array $editing = []): array
    {
        $window = self::ONLINE_WINDOW;
        $rows = $this->db->fetchAll(
            "SELECT p.session_id, p.user_id, p.connected_at, p.last_seen, p.editing_entity, p.editing_id, p.editing_field, p.editing_value,
                    u.email, u.first_name, u.last_name, u.name, u.member_id,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'first_name'
                     WHERE v.member_id = u.member_id LIMIT 1) AS member_first_name,
                    (SELECT v.value FROM member_field_values v
                     INNER JOIN member_field_definitions d ON d.id = v.field_definition_id AND d.`key` = 'last_name'
                     WHERE v.member_id = u.member_id LIMIT 1) AS member_last_name,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, p.last_seen, NOW())) AS idle_seconds
             FROM user_presence p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.ended_at IS NULL
               AND p.last_seen >= (NOW() - INTERVAL {$window} SECOND)
               AND u.is_active = 1
             ORDER BY p.connected_at ASC"
        );

        $currentId = (int) (auth_user()['id'] ?? 0);
        $users = [];
        $editors = [];
        $editEntity = (string) ($editing['entity'] ?? '');
        $editId = (int) ($editing['id'] ?? 0);
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            if ($userId < 1 || (string) ($row['name'] ?? '') === 'SOCLY Platform') {
                continue;
            }
            $identity = $this->identity($row);
            $idle = (int) ($row['idle_seconds'] ?? 0) >= self::IDLE_SECONDS;
            $location = $this->location($row);
            if ($editEntity !== '' && $editId > 0 && !$idle
                && (string) ($row['editing_entity'] ?? '') === $editEntity
                && (int) ($row['editing_id'] ?? 0) === $editId
                && $userId !== $currentId) {
                $editors[] = [
                    'id' => $userId,
                    'name' => $identity['name'],
                    'initials' => $identity['initials'],
                    'hue' => $this->hue($userId),
                    // Field the operator is working in ('' when the record is open but no field has
                    // focus yet) and the draft being typed: the client outlines and locks the field
                    // and shows the live value. A stale heartbeat releases the field quickly.
                    'field' => (int) ($row['idle_seconds'] ?? 0) <= self::FIELD_TTL_SECONDS
                        ? self::normalizeFieldName((string) ($row['editing_field'] ?? ''))
                        : '',
                    'value' => (int) ($row['idle_seconds'] ?? 0) <= self::FIELD_TTL_SECONDS
                        ? self::normalizeFieldValue((string) ($row['editing_value'] ?? ''))
                        : '',
                ];
            }
            if (isset($users[$userId])) {
                $users[$userId]['sessions']++;
                $users[$userId]['idle'] = $users[$userId]['idle'] && $idle;
                // The first session wins, but a session that is really working in a record is more
                // useful than an idle one opening the same account.
                if (($users[$userId]['url'] ?? '') === '' && $location['url'] !== '') {
                    $users[$userId]['editing_entity'] = $location['entity'];
                    $users[$userId]['editing_id'] = $location['id'];
                    $users[$userId]['url'] = $location['url'];
                }
                continue;
            }
            $users[$userId] = [
                'id' => $userId,
                'name' => $identity['name'],
                'initials' => $identity['initials'],
                'hue' => $this->hue($userId),
                'idle' => $idle,
                'self' => $userId === $currentId,
                'sessions' => 1,
                'connected_ts' => (int) (strtotime((string) ($row['connected_at'] ?? '')) ?: 0),
                // Record the operator has open right now (empty when they are just browsing), with
                // the link that opens exactly that page from a click on their badge.
                'editing_entity' => $location['entity'],
                'editing_id' => $location['id'],
                'url' => $userId === $currentId ? '' : $location['url'],
            ];
        }

        $sinceTs = max(0, $since);
        $new = [];
        foreach ($users as $user) {
            if ($sinceTs > 0 && $user['connected_ts'] > $sinceTs && !$user['self']) {
                $new[] = $user;
            }
        }

        return [
            'users' => array_values($users),
            'online' => count($users),
            'new' => $new,
            'editors' => $editors,
            'now' => time(),
        ];
    }

    /**
     * Where an operator is working right now: the record they have open (entity + id) and the link
     * that opens exactly that page. An inactive session, or one that is just browsing, carries none.
     *
     * @param array<string,mixed> $row
     * @return array{entity:string,id:int,url:string}
     */
    private function location(array $row): array
    {
        $idle = (int) ($row['idle_seconds'] ?? 0) >= self::IDLE_SECONDS;
        $entity = (string) ($row['editing_entity'] ?? '');
        $id = (int) ($row['editing_id'] ?? 0);
        if ($idle || $entity === '' || $id < 1) {
            return ['entity' => '', 'id' => 0, 'url' => ''];
        }

        return ['entity' => $entity, 'id' => $id, 'url' => $this->locationUrl($entity, $id)];
    }

    /**
     * Link to the page another operator is working in, so a click on their badge opens exactly that
     * record. Only the entities admitted to the version allowlist are mapped, each one behind the
     * same component flag and permission that guard the page: no permission, no link.
     */
    private function locationUrl(string $entity, int $id): string
    {
        if ($id < 1) {
            return '';
        }
        switch ($entity) {
            case 'member':
                if (!component_enabled('members')) {
                    return '';
                }
                if (can(Permission::MEMBERS_MANAGE)) {
                    return url('/members/' . $id . '/edit');
                }
                return can(Permission::MEMBERS_VIEW) ? url('/members/' . $id) : '';
            case 'treasury':
                if (!component_enabled('treasury')) {
                    return '';
                }
                if (can(Permission::TREASURY_MANAGE)) {
                    return url('/treasury/' . $id . '/edit');
                }
                return can(Permission::TREASURY_VIEW) ? url('/treasury') : '';
            case 'budget':
                if (!component_enabled('treasury') || !can(Permission::TREASURY_VIEW)) {
                    return '';
                }
                return url('/budgets/' . $id);
            case 'budget_item':
                if (!component_enabled('treasury') || !can(Permission::TREASURY_VIEW)) {
                    return '';
                }
                $row = $this->db->fetch('SELECT budget_id FROM budget_items WHERE id = :id', ['id' => $id]);
                $budgetId = (int) ($row['budget_id'] ?? 0);

                return $budgetId > 0 ? url('/budgets/' . $budgetId) : '';
            case 'document':
                if (!component_enabled('documents')) {
                    return '';
                }
                return can(Permission::DOCUMENTS_VIEW) ? url('/documents/' . $id) : '';
            case 'deadline':
                if (!component_enabled('deadlines')) {
                    return '';
                }
                if (can(Permission::DEADLINES_MANAGE)) {
                    return url('/deadlines/' . $id . '/edit');
                }
                return can(Permission::DEADLINES_VIEW) ? url('/deadlines') : '';
            case 'person':
                if (!component_enabled('org_roles')) {
                    return '';
                }
                return can(Permission::ORG_VIEW) ? url('/org/people/' . $id . '/edit') : '';
        }

        return '';
    }

    /**
     * Display name: operator first/last name, otherwise the linked member,
     * otherwise “Unknown user”.
     *
     * @param array<string,mixed> $row
     * @return array{name:string,initials:string}
     */
    private function identity(array $row): array
    {
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            $first = trim((string) ($row['member_first_name'] ?? ''));
            $last = trim((string) ($row['member_last_name'] ?? ''));
        }
        if ($first === '' && $last === '') {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                return ['name' => (string) __('presence.unknown_user'), 'initials' => '?'];
            }
            // The legacy `name` column already holds “First Last”.
            $parts = preg_split('/\s+/', $name) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = count($parts) > 1 ? (string) end($parts) : '';
        }
        $name = trim($first . ' ' . $last);
        $initials = mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
        if ($initials === '') {
            $initials = mb_strtoupper(mb_substr($name, 0, 2));
        }

        return ['name' => $name !== '' ? $name : (string) __('presence.unknown_user'), 'initials' => $initials !== '' ? $initials : '?'];
    }

    /**
     * Stable shade per user: same person, same color.
     */
    private function hue(int $userId): int
    {
        return (int) (($userId * 137) % 360);
    }
}
