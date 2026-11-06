<?php
/**
 * MES系统知识库
 * 应用引导文件 —— 所有入口（前台 / 后台 / 安装向导）统一从这里启动。
 *
 * @copyright ©Yangzengrong
 */

declare(strict_types=1);

if (defined('MES_KB_BOOTSTRAPPED')) {
    return;
}
define('MES_KB_BOOTSTRAPPED', true);

/**
 * 系统版本号。
 *
 * 【为什么不是 1.0.0】
 * 1.0.0 是 2026-09-10 首次部署时的初版代号，之后系统经历了一整轮功能建设，
 * 但版本号一直没动 —— 后台「系统信息」里显示 v1.0.0 与实际状态严重不符，
 * 一线反馈问题时说「v1.0.0 有点问题」管理员无法判断是哪一版。
 *
 * 【版本号推演依据（2026-10-05 实测）】
 *   表结构     10 → 15 张（+Feedback/Process/Position/Synonym/PinyinTerm/MesMenu/CaseEffectiveness）
 *   迁移脚本   5 个（工序/岗位/单编辑器/系统手册/有效性反馈）
 *   内容规模   74 案例 + 16 手册 + 5 更新日志
 *   检索能力   同义词扩展 + 拼音滑窗匹配 + 智能降级检索
 *   安全       nginx location 优先级修复、client_ip 重写、账号枚举消除、5 处 HY093 修复
 *   后台       侧栏分组折叠 + 面包屑 + 可收起
 *   前台       适用性标签、排查记录折叠区、有效性反馈
 *
 * 【为什么是 minor 而不是 major】
 * 全部改动都是「向后兼容的功能新增」，没有破坏性变更、没有数据格式不兼容、
 * 升级路径也不需要人工干预（打包覆盖即可）。语义化规范下这属于 minor。
 *
 * 【下一个 major 应该在什么时候】
 * 出现以下任一情况时升到 2.0.0：
 *   · 表结构需要重建或数据格式不兼容
 *   · 配置项重命名/废弃导致旧配置失效
 *   · 引入需要 Composer 的第三方依赖（会改变部署方式）
 */
// 2026-10-19：UI 排版优化轮（后台 admin.css 建 20 个设计 token 体系 + 前台 front.css P0/P1 去噪），
// 纯 CSS/视觉改动，无 schema 破坏性变更 → minor 1.6.0 → 1.7.0。
// 2026-10-21：员工反馈表重设计（对齐原 Excel 台账表头）+ 按月归档统计 + CSV 导出 + 完成时间自动记录；
// 新增 4 列为追加式（幂等迁移，旧数据兼容，无配置废弃、无外部依赖）→ minor 1.7.0 → 1.8.0。
//
// 【2026-10-22：升到 2.0.0】
// 上面列出的 major 触发条件这次一次出现了两条：
//   ① 配置项废弃 —— 顶部工序 chips 被全局导航取代、首页「按工序分类浏览」整块删除，
//      旧版 URL 与页面布局不再成立（导航入口、面包屑、工序筛选位置全部变了）；
//   ② 信息架构变更 —— 故障案例从「工序卡片 hub」改为「工序按钮筛选 + 列表」，
//      新增 /u9-sync 速查页与 app/Data 数据层，前台路由表与页面结构重排。
// 即：这是一次「页面骨架 + 导航 + 入口」级的重构，不是单纯加功能，故升 major 到 2.0.0。
// 表结构与配置本身仍是追加式兼容，升级路径依旧是打包覆盖、无需人工迁移。
//
// 【2026-10-23：2.0.0 → 2.1.0】
// 新增「车间设备采集 IP 地址」二级页面（/device-ip）：新表 device_ips（追加式，幂等迁移），
// 前台支持按设备名/IP/类型/工序检索历史内网地址 + 前台报备（待审核），后台支持增删改 /
// 审核 / 标记更换 / xlsx 合并导入。无配置废弃、无破坏性变更 → minor。
// 【2026-10-23：2.1.1 → 2.2.0】
// 「关联工序」支持自定义：device_ips 新增 custom_process 列（幂等迁移），后台/前台报备表单
// 均可选「自定义新工序」并填写名称，该名称保存后自动出现在关联工序下拉与筛选名单；
// 工序筛选统一改为按工序名（标准或自定义）匹配，兼容旧 process_id 链接。
// /device-ip 标题区补充「数据由 MES 系统采集提供」说明行。追加式兼容，无破坏性变更 → minor。
// 【2026-10-23：2.2.1 → 2.3.0】
// 本次为「新增二级页面」级发布：新增 /device-ip 车间设备采集 IP 地址查询页（前台导航 + 后台管理），
// 并在其上叠加关联工序自定义、后台待处理面板、页面按 design-taste 规范重构。
// 已在后台「MES版本更新管理」发布 v2.3.0 版本日志（前台「版本更新」页可见）。
//
// 【2026-10-23：2.3.0 → 2.4.0】
// 新增后台「反馈统计」页（登录可见，不对外公开，避免泄露员工隐私）：概览 KPI
// + 维度汇总计数表（状态 / 紧急度 / 工序 / 问题类型 / 部门，含数量与占比）
// + 工序×状态交叉矩阵 + 明细台账（类 Excel 密集表单，附件仅提供可点击「查看」链接、不内嵌图片），
// 并支持按当前筛选条件导出 Excel 兼容 CSV。无 schema 变更、无配置废弃 → minor。
// （原规划的「前台公开图表统计页」经评审改为后台台账，方向调整但版本号沿用 2.4.0。）
//
// 【2026-10-24：2.4.0 → 2.5.0】
// 本次仍是「追加式新功能」，无配置项废弃、无破坏性变更、无需人工迁移 → minor。
//   ① 操作手册独立成体系：categories 新增 scope 列区分「故障类型 / 功能模块」（默认值 'case'，
//      既有的 15 条分类零影响），新建 6 个功能模块并把 20 条手册全部归位；
//      新增前台 /manuals 手册列表页（此前手册只能在首页露最新 5 条），导航「操作手册」直达。
//   ② 整句检索降级：新增 app/Core/QueryTerms.php，整串 0 命中时切词，
//      改用「有结果且命中最少」的关键词重查（此前把报错原文整句粘进搜索框必然 0 条）。
//   ③ 检索同义词补充：MSQ-200（设备型号）→ 扫码枪、绎线 → 绞线、扫戏枪 → 扫码枪。
//   ④ 设备 IP：3 条未配置 IP 的记录在后台显示「未配置」占位 + 页面顶部告警条（翻页也可见）。
// 另含修复：搜索关键词未 trim（带空格必 0 结果，还会往 search_logs 写假的「无结果词」）、
// 反馈统计页紧急程度显示英文枚举、统计趋势「近 30/90 天」切换失效（$trendDays 变量遮蔽）。
define('MES_KB_VERSION', '2.5.0');
define('MES_KB_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', BASE_PATH . '/views');
define('STATIC_PATH', BASE_PATH . '/static');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('CONFIG_PATH', BASE_PATH . '/config');

// PHP 版本门槛：需求文档要求 PHP 7.4 / 8.0 稳定版本
if (PHP_VERSION_ID < 70400) {
    header('Content-Type: text/html; charset=utf-8');
    exit('本系统要求 PHP 7.4 及以上版本，当前运行版本为 ' . PHP_VERSION . '，请升级后重试。');
}

// 错误显示策略：安装阶段全开，正式运行按配置决定
if (!defined('MES_KB_INSTALLING')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', STORAGE_PATH . '/logs/php_error.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// 统一时区（MES 运维场景固定国内时区）
date_default_timezone_set('Asia/Shanghai');

// PSR-4 风格自动加载：App\Core\Database => app/Core/Database.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/Support/helpers.php';

// 会话：前后台共用一套会话，后台通过独立命名空间隔离
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('MESKBSESSID');
    session_start();
}

// 安装阶段不加载配置（此时 config/config.php 还不存在）
if (defined('MES_KB_INSTALLING')) {
    return;
}

$configFile = CONFIG_PATH . '/config.php';
if (!is_file($configFile)) {
    $installer = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if (basename($installer) === 'admin') {
        $installer = dirname($installer);
    }
    $url = ($installer === '/' ? '' : $installer) . '/install/index.php';
    header('Content-Type: text/html; charset=utf-8');
    exit('系统尚未安装，请先访问 <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">安装向导</a> 完成初始化。');
}

App\Core\Config::load(require $configFile);

// 依据配置调整错误显示
if (App\Core\Config::get('app.debug', false)) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
