<?php
/**
 * 幂等迁移：手册按「功能模块」独立成体系（2026-10-24，路线 B）。
 *
 * 为什么：
 *   1) 现有 15 个分类全是「故障类型」语义（设备故障 / 程序报错 / 工单异常…），
 *      描述的是"出了什么问题"；而手册（doc_type=manual，20 条）描述的是
 *      "某个功能模块怎么用"。两者混在同一棵分类树里，语义互不相容——
 *      手册挂到「程序报错」下是错的，不挂（category_id=0）则分类导航里根本不可见。
 *   2) 现状：20 条手册 + 6 条更新日志 100% category_id=0，分类导航不可见，
 *      案例详情页的「相关推荐」第③级（手册页推同分类手册）因此永远不命中；
 *      首页只露出最新 5 条，20 条手册里浏览量最高的仅 6 次——不是内容没用，是找不到。
 *   3) 方案：不动案例分类树，给 categories 加 scope 列做隔离
 *      （'case'=故障类型，'manual'=功能模块），手册获得自己独立的一级模块树。
 *      这样后台分类管理可以分两组维护，新建手册时下拉只出现模块名，不会误挂故障类型。
 *
 * 模块划分依据：逐条读取 20 条手册的标题/正文/标签归纳而成，不凭空造模块。
 *   工单管理(7)：订单审核、工单补料/数量变更/用料变更、子工单、需求料件查询、重工工单
 *   物料仓储(5)：返工发料、客户退货、料件工艺、料件转拨、替代料
 *   委外管理(2)：委外功能更新(CRM-委外价格)、委外加工
 *   设备管理(3)：火凤凰设备管理SOP、设备保养维护SOP1/SOP2
 *   SFIS系统(1)：标准工时（原文路径 SFIS系统→生产工艺）
 *   系统管理(2)：MES员工作业界面操作步骤、用户管理SOP
 *
 * 安全性：可重复执行。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=utf-8');

echo "== 手册功能模块体系迁移 ==\n";

// ---------- 1) categories 增加 scope 列 ----------
// 为什么用 NOT NULL DEFAULT 'case'：存量 15 条分类都是故障类型，
// 默认值即它们的真实语义，不需要再跑一次 UPDATE 回填。
$hasScope = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'scope'"
);
if ($hasScope === 0) {
    Database::execute(
        "ALTER TABLE categories
         ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'case' COMMENT 'case=故障类型 / manual=手册功能模块'
         AFTER parent_id"
    );
    echo "[ddl] categories.scope 已新增\n";
} else {
    echo "[skip] categories.scope 已存在\n";
}

// 兜底：把 NULL 显式归为 case（理论上 NOT NULL 不会有，防历史脏数据）
Database::execute("UPDATE categories SET scope = 'case' WHERE scope IS NULL OR scope = ''");

// ---------- 2) 手册功能模块（一级） ----------
$modules = [
    ['name' => '工单管理', 'sort' => 10],
    ['name' => '物料仓储', 'sort' => 20],
    ['name' => '委外管理', 'sort' => 30],
    ['name' => '设备管理', 'sort' => 40],
    ['name' => 'SFIS系统', 'sort' => 50],
    ['name' => '系统管理', 'sort' => 60],
];

$moduleIds = [];
foreach ($modules as $m) {
    // 幂等按 (scope, name) 判断，避免重复执行插出两份
    $row = Database::selectOne(
        'SELECT id FROM categories WHERE scope = :s AND name = :n LIMIT 1',
        ['s' => 'manual', 'n' => $m['name']]
    );
    if ($row !== null && $row !== false) {
        $moduleIds[$m['name']] = (int) $row['id'];
        echo sprintf("[skip] 模块「%s」已存在 #%d\n", $m['name'], (int) $row['id']);
        continue;
    }
    $newId = Database::insert('categories', [
        'parent_id'  => 0,
        'scope'      => 'manual',
        'name'       => $m['name'],
        'sort'       => $m['sort'],
        'status'     => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $moduleIds[$m['name']] = (int) $newId;
    echo sprintf("[insert] 模块「%s」#%d\n", $m['name'], (int) $newId);
}

// ---------- 3) 把 20 条手册归入模块 ----------
// 逐条硬编码：模块归属是对内容的人工判断，重复执行结果一致（幂等）。
$map = [
    '工单管理' => [53, 56, 57, 58, 59, 60, 65],
    '物料仓储' => [54, 61, 62, 63, 112],
    '委外管理' => [55, 64],
    '设备管理' => [109, 110, 111],
    'SFIS系统' => [52],
    '系统管理' => [66, 67],
];

$assigned = 0;
foreach ($map as $moduleName => $ids) {
    $cid = $moduleIds[$moduleName] ?? 0;
    if ($cid === 0) {
        echo "[warn] 模块「$moduleName」id 缺失，跳过\n";
        continue;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // 只更新手册，避免误伤同 id 段的案例
    $n = Database::execute(
        "UPDATE cases SET category_id = ?, updated_at = ?
         WHERE id IN ($placeholders) AND doc_type = 'manual'",
        array_merge([$cid, now()], $ids)
    );
    $assigned += $n;
    echo sprintf("[map] %s ← %d 条（%s）\n", $moduleName, $n, implode(',', $ids));
}

// ---------- 4) 结果自检 ----------
echo "\n== 自检 ==\n";
$remain = (int) Database::scalar(
    "SELECT COUNT(*) FROM cases WHERE doc_type = 'manual' AND deleted_at IS NULL
     AND (category_id IS NULL OR category_id = 0)"
);
$total = (int) Database::scalar("SELECT COUNT(*) FROM cases WHERE doc_type = 'manual' AND deleted_at IS NULL");
echo "手册总数 $total / 本次归位 $assigned / 仍未分类 $remain\n";

foreach (Database::select(
    "SELECT c.name, COUNT(k.id) n
     FROM categories c LEFT JOIN cases k ON k.category_id = c.id AND k.doc_type = 'manual' AND k.deleted_at IS NULL
     WHERE c.scope = 'manual' GROUP BY c.id, c.name ORDER BY c.sort"
) as $r) {
    printf("  %-10s %d 条\n", $r['name'], $r['n']);
}

echo "== 迁移完成 ==\n";
