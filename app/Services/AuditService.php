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
