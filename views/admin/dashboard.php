<?php
/**
 * 数据看板（需求 4.5 数据统计与看板模块）。
 *
 * @var array<string,int> $statusCounts
 * @var array<int,array{name:string,count:int,process_id:int}> $processStats
 * @var int $drillId
 * @var array<int,array{name:string,count:int}> $drillStats
 * @var array<int,array{name:string,count:int}> $categoryStats
 * @var array<int,array<string,mixed>> $topViewed
 * @var array<int,array{keyword:string,times:int,no_result:int}> $topKeywords
 * @var array<int,array{keyword:string,times:int}> $noResultWords
 * @var array<int,array{date:string,count:int}> $trend
 * @var int $trendDays
 * @var array{total:int,activeDays:int,avg:float,prevTotal:int,delta:int,peak:int,peakDate:string} $trendSummary
 * @var int $zeroViewCount
 * @var array{count:int,bytes:int} $storage
 * @var array<string,int> $logSummary
 */
$maxTrend = 1;
foreach ($trend as $point) {
    $maxTrend = max($maxTrend, (int) $point['count']);
}
$maxCategory = 1;
foreach ($categoryStats as $item) {
    $maxCategory = max($maxCategory, (int) $item['count']);
}
$isSuper = auth()->isSuperAdmin();
?>

<?php if (!$isSuper): ?>
    <div class="alert alert-info">
        当前为「<?= e(role_label(auth()->role())) ?>」视图，仅统计你本人负责的案例。
    </div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <span class="stat-label">已发布案例</span>
        <span class="stat-value"><?= (int) ($statusCounts['published'] ?? 0) ?></span>
        <span class="stat-foot">前台可检索</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">待审核案例</span>
        <span class="stat-value"><?= (int) ($statusCounts['pending'] ?? 0) ?></span>
        <span class="stat-foot">未审核前台不展示</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">本月新增</span>
        <span class="stat-value"><?= (int) $monthCount ?></span>
        <span class="stat-foot"><?= e(date('Y-m')) ?></span>
    </div>
    <?php /* 零浏览（内容沉底）指标，2026-10-18。
           实测 80 条内容里有 17 条 published 且 0 浏览 —— 写了、审了、发了，但没人看过。
           看板原来只有「已发布数」，会把「发布了」误当成「用起来了」。
           这里把它显式暴露出来，占比高说明曝光机制有问题，不是内容有问题。

           2026-10-26：卡片改为可点击，下钻到案例列表的「零浏览」筛选。
           原实现只是个数字，知道有 17 条却不知道是哪 17 条，指标无法行动。 */ ?>
    <a class="stat-card stat-card-link<?= (int) $zeroViewCount > 0 ? ' is-warn' : '' ?>"
       href="<?= e(admin_url('cases.php?zero_view=1')) ?>"
       title="查看这 <?= (int) $zeroViewCount ?> 条「已发布但 0 浏览」的内容">
        <span class="stat-label">发布后无人看</span>
        <span class="stat-value"><?= (int) $zeroViewCount ?></span>
        <span class="stat-foot">
            占已发布 <?= ($statusCounts['published'] ?? 0) > 0
                ? number_format((int) $zeroViewCount * 100 / max(1, (int) $statusCounts['published']), 1) . '%'
                : '—' ?>
            <?php if ((int) $zeroViewCount > 0): ?>
                <span class="stat-go">· 查看明细 →</span>
            <?php endif; ?>
        </span>
    </a>
    <div class="stat-card">
        <span class="stat-label">案例总数</span>
        <span class="stat-value"><?= (int) ($statusCounts['total'] ?? 0) ?></span>
        <span class="stat-foot">不含回收站 <?= (int) $trashCount ?> 条</span>
    </div>
    <?php if ($isSuper): ?>
        <div class="stat-card">
            <span class="stat-label">附件数量</span>
            <span class="stat-value"><?= (int) $storage['count'] ?></span>
            <span class="stat-foot">占用 <?= e(human_size((int) $storage['bytes'])) ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">系统账号</span>
            <span class="stat-value"><?= (int) $userCount ?></span>
            <span class="stat-foot">启用工序 <?= (int) $processCount ?> 个</span>
        </div>
    <?php else: ?>
        <div class="stat-card">
            <span class="stat-label">我的案例</span>
            <span class="stat-value"><?= (int) $myStats['total'] ?></span>
            <span class="stat-foot">已发布 <?= (int) $myStats['published'] ?> · 待审 <?= (int) $myStats['pending'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label">启用工序</span>
            <span class="stat-value"><?= (int) $processCount ?></span>
            <span class="stat-foot">故障类型 <?= (int) $categoryCount ?> 个</span>
        </div>
    <?php endif; ?>
</div>

<div class="panel-grid">
    <section class="card">
        <div class="card-head-row">
            <h2 class="card-title">近 <?= (int) $trendDays ?> 天新增案例趋势</h2>
            <div class="trend-switch" role="group" aria-label="切换趋势周期">
                <?php /* 周期切换用链接而非 JS —— 后台已全面依赖 GET 参数（如 drill），
                  保持一致，也让浏览器后退/前进能正常工作。 */ ?>
                <?php foreach ([14, 30, 90] as $d): ?>
                    <a class="trend-switch-btn<?= $d === (int) $trendDays ? ' is-active' : '' ?>"
                       href="<?= e(admin_url('index.php?trend_days=' . $d)) ?>"
                       <?= $d === (int) $trendDays ? 'aria-current="page"' : '' ?>>
                        <?= $d ?> 天
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php /* 汇总摘要：回答「最近到底还在不在录」。
               柱状图只回答「每天多少」。在内容集中批量录入的场景下
               （实测 14 天里 10 天为 0）会把正常水位显示成一片平坦的线，
               所以补一组汇总量：总量 / 活跃天数 / 日均 / 环比。 */ ?>
        <ul class="trend-summary">
            <li>
                <span class="ts-num"><?= (int) $trendSummary['total'] ?></span>
                <span class="ts-label">窗口内新增</span>
            </li>
            <li>
                <span class="ts-num"><?= (int) $trendSummary['activeDays'] ?></span>
                <span class="ts-label">有新增的天数</span>
            </li>
            <li>
                <span class="ts-num"><?= number_format((float) $trendSummary['avg'], 1) ?></span>
                <span class="ts-label">日均（条/天）</span>
            </li>
            <li>
                <span class="ts-num<?= (int) $trendSummary['delta'] < 0 ? ' is-down' : '' ?>">
                    <?= (int) $trendSummary['delta'] > 0 ? '+' : '' ?><?= (int) $trendSummary['delta'] ?>
                </span>
                <span class="ts-label">
                    环比前 <?= (int) $trendDays ?> 天
                    <?php if ((int) $trendSummary['prevTotal'] > 0): ?>
                        （前 <?= (int) $trendSummary['prevTotal'] ?> 条）
                    <?php endif; ?>
                </span>
            </li>
            <?php if ((int) $trendSummary['peak'] > 0): ?>
            <li>
                <span class="ts-num"><?= (int) $trendSummary['peak'] ?></span>
                <span class="ts-label">峰值 <?= e((string) $trendSummary['peakDate']) ?></span>
            </li>
            <?php endif; ?>
        </ul>

        <?php /* 日期独立成行、平铺不旋转（app-screens 规则：Dates are flat, never rotated）。
               旧方案把日期塞进柱列里 rotate(-40deg)：旋转不改变占位宽度，
               90 天全出会把文档撑到 3374px（已实测踩坑），只能靠 display:none 抽稀。
               新方案按周期抽稀（14 天逐日、30 天隔根、90 天每 5 根），
               标签行与柱行同 gap 保证对齐；首末标签分别左/右对齐收进绘图区内。 */ ?>
        <?php
        $trendTotal = count($trend);
        $dateStep   = $trendTotal <= 14 ? 1 : ($trendTotal <= 30 ? 2 : 5);
        ?>
        <div class="trend-block trend-dense-<?= (int) $trendDays ?>">
            <div class="trend">
                <?php foreach ($trend as $point): ?>
                    <?php $height = (int) round(((int) $point['count'] / $maxTrend) * 100); ?>
                    <div class="trend-col" title="<?= e($point['date']) ?>：<?= (int) $point['count'] ?> 条">
                        <div class="trend-bar-wrap">
                            <div class="trend-bar" style="height: <?= max(2, $height) ?>%"></div>
                        </div>
                        <span class="trend-count"><?= (int) $point['count'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="trend-x" aria-hidden="true">
                <?php foreach ($trend as $i => $point): ?>
                    <?php $showDate = $i % $dateStep === 0 || $i === $trendTotal - 1; ?>
                    <span class="trend-x-item"><?= $showDate ? e(date('m-d', strtotime((string) $point['date']))) : '' ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ((int) $trendSummary['activeDays'] === 0): ?>
            <p class="hint">该周期内没有任何新增，可切换到更长周期查看历史录入情况。</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="card-title">案例状态分布</h2>
        <ul class="status-list">
            <?php foreach (case_status_map() as $key => $label): ?>
                <li>
                    <span class="status-dot status-<?= e($key) ?>"></span>
                    <span class="status-name"><?= e($label) ?></span>
                    <span class="status-num"><?= (int) ($statusCounts[$key] ?? 0) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hint">
            待审核案例需超级管理员在「故障案例」中审核通过后，才会在前台展示。
        </p>
    </section>
</div>

<div class="panel-grid">
    <section class="card">
        <h2 class="card-title">
            <?php if ($drillId > 0): ?>
                工序故障分布
                <a class="btn btn-ghost btn-xs" href="<?= e(admin_url('index.php')) ?>" style="margin-left:8px">返回工序总览</a>
            <?php else: ?>
                各工序文档数量 <em class="muted small">点击工序名可下钻查看 7 类故障分布</em>
            <?php endif; ?>
        </h2>
        <?php if ($drillId > 0): ?>
            <?php if ($drillStats === []): ?>
                <p class="hint">该工序下暂无文档。</p>
            <?php else: ?>
                <ul class="bar-list">
                    <?php $maxDrill = 1; foreach ($drillStats as $item) { $maxDrill = max($maxDrill, (int) $item['count']); } ?>
                    <?php foreach ($drillStats as $item): ?>
                        <li>
                            <span class="bar-name"><?= e($item['name']) ?></span>
                            <span class="bar-track">
                                <span class="bar-fill" style="width: <?= (int) round(((int) $item['count'] / $maxDrill) * 100) ?>%"></span>
                            </span>
                            <span class="bar-num"><?= (int) $item['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($processStats === []): ?>
                <p class="hint">暂无数据。</p>
            <?php else: ?>
                <ul class="bar-list">
                    <?php foreach ($processStats as $item): ?>
                        <li>
                            <span class="bar-name">
                                <?php if ((int) $item['process_id'] > 0): ?>
                                    <a href="<?= e(admin_url('index.php?drill=' . (int) $item['process_id'])) ?>"><?= e($item['name']) ?></a>
                                <?php else: ?>
                                    <?= e($item['name']) ?>
                                <?php endif; ?>
                            </span>
                            <span class="bar-track">
                                <span class="bar-fill" style="width: <?= (int) round(((int) $item['count'] / $maxCategory) * 100) ?>%"></span>
                            </span>
                            <span class="bar-num"><?= (int) $item['count'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="card-title">高浏览故障案例</h2>
        <?php if ($topViewed === []): ?>
            <p class="hint">暂无浏览数据。</p>
        <?php else: ?>
            <table class="table table-compact">
                <thead>
                    <tr><th>案例</th><th class="ta-r">浏览量</th></tr>
                </thead>
                <tbody>
                <?php foreach ($topViewed as $item): ?>
                    <tr>
                        <td>
                            <a href="<?= e(admin_url('case_form.php?id=' . (int) $item['id'])) ?>">
                                <?= e(excerpt((string) $item['title'], 26)) ?>
                            </a>
                            <em class="muted"><?= e((string) ($item['category_name'] ?? '未分类')) ?></em>
                        </td>
                        <td class="ta-r"><?= (int) $item['views'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<div class="panel-grid">
    <section class="card">
        <h2 class="card-title">热门检索关键词</h2>
        <?php if ($topKeywords === []): ?>
            <p class="hint">暂无检索记录。</p>
        <?php else: ?>
            <table class="table table-compact">
                <thead>
                    <tr><th>关键词</th><th class="ta-r">检索次数</th><th class="ta-r">无结果</th></tr>
                </thead>
                <tbody>
                <?php foreach ($topKeywords as $item): ?>
                    <tr>
                        <td><?= e($item['keyword']) ?></td>
                        <td class="ta-r"><?= (int) $item['times'] ?></td>
                        <td class="ta-r"><?= (int) $item['no_result'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="card-title">无结果检索词（待补充案例）</h2>
        <?php if ($noResultWords === []): ?>
            <p class="hint">暂无无结果检索记录，检索命中情况良好。</p>
        <?php else: ?>
            <ul class="tag-list">
                <?php foreach ($noResultWords as $item): ?>
                    <li>
                        <span class="tag"><?= e($item['keyword']) ?></span>
                        <em><?= (int) $item['times'] ?> 次</em>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="hint">这些关键词在前台没有命中案例，建议优先补充对应故障案例。</p>
        <?php endif; ?>
    </section>

    <?php
    /**
     * 内容保鲜 · 待复核清单（断点D）。
     *
     * 【为什么放在看板而不是单独菜单】
     * 它回答的是「现在该做什么」—— 属于待办事项而非独立功能，
     * 放在看板顶部区域最容易被看到。新增菜单项反而会像「无结果检索词」一样被忽略。
     *
     * 【清单规则刻意保守】
     * 只挑「有明确信号」的：有人投票说没用，或高浏览却零反馈。
     * 低浏览零反馈的不列 —— 那可能只是没人遇到这个故障，不是内容有问题。
     * 全列出来会让清单变噪音，管理员就会忽略它。
     */
    ?>
    <section class="card">
        <h2 class="card-title">内容保鲜 · 待复核（<?= count($reviewQueue) ?> 条）</h2>

        <?php if ((int) $effOverview['withVotes'] === 0): ?>
            <p class="hint">
                前台尚未收到有效性反馈。案例详情页底部有两个按钮（帮到了 / 没帮到），
                点一下就能帮系统判断哪些内容真的有用 —— 一线点两下的成本，
                换来的是「哪些案例该重写」有据可依。
            </p>
        <?php elseif ($reviewQueue === []): ?>
            <p class="hint">
                暂无待复核内容。
                <?= (int) $effOverview['withVotes'] ?> 条案例已收到反馈且帮到率正常。
            </p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>案例</th>
                    <th style="width:100px">工序</th>
                    <th style="width:130px">信号</th>
                    <th style="width:70px">浏览</th>
                    <th style="width:80px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($reviewQueue as $row): ?>
                    <?php
                    $votes  = (int) $row['votes'];
                    $hasVoted = $votes > 0;
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(admin_url('case_form.php?id=' . (int) $row['id'])) ?>">
                                <?= e(excerpt((string) $row['title'], 40)) ?>
                            </a>
                        </td>
                        <td><?= e((string) ($row['process_name'] ?? '')) ?: '—' ?></td>
                        <td>
                            <?php if ($hasVoted): ?>
                                <span class="badge badge-review">
                                    <?= (int) $row['helpful'] ?><?php echo (int) $row['unhelpful'] > 0 ? '/' . (int) $row['unhelpful'] : ''; ?> 帮到率低
                                </span>
                            <?php else: ?>
                                <span class="badge">高浏览零反馈</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $row['views'] ?></td>
                        <td>
                            <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('case_form.php?id=' . (int) $row['id'])) ?>">编辑</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint">
                「帮到率低」= 有员工点过且多数说没用；「高浏览零反馈」= 很多人看过却没人投票。
                两种都指向内容需要复核 —— 要么补充适用范围，要么改写现象描述。
            </p>
        <?php endif; ?>

        <p class="hint">
            整体：已发布 <?= (int) $effOverview['published'] ?> 条 ·
            已收到反馈 <?= (int) $effOverview['withVotes'] ?> 条 ·
            零帮到 <?= (int) $effOverview['zeroUsefulRate'] ?> 条
        </p>
    </section>
</div>

<?php if ($isSuper): ?>
<section class="card">
    <h2 class="card-title">日志概览（需求 4.6）</h2>
    <div class="mini-grid">
        <div class="mini"><span>操作日志</span><strong><?= (int) $logSummary['operations'] ?></strong></div>
        <div class="mini"><span>今日操作</span><strong><?= (int) $logSummary['today_operations'] ?></strong></div>
        <div class="mini"><span>访问日志</span><strong><?= (int) $logSummary['accesses'] ?></strong></div>
        <div class="mini"><span>今日访问</span><strong><?= (int) $logSummary['today_accesses'] ?></strong></div>
        <div class="mini"><span>检索记录</span><strong><?= (int) $logSummary['searches'] ?></strong></div>
        <div class="mini"><span>登录失败</span><strong><?= (int) $logSummary['login_failed'] ?></strong></div>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('logs.php')) ?>">查看全部日志</a>
</section>
<?php endif; ?>
