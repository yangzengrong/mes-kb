<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 后台入口防火墙（需求 4.7 IP访问限制）。
 * 支持单个 IP 与 CIDR 网段，例如：192.168.90.216、192.168.90.0/24。
 */
class Firewall
{
    /**
     * 校验当前访问 IP；不在白名单内则直接中断。
     */
    public static function enforceAdmin(): void
    {
        if (!Setting::bool('security.ip_restrict', false)) {
            return;
        }

        $whitelist = self::whitelist();
        if ($whitelist === []) {
            return;
        }

        if (self::ipAllowed(client_ip(), $whitelist)) {
            return;
        }

        Logger::operation('security.ip_blocked', 'system', null,
            'IP 不在白名单，拒绝访问后台：' . client_ip());

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        exit('<h3>403 访问被拒绝</h3><p>当前 IP（' . e(client_ip())
            . '）不在后台访问白名单内，请联系超级管理员添加后再试。</p>');
    }

    /**
     * @return array<int,string>
     */
    public static function whitelist(): array
    {
        $raw = (string) Setting::get('security.ip_whitelist', '');
        $items = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * IP 是否命中白名单。
     *
     * @param array<int,string> $whitelist
     */
    public static function ipAllowed(string $ip, array $whitelist): bool
    {
        foreach ($whitelist as $rule) {
            if ($rule === $ip) {
                return true;
            }
            if (strpos($rule, '/') === false) {
                continue;
            }

            $parts = explode('/', $rule, 2);
            if (count($parts) !== 2 || !ctype_digit($parts[1])) {
                continue;
            }
            [$network, $maskBits] = $parts;
            $maskBits = (int) $maskBits;
            if ($maskBits < 0 || $maskBits > 32) {
                continue;
            }

            $ipLong      = ip2long($ip);
            $networkLong = ip2long($network);
            if ($ipLong === false || $networkLong === false) {
                continue;
            }

            $maskLong = $maskBits === 0 ? 0 : (~((1 << (32 - $maskBits)) - 1) & 0xFFFFFFFF);
            if (($ipLong & $maskLong) === ($networkLong & $maskLong)) {
                return true;
            }
        }
        return false;
    }
}
