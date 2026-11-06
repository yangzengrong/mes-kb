<?php
/**
 * 通用分页组件。
 *
 * 需要变量：
 *  - $result      array{page:int,last_page:int,total:int,per_page:int}
 *  - $basePath    string 目标页面路径，如 cases.php
 *  - $queryParams array  需要保留的查询参数
 */

if (!isset($result) || !isset($basePath)) {
    return;
}

$currentPage = (int) ($result['page'] ?? 1);
$lastPage    = (int) ($result['last_page'] ?? 1);
$total       = (int) ($result['total'] ?? 0);
$queryParams = $queryParams ?? [];

if ($lastPage <= 1) {
    if ($total > 0) {
        echo '<div class="pager"><span class="pager-total">共 ' . $total . ' 条记录</span></div>';
    }
    return;
}

$link = static function (int $page) use ($basePath, $queryParams): string {
    $params         = $queryParams;
    $params['page'] = $page;
    return $basePath . '?' . http_build_query($params);
};

$window = 2;
$start  = max(1, $currentPage - $window);
$end    = min($lastPage, $currentPage + $window);
?>
<div class="pager">
    <span class="pager-total">共 <?= $total ?> 条 · 第 <?= $currentPage ?>/<?= $lastPage ?> 页</span>
    <div class="pager-links">
        <?php if ($currentPage > 1): ?>
            <a href="<?= e($link(1)) ?>">首页</a>
            <a href="<?= e($link($currentPage - 1)) ?>">上一页</a>
        <?php else: ?>
            <span class="disabled">首页</span>
            <span class="disabled">上一页</span>
        <?php endif; ?>

        <?php if ($start > 1): ?>
            <a href="<?= e($link(1)) ?>">1</a>
            <?php if ($start > 2): ?><span class="disabled">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($page = $start; $page <= $end; $page++): ?>
            <?php if ($page === $currentPage): ?>
                <span class="current"><?= $page ?></span>
            <?php else: ?>
                <a href="<?= e($link($page)) ?>"><?= $page ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $lastPage): ?>
            <?php if ($end < $lastPage - 1): ?><span class="disabled">…</span><?php endif; ?>
            <a href="<?= e($link($lastPage)) ?>"><?= $lastPage ?></a>
        <?php endif; ?>

        <?php if ($currentPage < $lastPage): ?>
            <a href="<?= e($link($currentPage + 1)) ?>">下一页</a>
            <a href="<?= e($link($lastPage)) ?>">末页</a>
        <?php else: ?>
            <span class="disabled">下一页</span>
            <span class="disabled">末页</span>
        <?php endif; ?>
    </div>
</div>
