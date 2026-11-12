<?php

declare(strict_types=1);

namespace Socly\Services;

use Socly\Core\Database;
use Socly\Core\Migrator;

final class UpdateService
{
    private const CACHE_TTL = 3600;

    private const DEFAULT_MANIFEST_URL = 'https://raw.githubusercontent.com/dadaloop82/socly_public/main/latest.json';

    /** Public HTTPS repository: readable anonymously, no deploy key required. */
    private const DEFAULT_REPO_URL = 'https://github.com/dadaloop82/socly_public.git';

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
        private readonly AuditService $audit,
        private readonly Database $db
    ) {
    }

    /** Cached "this tree is a git checkout" probe (avoid spawning git on every call). */
    private ?bool $gitCheckout = null;

    /** When the running update started (seconds), used by the progress snapshot. */
    private ?int $progressStartedAt = null;

    /** Git-based one-click install (the checkout's `origin` remote must be readable). */
    public function installEnabled(): bool
    {
        $flag = self::installFlagFromEnv($_ENV);
        if ($flag !== null) {
            return $flag;
        }
        return $this->isGitCheckout();
    }

    /**
     * Resolve the UPDATE_DISABLED / UPDATE_ENABLED flags without booting the app.
     *
     * @param array<string,mixed> $env
     * @return bool|null null = rely on the git-checkout detection
     */
    public static function installFlagFromEnv(array $env): ?bool
    {
        // Explicit opt-out wins (UPDATE_DISABLED=true).
        if (filter_var($env['UPDATE_DISABLED'] ?? 'false', FILTER_VALIDATE_BOOL)) {
            return false;
        }
        // An explicit UPDATE_ENABLED value decides (true = force, false = disabled); when the
        // variable is empty/absent we keep the automatic detection so the button works on a
        // git checkout without touching .env.
        $enabledRaw = trim((string) ($env['UPDATE_ENABLED'] ?? ''));
        if ($enabledRaw === '') {
            return null;
        }
        return filter_var($enabledRaw, FILTER_VALIDATE_BOOL);
    }

    /** True when the code tree is a git checkout with a readable `origin` remote. */
    private function isGitCheckout(): bool
    {
        if ($this->gitCheckout !== null) {
            return $this->gitCheckout;
        }
        $this->gitCheckout = false;
        if (!is_dir(base_path('.git'))) {
            return false;
        }
        try {
            $inside = trim($this->git(['rev-parse', '--is-inside-work-tree']));
            $remote = trim($this->git(['remote', 'get-url', 'origin']));
            $this->gitCheckout = $inside === 'true' && $remote !== '';
        } catch (\Throwable) {
            $this->gitCheckout = false;
        }
        return $this->gitCheckout;
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
        if (function_exists('app_version')) {
            $v = trim(app_version());
            if ($v !== '') {
                return $v;
            }
        }
        // Prefer shared code tree (demos: instance dir has no VERSION file).
        $path = function_exists('code_path') ? code_path('VERSION') : base_path('VERSION');
        if (!is_file($path)) {
            $path = base_path('VERSION');
        }
        if (!is_file($path)) {
            return '0.0.0';
        }
        $raw = trim((string) file_get_contents($path));
        return function_exists('sanitize_app_version')
            ? sanitize_app_version($raw)
            : ($raw !== '' ? $raw : '0.0.0');
    }

    public function channel(): string
    {
        return trim((string) ($_ENV['UPDATE_CHANNEL'] ?? 'main')) ?: 'main';
    }

    public function repo(): string
    {
        $repo = trim((string) ($_ENV['UPDATE_REPO'] ?? self::DEFAULT_REPO_URL));
        // Instances provisioned before the public mirror keep the old private
        // remote in .env: treat it as the public default instead of leaking it.
        if ($repo === '' || preg_match('#^git@github\.com[^:]*:dadaloop82/socly(_public)?\.git$#', $repo) === 1) {
            return self::DEFAULT_REPO_URL;
        }
        return $repo;
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

        // Cached response (also applies to test instances).
        if (!$force && is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)
                && (($cached['checked_at'] ?? 0) + self::CACHE_TTL) > time()
                && (string) ($cached['current'] ?? '') === $this->currentVersion()) {
                $staleManifestOnly = $this->installEnabled()
                    && ($cached['source'] ?? '') === 'manifest'
                    && trim((string) ($cached['develop_version'] ?? '')) === '';
                if (!$staleManifestOnly) {
                    return $this->finalizeResult($cached);
                }
            }
        }

        if (function_exists('is_temporary_instance') && is_temporary_instance()) {
            $current = $this->currentVersion();
            $result = $this->baseResult($current, $current, false);
            $result['source'] = 'demo';
            $result['install_available'] = false;

            // A test instance does not update itself, but it still needs to notice if a newer public version
            // exists.
            if ($this->notifyEnabled()) {
                try {
                    $manifest = $this->fetchManifest();
                    $public = trim((string) ($manifest['version'] ?? ''));
                    if ($public !== '') {
                        $result['public_version'] = $public;
                        $result['remote'] = $public;
                        $result['available'] = $this->isNewer($public, $current);
                    }
                    $result['notes_url'] = (string) ($manifest['notes_url'] ?? '');
                    $result['download_url'] = (string) ($manifest['download_url'] ?? '');
                    $result['install_guide_url'] = (string) ($manifest['install_guide_url'] ?? '');
                    $result['released_at'] = (string) ($manifest['released_at'] ?? '');
                    $result['repository_url'] = (string) ($manifest['repository_url'] ?? '');
                } catch (\Throwable) {
                    // No network/manifest: it stays "no update".
                }
            }

            return $this->writeCache($cacheFile, $this->finalizeResult($result));
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
            // Git checkout where `git fetch` failed (permissions on .git, no network or a
            // private fork without credentials). Fall back to the public manifest, otherwise
            // the instance would report "up to date" while a newer release is published.
            if ($publicVersion !== null) {
                $remote = $publicVersion;
                $source = 'manifest';
                $available = $this->isNewer($publicVersion, $current);
            } else {
                $remote = $current;
                $source = 'git';
                $available = false;
            }
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

        if ($gitError !== null && ($manifestError !== null || $this->installEnabled())) {
            $result['error'] = $this->describeUpdateError($gitError);
        } elseif ($manifestError !== null && !$this->installEnabled()) {
            $result['error'] = $this->describeUpdateError($manifestError);
        }

        return $this->writeCache($cacheFile, $this->finalizeResult($result));
    }

    /**
     * One-click update: snapshot the running version, fast-forward, migrate, verify and — when
     * anything fails — restore code and database instead of leaving a broken instance.
     *
     * @return array{ok:bool,message:string,version?:string,from?:string,backup?:string,database_backup?:bool,rolled_back?:bool}
     */
    public function apply(string $ip): array
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        if (function_exists('is_temporary_instance') && is_temporary_instance()) {
            return ['ok' => false, 'message' => __('updates.disabled')];
        }
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

        $preflight = $this->preflightRepository();
        if ($preflight !== null) {
            $this->audit->log('update.failed', 'system', null, null, ['error' => $preflight], $ip);
            return ['ok' => false, 'message' => $preflight];
        }

        file_put_contents($lock, date('c'));
        $this->progress('starting');
        $before = $this->currentVersion();
        $beforeSha = '';
        $afterSha = '';
        $dbTouched = false;
        $backup = ['dir' => '', 'code' => '', 'database' => '', 'database_error' => ''];
        $merged = false;
        try {
            $beforeSha = trim($this->git(['rev-parse', 'HEAD']));
            $this->progress('backup');
            $backup = $this->createBackup($before, $beforeSha);
            if ($backup['database'] === '') {
                // Never touch the code without a database backup: data safety first.
                $this->audit->log('update.failed', 'system', null, null, [
                    'error' => 'database_backup_failed',
                    'detail' => $backup['database_error'],
                ], $ip);
                return [
                    'ok' => false,
                    'message' => __('updates.backup_required', ['error' => $backup['database_error']]),
                    'backup' => $this->relativePath($backup['code']),
                ];
            }
            $this->progress('fetch');
            $this->git(['fetch', 'origin']);
            $this->progress('merge');
            $this->git(['merge', '--ff-only', 'origin/' . $this->channel()]);
            $merged = true;
            $afterSha = trim($this->git(['rev-parse', 'HEAD']));

            // VERSION is read from disk: config('app.version') was built before the merge, and
            // comparing it with itself is what made every in-app update roll back.
            $after = $this->versionOnDisk();
            $this->progress('verify');
            $this->verifyUpdate($before, $after, $beforeSha, $afterSha);
            // Keep the rest of this request (footer, flash, audit) in sync with the new version.
            app()->setConfigValue('app.version', $after);
            $this->resetOpcache();

            $this->progress('migrate');
            if ($this->pendingMigrations() > 0) {
                $dbTouched = true;
                $this->migrator->migrate();
            }
            try {
                app(InstallerService::class)->seedFields(InstallerService::defaultFields());
            } catch (\Throwable) {
                // seed best-effort
            }
            $this->progress('done');
            $this->audit->log('update.applied', 'system', null, ['version' => $before], [
                'version' => $after,
                'commit' => $afterSha,
                'backup' => $this->relativePath($backup['code']),
                'database_backup' => $backup['database'] !== '',
                'migrations_applied' => $dbTouched,
            ], $ip);
            @unlink(storage_path('cache/update_check.json'));
            return [
                'ok' => true,
                'message' => __('updates.success', ['version' => $after]),
                'version' => $after,
                'from' => $before,
                'backup' => $this->relativePath($backup['code']),
                'database_backup' => $backup['database'] !== '',
                'database_backup_error' => $backup['database_error'],
            ];
        } catch (\Throwable $e) {
            $rolledBack = false;
            if ($merged && $beforeSha !== '') {
                // Only re-import the dump when the schema could actually have changed.
                $rolledBack = $this->rollback($beforeSha, $dbTouched ? $backup['database'] : '');
            }
            $this->progress($rolledBack ? 'rollback' : 'failed');
            $this->audit->log($rolledBack ? 'update.rolled_back' : 'update.failed', 'system', null, null, [
                'error' => $e->getMessage(),
                'backup' => $this->relativePath($backup['code']),
                'database_restored' => $dbTouched,
            ], $ip);
            return [
                'ok' => false,
                'message' => $rolledBack
                    ? __('updates.rolled_back', ['error' => $e->getMessage()])
                    : __('updates.failed', ['error' => $e->getMessage()]),
                'rolled_back' => $rolledBack,
                'backup' => $this->relativePath($backup['code']),
            ];
        } finally {
            if (is_file($lock)) {
                @unlink($lock);
            }
        }
    }

    /**
     * Snapshot of the running version: application code (git archive) plus a database dump.
     *
     * @return array{dir:string,code:string,database:string,database_error:string}
     */
    private function createBackup(string $version, string $sha): array
    {
        $safeVersion = preg_replace('/[^0-9A-Za-z._-]/', '', $version) ?: 'unknown';
        $dir = storage_path('backups/update-' . date('Ymd-His') . '-v' . $safeVersion);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('backup_dir_failed');
        }
        $code = $dir . '/code-' . substr($sha, 0, 12) . '.tar.gz';
        try {
            $this->git([
                'archive', '--format=tar.gz', '--output=' . $code, $sha,
                'app', 'bootstrap', 'resources', 'lang', 'public', 'database', 'bin', 'VERSION',
            ]);
        } catch (\Throwable) {
            // A path does not exist in this tree: archive everything tracked instead.
            $this->git(['archive', '--format=tar.gz', '--output=' . $code, $sha]);
        }
        [$dbOk, $dbPath, $dbError] = $this->dumpDatabase($dir . '/database.sql');
        if (!$dbOk && is_file($dir . '/database.sql')) {
            @unlink($dir . '/database.sql');
        }
        @file_put_contents($dir . '/update.json', (string) json_encode([
            'from_version' => $version,
            'from_commit' => $sha,
            'created_at' => date('c'),
            'database_backup' => $dbOk,
            'database_error' => $dbError,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'dir' => $dir,
            'code' => is_file($code) ? $code : '',
            'database' => $dbOk ? $dbPath : '',
            'database_error' => $dbError,
        ];
    }

    /** @return array{0:bool,1:string,2:string} */
    private function dumpDatabase(string $path): array
    {
        $cfg = (array) config('db', []);
        $database = (string) ($cfg['database'] ?? '');
        if ($database === '') {
            return [false, '', 'db_config_missing'];
        }
        if (!$this->binaryAvailable('mysqldump')) {
            return [false, '', 'mysqldump_missing'];
        }
        $args = [
            'mysqldump',
            '--host=' . (string) ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($cfg['port'] ?? '3306'),
            '--user=' . (string) ($cfg['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--result-file=' . $path,
            $database,
        ];
        $env = getenv();
        $env['MYSQL_PWD'] = (string) ($cfg['password'] ?? '');
        $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        if (!is_resource($proc)) {
            return [false, '', 'mysqldump_failed'];
        }
        fclose($pipes[0]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        clearstatcache();
        if ($code !== 0 || !is_file($path) || (int) filesize($path) === 0) {
            return [false, '', $stderr !== '' ? $stderr : 'mysqldump_failed'];
        }
        return [true, $path, ''];
    }

    private function restoreDatabase(string $dump): bool
    {
        $cfg = (array) config('db', []);
        $database = (string) ($cfg['database'] ?? '');
        if ($database === '' || !is_file($dump) || !$this->binaryAvailable('mysql')) {
            return false;
        }
        $args = [
            'mysql',
            '--host=' . (string) ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($cfg['port'] ?? '3306'),
            '--user=' . (string) ($cfg['username'] ?? ''),
            '--default-character-set=utf8mb4',
            $database,
        ];
        $env = getenv();
        $env['MYSQL_PWD'] = (string) ($cfg['password'] ?? '');
        $proc = proc_open($args, [
            0 => ['file', $dump, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path(), $env);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0;
    }

    private function binaryAvailable(string $binary): bool
    {
        $proc = proc_open(['which', $binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        $found = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0 && $found !== '';
    }

    /** Post-update sanity check: new version, reachable database, no pending migration. */
    /** Version read from the on-disk VERSION file (the in-memory config is stale after a merge). */
    private function versionOnDisk(): string
    {
        foreach ([code_path('VERSION'), base_path('VERSION')] as $path) {
            if (!is_file($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $raw = trim((string) file_get_contents($path));
            return function_exists('sanitize_app_version') ? sanitize_app_version($raw) : $raw;
        }
        return '0.0.0';
    }

    /** Migrations still to run (0 when the schema is already up to date). */
    private function pendingMigrations(): int
    {
        $files = glob(base_path('database/migrations/*.sql')) ?: [];
        $applied = (int) ($this->db->fetch('SELECT COUNT(*) AS c FROM migrations')['c'] ?? 0);
        return max(0, count($files) - $applied);
    }

    /** Drop the opcache so the merged PHP files are used immediately (opcache.validate_timestamps=0). */
    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /** Publish the current update step so the UI can poll it (best effort, never throws). */
    private function progress(string $step): void
    {
        try {
            $this->progressStartedAt ??= time();
            $file = storage_path('cache/update_progress.json');
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($file, (string) json_encode([
                'step' => $step,
                'started_at' => $this->progressStartedAt,
                'at' => time(),
            ]), LOCK_EX);
        } catch (\Throwable) {
            // Progress reporting must never break the update itself.
        }
    }

    /** @return array{step:string,started_at:int,at:int} */
    public function progressState(): array
    {
        $file = storage_path('cache/update_progress.json');
        $empty = ['step' => '', 'started_at' => 0, 'at' => 0];
        if (!is_file($file)) {
            return $empty;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return $empty;
        }
        return [
            'step' => (string) ($decoded['step'] ?? ''),
            'started_at' => (int) ($decoded['started_at'] ?? 0),
            'at' => (int) ($decoded['at'] ?? 0),
        ];
    }

    private function verifyUpdate(string $before, string $after, string $beforeSha = '', string $afterSha = ''): void
    {
        if ($after === '' || $after === '0.0.0') {
            throw new \RuntimeException('verify_version_missing');
        }
        // Success is proven by the fast-forward: a new commit or a new version is enough.
        $commitChanged = $beforeSha !== '' && $afterSha !== '' && $beforeSha !== $afterSha;
        if (!$commitChanged && $after === $before) {
            throw new \RuntimeException('verify_no_changes');
        }
        $probe = $this->db->fetch('SELECT 1 AS ok');
        if ((int) ($probe['ok'] ?? 0) !== 1) {
            throw new \RuntimeException('verify_database_unreachable');
        }
    }

    /** Restore the previous commit and, when available, the database dump. */
    private function rollback(string $sha, string $dump): bool
    {
        try {
            $this->git(['reset', '--hard', $sha]);
        } catch (\Throwable) {
            return false;
        }
        if ($dump !== '') {
            $this->restoreDatabase($dump);
        }
        $this->migrator->migrate();
        return true;
    }

    private function relativePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $base = rtrim(base_path(), '/') . '/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Human-readable update error: neutral classification + real detail
     * (permissions / authentication / network) for the admin UI.
     */
    private function describeUpdateError(string $detail): string
    {
        $detail = trim($detail);
        $message = __(self::classifyUpdateError($detail));
        if ($detail === '') {
            return $message;
        }
        return $message . ' ' . __('updates.error_detail', ['detail' => $detail]);
    }

    /** Map a git/HTTP failure to a stable i18n key. */
    public static function classifyUpdateError(string $message): string
    {
        $normalized = strtolower($message);
        if ($normalized === '') {
            return 'updates.error_generic';
        }
        if (preg_match('/authentication failed|could not read username|publickey|invalid username or password|terminal prompts disabled|host key verification failed|access denied \(publickey/', $normalized) === 1) {
            return 'updates.error_auth';
        }
        if (preg_match('/permission denied|not writable|read-only file system|unable to create|operation not permitted|eacces|failed to write|object file|insufficient permission/', $normalized) === 1) {
            return 'updates.error_permissions';
        }
        if (preg_match('/could not resolve host|connection timed out|network is unreachable|connection refused|failed to connect|ssl certificate|name resolution|early eof|unable to access/', $normalized) === 1) {
            return 'updates.error_network';
        }
        return 'updates.error_generic';
    }

    /**
     * Fail before touching the tree when the PHP user cannot write the git
     * metadata (typical: .git owned by root) or when the repository is damaged.
     */
    private function preflightRepository(): ?string
    {
        $gitDir = base_path('.git');
        if (!file_exists($gitDir)) {
            return __('updates.git_missing');
        }
        if (is_file($gitDir)) {
            // Worktrees/submodules: .git is a pointer to the real git directory.
            $pointer = trim((string) @file_get_contents($gitDir));
            if (preg_match('/^gitdir:\s*(.+)$/i', $pointer, $m) === 1) {
                $candidate = trim($m[1]);
                $gitDir = str_starts_with($candidate, '/') ? $candidate : base_path($candidate);
            }
        }
        $objects = rtrim($gitDir, '/') . '/objects';
        if (!is_dir($gitDir) || !is_writable($gitDir) || !is_dir($objects) || !is_writable($objects)) {
            return __('updates.git_not_writable', ['path' => $this->relativePath($gitDir)]);
        }
        try {
            // Detect a corrupted object store before the merge (cheap connectivity-only run).
            $this->git(['fsck', '--no-progress', '--connectivity-only']);
        } catch (\Throwable $e) {
            return __('updates.git_check_failed', ['error' => $e->getMessage()]);
        }
        return null;
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
        // A web request must never block on a credential prompt.
        $env['GIT_TERMINAL_PROMPT'] = '0';
        // The default remote is public HTTPS: only set up SSH when a fork needs it.
        $sshHost = trim((string) ($_ENV['UPDATE_SSH_HOST'] ?? ''));
        $sshKey = trim((string) ($_ENV['UPDATE_SSH_KEY'] ?? ''));
        if ($sshHost !== '' || $sshKey !== '') {
            // Deploy keys live outside the web user's $HOME on most servers: allow pointing at an
            // explicit key file (UPDATE_SSH_KEY=/path/to/deploy_key) and fail fast instead of
            // hanging on a passphrase prompt.
            $sshCommand = 'ssh -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new';
            if ($sshKey !== '') {
                $sshCommand .= ' -i ' . escapeshellarg($sshKey);
            }
            $env['GIT_SSH_COMMAND'] = $sshCommand;
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
        $public = trim((string) ($result['public_version'] ?? ''));

        if ($develop !== '') {
            $result['remote'] = $develop;
            $result['available'] = $this->isNewer($develop, $current);
        } elseif ($source === 'manifest') {
            $remote = trim((string) ($result['remote'] ?? $current));
            $result['remote'] = $remote !== '' ? $remote : $current;
            $result['available'] = $this->isNewer($result['remote'], $current);
        } elseif ($installAvailable) {
            // One-click install available but the git remote could not be read: still surface a
            // newer public release instead of claiming the instance is up to date.
            if ($public !== '' && $this->isNewer($public, $current)) {
                $result['remote'] = $public;
                $result['available'] = true;
            } else {
                $result['remote'] = $current;
                $result['available'] = false;
            }
        } else {
            $remote = trim((string) ($result['remote'] ?? $current));
            $result['remote'] = $remote !== '' ? $remote : $current;
            $result['available'] = $this->isNewer($result['remote'], $current);
        }

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
