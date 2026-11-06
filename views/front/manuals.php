<?php
/**
 * 操作手册：模块按钮筛选 + 手册列表（2026-10-24 新建）。
 *
 * 与「故障案例」页刻意保持同一套导航逻辑：顶部一排模块按钮，下方直接是列表，
 * 点按钮只换下半页内容（?module=X 服务端渲染，无 JS 也能用）。
 *
 * 手册与案例的差异在这里体现：手册正文多写在附件里（常见正文只有「点击附件查看详情」），
 * 所以列表不强行展示「解决方案」字段，而是把模块名前置、把附件数显式写出来——
 * 找手册的人要的是「这个功能的说明在哪」，不是先在列表里读一遍。
 *
 * @var array<int,array{id:int,name:string,total:int}> $modules  有手册的功能模块
 * @var int    $activeId   当前模块（0=全部）
 * @var string $activeName 当前模块名
 * @var int    $totalAll   全部已发布手册数
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 */

/** 模块按钮地址：带锚点，点完直接落到列表头，避免又从页面顶部看一遍 */
$moduleUrl = static function (int $id): string {
    return base_url('manuals' . ($id > 0 ? '?module=' . $id : '')) . '#manualList';
};
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('/')) ?>">首页</a>
    <span>/</span>
    <?php if ($activeName !== ''): ?>
        <a href="<?= e(base_url('manuals')) ?>">操作手册</a>
        <span>/</span>
        <em><?= e($activeName) ?></em>
    <?php else: ?>
        <em>操作手册</em>
    <?php endif; ?>
</nav>

<div class="page-head">
    <h1>操作手册</h1>
    <p class="page-sub">
        共 <strong><?= (int) $totalAll ?></strong> 篇已发布手册，按功能模块归类。
        点下面的模块按钮，只看该模块的操作说明。
    </p>
</div>

<?php if ($modules === []): ?>
    <div class="empty-state empty-large">
        <h2>暂时还没有已发布手册</h2>
        <p>手册发布后会出现在这里，并按功能模块归类。</p>
        <div class="empty-actions">
            <a class="btn-line" href="<?= e(base_url('search')) ?>">全文检索</a>
            <a class="btn-line ghost" href="<?= e(base_url('feedback')) ?>">提交问题反馈</a>
        </div>
    </div>
<?php else: ?>

<div class="proc-tabs" id="manualList">
    <a class="proc-tab<?= $activeId === 0 ? ' is-active' : '' ?>" href="<?= e($moduleUrl(0)) ?>">
        全部<em><?= (int) $totalAll ?></em>
    </a>
    <?php foreach ($modules as $module): ?>
        <a class="proc-tab<?= $activeId === (int) $module['id'] ? ' is-active' : '' ?>"
           href="<?= e($moduleUrl((int) $module['id'])) ?>"
           title="<?= e($module['name'] . '：' . (int) $module['total'] . ' 篇手册') ?>">
            <?= e((string) $module['name']) ?><em><?= (int) $module['total'] ?></em>
        </a>
    <?php endforeach; ?>
</div>

<div class="case-list-head">
    <h2><?= $activeName !== '' ? e($activeName) : '全部手册' ?></h2>
    <span class="case-list-count">共 <?= (int) $result['total'] ?> 篇</span>
</div>

<?php if ($result['items'] === []): ?>
    <div class="empty-state empty-large">
        <h2><?= $activeName !== '' ? e($activeName) . '暂无已发布手册' : '暂时还没有已发布手册' ?></h2>
        <p>
            可以用功能名称、单据名称直接检索，或提交问题反馈，运维补充后会沉淀到这里。
        </p>
        <div class="empty-actions">
            <a class="btn-line" href="<?= e(base_url('search')) ?>">全文检索</a>
            <a class="btn-line ghost" href="<?= e(base_url('feedback')) ?>">提交问题反馈</a>
            <a class="btn-line ghost" href="<?= e(base_url('manuals')) ?>">查看全部手册</a>
        </div>
    </div>
<?php else: ?>
    <div class="result-list">
        <?php foreach ($result['items'] as $item): ?>
            <?php $manualId = (int) $item['id']; ?>
            <article class="result-item">
                <div class="result-head">
                    <a class="result-title" href="<?= e(base_url('case/' . $manualId)) ?>">
                        <?= e((string) $item['title']) ?>
                    </a>
                    <?php if ((string) ($item['category_name'] ?? '') !== ''): ?>
                        <span class="case-cat"><?= e((string) $item['category_name']) ?></span>
                    <?php endif; ?>
                    <button type="button" class="btn-copy-mini js-copy-link"
                            data-path="<?= e(base_url('case/' . $manualId)) ?>"
                            data-title="<?= e(strip_tags((string) $item['title'])) ?>"
                            title="复制该手册标题和链接，发给同事打开即见">
                        <span class="copy-icon">⧉</span><span class="copy-text">复制链接</span>
                    </button>
                </div>

                <?php $summary = plain_text((string) ($item['phenomenon'] ?? ''), 110); ?>
                <?php if ($summary !== ''): ?>
                    <p class="result-desc"><?= e($summary) ?></p>
                <?php endif; ?>

                <div class="result-meta">
                    <?php foreach (array_filter(array_map('trim', explode(',', (string) ($item['tags'] ?? '')))) as $manualTag): ?>
                        <a class="meta-item meta-tag" href="<?= e(base_url('search?tag=' . urlencode($manualTag))) ?>">#<?= e($manualTag) ?></a>
                    <?php endforeach; ?>
                    <span class="meta-item meta-views"><?= (int) $item['views'] ?> 次浏览</span>
                    <span class="meta-item meta-date">
                        <?= e(date('Y-m-d', strtotime((string) ($item['published_at'] ?? $item['updated_at'])))) ?>
                    </span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => base_url('manuals'),
        'queryParams' => $activeId > 0 ? ['module' => $activeId] : [],
    ]) ?>
<?php endif; ?>
<?php endif; ?>
