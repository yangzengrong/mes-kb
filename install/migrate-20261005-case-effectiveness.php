<?php
declare(strict_types=1);

/**
 * 幂等迁移：案例有效性反馈（2026-10-05 · 断点D）。
 *
 * 【要解决什么】
 * 19/74 案例从未被浏览，26% 的内容长期零反馈。管理员无法判断一条案例是
 * 「写得差没人用」还是「写得好但根本没被搜到」——这两种情况的应对完全不同：
 * 前者要重写，后者要改检索策略。而现在两者在数据上完全一样（views=0）。
 *
 * 断点D 的做法：在案例详情页放两个图标（这条帮到你吗 / 没帮到），
 * 点击即记录，不点也算「没有帮到」——因为「看了也没用」本身就是强信号。
 * 后台按规则自动生成「待复核」清单，把长期无反馈的内容推到管理员面前。
 *
 * 【为什么不加在 cases 表上】
 * 有效性反馈是多维度、可重复、按时间累积的数据（谁在什么时候反馈了什么），
 * 与内容本身是 1:N 关系。塞进 cases 会让内容表承担行为数据的读写，
 * 且无法表达「同一个人多次反馈」「改版后重新反馈」。
 *
 * 【匿名设计的取舍】
 * 前台用户是匿名/实名混合的（反馈功能两种都支持），案例浏览也是。
 * 因此不强制登录，只用「浏览器指纹哈希」做同一人去重 ——
 * 目的不是识别个人，而是避免一个人连点导致比例失真。
 *
 * 幂等：可重复执行。
 * 执行方式：php install/migrate-20261005-case-effectiveness.php（CLI）
 * 执行完请删除本文件。
 */

$baseDir = dirname(__DIR__);
require $baseDir . '/config/config.php';
require $baseDir . '/app/bootstrap.php';

use App\Core\Database;

echo "== 案例有效性反馈迁移 ==\n";

// ---------- 0) 前置校验：cases 表存在 ----------
$hasCases = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases'"
);
if ($hasCases === 0) {
    echo "[!] cases 表不存在，请先完成安装。\n";
    exit(1);
}
echo "[0] cases 表就绪\n";

// ---------- 1) 建表：case_effectiveness ----------
/**
 * 字段设计说明：
 *  voter_key   浏览器指纹哈希（同一人去重，避免比例失真；不存原始 IP）
 *  helpful     1=帮到 0=没帮到
 *  唯一索引 (case_id, voter_key) —— 同一人对同一条只保留最后一次反馈，
 *  这样反复点不会把比例越推越极端。
 */
$sql = "
CREATE TABLE IF NOT EXISTS `case_effectiveness` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `case_id`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '案例ID（cases.id）',
  `voter_key`  VARCHAR(40) NOT NULL DEFAULT '' COMMENT '浏览器指纹哈希（同一人去重，非个人标识）',
  `helpful`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=帮到 0=没帮到',
  `note`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT '没帮到时的补充说明（可选）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_case_voter` (`case_id`, `voter_key`),
  KEY `idx_helpful` (`helpful`),
  KEY `idx_case_helpful` (`case_id`, `helpful`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='案例有效性反馈（一线员工对案例是否有用��投票）'
";

// 建 DDL 没有占位符，走 execute（Database 无 query() 方法，别写错）
Database::execute($sql);
$exists = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_effectiveness'"
);
if ($exists === 0) {
    echo "[!] 建表失败，请检查权限\n";
    exit(1);
}
echo "[1] case_effectiveness 表就绪\n";

// ---------- 2) 索引核查（SHOW COLUMNS 不返回索引，必须查 information_schema）----------
$wantIdx = ['uniq_case_voter', 'idx_helpful', 'idx_case_helpful'];
$haveIdx = [];
foreach (Database::select(
    "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_effectiveness'"
) as $row) {
    $haveIdx[] = (string) $row['INDEX_NAME'];
}
foreach ($wantIdx as $idx) {
    printf("    索引 %-20s %s\n", $idx, in_array($idx, $haveIdx, true) ? 'OK' : '缺失');
}

// ---------- 3) 列注释核对 ----------
$col = Database::selectOne(
    "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'case_effectiveness' AND COLUMN_NAME = 'voter_key'"
);
echo sprintf("[3] voter_key 注释：%s\n", (string) ($col['COLUMN_COMMENT'] ?? '(空)'));

// ---------- 4) 验收 ----------
$total = (int) Database::scalar("SELECT COUNT(*) FROM case_effectiveness");
printf("[4] 当前反馈记录：%d 条\n", $total);
printf("    表总数：%d 张\n", (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
));

echo "\n完成。请手动验证：\n";
echo "  1) 前台打开任一案例详情页，页面底部应出现「这条帮到你吗」两个图标\n";
echo "  2) 点「帮到了」后再点另一条，显示的统计应立即变化\n";
echo "  3) 同一浏览器重复点同一条，不应重复计数\n";
echo "  4) 后台案例列表应出现「有效性」列与筛选\n";
echo "执行完请删除本文件。\n";
