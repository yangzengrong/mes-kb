<?php
/**
 * 线缆专属分类改版：增量迁移（幂等，可重复执行）。
 *
 * 内容：
 *   1. 新建 processes 工序表（如不存在）
 *   2. 预置 9 大工序（按名称判重，重复执行自动跳过）
 *   3. cases 表补 process_id / doc_type 列（存在性检查后 ALTER）
 *   4. 输出迁移结果核对清单
 *
 * 用法：php migrate-20260911-process.php   （在应用根目录执行）
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

echo "== 1. 工序表 ==\n";
$createProcessTable =
    'CREATE TABLE IF NOT EXISTS `processes` ('
    . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
    . ' `name` VARCHAR(60) NOT NULL COMMENT \'工序名称\','
    . ' `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序值，越小越前\','
    . ' `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1启用 0停用\','
    . ' `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
    . ' `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
    . ' PRIMARY KEY (`id`),'
    . ' UNIQUE KEY `uk_name` (`name`),'
    . ' KEY `idx_status_sort` (`status`, `sort`)'
    . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT=\'工序分类表（一级分类）\'';
Database::execute($createProcessTable);
echo "  processes 表就绪\n";

echo "== 2. 预置 9 大工序 ==\n";
$processes = [
    ['绞线', 10], ['成缆', 20], ['绝缘', 30], ['护套', 40], ['数据缆', 50],
    ['编织', 60], ['自动供料', 70], ['实验室', 80], ['数据采集', 90],
];
foreach ($processes as [$name, $sort]) {
    $exists = Database::scalar('SELECT COUNT(*) FROM processes WHERE name = :n', ['n' => $name]);
    if ((int) $exists > 0) {
        echo "  跳过（已存在）：{$name}\n";
        continue;
    }
    Database::execute(
        'INSERT INTO processes (name, sort, created_at, updated_at) VALUES (:n, :s, :t1, :t2)',
        ['n' => $name, 's' => $sort, 't1' => now(), 't2' => now()]
    );
    echo "  新增：{$name}\n";
}

echo "== 3. cases 表补列 ==\n";
$columns = Database::select('SHOW COLUMNS FROM cases');
$has = static function (string $col) use ($columns): bool {
    foreach ($columns as $column) {
        if ((string) $column['Field'] === $col) {
            return true;
        }
    }
    return false;
};

if (!$has('process_id')) {
    Database::execute('ALTER TABLE cases ADD COLUMN `process_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'所属工序（processes.id，0=未绑定）\' AFTER `title`');
    echo "  已加列：process_id\n";
} else {
    echo "  跳过（已存在）：process_id\n";
}
if (!$has('doc_type')) {
    Database::execute('ALTER TABLE cases ADD COLUMN `doc_type` VARCHAR(20) NOT NULL DEFAULT \'case\' COMMENT \'文档类型：case/manual/changelog\' AFTER `process_id`');
    echo "  已加列：doc_type\n";
} else {
    echo "  跳过（已存在）：doc_type\n";
}
if (!$has('idx_process')) {
    // MySQL SHOW COLUMNS 不返回索引，改查 information_schema
    $indexExists = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND INDEX_NAME = 'idx_process'"
    );
    if ($indexExists === 0) {
        Database::execute('ALTER TABLE cases ADD INDEX `idx_process` (`process_id`)');
        echo "  已加索引：idx_process\n";
    } else {
        echo "  跳过（已存在）：idx_process\n";
    }
}

echo "== 4. 迁移结果核对 ==\n";
$count = (int) Database::scalar('SELECT COUNT(*) FROM processes');
echo "  工序总数：{$count}\n";
$rows = Database::select('SELECT id, name, sort, status FROM processes ORDER BY sort ASC');
foreach ($rows as $row) {
    echo '  #' . $row['id'] . ' ' . $row['name'] . ' (sort=' . $row['sort'] . ', status=' . $row['status'] . ")\n";
}
$caseTotal = (int) Database::scalar('SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL');
$unbound   = (int) Database::scalar('SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND process_id = 0');
echo "  存量案例：{$caseTotal} 条，其中待绑定工序 {$unbound} 条（后台案例列表可批量绑定）\n";
echo ($count === 9 ? "MIGRATE OK\n" : "MIGRATE WARN: 工序数量异常\n");
