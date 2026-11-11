<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Presenza degli operatori collegati: una riga per sessione aperta, aggiornata dal polling del
 * client. Alimenta i pallini con le iniziali nell'header e l'avviso “X si è appena collegato”.
 */
final class PresenceService
{
    /** Oltre questo silenzio l'utente è mostrato come inattivo (pallino semitrasparente). */
    public const IDLE_SECONDS = 300;

    /** Sessione considerata chiusa dopo questo silenzio (allineato a SessionIdleMiddleware). */
    public const ONLINE_WINDOW = 900;

    public function __construct(private readonly Database $db)
    {
    }

    /** Registra/rinfresca la sessione corrente (chiamato dal polling). */
    public function touch(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);
        $sessionId = session_id();
        if ($userId < 1 || $sessionId === '') {
            return;
        }
        $this->db->query(
            'INSERT INTO user_presence (session_id, user_id, connected_at, last_seen, ended_at)
             VALUES (:sid, :uid, NOW(), NOW(), NULL)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen = NOW(), ended_at = NULL',
            ['sid' => $sessionId, 'uid' => $userId]
        );
        // Pulizia: righe vecchie di oltre un giorno.
        $this->db->query('DELETE FROM user_presence WHERE last_seen < (NOW() - INTERVAL 1 DAY)');
    }

    /** Chiude la presenza corrente (logout). */
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
     * Utenti collegati (una voce per persona, anche con più sessioni aperte).
     *
     * @return array{users:list<array<string,mixed>>,online:int,new:list<array<string,mixed>>,now:int}
     */
    public function snapshot(int $since = 0): array
    {
        $window = self::ONLINE_WINDOW;
        $rows = $this->db->fetchAll(
            "SELECT p.session_id, p.user_id, p.connected_at, p.last_seen,
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
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            if ($userId < 1 || (string) ($row['name'] ?? '') === 'SOCLY Platform') {
                continue;
            }
            $identity = $this->identity($row);
            $idle = (int) ($row['idle_seconds'] ?? 0) >= self::IDLE_SECONDS;
            if (isset($users[$userId])) {
                $users[$userId]['sessions']++;
                $users[$userId]['idle'] = $users[$userId]['idle'] && $idle;
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
            'now' => time(),
        ];
    }

    /**
     * Nome visibile: nome/cognome dell'operatore, altrimenti il socio collegato,
     * altrimenti “Utente Sconosciuto”.
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
            // Il campo storico `name` contiene già “Nome Cognome”.
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

    /** Tonalità stabile per utente: stessa persona, stesso colore. */
    private function hue(int $userId): int
    {
        return (int) (($userId * 137) % 360);
    }
}
