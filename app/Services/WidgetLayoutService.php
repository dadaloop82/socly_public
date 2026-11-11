<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Per-user chart/widget layout (ordering + visibility) stored in the profile.
 */
final class WidgetLayoutService
{
    private const CONTEXT_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,79}$/';
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,59}$/';
    private const MAX_ITEMS = 60;

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit
    ) {
    }

    /** @return array{order:list<string>,hidden:list<string>} */
    public function forUser(int $userId, string $context): array
    {
        $context = strtolower(trim($context));
        if ($userId <= 0 || preg_match(self::CONTEXT_PATTERN, $context) !== 1) {
            return ['order' => [], 'hidden' => []];
        }
        $row = $this->db->fetch(
            'SELECT layout FROM user_widget_layouts WHERE user_id = :u AND context = :c',
            ['u' => $userId, 'c' => $context]
        );
        if (!$row) {
            return ['order' => [], 'hidden' => []];
        }
        $decoded = json_decode((string) ($row['layout'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['order' => [], 'hidden' => []];
        }
        return [
            'order' => $this->cleanIds($decoded['order'] ?? []),
            'hidden' => $this->cleanIds($decoded['hidden'] ?? []),
        ];
    }

    /**
     * @param array<mixed> $order
     * @param array<mixed> $hidden
     * @return array{ok:bool,error?:string,layout?:array{order:list<string>,hidden:list<string>}}
     */
    public function save(int $userId, string $context, array $order, array $hidden, string $ip): array
    {
        $context = strtolower(trim($context));
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'auth'];
        }
        if (preg_match(self::CONTEXT_PATTERN, $context) !== 1) {
            return ['ok' => false, 'error' => 'context'];
        }
        $order = $this->cleanIds($order);
        if ($order === []) {
            return ['ok' => false, 'error' => 'empty'];
        }
        $hidden = array_values(array_intersect($this->cleanIds($hidden), $order));
        $layout = ['order' => $order, 'hidden' => $hidden];
        $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return ['ok' => false, 'error' => 'encode'];
        }
        $this->db->query(
            'INSERT INTO user_widget_layouts (user_id, context, layout) VALUES (:u, :c, :l)
             ON DUPLICATE KEY UPDATE layout = VALUES(layout)',
            ['u' => $userId, 'c' => $context, 'l' => $json]
        );
        try {
            $this->audit->log('widget_layout.saved', 'user', (string) $userId, null, [
                'context' => $context,
                'widgets' => count($order),
                'hidden' => count($hidden),
            ], $ip);
        } catch (\Throwable) {
            // Preferences are not critical: never fail the save on audit errors.
        }
        return ['ok' => true, 'layout' => $layout];
    }

    /** @return list<string> */
    private function cleanIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) && !is_int($id)) {
                continue;
            }
            $id = strtolower(trim((string) $id));
            if (preg_match(self::ID_PATTERN, $id) !== 1 || isset($out[$id])) {
                continue;
            }
            $out[$id] = $id;
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return array_values($out);
    }
}
