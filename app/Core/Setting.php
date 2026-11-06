<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 系统基础配置（对应需求 4.9 系统基础配置模块）。
 * 存于 settings 表，进程内缓存，避免每页重复查库；后台改动后调用 flush() 清缓存。
 */
class Setting
{
    /** @var array<string,string>|null */
    private static $cache = null;

    /**
     * 出厂默认值。安装时写入数据库，后台可覆盖。
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            // 网站基础设置
            'site.name'                => 'MES系统知识库',
            'site.intro'               => '沉淀生产MES系统各工序疑难故障处置经验：系统参数、数据采集、物料消耗、标签打印、上下料、工单/委外/辐照/质检异常等，支撑运维人员快速定位与复用解决方案。',
            'site.copyright'           => '©Yangzengrong 版权所有',
            'site.notice'              => '本知识库案例均来自一线运维实践，请按现场实际情况核对后使用。',
            // 文件上传配置
            'upload.max_size_mb'       => '20',
            'upload.allowed_ext'       => 'doc,docx,pdf,png,jpg,jpeg,txt,log,xls,xlsx',
            // 首页展示配置
            'home.latest_count'        => '9',
            'home.hot_count'           => '8',
            'home.sort'                => 'latest',
            'home.notice_enabled'      => '1',
            // 安全防护
            'security.ip_restrict'     => '0',
            'security.ip_whitelist'    => '',
            // 反向代理信任（默认关闭）。
            // 内网直连部署（Nginx → php-fpm unix socket）时 REMOTE_ADDR 即真实来源，
            // 无需采信任何可伪造的转发头；仅当确实架在反向代理后面时才把
            // trust_proxy_header 置 1，并在 trusted_proxies 登记可信代理网段。
            // 详见 helpers.php 的 client_ip()。
            'security.trust_proxy_header' => '0',
            'security.trusted_proxies'    => '',
            // 案例生命周期
            'case.expire_days'         => '0',
            'case.auto_approve'        => '0',
            // 顶部导航 URL 地图（JSON：[{name,home,work}]，后台系统配置可编辑）
            'nav.url_map'              => url_map_default_json(),
            // 员工反馈（前台提交 MES 疑难问题）
            'feedback.enabled'         => '1',
            'feedback.departments'     => "行政综合科\n制造科\n业务科\n销售科\n品保科\n物控科\n仓库\n财务科",
            'feedback.limit_per_hour'  => '5',
            'feedback.tip'             => '遇到 MES 系统疑难问题？在这里提交，运维收到后会给出解决方案并沉淀到知识库。',
            // 反馈页横幅广告位（标题下方、表单上方；两项均留空则不展示，后台系统配置可维护）
            'feedback.banner_title'    => '把你在 MES 里遇到的疑难问题告诉我们。填得越具体，运维定位越快；处理结论会沉淀进知识库。',
            'feedback.banner_desc'     => '遇到 MES 系统疑难问题？在这里提交，运维收到后会给出解决方案并沉淀到知识库。',
            // 分享到微信（2026-10-17）。
            // 注意：微信「分享到朋友圈」不允许网页程序触发，只能生成卡片图让用户长按转发；
            // 下面两个 wechat_* 是为将来外网 + 认证公众号就位后接入 JS-SDK 预留的，
            // 现在留空即可，不影响「长按图片转发」这条路。
            'share.enabled'            => '1',
            'share.card_title'         => '',   // 卡片副标题，留空则用站点名称
            'share.card_hint'          => '扫码或长按图片转发',
            'share.wechat_appid'       => '',
            'share.wechat_secret'      => '',
        ];
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        if ($default !== null) {
            return $default;
        }
        $defaults = self::defaults();
        return $defaults[$key] ?? null;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];
        try {
            $rows = Database::select('SELECT skey, svalue FROM settings');
            foreach ($rows as $row) {
                $values[(string) $row['skey']] = (string) $row['svalue'];
            }
        } catch (\Throwable $e) {
            $values = [];
        }

        self::$cache = array_merge(self::defaults(), $values);
        return self::$cache;
    }

    /**
     * 批量写入（后台系统配置表单提交时使用）。
     *
     * @param array<string,mixed> $pairs
     */
    public static function putMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            self::put((string) $key, is_scalar($value) ? (string) $value : '');
        }
        self::flush();
    }

    public static function put(string $key, string $value): void
    {
        $exists = Database::scalar('SELECT COUNT(*) FROM settings WHERE skey = :k', ['k' => $key]);
        if ((int) $exists > 0) {
            Database::execute(
                'UPDATE settings SET svalue = :v, updated_at = :t WHERE skey = :k',
                ['v' => $value, 't' => now(), 'k' => $key]
            );
        } else {
            Database::insert('settings', [
                'skey'       => $key,
                'svalue'     => $value,
                'updated_at' => now(),
            ]);
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
