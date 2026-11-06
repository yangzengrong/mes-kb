<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 全局配置容器。
 * 站点可运维参数（网站名称、上传限制、首页展示规则等）走数据库 settings 表，
 * 这里只承载运行期基础设施配置（数据库、调试开关、加密盐等）。
 */
class Config
{
    /** @var array<string,mixed> */
    private static $items = [];

    public static function load(array $items): void
    {
        self::$items = $items;
    }

    /**
     * 点号取值：Config::get('database.host')
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }

    /**
     * 生成站点加密盐（首次安装时写入配置）。
     */
    public static function randomSalt(int $length = 32): string
    {
        try {
            return bin2hex(random_bytes($length / 2));
        } catch (\Exception $e) {
            return substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, $length);
        }
    }
}
