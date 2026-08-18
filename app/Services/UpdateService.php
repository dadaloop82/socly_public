<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Migrator;

final class UpdateService
{
    private const CACHE_TTL = 3600;

    private const DEFAULT_MANIFEST_URL = 'https://raw.githubusercontent.com/dadaloop82/socly_public/main/latest.json';

    /** @var list<string> */
    private const ALLOWED_MANIFEST_HOSTS = [
        'raw.githubusercontent.com',
        'github.com',
        'www.github.com',
        'socly.it',
        'www.socly.it',
    ];

    public function __construct(
        private readonly Migrator $migrator,
        private readonly AuditService $audit
    ) {
    }

    /** Git-based one-click install (requires SSH). */
    public function installEnabled(): bool
    {
        $flag = $_ENV['UPDATE_ENABLED'] ?? 'false';
        return filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    /** @deprecated Use installEnabled() */
    public function enabled(): bool
    {
        return $this->installEnabled();
    }

    /** Public HTTP release check (no credentials). */
    public function notifyEnabled(): bool
    {
        $flag = $_ENV['UPDATE_NOTIFY'] ?? 'true';
        return filter_var($flag, FILTER_VALIDATE_BOOL);
    }

    public function manifestUrl(): string
    {
        $url = trim((string) ($_ENV['UPDATE_MANIFEST_URL'] ?? self::DEFAULT_MANIFEST_URL));
        return $url !== '' ? $url : self::DEFAULT_MANIFEST_URL;
    }

    public function currentVersion(): string
    {
        $path = base_path('VERSION');
        if (!is_file($path)) {
            return '0.0.0';
        }
        return trim((string) file_get_contents($path)) ?: '0.0.0';
    }

    public function channel(): string
    {
        return trim((string) ($_ENV['UPDATE_CHANNEL'] ?? 'main')) ?: 'main';
    }

    public function repo(): string
    {
        return trim((string) ($_ENV['UPDATE_REPO'] ?? 'git@github.com-socly:dadaloop82/socly.git'));
    }

    /**
     * @return array{
     *   available:bool,
     *   current:string,
     *   remote:string,
     *   checked_at:int,
     *   source?:string,
     *   install_available?:bool,
     *   notes_url?:string,
     *   download_url?:string,
     *   install_guide_url?:string,
     *   error?:string
     * }
     */
    public function check(bool $force = false): array
    {
        $cacheFile = storage_path('cache/update_check.json');
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && (($cached['checked_at'] ?? 0) + self::CACHE_TTL) > time()) {
                return $cached;
            }
        }

        $current = $this->currentVersion();
        if (!$this->notifyEnabled() && !$this->installEnabled()) {
            $result = $this->baseResult($current, $current, false);
            $this->writeCache($cacheFile, $result);
            return $result;
        }

        if ($this->notifyEnabled()) {
            try {
                $manifest = $this->fetchManifest();
                $remote = (string) ($manifest['version'] ?? '0.0.0');
                $result = $this->baseResult($current, $remote, $this->isNewer($remote, $current));
                $result['source'] = 'manifest';
                $result['install_available'] = $this->installEnabled();
                $result['notes_url'] = (string) ($manifest['notes_url'] ?? '');
                $result['download_url'] = (string) ($manifest['download_url'] ?? '');
                $result['install_guide_url'] = (string) ($manifest['install_guide_url'] ?? '');
                $this->writeCache($cacheFile, $result);
                return $result;
            } catch (\Throwable $e) {
                if (!$this->installEnabled()) {
                    $result = $this->baseResult($current, $current, false);
                    $result['source'] = 'manifest';
                    $result['install_available'] = false;
                    $result['error'] = $e->getMessage();
                    $this->writeCache($cacheFile, $result);
                    return $result;
                }
            }
        }

        if ($this->installEnabled()) {
            try {
                $this->git(['fetch', 'origin']);
                $remoteVersion = $this->gitShowVersion('origin/' . $this->channel());
                $result = $this->baseResult($current, $remoteVersion, $this->isNewer($remoteVersion, $current));
                $result['source'] = 'git';
                $result['install_available'] = true;
                $this->writeCache($cacheFile, $result);
                return $result;
            } catch (\Throwable $e) {
                $result = $this->baseResult($current, $current, false);
                $result['source'] = 'git';
                $result['install_available'] = true;
                $result['error'] = $e->getMessage();
                $this->writeCache($cacheFile, $result);
                return $result;
            }
        }

        $result = $this->baseResult($current, $current, false);
        $this->writeCache($cacheFile, $result);
        return $result;
    }

    /** @return array{ok:bool,message:string,version?:string} */
    public function apply(string $ip): array
    {
        if (!$this->installEnabled()) {
            return ['ok' => false, 'message' => __('updates.disabled')];
        }

        $lock = storage_path('maintenance.lock');
        if (is_file($lock)) {
            return ['ok' => false, 'message' => __('updates.busy')];
        }

        $dirty = $this->dirtyFiles();
        if ($dirty !== []) {
            return [
                'ok' => false,
                'message' => __('updates.dirty', ['files' => implode(', ', array_slice($dirty, 0, 5))]),
            ];
        }

        file_put_contents($lock, date('c'));
        try {
            $before = $this->currentVersion();
            $this->git(['fetch', 'origin']);
            $this->git(['merge', '--ff-only', 'origin/' . $this->channel()]);
            $this->migrator->migrate();
            try {
                app(InstallerService::class)->seedFields(InstallerService::defaultFields());
            } catch (\Throwable) {
                // seed best-effort
            }
            $after = $this->currentVersion();
            $this->audit->log('update.applied', 'system', null, ['version' => $before], ['version' => $after], $ip);
            @unlink(storage_path('cache/update_check.json'));
            return ['ok' => true, 'message' => __('updates.success', ['version' => $after]), 'version' => $after];
        } catch (\Throwable $e) {
            $this->audit->log('update.failed', 'system', null, null, ['error' => $e->getMessage()], $ip);
            return ['ok' => false, 'message' => __('updates.failed', ['error' => $e->getMessage()])];
        } finally {
            if (is_file($lock)) {
                @unlink($lock);
            }
        }
    }

    /** @return array<string, mixed> */
    private function fetchManifest(): array
    {
        $url = $this->manifestUrl();
        if (!$this->isAllowedManifestUrl($url)) {
            throw new \RuntimeException('URL manifest non consentito.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: SOCLY-UpdateCheck/1.0',
                    'Accept: application/json',
                ]) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            throw new \RuntimeException('Manifest remoto non raggiungibile.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Manifest remoto non valido.');
        }

        $version = trim((string) ($data['version'] ?? ''));
        if ($version === '') {
            throw new \RuntimeException('Manifest remoto senza versione.');
        }

        return $data;
    }

    private function isAllowedManifestUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, self::ALLOWED_MANIFEST_HOSTS, true);
    }

    /** @return array{available:bool,current:string,remote:string,checked_at:int} */
    private function baseResult(string $current, string $remote, bool $available): array
    {
        return [
            'available' => $available,
            'current' => $current,
            'remote' => $remote,
            'checked_at' => time(),
        ];
    }

    /** @return list<string> */
    private function dirtyFiles(): array
    {
        $out = $this->git(['status', '--porcelain']);
        $files = [];
        foreach (preg_split('/\r\n|\r|\n/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $path = trim(substr($line, 3));
            if ($path === '' || $this->isPreservedPath($path)) {
                continue;
            }
            $files[] = $path;
        }
        return $files;
    }

    private function isPreservedPath(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), './');
        if ($path === '.env' || $path === '.env.user') {
            return true;
        }
        if (str_starts_with($path, 'storage/')) {
            return true;
        }
        return false;
    }

    private function gitShowVersion(string $ref): string
    {
        try {
            $raw = trim($this->git(['show', $ref . ':VERSION']));
            return $raw !== '' ? $raw : '0.0.0';
        } catch (\Throwable) {
            return '0.0.0';
        }
    }

    private function isNewer(string $remote, string $local): bool
    {
        if ($remote === '' || $remote === $local) {
            return false;
        }
        return version_compare($this->normalize($remote), $this->normalize($local), '>');
    }

    private function normalize(string $version): string
    {
        $version = trim($version);
        if (preg_match('/^\d+(\.\d+){0,3}/', $version, $m)) {
            return $m[0];
        }
        return '0.0.0';
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        $cmd = array_merge(['git', '-C', base_path()], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $_ENV;
        $sshHost = trim((string) ($_ENV['UPDATE_SSH_HOST'] ?? 'github.com-socly'));
        if ($sshHost !== '') {
            $env['GIT_SSH_COMMAND'] = 'ssh -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new';
        }
        $proc = proc_open($cmd, $descriptors, $pipes, base_path(), $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start git');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new \RuntimeException(trim($stderr !== '' ? $stderr : $stdout) ?: 'git failed');
        }
        return $stdout;
    }

    /** @param array<string, mixed> $result */
    private function writeCache(string $path, array $result): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
