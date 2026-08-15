<?php

declare(strict_types=1);

namespace Socly\Core;

/**
 * Technical application logger with daily files and 7-day retention.
 */
final class Logger
{
    private readonly string $directory;
    private readonly int $retentionDays;
    private bool $purged = false;

    public function __construct(string $directory, int $retentionDays = 7)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->retentionDays = max(1, $retentionDays);
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $this->redact($context));
    }

    /**
     * Technical anomaly (unexpected states, failed flows, security/auth issues).
     *
     * @param array<string, mixed> $context
     */
    public function anomaly(string $message, array $context = []): void
    {
        $this->write('ANOMALY', $message, $this->redact($context));
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $this->purgeExpiredOnce();

        $line = sprintf(
            "[%s] %s %s %s\n",
            date('c'),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE)
        );

        $file = $this->directory . '/app-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // Compatibility alias for tools that still tail app.log
        $alias = $this->directory . '/app.log';
        @file_put_contents($alias, $line, FILE_APPEND | LOCK_EX);
    }

    private function purgeExpiredOnce(): void
    {
        if ($this->purged) {
            return;
        }
        $this->purged = true;

        $cutoff = (new \DateTimeImmutable('today'))->modify('-' . $this->retentionDays . ' days');
        $files = glob($this->directory . '/app-*.log') ?: [];
        foreach ($files as $file) {
            if (!preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                continue;
            }
            $day = \DateTimeImmutable::createFromFormat('Y-m-d', $m[1]);
            if ($day === false) {
                continue;
            }
            if ($day < $cutoff) {
                @unlink($file);
            }
        }

        // Rotate legacy monolithic app.log if it grows too large (>5 MB).
        $legacy = $this->directory . '/app.log';
        if (is_file($legacy) && filesize($legacy) > 5 * 1024 * 1024) {
            $archive = $this->directory . '/app-legacy-' . date('Y-m-d-His') . '.log';
            @rename($legacy, $archive);
        }
    }

    /** @param array<string, mixed> $context */
    private function redact(array $context): array
    {
        $blocked = [
            'password',
            'password_confirmation',
            'admin_password',
            'db_password',
            'app_key',
            '_token',
            'fiscal_code',
            'token',
        ];
        foreach ($blocked as $key) {
            if (array_key_exists($key, $context)) {
                $context[$key] = '[redacted]';
            }
        }
        unset($context['POST'], $context['SESSION'], $context['payload']);
        return $context;
    }
}
