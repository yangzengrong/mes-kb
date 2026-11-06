<?php
/**
 * 前台布局（需求 3.1 首页模块化布局 + 2.4 版权规范 + 导航栏优化改版）。
 *
 * @var string $content
 */
$navCategories = $categories ?? [];
$navProcesses  = $navProcesses ?? [];
// 顶部工序导航选中项：0 = 全部案例；检索页/详情页会传入当前工序 ID
$navActiveProcessId = (int) ($navActiveProcessId ?? 0);
$urlMapItems        = url_map_items();
$keywordInput       = $keyword ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? $siteName ?? '') ?><?= isset($pageTitle) && $pageTitle !== '' ? ' - ' : '' ?><?= e($siteName ?? '') ?></title>
<meta name="description" content="<?= e(excerpt((string) ($siteIntro ?? ''), 100)) ?>">
<?php /* 站点图标（2026-10-22）：SVG 优先给现代浏览器，ICO 兜底（内含 16/32/48 三层），
   iOS 添加到主屏用 apple-touch-icon。版本号由 asset() 带出，换图标不用清缓存。 */ ?>
<link rel="icon" href="<?= e(asset('img/favicon.ico')) ?>" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/front.css')) ?>">
</head>
<body class="front-body">

<?php
    /* 全局导航激活态：按当前 URI 判定。
       故障案例含详情页(/case/{id})；操作手册 = 检索页带 doc_type=manual */
    $uriPath  = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    $uriQuery = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    $isHome     = $uriPath === '';
    $isCase     = str_contains($uriPath, '/case')
        || (str_contains($uriPath, 'search') && !str_contains($uriQuery, 'doc_type=manual') && !str_contains($uriQuery, 'doc_type=changelog'));
    // 操作手册既包含独立手册页(/manuals)，也保留检索页 ?doc_type=manual 的旧入口
    $isManual   = str_contains($uriPath, 'manuals')
        || (str_contains($uriPath, 'search') && str_contains($uriQuery, 'doc_type=manual'));
    $isUpdates  = str_contains($uriPath, 'updates');
    $isFeedback = str_contains($uriPath, 'feedback');
    $isPerm     = str_contains($uriPath, 'permissions');
    $isDeviceIp = str_contains($uriPath, 'device-ip');
    /* 【为什么导航在 header 外面】position:sticky 的吸附范围被父元素高度卡死：
       导航放在 header 内时，header 只有「导航 + 作业系统直达条」约 100px 高，
       滚动几十 px 后导航就被父元素底部「拽走」，无法全程吸顶（2026-10-22 实测）。
       放到 body 直属后，包含块 = 整个页面，才真正全程吸附；
       「作业系统直达」条留在 header 内，保持原样随页滚动。 */
    ?>
<nav class="front-nav" aria-label="全局导航">
    <div class="container nav-inner" id="navInner">
        <a class="site-brand" href="<?= e(base_url('/')) ?>">
            <span class="brand-mark">MES</span>
            <span class="brand-text">
                <strong><?= e($siteName ?? '') ?></strong>
                <small>日常故障查询 · 运维经验沉淀</small>
            </span>
        </a>

        <a class="nav-cat<?= $isHome ? ' active' : '' ?>"
           href="<?= e(base_url('/')) ?>">首页</a>
        <a class="nav-cat<?= $isCase ? ' active' : '' ?>"
           href="<?= e(base_url('cases')) ?>">故障案例</a>
        <a class="nav-cat<?= $isManual ? ' active' : '' ?>"
           href="<?= e(base_url('manuals')) ?>">操作手册</a>
        <a class="nav-cat<?= $isUpdates ? ' active' : '' ?>"
           href="<?= e(base_url('updates')) ?>">版本更新</a>
        <?php if (feedback_enabled()): ?>
        <a class="nav-cat<?= $isFeedback ? ' active' : '' ?>"
           href="<?= e(base_url('feedback')) ?>">问题反馈</a>
        <?php endif; ?>
        <a class="nav-cat<?= $isPerm ? ' active' : '' ?>"
           href="<?= e(base_url('permissions')) ?>">菜单权限</a>
        <a class="nav-cat<?= $isDeviceIp ? ' active' : '' ?>"
           href="<?= e(base_url('device-ip')) ?>">设备IP</a>

        <div class="nav-more" id="navMore" hidden>
            <button type="button" class="nav-cat nav-more-btn" id="navMoreBtn" aria-haspopup="true">
                更多 <span class="nav-more-caret">▾</span>
            </button>
            <div class="nav-more-panel" id="navMorePanel"></div>
        </div>

        <form class="header-search" method="get" action="<?= e(base_url('search')) ?>" autocomplete="off">
            <input type="text" name="keyword" id="searchInput" placeholder="输入故障关键词 / 设备名称 / 故障现象"
                   value="<?= e((string) $keywordInput) ?>">
            <button type="submit">搜索</button>
            <div class="suggest-box" id="suggestBox"></div>
        </form>
    </div>
</nav>

<header class="front-header">

    <?php if ($urlMapItems !== []): ?>
    <div class="url-map">
        <div class="container url-map-inner">
            <span class="url-map-label">作业系统直达</span>
            <?php foreach ($urlMapItems as $entry): ?>
                <span class="url-map-item">
                    <strong><?= e($entry['name']) ?></strong>
                    <?php if ($entry['home'] !== ''): ?>
                        <a href="<?= e($entry['home']) ?>" target="_blank" rel="noopener"
                           title="打开【<?= e($entry['name']) ?>】系统主页">主页</a>
                    <?php endif; ?>
                    <?php if ($entry['work'] !== ''): ?>
                        <a class="url-map-work" href="<?= e($entry['work']) ?>" target="_blank" rel="noopener"
                           title="打开【<?= e($entry['name']) ?>】作业页面">作业页面</a>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</header>

<main class="front-main">
    <div class="container">
        <?php if (!empty($noticeOn) && !empty($notice)): ?>
            <div class="notice-bar">
                <span class="notice-tag">公告</span>
                <span><?= e((string) $notice) ?></span>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </div>
</main>

<footer class="front-footer">
    <div class="container">
        <p class="footer-copy"><?= e($copyright ?? '') ?></p>
        <p class="footer-sub"><?= e($siteName ?? '') ?> · 运维知识沉淀与快速检索平台</p>
    </div>
</footer>

<script>
window.MES_KB = {
    suggestUrl: "<?= e(base_url('suggest')) ?>",
    searchUrl: "<?= e(base_url('search')) ?>"
};
</script>
<script src="<?= e(asset('js/front.js')) ?>"></script>
</body>
</html>
