<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Per-user chart/widget layout (ordering, visibility and grid width) stored in the profile.
 */
final class WidgetLayoutService
{
    private const CONTEXT_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,79}$/';
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,59}$/';
    private const MAX_ITEMS = 60;
    /** The charts grid has three columns: a card spans one, two or all three. */
    private const MIN_SIZE = 1;
    private const MAX_SIZE = 3;

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit
    ) {
    }

    /** @return array{order:list<string>,hidden:list<string>,sizes:array<string,int>} */
    public function forUser(int $userId, string $context): array
    {
        $context = strtolower(trim($context));
        if ($userId <= 0 || preg_match(self::CONTEXT_PATTERN, $context) !== 1) {
            return ['order' => [], 'hidden' => [], 'sizes' => []];
        }
        $row = $this->db->fetch(
            'SELECT layout FROM user_widget_layouts WHERE user_id = :u AND context = :c',
            ['u' => $userId, 'c' => $context]
        );
        if (!$row) {
            return ['order' => [], 'hidden' => [], 'sizes' => []];
        }
        $decoded = json_decode((string) ($row['layout'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['order' => [], 'hidden' => [], 'sizes' => []];
        }
        $order = $this->cleanIds($decoded['order'] ?? []);
        return [
            'order' => $order,
            'hidden' => $this->cleanIds($decoded['hidden'] ?? []),
            'sizes' => $this->cleanSizes($decoded['sizes'] ?? [], $order),
        ];
    }

    /**
     * @param array<mixed> $order
     * @param array<mixed> $hidden
     * @param array<mixed> $sizes
     * @return array{ok:bool,error?:string,layout?:array{order:list<string>,hidden:list<string>,sizes:array<string,int>}}
     */
    public function save(
        int $userId,
        string $context,
        array $order,
        array $hidden,
        array $sizes,
        string $ip
    ): array {
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
        $sizes = $this->cleanSizes($sizes, $order);
        $layout = ['order' => $order, 'hidden' => $hidden, 'sizes' => (object) $sizes];
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
                'resized' => count($sizes),
            ], $ip);
        } catch (\Throwable) {
            // Preferences are not critical: never fail the save on audit errors.
        }
        return ['ok' => true, 'layout' => ['order' => $order, 'hidden' => $hidden, 'sizes' => $sizes]];
    }

    /**
     * Keep only the sizes of known widgets, clamped to the 1..3 grid columns.
     *
     * @param mixed $sizes widget id => requested span
     * @param list<string> $order valid widget ids, in display order
     * @return array<string,int>
     */
    private function cleanSizes(mixed $sizes, array $order): array
    {
        if (!is_array($sizes) || $order === []) {
            return [];
        }
        $allowed = array_flip($order);
        $out = [];
        foreach ($sizes as $id => $size) {
            if (!is_string($id)) {
                continue;
            }
            $id = strtolower(trim($id));
            if (!isset($allowed[$id]) || !is_numeric($size)) {
                continue;
            }
            $value = (int) $size;
            if ($value < self::MIN_SIZE || $value > self::MAX_SIZE) {
                continue;
            }
            $out[$id] = $value;
        }
        return $out;
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
