<?php

declare(strict_types=1);

namespace Socly\Core\Http;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $server,
        private readonly array $files
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Isolated instances may be mounted under a URL prefix (without /public).
        $urlPrefix = (string) ($_SERVER['SOCLY_URL_PREFIX'] ?? '');
        if ($urlPrefix !== '' && $urlPrefix !== '/' && str_starts_with($path, $urlPrefix)) {
            $path = substr($path, strlen($urlPrefix)) ?: '/';
        }

        $scriptName = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '/' && $scriptName !== '\\' && str_starts_with($path, $scriptName)) {
            $path = substr($path, strlen($scriptName)) ?: '/';
        }
        // DirectoryIndex may expose /index.php as the path
        if ($path === '/index.php' || str_ends_with($path, '/index.php')) {
            $path = '/';
        }
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        if ($path === '/index.php') {
            $path = '/';
        }
        return new self($method, $path === '' ? '/' : $path, $_GET, $_POST, $_SERVER, $_FILES);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->input($key);
        }
        return $out;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Nested file input e.g. fields[photo] → nestedFile('fields', 'photo').
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function nestedFile(string $bag, string $key): ?array
    {
        if (!isset($this->files[$bag]['name'][$key])) {
            return null;
        }
        return [
            'name' => (string) $this->files[$bag]['name'][$key],
            'type' => (string) ($this->files[$bag]['type'][$key] ?? ''),
            'tmp_name' => (string) ($this->files[$bag]['tmp_name'][$key] ?? ''),
            'error' => (int) ($this->files[$bag]['error'][$key] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($this->files[$bag]['size'][$key] ?? 0),
        ];
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }
}
