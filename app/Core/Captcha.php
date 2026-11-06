<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 图形验证码（对应需求 4.7 后台登录安全）。
 * 优先使用 GD 输出 PNG；服务器未装 GD 时降级为 SVG，保证后台始终可登录。
 */
class Captcha
{
    private const SESSION_KEY = '_captcha';
    private const CODE_LENGTH = 4;
    private const TTL         = 300;

    /** 排除易混淆字符 0/O/1/I/l */
    private const CHARSET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * 输出验证码图片并结束请求。
     *
     * @param string $namespace 命名空间：后台登录用默认，前台员工反馈用 'feedback'，
     *                          避免同一会话内两处验证码互相覆盖。
     */
    public static function output(string $namespace = ''): void
    {
        $code = self::randomCode();
        $_SESSION[self::sessionKey($namespace)] = [
            'code'    => strtoupper($code),
            'expires' => time() + self::TTL,
        ];

        if (function_exists('imagecreatetruecolor')) {
            self::outputPng($code);
            return;
        }
        self::outputSvg($code);
    }

    /**
     * 校验验证码（一次性，校验后立即销毁）。
     */
    public static function verify(?string $input, string $namespace = ''): bool
    {
        $key    = self::sessionKey($namespace);
        $stored = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        if (!is_array($stored) || !isset($stored['code'], $stored['expires'])) {
            return false;
        }
        if ((int) $stored['expires'] < time()) {
            return false;
        }
        if ($input === null || trim($input) === '') {
            return false;
        }
        return strtoupper(trim($input)) === (string) $stored['code'];
    }

    private static function sessionKey(string $namespace): string
    {
        return $namespace === '' ? self::SESSION_KEY : self::SESSION_KEY . '_' . $namespace;
    }

    private static function randomCode(): string
    {
        $charset = self::CHARSET;
        $max     = strlen($charset) - 1;
        $code    = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $charset[random_int(0, $max)];
        }
        return $code;
    }

    private static function outputPng(string $code): void
    {
        $width  = 120;
        $height = 40;

        $image = imagecreatetruecolor($width, $height);
        $bg    = imagecolorallocate($image, 245, 247, 250);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        // 干扰点
        for ($i = 0; $i < 120; $i++) {
            $color = imagecolorallocate($image, random_int(180, 220), random_int(180, 220), random_int(180, 220));
            imagesetpixel($image, random_int(0, $width), random_int(0, $height), $color);
        }

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $color = imagecolorallocate($image, random_int(150, 200), random_int(150, 200), random_int(150, 200));
            imageline(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $color
            );
        }

        $length = strlen($code);
        $step   = (int) floor(($width - 20) / $length);
        for ($i = 0; $i < $length; $i++) {
            $color = imagecolorallocate($image, random_int(20, 90), random_int(60, 120), random_int(120, 180));
            $x     = 12 + $i * $step;
            $y     = random_int(10, 22);
            imagestring($image, 5, $x, $y, $code[$i], $color);
        }

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        imagepng($image);
        imagedestroy($image);
    }

    private static function outputSvg(string $code): void
    {
        $length = strlen($code);
        $chars  = '';
        for ($i = 0; $i < $length; $i++) {
            $x      = 18 + $i * 24;
            $y      = random_int(24, 30);
            $rotate = random_int(-18, 18);
            $chars .= sprintf(
                '<text x="%d" y="%d" transform="rotate(%d %d %d)" font-family="monospace" font-size="22" font-weight="700" fill="#185FA5">%s</text>',
                $x,
                $y,
                $rotate,
                $x,
                $y,
                e($code[$i])
            );
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40" viewBox="0 0 120 40">'
            . '<rect width="120" height="40" fill="#F5F7FA"/>'
            . '<line x1="0" y1="12" x2="120" y2="30" stroke="#CBD5E1" stroke-width="1"/>'
            . '<line x1="0" y1="32" x2="120" y2="8" stroke="#CBD5E1" stroke-width="1"/>'
            . $chars
            . '</svg>';

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $svg;
    }
}
