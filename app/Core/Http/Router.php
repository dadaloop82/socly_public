<?php

declare(strict_types=1);

namespace Socly\Core\Http;

use Socly\Core\App;
use Socly\Core\View;

final class Router
{
    /** @var list<array{method:string,pattern:string,action:callable|array,permission:?string,middleware:list<string>}> */
    private array $routes = [];

    public function __construct(private readonly App $app)
    {
    }

    public function get(string $pattern, callable|array $action, ?string $permission = null, array $middleware = []): void
    {
        $this->add('GET', $pattern, $action, $permission, $middleware);
    }

    public function post(string $pattern, callable|array $action, ?string $permission = null, array $middleware = []): void
    {
        $this->add('POST', $pattern, $action, $permission, $middleware);
    }

    public function add(string $method, string $pattern, callable|array $action, ?string $permission = null, array $middleware = []): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'action' => $action,
            'permission' => $permission,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->path());
            if ($params === null) {
                continue;
            }

            foreach ($route['middleware'] as $mw) {
                $instance = $this->app->get($mw);
                $result = $instance->handle($request);
                if ($result !== true) {
                    return;
                }
            }

            if ($route['permission'] !== null && !can($route['permission'])) {
                try {
                    app('logger')->anomaly('http.forbidden', [
                        'path' => $request->path(),
                        'permission' => $route['permission'],
                        'user_id' => auth_user()['id'] ?? null,
                    ]);
                } catch (\Throwable) {
                }
                http_response_code(403);
                echo $this->app->get(View::class)->render('errors/403', [], 'layouts/app');
                return;
            }

            $action = $route['action'];
            if (is_array($action)) {
                [$class, $method] = $action;
                $controller = $this->app->get($class);
                $controller->{$method}($request, ...array_values($params));
                return;
            }
            $action($request, ...array_values($params));
            return;
        }

        http_response_code(404);
        try {
            app('logger')->anomaly('http.not_found', [
                'path' => $request->path(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable) {
        }
        echo $this->app->get(View::class)->render('errors/404', [], 'layouts/app');
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }
        $params = [];
        foreach ($matches as $key => $value) {
            if (!is_int($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }
}
