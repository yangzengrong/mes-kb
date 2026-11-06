<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Firewall;
use App\Core\Request;
use App\Core\Setting;
use App\Core\Upload;

/**
 * 系统基础配置（需求 4.9）+ 数据备份（需求 5.4）。
 */
class SettingController extends BaseController
{
    /** 允许通过后台修改的配置键，白名单防止越权写入 */
    private const EDITABLE = [
        'site.name', 'site.intro', 'site.copyright', 'site.notice',
        'upload.max_size_mb', 'upload.allowed_ext',
        'home.latest_count', 'home.hot_count', 'home.sort', 'home.notice_enabled',
        'security.ip_restrict', 'security.ip_whitelist',
        'case.expire_days', 'case.auto_approve', 'case.new_days',
        'nav.url_map', 'search.synonyms',
        'feedback.enabled', 'feedback.departments', 'feedback.limit_per_hour',
        'feedback.banner_title', 'feedback.banner_desc',
    ];

    public function index(): void
    {
        $this->guardSuper();

        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        $this->render('admin/settings', [
            'activeMenu'   => 'settings',
            'settings'     => Setting::all(),
            'errors'       => $errors,
            'backups'      => $this->listBackups(),
            'allowedExt'   => Upload::allowedExtensions(),
            'phpUploadMax' => (string) ini_get('upload_max_filesize'),
            'phpPostMax'   => (string) ini_get('post_max_size'),
            'dbVersion'    => (string) Database::scalar('SELECT VERSION()'),
            'appVersion'   => MES_KB_VERSION,
            'debug'        => (bool) Config::get('app.debug', false),
        ], '系统配置');
    }

    public function save(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $errors = [];

        $siteName = trim((string) Request::post('site_name', ''));
        if ($siteName === '') {
            $errors['site_name'] = '网站名称不能为空。';
        }

        $maxSize = Request::int('upload_max_size_mb', 20);
        if ($maxSize < 1 || $maxSize > 512) {
            $errors['upload_max_size_mb'] = '单文件体积上限需在 1 - 512 MB 之间。';
        }

        // 上传格式：仅保留系统支持且用户勾选的格式
        $submittedExt = $_POST['allowed_ext'] ?? [];
        $submittedExt = is_array($submittedExt) ? $submittedExt : [];
        $supported    = ['doc', 'docx', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'log', 'xls', 'xlsx'];
        $allowedExt   = array_values(array_intersect($supported, array_map('strtolower', $submittedExt)));
        if ($allowedExt === []) {
            $errors['allowed_ext'] = '至少需要允许一种文件格式。';
        }

        $latestCount = Request::int('home_latest_count', 9);
        $hotCount    = Request::int('home_hot_count', 8);
        if ($latestCount < 1 || $latestCount > 50) {
            $errors['home_latest_count'] = '最新案例展示数量需在 1 - 50 之间。';
        }
        if ($hotCount < 1 || $hotCount > 50) {
            $errors['home_hot_count'] = '热门案例展示数量需在 1 - 50 之间。';
        }

        $sort = (string) Request::post('home_sort', 'latest');
        if (!in_array($sort, ['latest', 'hot'], true)) {
            $sort = 'latest';
        }

        // IP 白名单格式校验
        $ipRestrict = Request::bool('security_ip_restrict') ? '1' : '0';
        $rawList    = (string) Request::post('security_ip_whitelist', '');
        $ipList     = [];
        foreach (preg_split('/[\s,;]+/', $rawList) ?: [] as $ip) {
            $ip = trim($ip);
            if ($ip === '') {
                continue;
            }
            // 支持单个 IP 与 CIDR 网段
            if (strpos($ip, '/') !== false) {
                [$network, $mask] = explode('/', $ip, 2);
                if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($mask)) {
                    $errors['security_ip_whitelist'] = '白名单中的网段「' . $ip . '」格式不正确。';
                    break;
                }
            } elseif (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $errors['security_ip_whitelist'] = '白名单中的 IP「' . $ip . '」格式不正确。';
                break;
            }
            $ipList[] = $ip;
        }
        if ($ipRestrict === '1' && $ipList === []) {
            $errors['security_ip_whitelist'] = '启用 IP 访问限制时，必须至少配置一个白名单地址。';
        }
        if ($ipRestrict === '1' && $ipList !== [] && !Firewall::ipAllowed(Request::ip(), $ipList)) {
            $errors['security_ip_whitelist'] = '当前访问 IP（' . Request::ip()
                . '）不在白名单内，启用后你将无法访问后台，请先把自己的 IP 加入白名单。';
        }

        $expireDays = Request::int('case_expire_days', 0);
        if ($expireDays < 0 || $expireDays > 3650) {
            $errors['case_expire_days'] = '默认有效期需在 0 - 3650 天之间（0 表示不过期）。';
        }

        $newDays = Request::int('case_new_days', 1);
        if ($newDays < 0 || $newDays > 365) {
            $errors['case_new_days'] = '新案例标识天数需在 0 - 365 天之间（1 = 24 小时，0 表示不显示 NEW 标识）。';
        }

        // 顶部导航入口配置：必须是合法 JSON 数组，非法则报错并保留原值
        // （历史缺陷：此前未读取表单值，保存系统配置会把入口条配置写坏）
        $rawUrlMap   = trim((string) Request::post('nav_url_map', ''));
        $urlMapItems = [];
        if ($rawUrlMap !== '') {
            $decoded = json_decode($rawUrlMap, true);
            if (!is_array($decoded)) {
                $errors['nav_url_map'] = '顶部导航入口配置需为合法的 JSON 数组。';
            } else {
                $urlMapItems = $decoded;
            }
        }

        // 检索同义词：每行一组「主词=别名1,别名2」，超过 100 组或单词过长时提示
        $synonymText = trim((string) Request::post('search_synonyms', ''));
        $synonymGroups = 0;
        foreach (preg_split('/\r\n|\r|\n/', $synonymText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos(str_replace('＝', '=', $line), '=') === false) {
                continue;
            }
            $synonymGroups++;
        }
        if ($synonymGroups > 100) {
            $errors['search_synonyms'] = '同义词最多 100 组，当前 ' . $synonymGroups . ' 组。';
        }

        // 员工反馈：开关 / 部门候选 / 同 IP 每小时提交上限 / 页面提示语
        $feedbackEnabled = Request::bool('feedback_enabled') ? '1' : '0';
        $feedbackDepartments = trim((string) Request::post('feedback_departments', ''));
        $departmentLines = [];
        foreach (preg_split('/\r\n|\r|\n/', $feedbackDepartments) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $departmentLines[] = mb_substr($line, 0, 30, 'UTF-8');
            }
        }
        if (count($departmentLines) > 60) {
            $errors['feedback_departments'] = '部门最多配置 60 个，当前 ' . count($departmentLines) . ' 个。';
        }
        $feedbackDepartments = implode("\n", $departmentLines);

        $feedbackLimit = Request::int('feedback_limit_per_hour', 5);
        if ($feedbackLimit < 1 || $feedbackLimit > 100) {
            $errors['feedback_limit_per_hour'] = '每小时提交上限需在 1 - 100 之间。';
        }
        $feedbackBannerTitle = trim((string) Request::post('feedback_banner_title', ''));
        if (mb_strlen($feedbackBannerTitle, 'UTF-8') > 60) {
            $errors['feedback_banner_title'] = '横幅标题不能超过 60 个字符。';
        }
        $feedbackBannerDesc = trim((string) Request::post('feedback_banner_desc', ''));
        if (mb_strlen($feedbackBannerDesc, 'UTF-8') > 200) {
            $errors['feedback_banner_desc'] = '横幅描述不能超过 200 个字符。';
        }

        // 分享到微信：开关 / 卡片副标题 / 卡片提示语 / JS-SDK 预留参数
        $shareEnabled   = Request::bool('share_enabled') ? '1' : '0';
        $shareCardTitle = trim((string) Request::post('share_card_title', ''));
        if (mb_strlen($shareCardTitle, 'UTF-8') > 60) {
            $errors['share_card_title'] = '卡片副标题不能超过 60 个字符。';
        }
        $shareCardHint = trim((string) Request::post('share_card_hint', ''));
        if (mb_strlen($shareCardHint, 'UTF-8') > 40) {
            $errors['share_card_hint'] = '卡片提示语不能超过 40 个字符（卡片幅面有限，长了会换行挤压二维码）。';
        }
        // appid / secret 只做格式提示，不做强校验 —— 现在留空，接了外网再填
        $shareAppid = trim((string) Request::post('share_wechat_appid', ''));
        if ($shareAppid !== '' && !preg_match('/^[A-Za-z0-9_-]{6,32}$/', $shareAppid)) {
            $errors['share_wechat_appid'] = 'AppID 格式不正确（应为 6-32 位字母数字）。';
        }
        $shareSecret = trim((string) Request::post('share_wechat_secret', ''));

        if ($errors !== []) {
            $_SESSION['_errors'] = $errors;
            redirect(admin_url('settings.php'));
        }

        Setting::putMany([
            'site.name'             => $siteName,
            'site.intro'            => (string) Request::post('site_intro', ''),
            'site.copyright'        => (string) Request::post('site_copyright', '©Yangzengrong 版权所有'),
            'site.notice'           => (string) Request::post('site_notice', ''),
            'upload.max_size_mb'    => (string) $maxSize,
            'upload.allowed_ext'    => implode(',', $allowedExt),
            'home.latest_count'     => (string) $latestCount,
            'home.hot_count'        => (string) $hotCount,
            'home.sort'             => $sort,
            'home.notice_enabled'   => Request::bool('home_notice_enabled') ? '1' : '0',
            'security.ip_restrict'  => $ipRestrict,
            'security.ip_whitelist' => implode(',', $ipList),
            'case.expire_days'      => (string) $expireDays,
            'case.auto_approve'     => Request::bool('case_auto_approve') ? '1' : '0',
            'case.new_days'         => (string) $newDays,
            'nav.url_map'           => $urlMapItems === [] ? '' : (string) json_encode($urlMapItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'search.synonyms'       => $synonymText,
            'feedback.enabled'      => $feedbackEnabled,
            'feedback.departments'  => $feedbackDepartments,
            'feedback.limit_per_hour' => (string) $feedbackLimit,
            'feedback.banner_title'   => $feedbackBannerTitle,
            'feedback.banner_desc'    => $feedbackBannerDesc,
            'share.enabled'         => $shareEnabled,
            'share.card_title'      => $shareCardTitle,
            'share.card_hint'       => $shareCardHint,
            'share.wechat_appid'    => $shareAppid,
            'share.wechat_secret'   => $shareSecret,
        ]);

        $this->log('setting.update', 'setting', null, '更新系统配置');
        $this->back('settings.php', 'success', '系统配置已保存并立即生效。');
    }

    /**
     * 一键备份数据库（需求 5.4）。
     */
    public function backup(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->back('settings.php', 'error', '备份目录创建失败，请检查 storage/backups 目录权限。');
        }

        try {
            $sql      = $this->dumpDatabase();
            $filename = 'mes_kb_' . date('Ymd_His') . '.sql';
            $path     = $dir . '/' . $filename;

            if (@file_put_contents($path, $sql) === false) {
                $this->back('settings.php', 'error', '备份文件写入失败，请检查目录权限。');
            }
            @chmod($path, 0640);

            $this->log('system.backup', 'system', null, '手动备份数据库：' . $filename);
            $this->back('settings.php', 'success',
                '数据库备份完成：' . $filename . '（' . human_size(strlen($sql)) . '）');
        } catch (\Throwable $e) {
            $this->back('settings.php', 'error', '备份失败：' . $e->getMessage());
        }
    }

    public function downloadBackup(): void
    {
        $this->guardSuper();

        $file = basename((string) Request::get('file', ''));
        $path = STORAGE_PATH . '/backups/' . $file;

        if ($file === '' || !is_file($path) || strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'sql') {
            $this->back('settings.php', 'error', '备份文件不存在。');
        }

        $this->log('system.backup_download', 'system', null, '下载备份文件：' . $file);

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function deleteBackup(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $file = basename((string) Request::post('file', ''));
        $path = STORAGE_PATH . '/backups/' . $file;

        if ($file === '' || !is_file($path)) {
            $this->back('settings.php', 'error', '备份文件不存在。');
        }
        if (@unlink($path)) {
            $this->log('system.backup_delete', 'system', null, '删除备份文件：' . $file);
            $this->back('settings.php', 'success', '备份文件「' . $file . '」已删除。');
        }
        $this->back('settings.php', 'error', '备份文件删除失败，请检查目录权限。');
    }

    // =========================================================
    // 内部方法
    // =========================================================

    /**
     * 生成数据库全量 SQL 文本（纯 PHP 实现，不依赖 mysqldump）。
     */
    private function dumpDatabase(): string
    {
        $pdo   = Database::connection();
        $lines = [];

        $lines[] = '-- MES系统知识库 数据库备份';
        $lines[] = '-- 生成时间：' . now();
        $lines[] = '-- 数据库：' . (string) Config::get('database.name', '');
        $lines[] = '';
        $lines[] = 'SET NAMES utf8mb4;';
        $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $lines[] = '';

        $tables = Database::select('SHOW TABLES');
        foreach ($tables as $row) {
            $table = (string) array_values($row)[0];

            $create = Database::selectOne('SHOW CREATE TABLE `' . $table . '`');
            $lines[] = '';
            $lines[] = '-- ---------- 表结构：' . $table . ' ----------';
            $lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
            $lines[] = (string) ($create['Create Table'] ?? '').';';
            $lines[] = '';

            $rows = Database::select('SELECT * FROM `' . $table . '`');
            if ($rows === []) {
                continue;
            }

            $lines[] = '-- ---------- 数据：' . $table . '（' . count($rows) . ' 条） ----------';
            foreach ($rows as $dataRow) {
                $columns = [];
                $values  = [];
                foreach ($dataRow as $column => $value) {
                    $columns[] = '`' . $column . '`';
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_int($value) || is_float($value)) {
                        $values[] = (string) $value;
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }
                $lines[] = 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ');';
            }
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        return implode("\n", $lines);
    }

    /**
     * 备份文件列表（按时间倒序）。
     *
     * @return array<int,array{name:string,size:int,time:int}>
     */
    private function listBackups(): array
    {
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.sql') ?: [];
        $items = [];
        foreach ($files as $file) {
            $items[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'time' => (int) filemtime($file),
            ];
        }
        usort($items, static function (array $a, array $b): int {
            return $b['time'] <=> $a['time'];
        });
        return array_slice($items, 0, 20);
    }
}
