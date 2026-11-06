<?php
/**
 * 前台错误页（404 / 案例不存在）。
 *
 * @var string $message
 */
?>
<div class="empty-state empty-large">
    <h2><?= e($pageTitle ?? '页面不存在') ?></h2>
    <p><?= e($message ?? '你访问的内容不存在。') ?></p>
    <div class="empty-actions">
        <a class="btn-line" href="<?= e(base_url('/')) ?>">返回首页</a>
        <a class="btn-line ghost" href="<?= e(base_url('search')) ?>">浏览全部案例</a>
    </div>
</div>
