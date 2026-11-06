<?php
/**
 * 幂等迁移：岗位操作手册体系（2026-09-12）。
 *
 * 1) 建 positions 表（岗位分类，手册维度）
 * 2) 预置 9 大岗位：销售/研发/质量/物控/生产/仓库/技术/设备/实验室
 * 3) cases 增加 position_id 列
 *
 * 安全性：可重复执行，已存在结构/数据自动跳过。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 岗位操作手册体系迁移 ==\n";

// ---------- 1) positions 表 ----------
$createPositionsSql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `positions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(60)  NOT NULL COMMENT '岗位名称',
  `sort`       INT          NOT NULL DEFAULT 0 COMMENT '排序值，越小越前',
  `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status_sort` (`status`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='岗位分类表（岗位操作手册维度）'
SQL;
Database::execute($createPositionsSql);
echo "[1] positions 表就绪\n";

// ---------- 2) 预置岗位（幂等） ----------
$positions = [
    ['销售', 10], ['研发', 20], ['质量', 30], ['物控', 40], ['生产', 50],
    ['仓库', 60], ['技术', 70], ['设备', 80], ['实验室', 90],
];
$seeded = 0;
foreach ($positions as [$name, $sort]) {
    $exists = (int) Database::scalar('SELECT COUNT(*) FROM positions WHERE name = :n', ['n' => $name]);
    if ($exists > 0) {
        continue;
    }
    Database::insert('positions', ['name' => $name, 'sort' => $sort, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $seeded++;
}
echo "[2] 预置岗位写入 {$seeded} 条（其余已存在跳过），当前总数："
    . (int) Database::scalar('SELECT COUNT(*) FROM positions') . "\n";

// ---------- 3) cases.position_id 列 ----------
$hasColumn = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'position_id'"
);
if ($hasColumn === 0) {
    Database::execute('ALTER TABLE `cases` ADD COLUMN `position_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'责任岗位（positions.id，岗位操作手册必绑，0=未绑定）\' AFTER `process_id`');
    echo "[3] cases.position_id 列已添加\n";
} else {
    echo "[3] cases.position_id 列已存在，跳过\n";
}

echo "== 迁移完成 ==\n";
