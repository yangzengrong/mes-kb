<?php
/**
 * 故障案例：工序按钮筛选 + 案例列表（2026-10-22 二次改版）。
 *
 * 第一版是「工序卡片 hub」，点卡再跳检索：首屏全是卡片、看文章要多点一次。
 * 现在改成：顶部一排工序按钮，下方直接是案例列表；点工序按钮只换下半页内容
 * （走 ?process_id=X 服务端渲染，无 JS 也能用；带 #caseList 锚点直接落到列表）。
 *
 * @var array<int,array{id:int,name:string,desc:string,count:int}> $tabs 定稿工序
 * @var array<int,array{id:int,name:string,count:int}> $others 非定稿但有案例的工序
 * @var int $totalAll 全部已发布案例数
 * @var int $activeProcessId 当前筛选工序（0=全部）
 * @var string $activeName 当前工序名（用于标题，空=全部）
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 */

/** 工序按钮地址：带锚点，点完直接落到列表头，避免又从页面顶部看一遍 */
$tabUrl = static function (int $processId): string {
    return base_url('cases' . ($processId > 0 ? '?process_id=' . $processId : '')) . '#caseList';
};
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('/')) ?>">首页</a>
    <span>/</span>
    <?php if ($activeName !== ''): ?>
        <a href="<?= e(base_url('cases')) ?>">故障案例</a>
        <span>/</span>
        <em><?= e($activeName) ?></em>
    <?php else: ?>
        <em>故障案例</em>
    <?php endif; ?>
</nav>

<div class="page-head">
    <h1>故障案例</h1>
    <p class="page-sub">
        共 <strong><?= (int) $totalAll ?></strong> 篇已发布案例，默认按发布时间倒序。
        点下面的工序按钮，只看该工序的案例。
    </p>
</div>

<div class="proc-tabs" id="caseList">
    <a class="proc-tab<?= $activeProcessId === 0 ? ' is-active' : '' ?>" href="<?= e($tabUrl(0)) ?>">
        全部<em><?= (int) $totalAll ?></em>
    </a>
    <?php foreach ($tabs as $tab): ?>
        <a class="proc-tab<?= $activeProcessId === $tab['id'] ? ' is-active' : '' ?><?= $tab['count'] === 0 ? ' is-empty' : '' ?>"
           href="<?= e($tabUrl($tab['id'])) ?>"
           title="<?= e($tab['name'] . '：' . $tab['desc']) ?>">
            <?= e($tab['name']) ?><em><?= (int) $tab['count'] ?></em>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($others !== []): ?>
<div class="proc-others">
    <span class="proc-others-label">其他分类</span>
    <?php foreach ($others as $item): ?>
        <a class="proc-chip<?= $activeProcessId === $item['id'] ? ' is-active' : '' ?>"
           href="<?= e($tabUrl($item['id'])) ?>"><?= e($item['name']) ?><em><?= (int) $item['count'] ?></em></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="case-list-head">
    <h2><?= $activeName !== '' ? e($activeName) : '全部案例' ?></h2>
    <span class="case-list-count">共 <?= (int) $result['total'] ?> 篇</span>
</div>

<?php if ($result['items'] === []): ?>
    <div class="empty-state empty-large">
        <h2><?= $activeName !== '' ? e($activeName) . '暂无已发布案例' : '暂时还没有已发布案例' ?></h2>
        <p>
            <?= $activeName !== '' ? '该工序的案例还在整理中。' : '' ?>
            可以用报错原文、设备名称直接检索，或提交问题反馈，运维给出方案后会沉淀成标准案例。
        </p>
        <div class="empty-actions">
            <a class="btn-line" href="<?= e(base_url('search')) ?>">全文检索</a>
            <a class="btn-line ghost" href="<?= e(base_url('feedback')) ?>">提交问题反馈</a>
            <?php if ($activeProcessId !== 0): ?>
                <a class="btn-line ghost" href="<?= e(base_url('cases')) ?>">查看全部案例</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="result-list">
        <?php foreach ($result['items'] as $item): ?>
            <?php $caseId = (int) $item['id']; ?>
            <article class="result-item">
                <div class="result-head">
                    <a class="result-title" href="<?= e(base_url('case/' . $caseId)) ?>">
                        <?= e((string) $item['title']) ?>
                    </a>
                    <?php $rowProcess = (string) ($item['process_name'] ?? ''); ?>
                    <?php if ($rowProcess !== ''): ?>
                        <span class="case-process"><?= e($rowProcess) ?></span>
                    <?php endif; ?>
                    <span class="case-cat"><?= e((string) ($item['category_name'] ?? '未分类')) ?></span>
                    <?php if ((string) ($item['doc_type'] ?? 'case') !== 'case'): ?>
                        <span class="case-doctype"><?= e(doc_type_label((string) $item['doc_type'])) ?></span>
                    <?php endif; ?>
                    <button type="button" class="btn-copy-mini js-copy-link"
                            data-path="<?= e(base_url('case/' . $caseId)) ?>"
                            data-title="<?= e(strip_tags((string) $item['title'])) ?>"
                            title="复制该文章标题和链接，发给同事打开即见">
                        <span class="copy-icon">⧉</span><span class="copy-text">复制链接</span>
                    </button>
                </div>

                <p class="result-desc">
                    <?= e(plain_text((string) ($item['phenomenon'] ?? ''), 110)) ?>
                </p>

                <?php if ((string) ($item['solution'] ?? '') !== ''): ?>
                    <p class="result-solution">
                        <span class="label">解决方案</span>
                        <?= e(plain_text((string) $item['solution'], 110)) ?>
                    </p>
                <?php endif; ?>

                <div class="result-meta">
                    <?php if ((string) $item['device'] !== ''): ?>
                        <span class="meta-item">设备：<?= e((string) $item['device']) ?></span>
                    <?php endif; ?>
                    <?php if ((string) ($item['error_code'] ?? '') !== ''): ?>
                        <span class="meta-item">报错：<?= e((string) $item['error_code']) ?></span>
                    <?php endif; ?>
                    <?php foreach (array_filter(array_map('trim', explode(',', (string) ($item['tags'] ?? '')))) as $resultTag): ?>
                        <a class="meta-item meta-tag" href="<?= e(base_url('search?tag=' . urlencode($resultTag))) ?>">#<?= e($resultTag) ?></a>
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
        'basePath'    => base_url('cases'),
        'queryParams' => $activeProcessId > 0 ? ['process_id' => $activeProcessId] : [],
    ]) ?>
<?php endif; ?>

<p class="hub-foot">
    找不到对应工序的案例？<a href="<?= e(base_url('feedback')) ?>">提交问题反馈</a>，
    运维给出方案后会沉淀成标准案例并归入对应工序；也可以
    <a href="<?= e(base_url('u9-sync')) ?>">查 U9 同步报错速查表</a>。
</p>
