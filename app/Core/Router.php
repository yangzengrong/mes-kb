<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 极简路由：支持 {param} 占位与 GET / POST 两种方法。
 * 前台与后台各自维护一张路由表，互不干扰（对应需求 2.3 前后端路由隔离）。
 */
class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array<int,string>,handler:mixed}> */
    private $routes = [];

    /** @var string */
    private $basePath = '';

    /** @var array<int,array{pattern:string,handler:mixed}> */
    private $fallbacks = [];

    public function setBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * @param mixed $handler
     */
    public function get(string $pattern, $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param mixed $handler
     */
    public function post(string $pattern, $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * @param mixed $handler
     */
    public function any(string $pattern, $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    /**
     * 未匹配路由时的兜底处理器（例如前台 404 页面）。
     *
     * @param mixed $handler
     */
    public function fallback($handler): void
    {
        $this->fallbacks[] = ['pattern' => '*', 'handler' => $handler];
    }

    /**
     * @param mixed $handler
     */
    private function add(string $method, string $pattern, $handler): void
    {
        $params = [];
        $regex  = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * 解析当前请求并执行命中的处理器。
     */
    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = $this->currentPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            array_shift($matches);
            $arguments = [];
            foreach ($route['params'] as $index => $name) {
                $arguments[$name] = rawurldecode($matches[$index] ?? '');
            }
            $this->invoke($route['handler'], $arguments);
            return;
        }

        if ($this->fallbacks !== []) {
            $this->invoke($this->fallbacks[0]['handler'], []);
            return;
        }

        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '404 Not Found';
    }

    /**
     * 取当前请求路径（相对路由基准路径）。
     */
    public function currentPath(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($this->basePath !== '' && strpos($path, $this->basePath) === 0) {
            $path = substr($path, strlen($this->basePath));
        }
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @param mixed                $handler
     * @param array<string,string> $arguments
     */
    private function invoke($handler, array $arguments): void
    {
        if (is_callable($handler) && !is_array($handler)) {
            call_user_func($handler, $arguments);
            return;
        }
        if (is_array($handler) && count($handler) === 2) {
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->{$action}($arguments);
            return;
        }
        throw new \RuntimeException('无法识别的路由处理器。');
    }
}
