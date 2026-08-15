<?php

declare(strict_types=1);

namespace Socly\Services;

/**
 * File-based rate limiter (server-side). Do not store limits only in session —
 * clearing cookies must not reset the counter (legacy footgun).
 */
final class RateLimiter
{
    public function __construct(private readonly string $storagePath)
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $data = $this->read($key);
        if ($data === null) {
            return false;
        }
        if ($data['reset_at'] < time()) {
            $this->clear($key);
            return false;
        }
        return $data['attempts'] >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $data = $this->read($key);
        $now = time();
        if ($data === null || $data['reset_at'] < $now) {
            $data = ['attempts' => 0, 'reset_at' => $now + $decaySeconds];
        }
        $data['attempts']++;
        $this->write($key, $data);
        return $data['attempts'];
    }

    public function clear(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function availableIn(string $key): int
    {
        $data = $this->read($key);
        if ($data === null) {
            return 0;
        }
        return max(0, $data['reset_at'] - time());
    }

    private function path(string $key): string
    {
        return $this->storagePath . '/' . hash('sha256', $key) . '.json';
    }

    /** @return array{attempts:int,reset_at:int}|null */
    private function read(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['attempts'], $decoded['reset_at'])) {
            return null;
        }
        return [
            'attempts' => (int) $decoded['attempts'],
            'reset_at' => (int) $decoded['reset_at'],
        ];
    }

    /** @param array{attempts:int,reset_at:int} $data */
    private function write(string $key, array $data): void
    {
        file_put_contents($this->path($key), json_encode($data), LOCK_EX);
    }
}
