<?php
/**
 * MES 版本更新页（展示每次更新的时间、内容与更新后的变化）。
 *
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<int,string> $suggestions
 */
$query = [];
?>

<div class="page-head">
    <h1>MES 版本更新</h1>
    <p class="page-sub">共 <strong><?= (int) $result['total'] ?></strong> 条更新记录，按更新时间倒序</p>
</div>

<?php if ($result['items'] === []): ?>
    <div class="empty-state empty-large">
        <h2>暂无版本更新记录</h2>
        <p>后台新增文档时选择「MES版本更新日志」类型并发布后，将在此处展示。</p>
        <div class="empty-actions">
            <a class="btn-line" href="<?= e(base_url('/')) ?>">返回首页</a>
        </div>
    </div>
<?php else: ?>
    <div class="update-timeline">
        <?php foreach ($result['items'] as $item): ?>
            <article class="update-item">
                <div class="update-item-head">
                    <span class="update-date-badge">
                        <?= e(date('Y-m-d', strtotime((string) ($item['published_at'] ?? $item['updated_at'])))) ?>
                    </span>
                    <a class="update-item-title" href="<?= e(base_url('case/' . (int) $item['id'])) ?>">
                        <?= e(plain_text((string) $item['title'], 120)) ?>
                    </a>
                </div>
                <?php if ((string) ($item['phenomenon'] ?? '') !== ''): ?>
                    <p class="update-line">
                        <span class="update-label">更新内容</span>
                        <span class="update-text"><?= e(plain_text((string) $item['phenomenon'], 160)) ?></span>
                    </p>
                <?php endif; ?>
                <?php if ((string) ($item['solution'] ?? '') !== ''): ?>
                    <p class="update-line">
                        <span class="update-label change">更新后的变化</span>
                        <span class="update-text"><?= e(plain_text((string) $item['solution'], 160)) ?></span>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => base_url('updates'),
        'queryParams' => $query,
    ]) ?>
<?php endif; ?>
