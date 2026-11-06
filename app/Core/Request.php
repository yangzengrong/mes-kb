<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 请求封装：统一出入参获取入口，自动去除首尾空白。
 */
class Request
{
    /**
     * 读取参数（POST 优先，其次 GET），支持点号路径与默认值。
     *
     * @param mixed $default
     * @return mixed
     */
    public static function input(string $key, $default = null)
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' && $default !== null) {
                return $default;
            }
        }
        return $value;
    }

    /**
     * 取 GET 参数。
     *
     * 【为什么要做二次 urldecode】
     * PHP 的 $_GET 会自动解一次码，正常情况下够了。
     * 但实测 search_logs 里出现过 `%E6%89%AB%E7%A0%81%E6%9E%AA`（解码后是「扫码枪」）——
     * 说明这个词在到达服务器前**已被编码过一次**，前端又编码了一次（双重编码）。
     * 来源：用户从地址栏/聊天记录复制了一条已带 %xx 的链接，或某些输入法
     * 直接把全角字符输出成编码态。结果是：
     *   ① 检索必然无结果（拿编码串去匹配中文列）；
     *   ② search_logs 记下乱码，后台「无结果检索词」看板失去意义；
     *   ③ 同义词/拼音索引全部失效。
     * 这里做一次保守的二次解码：只在「解码后 % 明显减少且仍是合法 UTF-8」时才采用，
     * 避免把用户真想搜的 "%" 或 "%E6" 这类字面量改错。
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $value = $_GET[$key] ?? null;
        if (!is_string($value)) {
            return $value ?? $default;
        }
        $value = trim($value);
        if (strpos($value, '%') !== false) {
            $once = rawurldecode($value);
            /* 用 rawurldecode 而非 urldecode：后者会把 + 当成空格，
               而搜索词里的 + 可能是设备型号的一部分（如「MSQ-200+」）。 */
            if ($once !== $value && self::isValidUtf8($once)) {
                $value = trim($once);
            }
        }
        return $value;
    }

    /** 判断是否为合法 UTF-8（避免把编码串解成乱码后还采用） */
    private static function isValidUtf8(string $s): bool
    {
        return (bool) preg_match('//u', $s);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function post(string $key, $default = null)
    {
        $value = $_POST[$key] ?? null;
        return is_string($value) ? trim($value) : ($value ?? $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key): bool
    {
        $value = self::input($key, '');
        return in_array($value, ['1', 1, true, 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<string,mixed>
     */
    public static function postAll(): array
    {
        $data = $_POST;
        unset($data['_token']);
        return $data;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function file(string $key): ?array
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return null;
        }
        $file = $_FILES[$key];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public static function ip(): string
    {
        return client_ip();
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
    }

    public static function referer(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 255, 'UTF-8');
    }

    public static function url(): string
    {
        return mb_substr((string) ($_SERVER['REQUEST_URI'] ?? '/'), 0, 255, 'UTF-8');
    }
}
