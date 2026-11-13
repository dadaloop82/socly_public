<?php

declare(strict_types=1);

namespace Socly\Core\Addon;

use SodiumException;
use ZipArchive;

/**
 * Signed add-on packages (.soclyaddon).
 *
 * Layout:
 *   addon.json      manifest: id, name, version, min_core, entry, files{path: sha256}
 *   addon.json.sig  detached Ed25519 signature of the exact addon.json bytes
 *   plugin.php      entry point implementing PluginInterface (+ lib/… files)
 *
 * Only packages signed with the Socly package key are accepted; the code is extracted
 * under storage/addons/<id>/<version>/ (outside the document root) and survives core updates.
 */
final class AddonPackage
{
    private const MAX_BYTES = 25_000_000;
    private const MAX_MANIFEST_BYTES = 262_144;
    private const MAX_FILES = 500;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'php', 'js', 'css', 'json', 'txt', 'md', 'sql',
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico',
        'woff', 'woff2', 'ttf', 'otf',
    ];

    public function __construct(private readonly string $root)
    {
    }

    public function installRoot(): string
    {
        return $this->root . '/storage/addons';
    }

    /**
     * Drop-in folder for signed packages: the admin (or the build tool) copies
     * .soclyaddon files here and the settings page proposes them; no browser upload.
     */
    public function incomingDir(): string
    {
        $dir = $this->installRoot() . '/incoming';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        return $dir;
    }

    /**
     * Verify every package found in the incoming folder (signature + hashes + min core).
     *
     * @return list<array{file:string,path:string,ok:bool,error?:string,manifest?:array<string,mixed>,size:int,sha256:string}>
     */
    public function scanIncoming(int $limit = 100): array
    {
        $dir = $this->incomingDir();
        if ($dir === '') {
            return [];
        }
        $files = glob($dir . '/*.soclyaddon') ?: [];
        usort($files, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));
        $out = [];
        foreach (array_slice($files, 0, max(1, min(500, $limit))) as $path) {
            $entry = [
                'file' => basename($path),
                'path' => $path,
                'ok' => false,
                'size' => (int) @filesize($path),
                'sha256' => '',
            ];
            $inspected = $this->inspect($path);
            if (empty($inspected['ok']) || !is_array($inspected['manifest'] ?? null)) {
                $entry['error'] = (string) ($inspected['error'] ?? 'invalid');
            } else {
                $entry['ok'] = true;
                $entry['manifest'] = $inspected['manifest'];
                $entry['sha256'] = (string) ($inspected['sha256'] ?? '');
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** Resolve a file name inside the incoming folder (no traversal, .soclyaddon only). */
    public function resolveIncomingFile(string $file): ?string
    {
        $file = trim($file);
        if ($file === '' || basename($file) !== $file || !str_ends_with(strtolower($file), '.soclyaddon')) {
            return null;
        }
        $dir = $this->incomingDir();
        if ($dir === '') {
            return null;
        }
        $base = realpath($dir);
        $path = realpath($dir . '/' . $file);
        if ($base === false || $path === false || dirname($path) !== $base || !is_file($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Validate an uploaded file (multipart) and stage it for install.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool,error?:string,manifest?:array<string,mixed>,sha256?:string,staged?:string}
     */
    public function inspectUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => $this->label('addons.error_no_file')];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => $this->label('addons.error_upload')];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => $this->label('addons.error_upload')];
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => $this->label('addons.error_too_large')];
        }
        $staged = $this->installRoot() . '/.incoming/' . bin2hex(random_bytes(8)) . '.soclyaddon';
        if (!is_dir(dirname($staged)) && !@mkdir(dirname($staged), 0750, true) && !is_dir(dirname($staged))) {
            return ['ok' => false, 'error' => $this->label('addons.error_storage')];
        }
        if (!@move_uploaded_file($tmp, $staged)) {
            return ['ok' => false, 'error' => $this->label('addons.error_storage')];
        }
        @chmod($staged, 0640);
        $result = $this->inspect($staged);
        if (empty($result['ok'])) {
            @unlink($staged);
            return $result;
        }
        $result['staged'] = $staged;

        return $result;
    }

    /**
     * Verify signature, manifest integrity, file hashes and core compatibility.
     *
     * @return array{ok:bool,error?:string,manifest?:array<string,mixed>,sha256?:string}
     */
    public function inspect(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => $this->label('addons.error_package_missing')];
        }
        $size = (int) filesize($zipPath);
        if ($size < 1 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => $this->label('addons.error_package_size')];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return ['ok' => false, 'error' => $this->label('addons.error_package_invalid')];
        }
        try {
            $manifestRaw = $zip->getFromName('addon.json');
            $signatureRaw = $zip->getFromName('addon.json.sig');
            if (!is_string($manifestRaw) || !is_string($signatureRaw)) {
                return ['ok' => false, 'error' => $this->label('addons.error_manifest_missing')];
            }
            if (strlen($manifestRaw) > self::MAX_MANIFEST_BYTES) {
                return ['ok' => false, 'error' => $this->label('addons.error_manifest_missing')];
            }
            $publicKey = AddonKeys::publicKey('package');
            $signature = AddonKeys::b64uDecode($signatureRaw);
            if ($publicKey === '' || $signature === '') {
                return ['ok' => false, 'error' => $this->label('addons.error_key_missing')];
            }
            try {
                if (!sodium_crypto_sign_verify_detached($signature, $manifestRaw, $publicKey)) {
                    return ['ok' => false, 'error' => $this->label('addons.error_signature')];
                }
            } catch (SodiumException) {
                return ['ok' => false, 'error' => $this->label('addons.error_signature')];
            }
            $decoded = json_decode($manifestRaw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => $this->label('addons.error_manifest_invalid')];
            }
            $manifest = $this->normalizeManifest($decoded);
            if ($manifest === null) {
                return ['ok' => false, 'error' => $this->label('addons.error_manifest_invalid')];
            }
            if (version_compare($manifest['min_core'], $this->currentCore(), '>')) {
                return [
                    'ok' => false,
                    'error' => $this->label('addons.error_min_core', ['min' => $manifest['min_core'], 'core' => $this->currentCore()]),
                ];
            }
            foreach ($manifest['files'] as $path => $hash) {
                $bytes = $zip->getFromName($path);
                if (!is_string($bytes) || !hash_equals($hash, hash('sha256', $bytes))) {
                    return ['ok' => false, 'error' => $this->label('addons.error_file_hash', ['file' => $path])];
                }
            }
            if ($zip->numFiles > count($manifest['files']) + 2) {
                return ['ok' => false, 'error' => $this->label('addons.error_extra_files')];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === 'addon.json' || $name === 'addon.json.sig') {
                    continue;
                }
                if (!isset($manifest['files'][$name])) {
                    return ['ok' => false, 'error' => $this->label('addons.error_extra_files')];
                }
            }

            return [
                'ok' => true,
                'manifest' => $manifest,
                'sha256' => (string) hash_file('sha256', $zipPath),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Extract a validated package under storage/addons/<id>/<version>/.
     *
     * @param array<string,mixed> $manifest
     * @return array{ok:bool,error?:string,path?:string,replaced?:bool}
     */
    public function install(string $zipPath, array $manifest): array
    {
        $id = (string) ($manifest['id'] ?? '');
        $version = (string) ($manifest['version'] ?? '');
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        if ($id === '' || $version === '' || $files === []) {
            return ['ok' => false, 'error' => $this->label('addons.error_manifest_invalid')];
        }
        $base = $this->installRoot() . '/' . $id;
        $target = $base . '/' . $version;
        $temp = $this->installRoot() . '/.tmp/' . $id . '-' . bin2hex(random_bytes(6));
        if (!is_dir($temp) && !@mkdir($temp, 0750, true) && !is_dir($temp)) {
            return ['ok' => false, 'error' => $this->label('addons.error_storage')];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            $this->removeDir($temp);
            return ['ok' => false, 'error' => $this->label('addons.error_package_invalid')];
        }
        try {
            $phpFiles = [];
            foreach ($files as $path => $expectedHash) {
                $bytes = $zip->getFromName((string) $path);
                if (!is_string($bytes) || !hash_equals((string) $expectedHash, hash('sha256', $bytes))) {
                    $this->removeDir($temp);
                    return ['ok' => false, 'error' => $this->label('addons.error_file_hash', ['file' => (string) $path])];
                }
                $destination = $temp . '/' . $path;
                if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0750, true) && !is_dir(dirname($destination))) {
                    $this->removeDir($temp);
                    return ['ok' => false, 'error' => $this->label('addons.error_storage')];
                }
                if (file_put_contents($destination, $bytes, LOCK_EX) === false) {
                    $this->removeDir($temp);
                    return ['ok' => false, 'error' => $this->label('addons.error_storage')];
                }
                @chmod($destination, 0640);
                if (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) === 'php') {
                    $phpFiles[] = $destination;
                }
            }
            foreach ($phpFiles as $phpFile) {
                $lint = $this->lint($phpFile);
                if ($lint !== '') {
                    $this->removeDir($temp);
                    return ['ok' => false, 'error' => $this->label('addons.error_lint', ['file' => basename($phpFile), 'detail' => $lint])];
                }
            }

            $replaced = false;
            $backup = $target . '.bak-' . date('YmdHis');
            if (is_dir($target) && !@rename($target, $backup)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => $this->label('addons.error_storage')];
            }
            if (is_dir($backup)) {
                $replaced = true;
            }
            if (!is_dir($base) && !@mkdir($base, 0750, true) && !is_dir($base)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => $this->label('addons.error_storage')];
            }
            if (!@rename($temp, $target)) {
                if ($replaced) {
                    @rename($backup, $target);
                }
                $this->removeDir($temp);
                return ['ok' => false, 'error' => $this->label('addons.error_storage')];
            }
            if ($replaced) {
                $this->removeDir($backup);
            }

            return ['ok' => true, 'path' => $target, 'replaced' => $replaced];
        } finally {
            $zip->close();
        }
    }

    /**
     * Translation with a safe fallback: the CLI tooling runs without a booted app.
     *
     * @param array<string,string> $replace
     */
    private function label(string $key, array $replace = []): string
    {
        try {
            return (string) __($key, $replace);
        } catch (\Throwable) {
            return $key;
        }
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{id:string,name:string,version:string,min_core:string,entry:string,files:array<string,string>}|null
     */
    private function normalizeManifest(array $raw): ?array
    {
        $id = trim((string) ($raw['id'] ?? ''));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1) {
            return null;
        }
        $version = trim((string) ($raw['version'] ?? ''));
        if (preg_match('/^\d+\.\d+\.\d+(?:[-.+][A-Za-z0-9.-]+)?$/', $version) !== 1) {
            return null;
        }
        $minCore = trim((string) ($raw['min_core'] ?? '1.0.0')) ?: '1.0.0';
        if (preg_match('/^\d+(?:\.\d+){0,3}$/', $minCore) !== 1) {
            return null;
        }
        $entry = trim((string) ($raw['entry'] ?? 'plugin.php')) ?: 'plugin.php';
        if (!$this->safeRelative($entry) || strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }
        $filesRaw = $raw['files'] ?? null;
        if (!is_array($filesRaw) || $filesRaw === []) {
            return null;
        }
        $files = [];
        foreach ($filesRaw as $path => $hash) {
            $path = (string) $path;
            $hash = strtolower(trim((string) $hash));
            if (!$this->safeRelative($path) || in_array($path, ['addon.json', 'addon.json.sig'], true)) {
                return null;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                return null;
            }
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                return null;
            }
            $files[$path] = $hash;
        }
        if (count($files) > self::MAX_FILES || !isset($files[$entry])) {
            return null;
        }
        ksort($files);

        return [
            'id' => $id,
            'name' => mb_substr(trim((string) ($raw['name'] ?? $id)), 0, 120),
            'version' => $version,
            'min_core' => $minCore,
            'entry' => $entry,
            'files' => $files,
        ];
    }

    private function safeRelative(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }
        if (preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            return false;
        }
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $path) !== 1) {
            return false;
        }

        return true;
    }

    private function currentCore(): string
    {
        if (function_exists('app_version')) {
            $version = trim(app_version());
            if ($version !== '') {
                return $version;
            }
        }
        $file = $this->root . '/VERSION';
        $version = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : '0.0.0';
    }

    private function lint(string $file): string
    {
        if (!function_exists('exec')) {
            // Hardened hosting: the package is already signed, lint is a safety net, not the boundary.
            return '';
        }
        $out = [];
        $code = 0;
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        @exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        if ($code === 0) {
            return '';
        }
        $detail = trim(implode(' ', $out));

        return mb_substr($detail !== '' ? $detail : 'syntax error', 0, 240);
    }

    /** @return list<string> */
    public function versions(string $id): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1) {
            return [];
        }
        $base = $this->installRoot() . '/' . $id;
        if (!is_dir($base)) {
            return [];
        }
        $versions = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_contains($entry, '.bak-')) {
                continue;
            }
            if (is_dir($base . '/' . $entry)) {
                $versions[] = $entry;
            }
        }
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        return $versions;
    }

    public function removeVersion(string $id, string $version): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1
            || preg_match('/^\d+\.\d+\.\d+(?:[-.+][A-Za-z0-9.-]+)?$/', $version) !== 1) {
            return;
        }
        $this->removeDir($this->installRoot() . '/' . $id . '/' . $version);
    }

    public function removeAll(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{2,80}$/', $id) !== 1) {
            return;
        }
        $this->removeDir($this->installRoot() . '/' . $id);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
