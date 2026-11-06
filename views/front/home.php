<?php
/**
 * 前台首页（需求 3.1 首页展示 / 4.8 前端用户交互）。
 *
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $processes
 * @var array<int,array<string,mixed>> $positions
 * @var int $manualTotal
 * @var int $manualPositionCount
 * @var array<int,array<string,mixed>> $latestManuals
 * @var array<int,array<string,mixed>> $changelogs
 * @var array<int,array<string,mixed>> $latestCases
 * @var array<int,array<string,mixed>> $hotCases
 * @var array<int,array<string,mixed>> $weeklyTop
 * @var int $totalCases
 * @var int $categoryId
 * @var array<int,array{keyword:string,times:int,no_result:int}> $topKeywords
 */
/* 最新案例：3×3 卡片网格 */
$caseCard = static function (array $item): string {
    $id = (int) $item['id'];
    $isNew = case_is_new($item);
    $html  = '<a class="case-card' . ($isNew ? ' is-new' : '') . '" href="' . e(base_url('case/' . $id)) . '">';
    $html .= '<div class="case-card-top">';
    $processName = (string) ($item['process_name'] ?? '');
    if ($processName !== '') {
        $html .= '<span class="case-process">' . e($processName) . '</span>';
    }
    $html .= '<span class="case-cat">' . e((string) ($item['category_name'] ?? '未分类')) . '</span>';
    if ($isNew) {
        $html .= new_badge();
    }
    if ((int) ($item['is_top'] ?? 0) === 1) {
        $html .= '<span class="case-top">置顶</span>';
    }
    $html .= '</div>';
    $html .= '<h3 class="case-title">' . e(excerpt((string) $item['title'], 40)) . '</h3>';
    $html .= '<p class="case-desc">' . e(excerpt((string) ($item['phenomenon'] ?? ''), 68)) . '</p>';
    $html .= '<div class="case-meta">';
    if ((string) ($item['device'] ?? '') !== '') {
        $html .= '<span class="meta-item">' . e(excerpt((string) $item['device'], 14)) . '</span>';
    }
    $html .= '<span class="meta-item meta-views">' . (int) $item['views'] . ' 次浏览</span>';
    $html .= '</div>';
    $html .= '</a>';
    return $html;
};
?>

<section class="hero">
    <div class="hero-text">
        <h1>MES 故障知识库</h1>
        <p><?= e((string) ($siteIntro ?? '')) ?></p>
        <div class="hero-stats">
            <span><strong><?= (int) $totalCases ?></strong> 篇已发布文档</span>
            <span><strong><?= count($processes) ?></strong> 个生产工序</span>
            <span>支持全文模糊检索</span>
        </div>
    </div>
    <form class="hero-search" method="get" action="<?= e(base_url('search')) ?>" autocomplete="off">
        <input type="text" name="keyword" id="searchInput"
               placeholder="例如：绞线报工、成缆扫码、实验室数据采集报错">
        <button type="submit">立即检索</button>
        <div class="suggest-box" id="suggestBox"></div>
    </form>
</section>

<?php if (feedback_enabled()): ?>
<section class="block fb-cta">
    <div class="fb-cta-inner">
        <div class="fb-cta-text">
            <strong>生产中遇到 MES 疑难问题？</strong>
            <span>扫码失灵、报工报错、数据不同步……直接提交，运维给出解决方案后自动沉淀到知识库。</span>
        </div>
        <div class="fb-cta-actions">
            <a class="fb-cta-btn" href="<?= e(base_url('feedback')) ?>">✎ 提交问题反馈</a>
            <a class="fb-cta-link" href="<?= e(base_url('feedback')) ?>#fb-track">查询处理进度 →</a>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="block process-bar-block">
    <div class="process-bar">
        <a class="process-chip active" href="<?= e(base_url('/')) ?>">全部案例</a>
        <?php foreach ($processes as $process): ?>
            <a class="process-chip" href="<?= e(base_url('search?process_id=' . (int) $process['id'])) ?>"
               title="点击查看【<?= e((string) $process['name']) ?>】MES相关文档">
                <?= e((string) $process['name']) ?>
                <em><?= (int) $process['published_count'] ?></em>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php
$manualTotal         = (int) ($manualTotal ?? 0);
$manualPositionCount = (int) ($manualPositionCount ?? 0);
$latestManuals       = isset($latestManuals) && is_array($latestManuals) ? $latestManuals : [];
$hasManualBlock      = ($manualTotal > 0 || $latestManuals !== []);
$hasChangelogBlock   = ($changelogs !== []);
$duoBoth             = ($hasManualBlock && $hasChangelogBlock);
?>
<?php if ($hasManualBlock || $hasChangelogBlock): ?>
<!-- 系统操作手册 与 MES 版本更新 左右并行（两栏结构对称、列表按内容取高；窄屏自动回落单列） -->
<div class="home-duo<?= $duoBoth ? '' : ' is-single' ?>">
<?php if ($hasManualBlock): ?>
<section class="block duo-col">
    <div class="block-head">
        <h2>系统操作手册</h2>
        <a class="block-more" href="<?= e(base_url('search?doc_type=manual')) ?>">查看全部手册 →</a>
    </div>
    <p class="block-sub">按 MES 系统模块梳理的标准操作步骤，含系统配置、单据流转、常用功能等；可按标签与匹配岗位检索。</p>

    <?php if ($latestManuals !== []): ?>
    <div class="scroll-caption">
        <span class="caption-title">最新手册</span>
    </div>
    <div class="scroll-wrap" data-scroll-list>
        <div class="scroll-track">
            <?php foreach ($latestManuals as $manual): ?>
                <a class="manual-item" href="<?= e(base_url('case/' . (int) $manual['id'])) ?>">
                    <span class="manual-position"><?= e((string) ($manual['position_name'] ?? '通用')) ?></span>
                    <span class="manual-title">
                        <?php if (case_is_new($manual)): ?><?= new_badge() ?><?php endif; ?>
                        <?= e(plain_text((string) $manual['title'], 46)) ?>
                    </span>
                    <span class="manual-date"><?= e(date('Y-m-d', strtotime((string) ($manual['published_at'] ?? $manual['updated_at'])))) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($hasChangelogBlock): ?>
<section class="block duo-col">
    <div class="block-head">
        <h2>MES 版本更新</h2>
        <a class="block-more" href="<?= e(base_url('updates')) ?>">全部更新记录 →</a>
    </div>
    <p class="block-sub">MES 每次发版的变更内容与注意事项，点击条目可展开详情。</p>
    <div class="scroll-caption">
        <span class="caption-title">更新记录</span>
        <span class="caption-hint">悬停暂停 ⏸</span>
    </div>
    <div class="scroll-wrap" data-scroll-list>
        <div class="scroll-track">
            <?php foreach ($changelogs as $log): ?>
                <div class="update-row">
                    <span class="update-date"><?= e(date('Y-m-d', strtotime((string) ($log['published_at'] ?? $log['updated_at'])))) ?></span>
                    <div class="update-main">
                        <a class="update-title" href="<?= e(base_url('case/' . (int) $log['id'])) ?>">
                            <?= e(plain_text((string) $log['title'], 40)) ?>
                        </a>
                        <p class="update-summary"><span class="update-label">更新内容</span><?= e(plain_text((string) ($log['phenomenon'] ?? ''), 80)) ?></p>
                        <p class="update-summary"><span class="update-label change">更新后的变化</span><?= e(plain_text((string) ($log['solution'] ?? ''), 80)) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
</div>
<?php endif; ?>

<script>
/* 首页双栏交互：更新记录折叠 + 滚动列表（无外部依赖，仅增删 class/属性，不改动原有 DOM 结构） */
(function () {
    /* ① 更新记录：默认收起「更新内容 / 更新后的变化」，点击条目展开；展开期间暂停自动滚动 */
    function initCollapsibleUpdates() {
        var wraps = document.querySelectorAll('.home-duo [data-scroll-list]');
        wraps.forEach(function (wrap) {
            if (wrap.dataset.collapseReady) {
                return;
            }
            wrap.dataset.collapseReady = '1';

            function markRows() {
                wrap.querySelectorAll('.update-row').forEach(function (row) {
                    if (row.dataset.collapsible === '1') {
                        return;
                    }
                    row.dataset.collapsible = '1';
                    row.setAttribute('title', '点击「展开」查看更新内容与变化；点击标题查看完整记录');
                });
            }
            markRows();

            wrap.addEventListener('click', function (ev) {
                var el = ev.target;
                /* 点在标题链接上保持原行为：跳转详情页 */
                if (el.closest !== undefined && el.closest('.update-title') !== null) {
                    return;
                }
                var row = (el.closest !== undefined) ? el.closest('.update-row') : null;
                if (row === null) {
                    return;
                }
                ev.preventDefault();
                row.classList.toggle('is-open');
                /* 有任一条展开时暂停滚动，避免阅读时列表继续移动 */
                wrap.classList.toggle('is-paused', wrap.querySelector('.update-row.is-open') !== null);
            });
        });
    }

    /* ② 滚动列表：内容超出可视高度时匀速上滚，鼠标悬停暂停；装得下则静止并去掉上下淡出遮罩 */
    function initScrollLists() {
        var wraps = document.querySelectorAll('[data-scroll-list]');
        wraps.forEach(function (wrap) {
            if (wrap.dataset.scrollReady) {
                return;
            }
            wrap.dataset.scrollReady = '1';
            var track = wrap.querySelector('.scroll-track');
            /* 内容不足一屏：静止展示，并通知所在栏隐藏「悬停暂停」提示（列表本身不需要滚动） */
            function markStatic() {
                wrap.classList.add('is-static');
                var col = (wrap.closest !== undefined) ? wrap.closest('.duo-col') : null;
                if (col !== null) { col.classList.add('is-static-list'); }
            }
            if (track === null || track.children.length < 3) {
                markStatic();
                return;
            }

            /* 复制一份内容，滚动到底无缝衔接；内容不足一屏时不滚动 */
            var original = track.innerHTML;
            track.innerHTML = original + original;
            var halfHeight = track.scrollHeight / 2;
            if (halfHeight <= wrap.clientHeight) {
                track.innerHTML = original;
                markStatic();
                return;
            }

            var offset = 0;
            var paused = false;
            var last = null;

            wrap.addEventListener('mouseenter', function () { paused = true; });
            wrap.addEventListener('mouseleave', function () { paused = false; });

            function step(ts) {
                if (last === null) { last = ts; }
                var dt = ts - last;
                last = ts;
                if (!paused && !wrap.classList.contains('is-paused')) {
                    offset += dt * 0.028; /* 约 28px/秒，舒缓滚动 */
                    if (offset >= halfHeight) { offset -= halfHeight; }
                    track.style.transform = 'translateY(' + (-offset) + 'px)';
                }
                window.requestAnimationFrame(step);
            }
            window.requestAnimationFrame(step);
        });
    }

    function init() {
        initScrollLists();        /* 先复制内容，克隆出的条目才能被下一步一并标记 */
        initCollapsibleUpdates();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<?php if ($topKeywords !== []): ?>
<section class="block">
    <div class="block-head">
        <h2>热门检索</h2>
    </div>
    <div class="keyword-chips">
        <?php foreach ($topKeywords as $item): ?>
            <a class="chip" href="<?= e(base_url('search?keyword=' . urlencode($item['keyword']))) ?>">
                <?= e($item['keyword']) ?>
                <em><?= (int) $item['times'] ?></em>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="block">
    <div class="block-head">
        <h2>最新故障案例</h2>
        <a class="block-more" href="<?= e(base_url('search')) ?>">查看全部 →</a>
    </div>
    <?php if ($latestCases === []): ?>
        <div class="empty-state"><p>暂无已发布案例，请登录后台补充。</p></div>
    <?php else: ?>
        <div class="case-grid">
            <?php foreach ($latestCases as $item): ?>
                <?= $caseCard($item) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
/* 本周最多浏览：数据来自访问日志（周一 00:00 起算）；本周无记录时回落累计热门榜 */
$weekItems  = isset($weeklyTop) && is_array($weeklyTop) ? $weeklyTop : [];
$weekIsReal = $weekItems !== [];
if (!$weekIsReal) {
    $weekItems = $hotCases;
}
$weekFromLabel = date('m月d日', strtotime('monday this week'));
?>
<?php if ($weekItems !== []): ?>
<section class="block">
    <div class="block-head">
        <h2>本周最多浏览</h2>
        <span class="block-note">统计周期：<?= e($weekFromLabel) ?> — 今日</span>
    </div>
    <p class="block-sub">
        <?= $weekIsReal
            ? '按本周内同事打开次数排序，大家都查的内容往往是最常踩的坑。'
            : '本周暂无浏览记录，暂以累计浏览量展示。' ?>
    </p>
    <ol class="hot-list">
        <?php foreach ($weekItems as $index => $item): ?>
            <li>
                <span class="hot-rank<?= $index < 3 ? ' top' : '' ?>"><?= $index + 1 ?></span>
                <a class="hot-title" href="<?= e(base_url('case/' . (int) $item['id'])) ?>">
                    <?php if (case_is_new($item)): ?><?= new_badge() ?><?php endif; ?>
                    <?= e(excerpt((string) $item['title'], 46)) ?>
                </a>
                <?php $weekProcess = (string) ($item['process_name'] ?? ''); ?>
                <span class="hot-cat"><?= $weekProcess !== '' ? e($weekProcess) . ' · ' : '' ?><?= e((string) ($item['category_name'] ?? '未分类')) ?></span>
                <?php if ($weekIsReal): ?>
                    <span class="hot-views hot-views-week" title="本周打开次数">本周 <?= (int) ($item['week_views'] ?? 0) ?> 次</span>
                <?php else: ?>
                    <span class="hot-views"><?= (int) $item['views'] ?> 次</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<section class="block">
    <div class="block-head">
        <h2>热门故障案例</h2>
        <a class="block-more" href="<?= e(base_url('search')) ?>">按浏览量排序 →</a>
    </div>
    <?php if ($hotCases === []): ?>
        <div class="empty-state"><p>暂无浏览数据。</p></div>
    <?php else: ?>
        <ol class="hot-list">
            <?php foreach ($hotCases as $index => $item): ?>
                <li>
                    <span class="hot-rank<?= $index < 3 ? ' top' : '' ?>"><?= $index + 1 ?></span>
                    <a class="hot-title" href="<?= e(base_url('case/' . (int) $item['id'])) ?>">
                        <?php if (case_is_new($item)): ?><?= new_badge() ?><?php endif; ?>
                        <?= e(excerpt((string) $item['title'], 46)) ?>
                    </a>
                    <?php $hotProcess = (string) ($item['process_name'] ?? ''); ?>
                    <span class="hot-cat"><?= $hotProcess !== '' ? e($hotProcess) . ' · ' : '' ?><?= e((string) ($item['category_name'] ?? '未分类')) ?></span>
                    <span class="hot-views"><?= (int) $item['views'] ?> 次</span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>

<?php
/* MES → U9 同步查询入口（2026-10-22 替换原「按工序分类浏览」网格）。
   原网格把 7 工序 × 11 故障类型全量铺开（最多 12 张卡、77 个链接），
   首页被塞成目录页；实际上「按工序找」已由导航「故障案例」承接，
   这里只留同步报错这一个高频场景，其余交给搜索。 */
$u9Groups      = isset($u9Groups) && is_array($u9Groups) ? $u9Groups : [];
$u9GroupCounts = isset($u9GroupCounts) && is_array($u9GroupCounts) ? $u9GroupCounts : [];
$u9Total       = (int) ($u9Total ?? 0);
?>
<?php if ($u9Groups !== []): ?>
<section class="block u9-entry">
    <div class="u9-entry-head">
        <div>
            <h2>MES → U9 同步不成功查询</h2>
            <p class="block-sub">
                同步报错先在这里定位环节：是 MES 缺数据、上游单据没同步、库存对不上，还是单据已在 ERP 重复生成。
                共 <?= (int) $u9Total ?> 条已知报错，每条给处理步骤与责任方。
            </p>
        </div>
        <a class="block-more" href="<?= e(base_url('u9-sync')) ?>">进入速查表 →</a>
    </div>

    <form class="u9-entry-search" method="get" action="<?= e(base_url('u9-sync')) ?>" autocomplete="off">
        <input type="text" name="q" placeholder="粘贴报错原文或错误码，如：未能找到料品 / GR-05 / 负库存">
        <button type="submit">查一下</button>
    </form>

    <div class="u9-entry-groups">
        <?php foreach ($u9Groups as $u9Key => $u9Meta): ?>
            <a class="u9-entry-chip" href="<?= e(base_url('u9-sync?group=' . $u9Key)) ?>">
                <span class="u9-entry-code"><?= e((string) $u9Key) ?></span>
                <?= e((string) $u9Meta['short']) ?>
                <em><?= (int) ($u9GroupCounts[$u9Key] ?? 0) ?></em>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
