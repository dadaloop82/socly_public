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
     *   develop_version?:string,
     *   public_version?:string,
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
                $staleManifestOnly = $this->installEnabled()
                    && ($cached['source'] ?? '') === 'manifest'
                    && trim((string) ($cached['develop_version'] ?? '')) === '';
                if (!$staleManifestOnly) {
                    return $this->finalizeResult($cached);
                }
            }
        }

        $current = $this->currentVersion();
        if (!$this->notifyEnabled() && !$this->installEnabled()) {
            return $this->writeCache($cacheFile, $this->finalizeResult(
                $this->baseResult($current, $current, false)
            ));
        }

        $manifest = null;
        $manifestError = null;
        if ($this->notifyEnabled()) {
            try {
                $manifest = $this->fetchManifest();
            } catch (\Throwable $e) {
                $manifestError = $e->getMessage();
            }
        }

        $gitRemote = null;
        $gitError = null;
        if ($this->installEnabled()) {
            try {
                $this->git(['fetch', 'origin']);
                $gitRemote = $this->gitShowVersion('origin/' . $this->channel());
            } catch (\Throwable $e) {
                $gitError = $e->getMessage();
            }
        }

        $publicVersion = is_array($manifest)
            ? trim((string) ($manifest['version'] ?? ''))
            : '';
        if ($publicVersion === '') {
            $publicVersion = null;
        }

        if ($gitRemote !== null) {
            $remote = $gitRemote;
            $source = 'git';
            $available = $this->isNewer($gitRemote, $current);
            if ($publicVersion !== null && $this->isNewer($publicVersion, $gitRemote)) {
                $publicVersion = $gitRemote;
            }
        } elseif ($this->installEnabled()) {
            $remote = $current;
            $source = 'git';
            $available = false;
        } elseif ($publicVersion !== null) {
            $remote = $publicVersion;
            $source = 'manifest';
            $available = $this->isNewer($publicVersion, $current);
        } else {
            $remote = $current;
            $source = $this->installEnabled() ? 'git' : 'manifest';
            $available = false;
        }

        $result = $this->baseResult($current, $remote, $available);
        $result['source'] = $source;
        $result['install_available'] = $this->installEnabled();
        if ($publicVersion !== null) {
            $result['public_version'] = $publicVersion;
        }
        if ($gitRemote !== null) {
            $result['develop_version'] = $gitRemote;
        }

        if (is_array($manifest)) {
            $result['notes_url'] = (string) ($manifest['notes_url'] ?? '');
            $result['download_url'] = (string) ($manifest['download_url'] ?? '');
            $result['install_guide_url'] = (string) ($manifest['install_guide_url'] ?? '');
            $result['released_at'] = (string) ($manifest['released_at'] ?? '');
            $result['repository_url'] = (string) ($manifest['repository_url'] ?? '');
        }

        if ($source === 'git') {
            $commit = $this->fetchGithubLatestCommit($this->githubRepoSlugFromGitRemote($this->repo()));
            if ($commit !== null) {
                $result['last_commit'] = $commit;
            }
        } elseif (is_array($manifest)) {
            $commit = $this->fetchPublicLatestCommit((string) ($manifest['repository_url'] ?? ''));
            if ($commit !== null) {
                $result['last_commit'] = $commit;
            }
        }

        if ($gitError !== null && $manifestError !== null) {
            $result['error'] = $gitError;
        } elseif ($gitError !== null && $this->installEnabled()) {
            $result['error'] = $gitError;
        } elseif ($manifestError !== null && !$this->installEnabled()) {
            $result['error'] = $manifestError;
        }

        return $this->writeCache($cacheFile, $this->finalizeResult($result));
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

    /**
     * Latest commit on the public GitHub repository (dadaloop82/socly_public).
     *
     * @return array{sha:string,message:string,date:string,url:string}|null
     */
    private function fetchPublicLatestCommit(string $repositoryUrl): ?array
    {
        $repo = $this->publicGithubRepoSlug($repositoryUrl);
        if ($repo === null) {
            return null;
        }
        $url = 'https://api.github.com/repos/' . $repo . '/commits?per_page=1';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 6,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'User-Agent: SOCLY-UpdateCheck/1.0',
                    'Accept: application/vnd.github+json',
                ]) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || $data === []) {
            return null;
        }
        $row = $data[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $sha = trim((string) ($row['sha'] ?? ''));
        $message = trim((string) ($row['commit']['message'] ?? ''));
        if ($message !== '') {
            $message = preg_split('/\r\n|\r|\n/', $message)[0] ?? $message;
            $message = mb_substr($message, 0, 160);
        }
        $date = trim((string) ($row['commit']['committer']['date'] ?? $row['commit']['author']['date'] ?? ''));
        $htmlUrl = trim((string) ($row['html_url'] ?? ''));
        if ($sha === '') {
            return null;
        }
        return [
            'sha' => substr($sha, 0, 7),
            'message' => $message,
            'date' => $date,
            'url' => $htmlUrl !== '' ? $htmlUrl : ('https://github.com/' . $repo . '/commit/' . $sha),
        ];
    }

    private function publicGithubRepoSlug(string $repositoryUrl): ?string
    {
        $repositoryUrl = trim($repositoryUrl);
        if ($repositoryUrl === '') {
            $repositoryUrl = 'https://github.com/dadaloop82/socly_public';
        }
        if (!preg_match('#github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)#', $repositoryUrl, $m)) {
            return null;
        }
        return $m[1] . '/' . rtrim($m[2], '/.git');
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
        $cmd = array_merge(['git', '-c', 'safe.directory=' . base_path(), '-C', base_path()], $args);
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

    /**
     * Latest commit on a GitHub repository.
     *
     * @return array{sha:string,message:string,date:string,url:string}|null
     */
    private function fetchGithubLatestCommit(?string $repoSlug): ?array
    {
        if ($repoSlug === null || $repoSlug === '') {
            return null;
        }
        return $this->fetchPublicLatestCommit('https://github.com/' . $repoSlug);
    }

    private function githubRepoSlugFromGitRemote(string $remote): ?string
    {
        $remote = trim($remote);
        if ($remote === '') {
            return null;
        }
        if (preg_match('#github\.com[^:]*:([^/]+)/([^/]+?)(?:\.git)?$#', $remote, $m) === 1) {
            return $m[1] . '/' . rtrim($m[2], '.git');
        }
        if (preg_match('#github\.com/([^/]+)/([^/]+?)(?:\.git)?#', $remote, $m) === 1) {
            return $m[1] . '/' . rtrim($m[2], '.git');
        }
        return null;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function finalizeResult(array $result): array
    {
        $current = $this->currentVersion();
        $result['current'] = $current;

        $develop = trim((string) ($result['develop_version'] ?? ''));
        $source = (string) ($result['source'] ?? '');
        $installAvailable = !empty($result['install_available']);

        if ($develop !== '') {
            $result['remote'] = $develop;
            $result['available'] = $this->isNewer($develop, $current);
        } elseif ($source === 'manifest') {
            $remote = trim((string) ($result['remote'] ?? $current));
            $result['remote'] = $remote !== '' ? $remote : $current;
            $result['available'] = $this->isNewer($result['remote'], $current);
        } elseif ($installAvailable) {
            $result['remote'] = $current;
            $result['available'] = false;
        } else {
            $remote = trim((string) ($result['remote'] ?? $current));
            $result['remote'] = $remote !== '' ? $remote : $current;
            $result['available'] = $this->isNewer($result['remote'], $current);
        }

        $public = trim((string) ($result['public_version'] ?? ''));
        if ($develop !== '' && $public !== '' && $this->isNewer($public, $develop)) {
            $result['public_version'] = $develop;
        }

        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function writeCache(string $path, array $result): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $result;
    }
}
