<?php
/**
 * 幂等迁移：发布 v2.5.0 版本日志（2026-10-24）。
 *
 * 为什么：
 *   版本号常量已在 app/bootstrap.php 升到 2.5.0，但「版本更新」页的内容是
 *   doc_type='changelog' 的案例记录（后台「版本更新管理」维护，v2.3.0 起就是这个做法）。
 *   两者必须一起发，否则前台「版本更新」页停在 v2.3.0，用户看不到这次改了什么。
 *
 * 幂等：按标题判重，重复执行不会插入第二条；已存在则只打印跳过。
 *
 * 安全性：只 INSERT 一条 changelog，不改动任何既有数据。执行完请删除本文件。
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\CaseModel;

header('Content-Type: text/plain; charset=utf-8');

echo "== 发布 v2.5.0 版本日志 ==\n";

$title = 'v2.5.0 操作手册独立成体系 + 整句检索降级 + 设备 IP 空值提示';

$exists = (int) Database::scalar(
    "SELECT COUNT(*) FROM cases WHERE doc_type = 'changelog' AND title = :t AND deleted_at IS NULL",
    ['t' => $title]
);
if ($exists > 0) {
    echo "[skip] 版本日志已存在，无需重复发布\n";
    echo "== 完成 ==\n";
    return;
}

$now = now();

$payload = [
    'title'       => $title,
    'doc_type'    => 'changelog',
    'category_id' => 0,
    'process_id'  => 0,
    'position_id' => 0,
    'device'      => '',
    'tags'        => '版本发布,操作手册,检索优化,设备IP',
    'phenomenon'  => <<<'HTML'
<h4>本次更新内容</h4>
<p><strong>1. 操作手册独立成体系。</strong>新增前台「操作手册」列表页（导航直达），20 篇手册按功能模块归类：
工单管理 7 篇、物料仓储 5 篇、设备管理 3 篇、委外管理 2 篇、SFIS 系统 1 篇、系统管理 2 篇。
此前手册只能从首页看到最新 5 条，现在可按模块浏览；打开任意一篇手册，页尾还会推荐同模块的其它手册。</p>

<p><strong>2. 整句检索降级。</strong>把报错原文整句粘进搜索框（例如「扫码枪 MSQ-200 故障」）不再返回 0 条：
系统会自动拆词，改用其中最具体的关键词检索，并在结果上方说明「已改用关键词 X 检索」，
同时给出其它备选词及其条数，点一下就能切换。</p>

<p><strong>3. 检索同义词补充。</strong>设备型号「MSQ-200」现在能带出扫码枪相关案例；
错别字「绎线」指向「绞线」、「扫戏枪」指向「扫码枪」。</p>

<p><strong>4. 设备 IP 空值可见。</strong>3 条未配置 IP 的设备记录，后台列表明确显示「未配置」而不是一片空白，
并在页面顶部列出设备名告警，翻页也不会漏看。</p>

<p><strong>5. 其它修复。</strong>搜索关键词带首尾空格时查不到结果（还会误记一条「无结果词」）、
反馈统计页紧急程度显示英文（urgent / high）、统计趋势「近 30 / 90 天」切换不生效。</p>
HTML,
    'solution'    => <<<'HTML'
<h4>使用方式变化</h4>
<p>想看操作手册：直接点导航「操作手册」，按模块点选即可，不用再从检索结果里翻。</p>
<p>搜索时可以直接粘贴整句报错（含型号、现象），不必自己拆关键词；结果页顶部会提示实际用了哪个词。</p>
<p>设备 IP 未配置的机器，后台列表会明确标「未配置」并在顶部列出设备名，方便补录。</p>
HTML,
    'cause'       => '',
    'prevention'  => '',
    'handler'     => 'MES 运维',
    'handled_at'  => $now,
    'status'      => CaseModel::STATUS_PUBLISHED,
    'author_id'   => 1,
    'version_no'  => 1,
    'reviewed_by' => 1,
    'reviewed_at' => $now,
    'published_at' => $now,
    'created_at'  => $now,
    'updated_at'  => $now,
];

$id = CaseModel::create($payload);
echo "[insert] 版本日志已发布，id={$id}\n";
echo "标题：{$title}\n";

// 版本日志会进入拼音联想词表，重建一次，保证新词条可被缩写命中
if (method_exists(\App\Models\PinyinTerm::class, 'flush')) {
    \App\Models\PinyinTerm::flush();
    echo "[flush] 拼音词表已重建\n";
}

echo "== 完成 ==\n";
