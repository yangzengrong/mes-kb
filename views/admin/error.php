<?php
/**
 * 后台错误提示页（权限不足等）。
 *
 * @var int    $code
 * @var string $message
 */
?>
<div class="card">
    <div class="empty-state">
        <div class="empty-code"><?= (int) ($code ?? 403) ?></div>
        <h2><?= e($title ?? '操作受限') ?></h2>
        <p><?= e($message ?? '当前请求无法完成。') ?></p>
        <a class="btn btn-primary" href="<?= e(admin_url('index.php')) ?>">返回数据看板</a>
    </div>
</div>
