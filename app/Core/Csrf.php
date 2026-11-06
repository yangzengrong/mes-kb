<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSRF 令牌：所有写操作（POST）必须携带 _token。
 */
class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    public static function check(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return $expected !== '' && hash_equals((string) $expected, $token);
    }

    /**
     * 校验失败直接中断请求。
     */
    public static function verifyOrFail(): void
    {
        if (self::check($_POST['_token'] ?? null)) {
            return;
        }
        if (Request::isAjax()) {
            View::json(['code' => 419, 'message' => '页面令牌已失效，请刷新页面后重试。'], 419);
        }
        http_response_code(419);
        header('Content-Type: text/html; charset=utf-8');
        exit('页面令牌已失效（CSRF 校验未通过），请返回上一页刷新后重试。');
    }

    public static function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
