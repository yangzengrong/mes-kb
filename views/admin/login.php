<?php
/**
 * 后台登录页（需求 2.3 独立入口 + 4.7 登录安全）。
 *
 * 2026-10-27 改版：Split Screen 左右分屏式登录布局。
 *   左栏 = 品牌表达（渐变品牌底 + 定位标语 + 信任数字），右栏 = 登录表单。
 *   验证码 / 防暴力破解 / CSRF / 错误回显逻辑一律未动，只改布局与视觉。
 *
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var int                  $statCases   已发布案例数（品牌区信任数字，动态取库）
 * @var int                  $statProcesses 启用工序数
 * @var int                  $statMenus   菜单权限库条目数
 */
$errors = $errors ?? [];
$old    = $old ?? [];
$statCases     = (int) ($statCases ?? 0);
$statProcesses = (int) ($statProcesses ?? 0);
$statMenus     = (int) ($statMenus ?? 0);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>后台登录 - <?= e($siteName ?? '') ?></title>
<?php /* 站点图标（2026-10-22）：登录页也带，否则浏览器只能靠根 /favicon.ico 兜底 */ ?>
<link rel="icon" href="<?= e(asset('img/favicon.ico')) ?>" sizes="any">
<link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
</head>
<body class="login-body">

<div class="login-wrap">
    <div class="login-split">

        <?php /* 左：品牌表达区。纯展示，不放任何可点元素，避免误触离开登录流程。 */ ?>
        <aside class="login-brand">
            <div class="login-brand-top">
                <span class="login-brand-badge">M</span>
                <span class="login-brand-name"><?= e($siteName ?? 'MES系统知识库') ?></span>
            </div>

            <div class="login-brand-hero">
                <h1>把每一次故障处置，<br>沉淀为<span class="hl">可复用的经验</span></h1>
                <p>故障案例、系统操作手册、版本更新日志与 U9 报错速查，统一沉淀、统一检索，七大工序全覆盖。</p>
                <div class="login-brand-stats">
                    <div class="bstat"><span class="num"><?= $statCases ?><small>+</small></span><span class="lab">沉淀案例</span></div>
                    <div class="bstat"><span class="num"><?= $statProcesses ?></span><span class="lab">覆盖工序</span></div>
                    <div class="bstat"><span class="num"><?= $statMenus ?></span><span class="lab">菜单权限基线</span></div>
                </div>
            </div>

            <div class="login-brand-foot">
                <span><?= e($copyright ?? '') ?></span>
                <span>内部系统 · 仅限授权访问</span>
            </div>
            <div class="login-deco" aria-hidden="true"></div>
        </aside>

        <?php /* 右：登录表单区（原单栏卡片逻辑原样迁入） */ ?>
        <main class="login-form">
            <h2>欢迎回来</h2>
            <p class="login-sub">登录以继续使用知识库工作台</p>

            <?php if (isset($errors['general'])): ?>
                <div class="alert alert-error"><?= e($errors['general']) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= e(admin_url('login.php')) ?>" autocomplete="off">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="username">登录账号</label>
                    <input type="text" id="username" name="username" required autofocus
                           value="<?= e((string) ($old['username'] ?? '')) ?>"
                           class="<?= isset($errors['username']) ? 'has-error' : '' ?>">
                    <?php if (isset($errors['username'])): ?>
                        <span class="field-error"><?= e($errors['username']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="password">登录密码</label>
                    <input type="password" id="password" name="password" required
                           class="<?= isset($errors['password']) ? 'has-error' : '' ?>">
                    <?php if (isset($errors['password'])): ?>
                        <span class="field-error"><?= e($errors['password']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="captcha">验证码</label>
                    <div class="captcha-row">
                        <input type="text" id="captcha" name="captcha" required maxlength="6"
                               placeholder="请输入右侧 4 位字符"
                               class="<?= isset($errors['captcha']) ? 'has-error' : '' ?>">
                        <img src="<?= e(admin_url('captcha.php')) ?>" alt="点击刷新验证码"
                             id="captchaImage" title="点击刷新" class="captcha-img">
                    </div>
                    <?php if (isset($errors['captcha'])): ?>
                        <span class="field-error"><?= e($errors['captcha']) ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">登 录</button>
            </form>

            <div class="login-tip">
                连续 <?= (int) \App\Core\Auth::MAX_LOGIN_FAILURES ?> 次密码错误将锁定账号 15 分钟
            </div>
        </main>
    </div>
</div>

<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
