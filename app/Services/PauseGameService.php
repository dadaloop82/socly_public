<?php
/*
 * SOCLY Project Header
 *
 *   ███████╗  ██████╗  ██████╗ ██╗     ██╗   ██╗
 *   ██╔════╝ ██╔═══██╗██╔════╝ ██║     ╚██╗ ██╔╝
 *   ███████╗ ██║   ██║██║      ██║      ╚████╔╝
 *   ╚════██║ ██║   ██║██║      ██║       ╚██╔╝
 *   ███████║ ╚██████╔╝╚██████╗ ███████╗   ██║
 *   ╚══════╝  ╚═════╝  ╚═════╝ ╚══════╝   ╚═╝
 *
 *   SOCLY — https://socly.it/
 *   Open-source project for association management.
 *   Independent project by Stimpfl Daniel. Free software.
 *   Repository: https://github.com/dadaloop82/socly_public
 *   License: Socly Source-Available License (Community / Base Edition)
 *   Copyright (c) 2026 Socly. All rights reserved.
 *
 *   This header is part of the project identity. Keep it in derived files.
 *
 */

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;

/**
 * Shared scoreboard of the pause mini-game (“Procedura lunga in corso”).
 *
 * The game runs while a heavy job keeps the instance paused, so the score is written in small,
 * throttled POSTs. One row per operator stores the best score: the all-time record and its holder
 * are a single indexed query, and the table cannot grow with every played round.
 */
final class PauseGameService
{
    /** Hard cap: a higher value can only come from a tampered client. */
    private const MAX_SCORE = 100000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   record:?array{name:string,score:int},
     *   personal:int,
     *   players:int,
     *   top:list<array{name:string,score:int}>
     * }
     */
    public function state(int $userId): array
    {
        try {
            $recordRow = $this->db->fetch(
                'SELECT display_name, best_score FROM pause_game_scores
                 WHERE best_score > 0
                 ORDER BY best_score DESC, updated_at ASC
                 LIMIT 1'
            );
            $topRows = $this->db->fetchAll(
                'SELECT display_name, best_score FROM pause_game_scores
                 WHERE best_score > 0
                 ORDER BY best_score DESC, updated_at ASC
                 LIMIT 5'
            );
            $personalRow = $userId > 0
                ? $this->db->fetch(
                    'SELECT best_score FROM pause_game_scores WHERE user_id = :id',
                    ['id' => $userId]
                )
                : null;
            $playersRow = $this->db->fetch(
                'SELECT COUNT(*) AS c FROM pause_game_scores WHERE best_score > 0'
            );
        } catch (\Throwable) {
            // The migration runs at boot; until it has, the game must still work without a record.
            return ['record' => null, 'personal' => 0, 'players' => 0, 'top' => []];
        }

        $top = [];
        foreach ($topRows as $row) {
            $top[] = [
                'name' => trim((string) ($row['display_name'] ?? '')),
                'score' => (int) ($row['best_score'] ?? 0),
            ];
        }

        return [
            'record' => $recordRow === null ? null : [
                'name' => trim((string) ($recordRow['display_name'] ?? '')),
                'score' => (int) ($recordRow['best_score'] ?? 0),
            ],
            'personal' => (int) ($personalRow['best_score'] ?? 0),
            'players' => (int) ($playersRow['c'] ?? 0),
            'top' => $top,
        ];
    }

    /**
     * Store the best score of the operator (never lowers an existing one) and return the fresh
     * scoreboard, so the client can show the new record holder immediately.
     *
     * @return array{
     *   ok:bool,
     *   score:int,
     *   improved:bool,
     *   is_record:bool,
     *   record:?array{name:string,score:int},
     *   personal:int,
     *   players:int,
     *   top:list<array{name:string,score:int}>
     * }
     */
    public function save(int $userId, string $name, int $score): array
    {
        $score = max(0, min(self::MAX_SCORE, $score));
        $name = mb_substr(trim($name), 0, 190);
        $before = $this->state($userId);
        if ($userId < 1 || $score < 1) {
            return ['ok' => true, 'score' => $score, 'improved' => false, 'is_record' => false] + $before;
        }
        $improved = $score > $before['personal'];
        try {
            $this->db->query(
                'INSERT INTO pause_game_scores (user_id, display_name, best_score)
                 VALUES (:id, :name, :score)
                 ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name),
                    best_score = GREATEST(best_score, VALUES(best_score))',
                ['id' => $userId, 'name' => $name, 'score' => $score]
            );
        } catch (\Throwable) {
            return ['ok' => false, 'score' => $score, 'improved' => false, 'is_record' => false] + $before;
        }
        $after = $this->state($userId);
        $isRecord = $after['record'] !== null
            && $after['record']['score'] === $score
            && ($before['record'] === null || $score > $before['record']['score']);

        return [
            'ok' => true,
            'score' => $score,
            'improved' => $improved,
            'is_record' => $isRecord,
        ] + $after;
    }
}
