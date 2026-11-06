<?php
/**
 * 知识缺口（内容运营队列）。
 *
 * 两类信号汇成一个工作队列：
 *   ① 无结果检索词（search_logs.result_count = 0，同词 ≥2 次）——员工搜了没找到；
 *   ② 已办结未转案例的反馈（feedbacks.case_id = 0）——运维解决了没沉淀。
 * 只聚合与跳转，不采新数据；转化仍走反馈详情页的现有表单。
 *
 * @var array<int,array{keyword:string,times:int,no_result:int,last_at:string}> $gapWords
 * @var array<int,array<string,mixed>> $unconverted
 * @var int $recentNoResult
 */
?>

<div class="stat-grid stat-grid-sm">
    <div class="stat-card">
        <span class="stat-label">无结果检索词</span>
        <span class="stat-value"><?= count($gapWords) ?></span>
        <span class="stat-foot">同一词 ≥2 次无结果才计入</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">待转化反馈</span>
        <span class="stat-value"><?= count($unconverted) ?></span>
        <span class="stat-foot">已办结、未生成案例</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">近 7 天无结果检索</span>
        <span class="stat-value"><?= (int) $recentNoResult ?></span>
        <span class="stat-foot">次（不受计入门槛影响）</span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">无结果检索词 <em class="muted">员工搜了，但库里没有</em></h2>
    </div>
    <?php if ($gapWords === []): ?>
        <div class="empty-state">
            <p>暂无缺口：高频检索词均能在库内找到内容。</p>
            <p class="hint">有新反馈办结后，记得回来看看第二条队列。</p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>检索词</th>
                    <th class="ta-r" style="width:110px">检索次数</th>
                    <th class="ta-r" style="width:110px">无结果次数</th>
                    <th style="width:160px">最近一次无结果</th>
                    <th style="width:150px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($gapWords as $w): ?>
                <tr>
                    <td><strong><?= e((string) $w['keyword']) ?></strong></td>
                    <td class="ta-r"><?= (int) $w['times'] ?></td>
                    <td class="ta-r"><?= (int) $w['no_result'] ?></td>
                    <td class="small muted"><?= e((string) $w['last_at']) ?></td>
                    <td>
                        <a class="link-strong" target="_blank" rel="noopener"
                           href="<?= e(base_url('search?keyword=' . urlencode((string) $w['keyword']))) ?>">去检索</a>
                        <span class="muted"> · </span>
                        <a class="link-strong" href="<?= e(admin_url('case_form.php')) ?>">写案例</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">「去检索」在前台复核是否真的没有覆盖；确认后点「写案例」，把检索词作为标题起点。</p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">待转化反馈 <em class="muted">运维已办结，还没沉淀成案例</em></h2>
    </div>
    <?php if ($unconverted === []): ?>
        <div class="empty-state"><p>暂无待转化反馈：已办结的反馈都已生成案例。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:130px">反馈单号</th>
                    <th>问题标题</th>
                    <th style="width:110px">归属部门</th>
                    <th style="width:110px">发生工序</th>
                    <th style="width:160px">办结时间</th>
                    <th style="width:110px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($unconverted as $fb): ?>
                <tr>
                    <td class="fb-code"><?= e((string) $fb['code']) ?></td>
                    <td><?= e(excerpt((string) $fb['title'], 50)) ?></td>
                    <td class="small"><?= e((string) $fb['department']) ?></td>
                    <td class="small"><?= e((string) ($fb['process_name'] ?? '') !== '' ? $fb['process_name'] : '未指定') ?></td>
                    <td class="small muted"><?= e((string) $fb['handled_at']) ?></td>
                    <td>
                        <a class="link-strong"
                           href="<?= e(admin_url('feedback_detail.php?id=' . (int) $fb['id'])) ?>">去转化</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">「去转化」进入反馈详情页，员工描述会自动带入故障现象，只需补充解决方案与分类。</p>
    <?php endif; ?>
</div>
