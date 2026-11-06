<?php
/**
 * 后台主框架布局（需求 2.3 独立后台入口 + 4.1 按角色渲染菜单）。
 *
 * 结构：侧栏（分组折叠 + 可收起） + 顶栏（面包屑 + 用户区） + 内容区
 *
 * @var string $content
 * @var array<string,mixed>|null $currentUser
 * @var string $pageTitle
 * @var string $activeMenu
 * @var array<string,string>|null $flash
 * @var int $trashCount
 * @var int $pendingCount
 * @var int $pendingFeedbackCount
 */
$user       = $currentUser ?? null;
$active     = (string) ($activeMenu ?? '');
$role       = (string) ($user['role'] ?? 'guest');
$isSuper    = $role === 'super_admin';
$flashMsg   = $flash ?? null;

/**
 * 侧栏菜单分组。
 *
 * 【为什么分组】
 * 早期版本 13 项菜单平铺，其中「故障案例 / 系统操作手册 / 版本更新日志」本质是
 * 同一张 cases 表的三种 doc_type 视图，「工序 / 岗位 / 故障类型 / 菜单权限库」
 * 属于同一族配置数据。混在一起平铺时用户每次都要重新扫视才能找到目标。
 * 分为 4 组后，语义边界清晰，且组数固定（4）不随功能增加而膨胀。
 *
 * 每项：key（对应控制器传的 activeMenu）、label、url、icon、permission、badge
 *
 * @var array<int,array{label:string,items:array<int,array<string,mixed>>}>
 */
$menuGroups = [
    [
        'label' => '概览',
        'items' => [
            ['key' => 'dashboard', 'label' => '数据看板', 'url' => admin_url('index.php'),
             'icon' => '▦', 'permission' => 'dashboard.view'],
        ],
    ],
    [
        'label' => '内容管理',
        /* 【add 字段 = 该菜单项的「新建」子动作】
           文档类型已从表单里的下拉框移到菜单：用户从「故障案例」进就写案例，
           从「系统操作手册」进就写手册。原先只有列表页右上角一个「新增」按钮，
           要新建手册必须先进手册列表再点按钮，多一次跳转。
           有了 add，直接在侧栏菜单项右侧带一个「+」，一步到位。
           add 的 URL 与列表页按钮同源（cases.php:90），保证类型不会错配。 */
        'items' => [
            ['key' => 'cases', 'label' => '故障案例', 'url' => admin_url('cases.php'),
             'icon' => '▤', 'permission' => 'case.view', 'badge' => 'pendingCount',
             'add' => admin_url('case_form.php'), 'addTitle' => '新增故障案例'],
            ['key' => 'manuals', 'label' => '系统操作手册', 'url' => admin_url('cases.php?doc_type=manual'),
             'icon' => '▣', 'permission' => 'case.view',
             'add' => admin_url('case_form.php?doc_type=manual'), 'addTitle' => '新增系统操作手册'],
            ['key' => 'changelogs', 'label' => '版本更新日志', 'url' => admin_url('cases.php?doc_type=changelog'),
             'icon' => '◪', 'permission' => 'case.view',
             'add' => admin_url('case_form.php?doc_type=changelog'), 'addTitle' => '新增版本更新日志'],
            ['key' => 'recycle', 'label' => '回收站', 'url' => admin_url('cases.php?trashed=1'),
             'icon' => '♻', 'permission' => 'case.view', 'badge' => 'trashCount'],
        ],
    ],
    [
        'label' => '基础配置',
        'items' => [
            ['key' => 'processes', 'label' => '工序管理', 'url' => admin_url('process.php'),
             'icon' => '◈', 'permission' => 'category.view'],
            ['key' => 'positions', 'label' => '岗位管理', 'url' => admin_url('position.php'),
             'icon' => '◔', 'permission' => 'category.view'],
            ['key' => 'categories', 'label' => '故障类型', 'url' => admin_url('categories.php'),
             'icon' => '▥', 'permission' => 'category.view'],
            ['key' => 'menus', 'label' => 'MES菜单权限库', 'url' => admin_url('menus.php'),
             'icon' => '⚿', 'permission' => 'category.view'],
            ['key' => 'device_ips', 'label' => '设备IP管理', 'url' => admin_url('device_ips.php'),
             'icon' => '⌘', 'permission' => 'device.view'],
            ['key' => 'attachments', 'label' => '附件管理', 'url' => admin_url('attachments.php'),
             'icon' => '▧', 'permission' => 'attachment.view'],
        ],
    ],
    [
        'label' => '系统',
        'items' => [
            ['key' => 'feedbacks', 'label' => '员工反馈', 'url' => admin_url('feedbacks.php'),
             'icon' => '✎', 'permission' => 'feedback.view', 'badge' => 'pendingFeedbackCount'],
            ['key' => 'feedback_stats', 'label' => '反馈统计', 'url' => admin_url('feedback_stats.php'),
             'icon' => '▤', 'permission' => 'feedback.view'],
            ['key' => 'gaps', 'label' => '知识缺口', 'url' => admin_url('gaps.php'),
             'icon' => '◌', 'permission' => 'feedback.view'],
            ['key' => 'logs', 'label' => '系统日志', 'url' => admin_url('logs.php'),
             'icon' => '▩', 'permission' => 'log.view_own'],
            ['key' => 'users', 'label' => '用户权限', 'url' => admin_url('users.php'),
             'icon' => '◍', 'permission' => '__super__'],
            ['key' => 'settings', 'label' => '系统配置', 'url' => admin_url('settings.php'),
             'icon' => '⚙', 'permission' => '__super__'],
        ],
    ],
];

// 按权限过滤：__super__ 表示仅超级管理员可见
$badgeValues = [
    'trashCount'           => (int) ($trashCount ?? 0),
    'pendingCount'         => (int) ($pendingCount ?? 0),
    'pendingFeedbackCount' => (int) ($pendingFeedbackCount ?? 0),
];

$allowedGroups = [];
$activeGroupLabel = '';
$activeItemLabel  = '';
foreach ($menuGroups as $group) {
    $visible = [];
    foreach ($group['items'] as $item) {
        $allowed = $item['permission'] === '__super__'
            ? $isSuper
            : auth()->can($item['permission']);
        if (!$allowed) {
            continue;
        }
        if ($item['key'] === $active) {
            $activeGroupLabel = $group['label'];
            $activeItemLabel  = $item['label'];
        }
        $visible[] = $item;
    }
    if ($visible !== []) {
        $allowedGroups[] = ['label' => $group['label'], 'items' => $visible];
    }
}

/**
 * 面包屑。
 *
 * 【为什么不用控制器额外传】
 * 全部 14 个后台控制器都已经传了 activeMenu（BaseController::render 会兜底为空串），
 * 且 render() 第三参数已给出页面标题。因此面包屑可以纯由 activeMenu + pageTitle
 * 推导出来，无需改动任何控制器 —— 这是本次布局改造「零控制器改动」的关键。
 *
 * 只有当页面标题与菜单项名不同（如「故障案例管理」vs「故障案例」）时才显示末级，
 * 避免出现「内容管理 / 故障案例 / 故障案例管理」这种冗余层级。
 *
 * 【重要】以 activeItemLabel 非空为前提。若当前角色看不到任何菜单项
 * （如访客误入后台），activeItemLabel 为空，此时不应凭 pageTitle 生成孤立面包屑，
 * 否则会出现「首页 › 附件管理」而侧栏空空如是的怪异状态。
 */
$titleText = (string) ($pageTitle ?? '后台管理');
$crumbs    = [];

if ($activeItemLabel !== '') {
    // 先取当前菜单项的 URL，供面包屑中间级做跳转
    $menuItemUrl = '';
    foreach ($allowedGroups as $group) {
        foreach ($group['items'] as $item) {
            if ($item['key'] === $active) {
                $menuItemUrl = $item['url'];
                break 2;
            }
        }
    }

    $crumbs[] = ['text' => $activeGroupLabel, 'url' => ''];
    $crumbs[] = ['text' => $activeItemLabel, 'url' => $menuItemUrl];
    if ($titleText !== '' && $titleText !== $activeItemLabel) {
        $crumbs[] = ['text' => $titleText, 'url' => ''];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titleText) ?> - <?= e($siteName ?? '') ?> 后台</title>
<?php /* 站点图标（2026-10-22）：与前台共用同一套，SVG 优先 / ICO 兜底 */ ?>
<link rel="icon" href="<?= e(asset('img/favicon.ico')) ?>" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="admin-body">

<div class="admin-shell" id="adminShell">

    <aside class="admin-side" id="adminSide">
        <div class="side-brand">
            <span class="brand-mark">MES</span>
            <span class="brand-text">故障知识库<br><small>后台管理系统</small></span>
        </div>

        <nav class="side-nav" aria-label="后台主导航">
            <?php foreach ($allowedGroups as $group): ?>
                <?php
                $groupId = 'navgrp-' . preg_replace('/[^a-z0-9]+/i', '', $group['label']);
                ?>
                <?php /* 【为什么默认全展开】
                     原实现只展开「当前页所在分组」，实测导致侧栏 4 个分组头只占 59px / 900px
                     = 6.5%，14 个菜单项全被折叠，下方 2/3 是死白 —— 功能等于藏起来了。
                     而且「当前页在内容管理 → 故障案例」时，用户看不到「系统配置」，
                     明明两组平级却要再点一次才知道那里有什么。
                     14 个菜单项约需 532px，在 900px 视口内放得下，没有折叠的必要。
                     用户仍可手动收起（偏好存 localStorage），这是「默认宽松」而非「强制展开」。 */ ?>
                <div class="nav-group is-open">
                    <button type="button" class="nav-group-head" data-nav-toggle
                            aria-expanded="true"
                            aria-controls="<?= e($groupId) ?>">
                        <span class="nav-group-label"><?= e($group['label']) ?></span>
                        <span class="nav-group-caret" aria-hidden="true">▾</span>
                    </button>
                    <div class="nav-group-body" id="<?= e($groupId) ?>">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php
                            $badge = '';
                            if (!empty($item['badge'])) {
                                $badge = $badgeValues[$item['badge']] ?? 0;
                            }
                            ?>
                            <?php /* 【为什么用 li 包一层】
                                 「+ 新增」是**并列的独立入口**，不能放在菜单项 <a> 内部 ——
                                 嵌套 <a> 是非法 HTML，且点击「+」会连带触发父项跳转。
                                 这里用 li 把两个链接变成兄弟节点：
                                   li > a.nav-item  （去看列表）
                                   a.nav-add        （直接新建）
                                 CSS 让 li 变成 flex 容器，两个链接并排且视觉上仍是「一条菜单项」。 */ ?>
                            <li class="nav-row<?= !empty($item['add']) ? ' has-add' : '' ?>">
                                <a href="<?= e($item['url']) ?>"
                                   class="nav-item<?= $active === $item['key'] ? ' active' : '' ?>"
                                   title="<?= e($group['label'] . ' · ' . $item['label']) ?>"
                                   <?= $active === $item['key'] ? 'aria-current="page"' : '' ?>
                                   <?= $badge > 0 ? 'data-badge="' . (int) $badge . '"' : '' ?>>
                                    <span class="nav-icon" aria-hidden="true"><?= $item['icon'] ?></span>
                                    <span class="nav-label"><?= e($item['label']) ?></span>
                                    <?php if ($badge > 0): ?>
                                        <span class="nav-badge"><?= (int) $badge ?></span>
                                    <?php endif; ?>
                                </a>
                                <?php if (!empty($item['add'])): ?>
                                    <a class="nav-add" href="<?= e($item['add']) ?>"
                                       title="<?= e($item['addTitle'] ?? ('新增' . $item['label'])) ?>"
                                       aria-label="<?= e($item['addTitle'] ?? ('新增' . $item['label'])) ?>">
                                        <span aria-hidden="true">+</span>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="side-foot">
            <a href="<?= e(base_url('/')) ?>" target="_blank" rel="noopener">查看前台站点 ↗</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-top">
            <div class="top-left">
                <button type="button" class="side-toggle" id="sideToggle"
                        aria-controls="adminSide" aria-expanded="true" title="收起／展开侧栏">
                    <span aria-hidden="true">☰</span>
                    <span class="sr-only">收起或展开侧栏</span>
                </button>
                <h1 class="page-title"><?= e($titleText) ?></h1>
            </div>

            <?php if ($crumbs !== []): ?>
                <nav class="breadcrumb" aria-label="面包屑">
                    <a href="<?= e(admin_url('index.php')) ?>">首页</a>
                    <?php foreach ($crumbs as $i => $crumb): ?>
                        <?php if ($i > 0): ?><span class="crumb-sep" aria-hidden="true">›</span><?php endif; ?>
                        <?php if ($crumb['url'] !== '' && $i < count($crumbs) - 1): ?>
                            <a href="<?= e($crumb['url']) ?>"><?= e($crumb['text']) ?></a>
                        <?php else: ?>
                            <span class="crumb-current" aria-current="page"><?= e($crumb['text']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="top-right">
                <span class="user-chip">
                    <strong><?= e((string) ($user['real_name'] ?? $user['username'] ?? '')) ?></strong>
                    <em><?= e(role_label($role)) ?></em>
                </span>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('logout.php')) ?>">退出登录</a>
            </div>
        </header>

        <?php if ($flashMsg !== null): ?>
            <div class="alert alert-<?= e((string) $flashMsg['type']) ?>">
                <?= e((string) $flashMsg['message']) ?>
            </div>
        <?php endif; ?>

        <div class="admin-content">
            <?= $content ?>
        </div>

        <footer class="admin-foot"><?= e($copyright ?? '') ?></footer>
    </main>
</div>

<script src="<?= e(asset('js/admin.js')) ?>"></script>
<script src="<?= e(asset('ueditor/ueditor.config.js')) ?>"></script>
<script src="<?= e(asset('ueditor/ueditor.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/ueditor-boot.js')) ?>"></script>
<script>
/* 侧栏折叠：纯前端偏好，写入 localStorage 后下次进入沿用。
   偏好缺失时按视口宽度决定默认值 —— 窄屏（<1200px）默认收起，
   避免表格类页面被固定 216px 侧栏挤掉横向空间。 */
(function () {
    var shell = document.getElementById('adminShell');
    var btn   = document.getElementById('sideToggle');
    if (!shell || !btn) { return; }

    var KEY = 'meskb_admin_side';
    var stored = null;
    try { stored = window.localStorage.getItem(KEY); } catch (e) { /* 隐私模式忽略 */ }

    var collapsed = stored === null
        ? (window.innerWidth < 1200)
        : (stored === 'collapsed');

    function apply(flag) {
        shell.classList.toggle('is-side-collapsed', flag);
        btn.setAttribute('aria-expanded', flag ? 'false' : 'true');
    }
    apply(collapsed);

    btn.addEventListener('click', function () {
        collapsed = !collapsed;
        apply(collapsed);
        try { window.localStorage.setItem(KEY, collapsed ? 'collapsed' : 'expanded'); } catch (e) {}
    });
})();

/* 菜单分组折叠：<details> 之外的轻量实现，用 class 切换避免额外依赖 */
(function () {
    var toggles = document.querySelectorAll('[data-nav-toggle]');
    for (var i = 0; i < toggles.length; i++) {
        toggles[i].addEventListener('click', function () {
            var group = this.closest('.nav-group');
            if (!group) { return; }
            var open = group.classList.toggle('is-open');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
})();
</script>
</body>
</html>
