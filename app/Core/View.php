<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * 视图渲染：views/{template}.php，可选套用布局文件。
 */
class View
{
    /** @var array<string,mixed> 布局共享变量 */
    private static $shared = [];

    /**
     * @param mixed $value
     */
    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = null): void
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            echo $content;
            return;
        }

        $data['content'] = $content;
        echo self::capture($layout, $data);
    }

    /**
     * 渲染并返回字符串，供控制器内部嵌套使用。
     *
     * @param array<string,mixed> $data
     */
    public static function capture(string $template, array $data = []): string
    {
        $file = VIEW_PATH . '/' . str_replace('.', '/', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('视图文件不存在：' . $template);
        }

        $payload = array_merge(self::$shared, $data);
        extract($payload, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /**
     * JSON 输出（后台异步操作统一响应格式）。
     *
     * @param array<string,mixed> $payload
     */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
