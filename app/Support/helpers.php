<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Setting;

/**
 * 全局辅助函数。保持精简，只放模板与控制器高频使用的能力。
 */

if (!function_exists('e')) {
    /**
     * HTML 转义输出，所有模板变量一律经此函数输出。
     *
     * @param mixed $value
     */
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    /**
     * 站点根路径（自动适配子目录部署）。
     */
    function base_url(string $path = ''): string
    {
        static $base = null;
        if ($base === null) {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $dir    = rtrim(dirname($script), '/');
            // 入口位于 admin/ 或 install/ 时，向上回退一级才是站点根
            if (preg_match('#/(admin|install)$#', $dir)) {
                $dir = rtrim(dirname($dir), '/');
            }
            $base = ($dir === '' || $dir === '.') ? '' : $dir;
        }
        $path = ltrim($path, '/');
        return $path === '' ? ($base === '' ? '/' : $base . '/') : $base . '/' . $path;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return base_url('admin/' . ltrim($path, '/'));
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $rel = 'static/' . ltrim($path, '/');

        // 版本号 = 应用版本 + 文件修改时间：文件一更新 URL 自动变化，
        // 浏览器/内网代理缓存立即失效，避免「改了样式页面没变化」。
        $version = MES_KB_VERSION;
        $file = dirname(__DIR__, 2) . '/' . $rel;
        if (is_file($file)) {
            $version .= '.' . (string) filemtime($file);
        }

        return base_url($rel . '?v=' . $version);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('setting')) {
    /**
     * 读取系统配置项。
     *
     * @param mixed $default
     * @return mixed
     */
    function setting(string $key, $default = null)
    {
        return Setting::get($key, $default);
    }
}

if (!function_exists('auth')) {
    function auth(): Auth
    {
        return Auth::instance();
    }
}

if (!function_exists('flash')) {
    /**
     * 写入 / 读取一次性提示消息。
     */
    function flash(?string $type = null, ?string $message = null): ?array
    {
        if ($type !== null) {
            $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
            return null;
        }
        if (!isset($_SESSION['_flash'])) {
            return null;
        }
        $payload = $_SESSION['_flash'];
        unset($_SESSION['_flash']);
        return $payload;
    }
}

if (!function_exists('old')) {
    /**
     * 表单回填值。
     *
     * @param mixed $default
     * @return mixed
     */
    function old(string $key, $default = '')
    {
        $value = $_SESSION['_old'][$key] ?? $default;
        return $value;
    }
}

if (!function_exists('human_size')) {
    function human_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $size  = (float) $bytes;
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }
        return ($index === 0 ? (string) (int) $size : number_format($size, 1)) . ' ' . $units[$index];
    }
}

if (!function_exists('case_status_map')) {
    /**
     * 案例状态字典（对应需求 4.3 案例状态管理）。
     *
     * @return array<string,string>
     */
    function case_status_map(): array
    {
        return [
            'pending'   => '待审核',
            'published' => '已发布',
            'offline'   => '已下架',
            'expired'   => '已过期',
        ];
    }
}

if (!function_exists('case_status_label')) {
    function case_status_label(string $status): string
    {
        $map = case_status_map();
        return $map[$status] ?? $status;
    }
}

if (!function_exists('doc_type_map')) {
    /**
     * 文档类型字典（线缆专属改版：筛选维度3）。
     *
     * @return array<string,string>
     */
    function doc_type_map(): array
    {
        return [
            'case'      => '故障案例',
            'manual'    => '系统操作手册',
            'changelog' => 'MES版本更新日志',
        ];
    }
}

if (!function_exists('doc_type_label')) {
    function doc_type_label(string $type): string
    {
        $map = doc_type_map();
        return $map[$type] ?? ($type === '' ? '故障案例' : $type);
    }
}

if (!function_exists('url_map_default_json')) {
    /**
     * 顶部 URL 地图出厂默认值（导航栏优化：四大工序作业系统快捷入口）。
     */
    function url_map_default_json(): string
    {
        return json_encode([
            ['name' => '绞线', 'home' => 'http://mes.example.com/', 'work' => 'http://mes.example.com/order/operate'],
            ['name' => '绝缘', 'home' => 'http://mes.example.com/', 'work' => 'http://mes.example.com/order/operate'],
            ['name' => '成缆', 'home' => 'http://mes.example.com/', 'work' => 'http://mes.example.com/order/operate'],
            ['name' => '护套', 'home' => 'http://mes.example.com/', 'work' => 'http://mes.example.com/order/operate'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('url_map_json_resolved')) {
    /**
     * 顶部 URL 地图的「可编辑 JSON 文本」。
     *
     * 历史缺陷（2026-09-12 修复）：早期版本保存系统配置时未从表单读取 nav_url_map，
     * 导致 nav.url_map 被写成字符串 'null' —— 前台「作业系统直达」入口条整体消失，
     * 后台配置框显示 null。此处对损坏值回退出厂默认配置，保证入口条不再凭空丢失；
     * 返回空字符串仍表示管理员主动关闭该入口条。
     */
    function url_map_json_resolved(): string
    {
        $raw = trim((string) setting('nav.url_map', ''));
        if ($raw === '') {
            return '';
        }
        return is_array(json_decode($raw, true)) ? $raw : url_map_default_json();
    }
}

if (!function_exists('url_map_items')) {
    /**
     * 解析顶部 URL 地图（settings.nav.url_map，JSON 数组）。
     * 每项：{name: 工序名, home: 主页地址, work: 作业页面地址}；
     * name 必填，home/work 至少一项且必须是 http(s) 地址，非法项自动剔除。
     *
     * @return array<int,array{name:string,home:string,work:string}>
     */
    function url_map_items(): array
    {
        $raw = url_map_json_resolved();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $home = trim((string) ($entry['home'] ?? ''));
            $work = trim((string) ($entry['work'] ?? ''));
            if ($home !== '' && preg_match('#^https?://#i', $home) !== 1) {
                $home = '';
            }
            if ($work !== '' && preg_match('#^https?://#i', $work) !== 1) {
                $work = '';
            }
            if ($home === '' && $work === '') {
                continue;
            }
            $items[] = ['name' => $name, 'home' => $home, 'work' => $work];
        }
        return $items;
    }
}

if (!function_exists('feedback_status_map')) {
    /**
     * 员工反馈状态字典。
     *
     * @return array<string,string>
     */
    function feedback_status_map(): array
    {
        return [
            'pending'    => '待受理',
            'processing' => '处理中',
            'resolved'   => '已解决',
            'closed'     => '已关闭',
        ];
    }
}

if (!function_exists('feedback_status_label')) {
    function feedback_status_label(string $status): string
    {
        return feedback_status_map()[$status] ?? $status;
    }
}

if (!function_exists('feedback_urgency_map')) {
    /**
     * 紧急程度字典（列表排序：urgent > high > normal > low）。
     *
     * @return array<string,string>
     */
    function feedback_urgency_map(): array
    {
        return [
            'urgent' => '紧急',
            'high'   => '较高',
            'normal' => '一般',
            'low'    => '较低',
        ];
    }
}

if (!function_exists('feedback_urgency_label')) {
    function feedback_urgency_label(string $urgency): string
    {
        return feedback_urgency_map()[$urgency] ?? '一般';
    }
}

if (!function_exists('feedback_departments')) {
    /**
     * 归属部门候选（后台系统配置维护，每行一个）。
     *
     * @return array<int,string>
     */
    function feedback_departments(): array
    {
        $raw = (string) setting('feedback.departments', '');
        $items = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }
        return $items === [] ? ['生产部', '质量部', '设备部', '其它'] : $items;
    }
}

if (!function_exists('feedback_enabled')) {
    /**
     * 前台反馈入口是否开启（后台系统配置）。
     */
    function feedback_enabled(): bool
    {
        return setting('feedback.enabled', '1') !== '0';
    }
}

if (!function_exists('role_map')) {
    /**
     * 角色字典（对应需求 4.1 分级权限体系）。
     *
     * @return array<string,string>
     */
    function role_map(): array
    {
        return [
            'super_admin' => '超级管理员',
            'op_admin'    => '运维管理员',
            'guest'       => '普通访客',
            'gm'          => '只读',
        ];
    }
}

if (!function_exists('role_label')) {
    function role_label(string $role): string
    {
        $map = role_map();
        return $map[$role] ?? $role;
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $parts = explode(',', (string) $_SERVER[$key]);
            $ip    = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
        return '0.0.0.0';
    }
}

if (!function_exists('highlight_keyword')) {
    /**
     * 检索结果关键词高亮（输出已转义，可安全直出）。
     */
    function highlight_keyword(string $text, string $keyword): string
    {
        $safe = e($text);
        if ($keyword === '') {
            return $safe;
        }
        $pattern = '/' . preg_quote(e($keyword), '/') . '/iu';
        return (string) preg_replace($pattern, '<mark>$0</mark>', $safe);
    }
}

if (!function_exists('excerpt')) {
    /**
     * 按字符截取摘要（UTF-8 安全）。富文本内容会先剥掉 HTML 标签再截取。
     */
    function excerpt(string $text, int $length = 80): string
    {
        $text = strip_tags($text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length, 'UTF-8') . '…';
    }
}

if (!function_exists('plain_text')) {
    /**
     * 富文本 → 安全纯文本摘要。
     *
     * 与 excerpt() 的区别：先还原 HTML 实体再剥标签。
     * 后台富文本编辑器会把 `>` `<` `&` 分别存成 `&gt;` `&lt;` `&amp;`，
     * 若直接 strip_tags() 再 e() 输出，会二次转义成 `&amp;gt;`，
     * 页面上就会看到字面量「&gt;」而不是「>」。此函数负责先解码再交给 e()。
     *
     * 用法：`<?= e(plain_text((string) $row['content'], 80)) ?>`
     */
    function plain_text(string $text, int $length = 80): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return excerpt($text, $length);
    }
}

if (!function_exists('case_is_new')) {
    /**
     * 判断案例是否为「新案例」（默认按配置 case.new_days，未配置为 1 天）。
     * 以发布时间为准，未发布时回退到创建时间。
     *
     * 窗口按 24 小时滚动计算：days=1 即「发布超过 24 小时自动取消 NEW 标识」。
     *
     * @param array<string,mixed> $case
     */
    function case_is_new(array $case, int $days = 0): bool
    {
        if ($days <= 0) {
            $days = (int) setting('case.new_days', 1);
        }
        if ($days <= 0) {
            return false;
        }

        $timestamp = 0;
        foreach (['published_at', 'created_at'] as $field) {
            $value = (string) ($case[$field] ?? '');
            if ($value !== '' && $value !== '0000-00-00 00:00:00') {
                $timestamp = (int) strtotime($value);
                break;
            }
        }
        if ($timestamp <= 0) {
            return false;
        }

        return $timestamp >= time() - $days * 86400;
    }
}

if (!function_exists('new_badge')) {
    /**
     * NEW 标识 HTML（醒目角标，仅内容可信，可直接输出）。
     */
    function new_badge(): string
    {
        return '<span class="badge-new" title="近期新增案例">NEW</span>';
    }
}

if (!function_exists('rich_html')) {
    /**
     * 安全输出案例内容：富文本经白名单净化后直出；旧纯文本按换行转 <br>。
     */
    function rich_html(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        if (\App\Core\HtmlSanitizer::looksLikeHtml($content)) {
            return \App\Core\HtmlSanitizer::clean($content);
        }
        return nl2br(e($content));
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
