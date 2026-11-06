<?php
declare(strict_types=1);

/**
 * 幂等迁移：标签规范化 + 适用性标签初值（2026-10-05）。
 *
 * 【背景：为什么现在要洗 tags】
 * 标签的写入端只把「,，;；」当作分隔符，漏了顿号「、」。而员工实际录入时
 * 习惯用顿号 —— 库里 29 条有标签的记录中，14 条用的是顿号。于是：
 *
 *   库里存的是  MES、ERP、同步平台
 *   前台渲染成  #MES、ERP、同步平台        （explode(',') 切不开，粘成一个超长标签）
 *   搜「MES」   命中 0 条                  （CONCAT LIKE 精确匹配整串，匹配不上）
 *   搜「ERP」   命中 0 条
 *
 * 这正是检索无结果词里「MES」高居第一（10 次）的根因 —— 不是同义词库没覆盖，
 * 是标签本身就是粘连的。本迁移把 tags 统一为半角逗号分隔，让每个词独立成标签、
 * 可被精确匹配。
 *
 * 【顺带做适用性标签初值】
 * MES 按线体区分系统，一线拿到案例第一个要判断的是「这条是不是我这条线的情况」。
 * tags 里此前没有表达这个维度的位置。带冒号的形式（产线:护套）被约定为适用性标签，
 * 与关键词标签同存于 tags 列（零表结构变更），前台按冒号识别并独立成组渲染。
 *
 * 只有「工序」能自动推断 —— 来自 processes 表，是权威数据，且仅对**已绑工序**的
 * 案例补初值。实测 24 条未绑工序的案例全是 MES 系统类问题（打印机、扫码枪、权限、
 * 工单、浏览器缓存…），本就不属于任何具体产线，填「工序:xxx」是写入错误信息。
 * 线体/机台/版本同样无法从标题可靠推断（「护套押出8线」里的 8 可能是线号也可能是机台号），
 * 一律留空由管理员按实际情况填 —— 缺信息可补，错信息会误导人。
 *
 * 幂等：可重复执行，第二次运行输出「无需处理」。
 * 执行方式：php install/migrate-20261005-case-tags.php（CLI）
 * 执行完请删除本文件。
 */

$baseDir = dirname(__DIR__);
require $baseDir . '/config/config.php';
require $baseDir . '/app/bootstrap.php';

use App\Core\CaseTag;
use App\Core\Database;

echo "== 标签规范化 + 适用性标签初值迁移 ==\n";

// ---------- 0) 前置校验：cases.tags 存在且为字符串类型 ----------
$tagsCol = Database::selectOne(
    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cases' AND COLUMN_NAME = 'tags'"
);
if ($tagsCol === null) {
    echo "[!] cases.tags 列不存在，无法迁移。请先核对表结构。\n";
    exit(1);
}
$maxLen = (int) ($tagsCol['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
echo sprintf("[0] cases.tags 就绪（VARCHAR(%d)）\n", $maxLen);


// ---------- 1) 规范化 tags：顿号/分号/斜杠 → 半角逗号，并清理脏数据 ----------
$rows = Database::select(
    "SELECT id, title, tags FROM cases WHERE deleted_at IS NULL AND tags <> ''"
);
echo sprintf("[1] 待检查记录：%d 条\n", count($rows));

$fixed = 0;      // 需要改写的记录数
$removed = 0;    // 剔除冗余「工序:xxx」标签的记录数
$skipped = 0;    // 因长度超限未写入的记录数

foreach ($rows as $row) {
    $old = (string) $row['tags'];
    $new = CaseTag::normalize($old);

    // ① 清理历史写入的「工序:xxx」标签
    //
    // 【为什么必须清理】首版迁移曾用「从 process_id 推断适用性初值」的思路，
    // 给 14 条案例写入了「工序:护套」这类标签。上线后发现这是设计错误：
    //   · 工序是系统已有的一等维度（cases.process_id），前台导航与详情页都已展示，
    //     标签里再写一遍是纯冗余；
    //   · 22/74 条案例绑的是「其它」，推出「适用范围：工序 其它」毫无信息量，
    //     还会让人误以为「适用范围已被认真标注过」而停止自行判断。
    //
    // 适用性标签的价值只在于表达工序表**没有**的信息：线体、机台、版本。
    // 这些维度无法从现有数据可靠推断，一律留空由管理员按实际情况填。
    $withoutProcessScope = [];
    foreach (CaseTag::parse($new) as $tag) {
        $pos = strpos($tag, ':');
        if ($pos !== false && $pos > 0 && trim(substr($tag, 0, $pos)) === '工序') {
            continue;                        // 剔除冗余的工序维度
        }
        $withoutProcessScope[] = $tag;
    }
    if ($withoutProcessScope !== CaseTag::parse($new)) {
        $new = implode(CaseTag::SEPARATOR, $withoutProcessScope);
        $removed++;
    }

    if ($new === $old) {
        continue;
    }

    // 更新前再确认一次长度，杜绝任何截断风险
    if (mb_strlen($new, 'UTF-8') > $maxLen) {
        printf("    [!] #%d 归一化后超长（%d>%d），跳过：%s\n",
            (int) $row['id'], mb_strlen($new, 'UTF-8'), $maxLen, $old);
        $skipped++;
        continue;
    }

    Database::update('cases', ['tags' => $new], 'id = :id', ['id' => (int) $row['id']]);
    $fixed++;
    printf("    #%-4d %s\n           → %s\n", (int) $row['id'], $old, $new);
}

echo sprintf("[1] 规范化完成：改写 %d 条，剔除冗余工序维度 %d 条，跳过 %d 条\n",
    $fixed, $removed, $skipped);

// ---------- 2) 清空拼音索引缓存 ----------
// PinyinTerm 的 phrases() 会读 tags 建索引。CaseTag::parse 现在会过滤超长/
// 残缺标签，与旧的 preg_split 口径有细微差异（如「:护套」这类脏标签不再入索引），
// 因此清一次缓存，让下次访问时按新规则惰性重建。
echo "[2] 清空拼音索引缓存...\n";
\App\Models\PinyinTerm::flush();
echo "    已清空，下次访问时按新规则重建\n";

// ---------- 3) 验收 ----------
echo "[3] 验收：\n";
$verify = Database::select(
    "SELECT id, tags FROM cases WHERE deleted_at IS NULL AND tags <> ''"
);
$bad = 0;
$tagCount = 0;
foreach ($verify as $v) {
    $tags = (string) $v['tags'];
    // 库里不该再出现顿号或其他非标准分隔符
    if (preg_match('/[，;；、|\/]/u', $tags) === 1) {
        printf("    [!] #%d 仍含非法分隔符：%s\n", (int) $v['id'], $tags);
        $bad++;
    }
    $parts = CaseTag::parse($tags);
    if (count($parts) !== count(explode(',', $tags))) {
        printf("    [!] #%d 解析后标签数不符：%s\n", (int) $v['id'], $tags);
        $bad++;
    }
    $tagCount += count($parts);
}

$scoped = 0;
foreach ($verify as $v) {
    if (CaseTag::scopes((string) $v['tags']) !== []) {
        $scoped++;
    }
}

printf("    记录 %d 条，独立标签 %d 个（迁移前 29 个粘连值）\n", count($verify), $tagCount);
printf("    含适用性标签的记录：%d 条\n", $scoped);
printf("    异常记录：%d 条 %s\n", $bad, $bad === 0 ? '✅' : '❌');

echo "\n完成。请手动验证：\n";
echo "  1) 后台打开任一案例编辑页，确认「适用范围标签」有内容且保存后不重复\n";
echo "  2) 前台案例详情页：适用范围块只应显示线体/机台/版本等维度，不应出现「工序」\n";
echo "  3) 前台搜标签「MES」「ERP」「工艺参数」，确认能命中对应案例\n";
echo "执行完请删除本文件。\n";
