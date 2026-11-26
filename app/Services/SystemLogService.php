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

/**
 * Read-only view over the technical application log (storage/logs/app-*.log).
 *
 * Only ERROR / WARNING / ANOMALY lines are exposed, redacted and truncated: the
 * maintenance console shows them to the platform owner, never to guests.
 */
final class SystemLogService
{
    private const SEVERITIES = ['ERROR', 'WARNING', 'ANOMALY'];
    private const MAX_MESSAGE = 320;
    /** Read at most this much from the end of a log file (the tail is what matters). */
    private const TAIL_BYTES = 262144;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Newest error/warning lines across the last days of the log.
     *
     * @return list<array{at:string,level:string,message:string}>
     */
    public function recentProblems(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $files = glob(rtrim($this->directory, '/') . '/app-*.log') ?: [];
        // Newest first (file names carry the date), legacy app.log as fallback.
        usort($files, static fn (string $a, string $b): int => strcmp($b, $a));
        $files = array_slice($files, 0, 3);
        if ($files === [] && is_file($this->directory . '/app.log')) {
            $files = [$this->directory . '/app.log'];
        }

        $out = [];
        foreach ($files as $file) {
            $lines = $this->tailLines($file, 400);
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $parsed = $this->parseLine($lines[$i]);
                if ($parsed === null) {
                    continue;
                }
                $out[] = $parsed;
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function tailLines(string $file, int $maxLines): array
    {
        $size = (int) @filesize($file);
        if ($size < 1) {
            return [];
        }
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            return [];
        }
        $read = min($size, self::TAIL_BYTES);
        if ($read < $size) {
            fseek($handle, -$read, SEEK_END);
        }
        $chunk = (string) fread($handle, $read);
        fclose($handle);

        $lines = preg_split("/\r\n|\n|\r/", $chunk) ?: [];
        if ($read < $size) {
            // The first line is probably cut in half: drop it.
            array_shift($lines);
        }
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        return $lines === [] ? [] : array_slice($lines, -$maxLines);
    }

    /** @return array{at:string,level:string,message:string}|null */
    private function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || preg_match('/^\[([^\]]{4,64})\]\s+([A-Z]{3,12})\s+(.*)$/u', $line, $m) !== 1) {
            return null;
        }
        $level = strtoupper($m[2]);
        if (!in_array($level, self::SEVERITIES, true)) {
            return null;
        }
        $at = $m[1];
        try {
            $at = (new \DateTimeImmutable($m[1]))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            // Keep the raw timestamp text.
        }

        return [
            'at' => $at,
            'level' => $level,
            'message' => $this->sanitize($m[3]),
        ];
    }

    /** Redact secret-looking values, collapse whitespace and truncate. */
    private function sanitize(string $message): string
    {
        $message = (string) preg_replace(
            '/(\"?(?:password|password_confirmation|db_password|app_key|_token|token|secret|api_key|fiscal_code)\"?\s*[:=]\s*)(\"[^\"]*\"|[^,\s}]+)/i',
            '$1"[redacted]"',
            $message
        );
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE - 1) . '…';
        }

        return $message;
    }
}
