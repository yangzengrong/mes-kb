<?php
/**
 * 员工反馈统计（前台公开页，2026-10-23 重设计上线）。
 *
 * 只回答一个问题：「大家提的反馈，处理得怎么样了？」
 * 规则基线（app-screen）：颜色只上数据、KPI 是字不是瓷砖、图表零 chrome、
 * 数字右对齐 + tabular、真实 0 打印 0、空态保留框架、无入场动画。
 * 全部走产品 token，零外部依赖；隐私：仅聚合计数，不含任何个人信息。
 * 与后台「反馈统计」同源（Feedback::stats），前后台数字一致。
 *
 * @var array<string,mixed> $summary  Feedback::stats([]) 聚合结果（纯计数）
 * @var array<int,array{day:string,created:int,resolved:int}> $trend 近 N 天成对数据
 * @var int $trendDays 趋势窗口（30 / 90）
 * @var bool $enabled 反馈功能开关（关闭时隐藏「我要反馈」主操作）
 */

$statusMap   = feedback_status_map();
$total       = (int) ($summary['total'] ?? 0);
$statusCnt   = $summary['status'] ?? [];
$closed      = (int) ($summary['closed'] ?? 0);
$open        = (int) ($summary['open'] ?? 0);
$pending     = (int) ($statusCnt['pending'] ?? 0);
$processing  = (int) ($statusCnt['processing'] ?? 0);
$rate        = (float) ($summary['resolved_rate'] ?? 0);
$avgHours    = $summary['avg_handle_hours'] ?? null;
$generatedAt = (string) ($summary['generated_at'] ?? '');

/* 平均处理时长：分钟 / 小时 / 天，无人办结时为 — */
$avgText = '—';
if ($avgHours !== null) {
    $minutes = (float) $avgHours * 60;
    if ($minutes < 60) {
        $avgText = max(1, (int) round($minutes)) . ' 分钟';
    } elseif ($minutes < 1440) {
        $avgText = round($minutes / 60, 1) . ' 小时';
    } else {
        $avgText = round($minutes / 1440, 1) . ' 天';
    }
}
$updatedText = $generatedAt !== '' ? date('Y-m-d H:i', strtotime($generatedAt)) : '';

/* 状态色：与前台反馈进度页的状态胶囊同色，仅作状态数据编码 */
$statusColors = [
    'pending'    => '#A32D2D',
    'processing' => '#854F0B',
    'resolved'   => '#0F6E56',
    'closed'     => '#5F5E5A',
];

/* 副标题 = 状态句（不重复 KPI 数字；KPI 已是数字的唯一出处） */
if ($total === 0) {
    $finding = '还没有收到反馈。你在 MES 里遇到的问题，都可以从这里告诉运维。';
} elseif ($open > 0) {
    $finding = '当前有反馈正在处理，进度可在「问题反馈」页按单号查询。';
} else {
    $finding = '全部反馈均已闭环，谢谢每一位的反馈。';
}

/* 维度榜：取前 5，其余合并为「其他 N 项」；条宽按组内最大值 × 88% */
$rank = static function (array $rows): array {
    $top   = array_slice($rows, 0, 5);
    $rest  = array_slice($rows, 5);
    $other = 0;
    foreach ($rest as $r) {
        $other += (int) $r['total'];
    }
    $max = 1;
    foreach ($top as $r) {
        $max = max($max, (int) $r['total']);
    }
    return ['top' => $top, 'other' => $other, 'otherCount' => count($rest), 'max' => $max];
};
$processRank  = $rank($summary['process'] ?? []);
$categoryRank = $rank($summary['category'] ?? []);
$deptRank     = $rank($summary['department'] ?? []);
$urgencyCnt   = $summary['urgency'] ?? [];
/* name 必须取映射后的中文（紧急/较高/一般/较低）——
   这里曾直接用枚举键当名字，页面上露出 urgent/high/normal/low 四个英文值。 */
$urgencyRank  = $rank(array_map(
    static fn ($k, $label) => ['name' => (string) $label, 'total' => (int) ($urgencyCnt[$k] ?? 0)],
    array_keys(feedback_urgency_map()),
    array_values(feedback_urgency_map())
));

/* 趋势几何：顶部刻度高于最大值（柱顶不贴边），零线常画 */

/* 【为什么叫 $trendRows 而不是 $trendDays】
   $trendDays 是控制器传入的「窗口天数」（int 30/90），下面标题与 30/90 切换高亮都用它。
   早前这里误写成 $trendDays = $trend，把 int 覆盖成了行数组：
   (int)$array 在 PHP 中恒为 1，于是标题渲染成「近 1 天趋势」，且 30 天按钮永远不高亮。
   行数组另起名字可以从根上杜绝同名遮蔽。 */
$trendRows = is_array($trend) ? $trend : [];
$maxV      = 1;
foreach ($trendRows as $d) {
    $maxV = max($maxV, (int) $d['created'], (int) $d['resolved']);
}
$tickMax   = $maxV + 1;
$dayCount  = max(1, count($trendRows));
$lastIndex = $dayCount - 1;
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('/')) ?>">首页</a>
    <span>/</span>
    <em>员工反馈统计</em>
</nav>

<div class="fbs-head">
    <div class="fbs-head-main">
        <h1>员工反馈统计</h1>
        <p class="fbs-finding"><?= e($finding) ?></p>
    </div>
    <?php if ($enabled): ?>
    <a class="fbs-cta" href="<?= e(base_url('feedback')) ?>">我要反馈</a>
    <?php endif; ?>
</div>

<!-- 概览：一个容器、1px 分隔，主数字（总量）回答标题句 -->
<section class="fbs-kpi" aria-label="反馈概览">
    <div class="fbs-kpi-cell fbs-kpi-lead">
        <span class="fbs-kpi-label">反馈总量</span>
        <span class="fbs-kpi-value"><?= $total ?></span>
    </div>
    <div class="fbs-kpi-cell">
        <span class="fbs-kpi-label">已闭环</span>
        <span class="fbs-kpi-value"><?= $closed ?></span>
    </div>
    <div class="fbs-kpi-cell">
        <span class="fbs-kpi-label">待受理</span>
        <span class="fbs-kpi-value"><?= $pending ?></span>
    </div>
    <div class="fbs-kpi-cell">
        <span class="fbs-kpi-label">处理中</span>
        <span class="fbs-kpi-value"><?= $processing ?></span>
    </div>
    <div class="fbs-kpi-cell">
        <span class="fbs-kpi-label">闭环率</span>
        <span class="fbs-kpi-value"><?= $total > 0 ? number_format($rate, 1) . '%' : '—' ?></span>
    </div>
    <div class="fbs-kpi-cell">
        <span class="fbs-kpi-label">平均处理时长</span>
        <span class="fbs-kpi-value fbs-kpi-value-sm"><?= e($avgText) ?></span>
    </div>
</section>

<div class="fbs-row">
    <!-- 近 30 天趋势：每日成对柱（提交=品牌蓝，解决=绿），方角、无填充、零线常画 -->
    <section class="fbs-card">
        <div class="fbs-card-head">
            <h2 class="fbs-card-title">近 <?= (int) $trendDays ?> 天趋势</h2>
            <div class="fbs-trend-tools">
                <span class="fbs-legend">
                    <span><i class="fbs-dot" style="background:var(--primary)"></i>提交</span>
                    <span><i class="fbs-dot" style="background:var(--accent)"></i>解决</span>
                </span>
                <span class="fbs-trend-switch">
                    <a class="<?= (int) $trendDays === 30 ? 'is-on' : '' ?>" href="<?= e(base_url('feedback/stats?days=30')) ?>">30 天</a>
                    <a class="<?= (int) $trendDays === 90 ? 'is-on' : '' ?>" href="<?= e(base_url('feedback/stats?days=90')) ?>">90 天</a>
                </span>
            </div>
        </div>
        <div class="fbs-trend">
            <div class="fbs-trend-y" aria-hidden="true">
                <?php for ($t = $tickMax; $t >= 0; $t--): ?>
                <span class="fbs-trend-tick" style="bottom:<?= $t / $tickMax * 100 ?>%"><?= $t ?></span>
                <?php endfor; ?>
            </div>
            <div class="fbs-trend-plot">
                <?php for ($t = 1; $t <= $tickMax - 1; $t++): ?>
                <span class="fbs-trend-grid" style="bottom:<?= $t / $tickMax * 100 ?>%"></span>
                <?php endfor; ?>
                <span class="fbs-trend-zero"></span>
                <div class="fbs-trend-cols">
                    <?php foreach ($trendRows as $d): ?>
                    <div class="fbs-trend-day"
                         title="<?= e(substr((string) $d['day'], 5)) ?> 提交 <?= (int) $d['created'] ?>，解决 <?= (int) $d['resolved'] ?>">
                        <span class="fbs-trend-pair">
                            <span class="fbs-trend-bar" style="height:<?= (int) $d['created'] / $tickMax * 100 ?>%"></span>
                            <span class="fbs-trend-bar fbs-trend-bar-alt" style="height:<?= (int) $d['resolved'] / $tickMax * 100 ?>%"></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="fbs-trend-x" aria-hidden="true">
            <?php foreach ($trendRows as $i => $d): ?>
            <?php $show = $i % 5 === 0 || $i === $lastIndex; ?>
            <span class="<?= $show ? '' : 'is-minor' ?>"><?= $show ? e(substr((string) $d['day'], 5)) : '' ?></span>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 状态分布：100% 堆叠条（2px 缝）+ 图例表（点数即数据） -->
    <section class="fbs-card">
        <div class="fbs-card-head">
            <h2 class="fbs-card-title">状态分布</h2>
        </div>
        <?php if ($total > 0): ?>
        <div class="fbs-share" role="img" aria-label="各状态占比">
            <?php foreach ($statusMap as $key => $label): ?>
            <?php $v = (int) ($statusCnt[$key] ?? 0); ?>
            <?php if ($v > 0): ?>
            <span class="fbs-share-seg" style="width:<?= $v / $total * 100 ?>%;background:<?= e($statusColors[$key]) ?>"></span>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <table class="fbs-legend-table">
            <?php foreach ($statusMap as $key => $label): ?>
            <?php $v = (int) ($statusCnt[$key] ?? 0); ?>
            <tr>
                <td><i class="fbs-dot" style="background:<?= e($statusColors[$key]) ?>"></i><?= e((string) $label) ?></td>
                <td class="fbs-num"><?= $v ?></td>
                <td class="fbs-num fbs-num-pct"><?= number_format($v / $total * 100, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php else: ?>
        <p class="fbs-empty">暂无数据。</p>
        <?php endif; ?>
    </section>
</div>

<!-- 维度榜：单色条（首名 ink），真实 0 打印 0，「其他」如实合并 -->
<div class="fbs-dims">
    <?php
    $dimCards = [
        ['title' => '工序分布', 'rank' => $processRank],
        ['title' => '问题类型分布', 'rank' => $categoryRank],
        ['title' => '部门分布', 'rank' => $deptRank],
        ['title' => '紧急程度', 'rank' => $urgencyRank],
    ];
    foreach ($dimCards as $card):
        $rk = $card['rank'];
    ?>
    <section class="fbs-card">
        <div class="fbs-card-head">
            <h2 class="fbs-card-title"><?= e($card['title']) ?></h2>
            <?php if ($rk['top'] !== []): ?>
            <span class="fbs-card-note">前 <?= count($rk['top']) ?> 名</span>
            <?php endif; ?>
        </div>
        <?php if ($rk['top'] === []): ?>
        <p class="fbs-empty">暂无数据。</p>
        <?php else: ?>
        <div class="fbs-rank">
            <?php foreach ($rk['top'] as $i => $row): ?>
            <div class="fbs-rank-row<?= $i === 0 ? ' is-top' : '' ?>">
                <span class="fbs-rank-label" title="<?= e((string) $row['name']) ?>"><?= e((string) $row['name']) ?></span>
                <span class="fbs-rank-track">
                    <span class="fbs-rank-bar" style="width:<?= (int) $row['total'] / $rk['max'] * 88 ?>%"></span>
                </span>
                <span class="fbs-rank-value"><?= (int) $row['total'] ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($rk['other'] > 0): ?>
            <div class="fbs-rank-row fbs-rank-other">
                <span class="fbs-rank-label">其他 <?= $rk['otherCount'] ?> 项</span>
                <span class="fbs-rank-track">
                    <span class="fbs-rank-bar" style="width:<?= $rk['other'] / $rk['max'] * 88 ?>%"></span>
                </span>
                <span class="fbs-rank-value"><?= $rk['other'] ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>

<p class="fbs-note">
    统计范围：全部历史反馈（不含已删除），数据更新于 <?= e($updatedText) ?>。<br>
    仅汇总数量与分布，不展示任何个人信息。
</p>
