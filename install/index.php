<?php
/**
 * 安装向导：环境检测 -> 数据库与管理员配置 -> 执行安装
 * 安装完成后请删除或重命名 install 目录。
 *
 * ©Yangzengrong 版权所有
 */

declare(strict_types=1);

define('MES_KB_INSTALLING', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Setting;

$lockFile = __DIR__ . '/install.lock';
$configFile = CONFIG_PATH . '/config.php';
$step       = (string) ($_GET['step'] ?? '1');
$errors     = [];
$notices    = [];

/**
 * 环境检测项。
 *
 * @return array<int,array{name:string,current:string,required:string,pass:bool,hint:string}>
 */
function env_checks(): array
{
    $checks = [];

    $checks[] = [
        'name'     => 'PHP 版本',
        'current'  => PHP_VERSION,
        'required' => '>= 7.4.0',
        'pass'     => PHP_VERSION_ID >= 70400,
        'hint'     => '需求文档要求 PHP 7.4 / 8.0 稳定版本',
    ];

    $extensions = [
        'pdo'        => 'PDO 数据库抽象层（必需）',
        'pdo_mysql'  => 'MySQL 驱动（必需）',
        'mbstring'   => '多字节字符串（必需，中文检索依赖）',
        'fileinfo'   => '文件类型嗅探（附件安全校验依赖）',
        'json'       => 'JSON 扩展（版本快照依赖）',
        'session'    => '会话支持（后台登录依赖）',
        'gd'         => 'GD 图像库（验证码，未装则自动降级为 SVG）',
    ];
    foreach ($extensions as $ext => $desc) {
        $loaded = extension_loaded($ext);
        $checks[] = [
            'name'     => '扩展 ' . $ext,
            'current'  => $loaded ? '已安装' : '未安装',
            'required' => in_array($ext, ['gd'], true) ? '建议安装' : '必需',
            'pass'     => $loaded || $ext === 'gd',
            'hint'     => $desc,
        ];
    }

    $paths = [
        CONFIG_PATH        => '配置文件目录',
        STORAGE_PATH       => '数据目录',
        STORAGE_PATH . '/uploads' => '附件上传目录',
        STORAGE_PATH . '/logs'    => '日志目录',
        STORAGE_PATH . '/backups' => '数据库备份目录',
    ];
    foreach ($paths as $path => $desc) {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $writable = is_dir($path) && is_writable($path);
        $checks[] = [
            'name'     => $desc,
            'current'  => $writable ? '可写' : '不可写',
            'required' => '可写',
            'pass'     => $writable,
            'hint'     => str_replace(BASE_PATH, '', $path) ?: '/',
        ];
    }

    return $checks;
}

/**
 * 执行 SQL 文件（按分号切分，跳过注释）。
 *
 * @return array{ok:bool,message:string}
 */
function run_sql_file(PDO $pdo, string $file): array
{
    if (!is_file($file)) {
        return ['ok' => false, 'message' => '找不到 SQL 文件：' . $file];
    }
    $sql = (string) file_get_contents($file);

    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $buffer = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer[] = $line;
    }
    $statements = explode(';', implode("\n", $buffer));

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => '执行建表语句失败：' . $e->getMessage()];
        }
    }
    return ['ok' => true, 'message' => '数据库结构导入完成。'];
}

function write_config(array $db, string $salt): array
{
    $template = <<<'PHP'
<?php
/**
 * 系统运行配置（由安装向导生成）
 * 请勿将本文件提交到版本库。
 *
 * ©Yangzengrong 版权所有
 */

return [
    'app' => [
        'name'     => %s,
        'debug'    => %s,
        'url'      => %s,
        'salt'     => %s,
        'timezone' => 'Asia/Shanghai',
    ],
    'database' => [
        'host'     => %s,
        'port'     => %d,
        'name'     => %s,
        'user'     => %s,
        'password' => %s,
        'charset'  => 'utf8mb4',
    ],
];
PHP;

    $content = sprintf(
        $template,
        var_export((string) $db['site_name'], true),
        $db['debug'] ? 'true' : 'false',
        var_export((string) $db['site_url'], true),
        var_export($salt, true),
        var_export((string) $db['host'], true),
        (int) $db['port'],
        var_export((string) $db['name'], true),
        var_export((string) $db['user'], true),
        var_export((string) $db['password'], true)
    );

    if (!is_dir(CONFIG_PATH)) {
        @mkdir(CONFIG_PATH, 0755, true);
    }
    if (@file_put_contents($configFile = CONFIG_PATH . '/config.php', $content) === false) {
        return ['ok' => false, 'message' => '配置文件写入失败，请检查 config 目录权限。'];
    }
    @chmod($configFile, 0640);
    return ['ok' => true, 'message' => '配置文件写入成功。'];
}

// ---------------------------------------------------------------
// 已安装拦截
// ---------------------------------------------------------------
if (is_file($lockFile) && $step !== 'done') {
    $step = 'locked';
}

// ---------------------------------------------------------------
// 执行安装
// ---------------------------------------------------------------
if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host     = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
    $port     = (int) ($_POST['db_port'] ?? 3306);
    $dbName   = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser   = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass   = (string) ($_POST['db_password'] ?? '');
    $createDb = isset($_POST['create_db']) && $_POST['create_db'] === '1';
    $siteName = trim((string) ($_POST['site_name'] ?? 'MES系统知识库'));
    $siteUrl  = trim((string) ($_POST['site_url'] ?? ''));
    $adminUser = trim((string) ($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string) ($_POST['admin_password'] ?? '');
    $adminPass2 = (string) ($_POST['admin_password_confirm'] ?? '');
    $adminName = trim((string) ($_POST['admin_name'] ?? '超级管理员'));
    $debug    = isset($_POST['debug']) && $_POST['debug'] === '1';

    if ($host === '') {
        $errors[] = '数据库地址不能为空。';
    }
    if ($dbName === '') {
        $errors[] = '数据库名称不能为空。';
    }
    if ($dbUser === '') {
        $errors[] = '数据库账号不能为空。';
    }
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $adminUser)) {
        $errors[] = '管理员账号只能是 3-20 位字母、数字或下划线。';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = '管理员密码长度不能少于 8 位。';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = '两次输入的管理员密码不一致。';
    }

    if ($errors === []) {
        try {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);

            if ($createDb) {
                $rootPdo = new PDO($dsn, $dbUser, $dbPass, $options);
                $rootPdo->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
                    str_replace('`', '', $dbName)
                ));
            }

            $pdo = new PDO($dsn . ';dbname=' . $dbName, $dbUser, $dbPass, $options);

            // 1) 导入表结构
            $result = run_sql_file($pdo, __DIR__ . '/schema.sql');
            if (!$result['ok']) {
                $errors[] = $result['message'];
            } else {
                // 2) 写入配置文件
                $salt   = App\Core\Config::randomSalt(32);
                $config = write_config([
                    'host'      => $host,
                    'port'      => $port,
                    'name'      => $dbName,
                    'user'      => $dbUser,
                    'password'  => $dbPass,
                    'site_name' => $siteName,
                    'site_url'  => $siteUrl,
                    'debug'     => $debug,
                ], $salt);
                if (!$config['ok']) {
                    $errors[] = $config['message'];
                }
            }

            if ($errors === []) {
                // 3) 初始化系统配置
                $stmt = $pdo->prepare('INSERT INTO settings (skey, svalue, updated_at) VALUES (?, ?, NOW())
                                       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                foreach (Setting::defaults() as $key => $value) {
                    if ($key === 'site.name') {
                        $value = $siteName;
                    }
                    $stmt->execute([$key, $value]);
                }

                // 4) 创建超级管理员
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, real_name, role, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role), status = 1'
                );
                $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminName, 'super_admin']);

                // 5) 写入演示分类（便于首次进入即可建案例）
                $exists = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
                if ($exists === 0) {
                    $presets = ['系统参数', '数据采集', '物料消耗', '标签无法打印', '无法上料', '无法下料', '无法结束工单', '工单异常', '委外异常', '辐照异常', '质检异常'];
                    $insert  = $pdo->prepare('INSERT INTO categories (parent_id, name, sort, status, created_at, updated_at) VALUES (0, ?, ?, 1, NOW(), NOW())');
                    foreach ($presets as $index => $name) {
                        $insert->execute([$name, ($index + 1) * 10]);
                    }
                }

                // 6) 锁定安装
                @file_put_contents($lockFile, '安装完成时间：' . date('Y-m-d H:i:s') . PHP_EOL
                    . '如需重新安装，请先删除本文件并清空 config/config.php。' . PHP_EOL);

                header('Location: index.php?step=done');
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = '数据库连接或初始化失败：' . $e->getMessage();
        }
    }

    $step = '2';
}

$checks   = $step === '1' ? env_checks() : [];
$allPass  = true;
foreach ($checks as $check) {
    if (!$check['pass']) {
        $allPass = false;
    }
}

$guessUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 - MES系统知识库</title>
<style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 40px 16px; background: #F1EFE8; color: #2C2C2A;
           font-family: -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif; font-size: 14px; line-height: 1.6; }
    .wrap { max-width: 760px; margin: 0 auto; }
    .card { background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 12px; padding: 28px; }
    h1 { font-size: 20px; font-weight: 600; margin: 0 0 6px; }
    .sub { color: #5F5E5A; margin-bottom: 24px; font-size: 13px; }
    .steps { display: flex; gap: 8px; margin-bottom: 24px; }
    .steps span { flex: 1; padding: 8px 12px; border-radius: 8px; background: #F1EFE8; color: #5F5E5A;
                  text-align: center; font-size: 13px; }
    .steps span.on { background: #E6F1FB; color: #0C447C; font-weight: 500; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th, td { padding: 9px 12px; border-bottom: 1px solid rgba(0,0,0,.06); text-align: left; font-size: 13px; }
    th { color: #5F5E5A; font-weight: 500; background: #FAFAF8; }
    .ok { color: #0F6E56; font-weight: 500; }
    .no { color: #A32D2D; font-weight: 500; }
    label { display: block; margin: 14px 0 6px; font-size: 13px; color: #444441; }
    input[type=text], input[type=password], input[type=number] {
        width: 100%; padding: 9px 12px; border: 1px solid rgba(0,0,0,.16); border-radius: 8px;
        font-size: 13px; font-family: inherit; background: #fff; color: #2C2C2A; }
    .row { display: flex; gap: 12px; }
    .row > div { flex: 1; }
    .btn { display: inline-block; padding: 10px 22px; border: none; border-radius: 8px; background: #185FA5;
           color: #fff; font-size: 14px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn.ghost { background: #F1EFE8; color: #2C2C2A; }
    .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; }
    .alert.err { background: #FCEBEB; color: #A32D2D; }
    .alert.ok  { background: #E1F5EE; color: #0F6E56; }
    .hint { color: #888780; font-size: 12px; margin-top: 4px; }
    .actions { margin-top: 24px; display: flex; gap: 12px; align-items: center; }
    code { background: #F1EFE8; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
    .footer { text-align: center; color: #888780; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>MES系统知识库</h1>
        <div class="sub">安装向导 v<?= e(MES_KB_VERSION) ?> · PHP <?= e(PHP_VERSION) ?></div>

        <?php if ($step === 'locked'): ?>
            <div class="alert err">
                系统已完成安装。若需重新安装，请先删除 <code>install/install.lock</code>，
                并清空 <code>config/config.php</code> 后刷新本页。
            </div>
            <div class="actions">
                <a class="btn" href="<?= e(base_url('admin/')) ?>">进入后台</a>
                <a class="btn ghost" href="<?= e(base_url('/')) ?>">访问前台</a>
            </div>

        <?php elseif ($step === 'done'): ?>
            <div class="steps"><span>1 环境检测</span><span>2 数据库配置</span><span class="on">3 安装完成</span></div>
            <div class="alert ok">安装成功！系统已初始化完毕，可以开始使用了。</div>
            <table>
                <tr><th style="width:180px">项目</th><th>说明</th></tr>
                <tr><td>后台入口</td><td><code><?= e(base_url('admin/')) ?></code>（需求 2.3 规定为 域名 + /admin）</td></tr>
                <tr><td>前台入口</td><td><code><?= e(base_url('/')) ?></code></td></tr>
                <tr><td>安全提醒</td><td>请立即删除 <code>install</code> 目录，避免安装程序被再次访问</td></tr>
            </table>
            <div class="actions">
                <a class="btn" href="<?= e(base_url('admin/')) ?>">进入后台登录</a>
                <a class="btn ghost" href="<?= e(base_url('/')) ?>">查看前台首页</a>
            </div>

        <?php elseif ($step === '2'): ?>
            <div class="steps"><span>1 环境检测</span><span class="on">2 数据库配置</span><span>3 安装完成</span></div>
            <?php foreach ($errors as $error): ?>
                <div class="alert err"><?= e($error) ?></div>
            <?php endforeach; ?>
            <form method="post" action="index.php?step=install">
                <label>数据库地址</label>
                <div class="row">
                    <div><input type="text" name="db_host" value="<?= e($_POST['db_host'] ?? '127.0.0.1') ?>"></div>
                    <div style="max-width:140px"><input type="number" name="db_port" value="<?= e($_POST['db_port'] ?? '3306') ?>"></div>
                </div>
                <label>数据库名称</label>
                <input type="text" name="db_name" value="<?= e($_POST['db_name'] ?? 'mes_kb') ?>">
                <label>数据库账号</label>
                <input type="text" name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>">
                <label>数据库密码</label>
                <input type="password" name="db_password" value="">
                <label style="display:flex;align-items:center;gap:8px;margin-top:14px">
                    <input type="checkbox" name="create_db" value="1" checked style="width:auto">
                    <span>数据库不存在时自动创建（需账号具备 CREATE 权限）</span>
                </label>

                <hr style="border:none;border-top:1px solid rgba(0,0,0,.08);margin:26px 0">

                <label>网站名称</label>
                <input type="text" name="site_name" value="<?= e($_POST['site_name'] ?? 'MES系统知识库') ?>">
                <label>站点地址（可留空，后续在后台系统配置中修改）</label>
                <input type="text" name="site_url" value="<?= e($_POST['site_url'] ?? $guessUrl) ?>">

                <hr style="border:none;border-top:1px solid rgba(0,0,0,.08);margin:26px 0">

                <label>超级管理员账号</label>
                <input type="text" name="admin_user" value="<?= e($_POST['admin_user'] ?? 'admin') ?>">
                <label>超级管理员姓名</label>
                <input type="text" name="admin_name" value="<?= e($_POST['admin_name'] ?? '超级管理员') ?>">
                <div class="row">
                    <div>
                        <label>登录密码（不少于 8 位）</label>
                        <input type="password" name="admin_password" value="">
                    </div>
                    <div>
                        <label>确认密码</label>
                        <input type="password" name="admin_password_confirm" value="">
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:14px">
                    <input type="checkbox" name="debug" value="1" style="width:auto">
                    <span>开启调试模式（显示详细错误信息，生产环境请勿勾选）</span>
                </label>

                <div class="actions">
                    <button class="btn" type="submit">开始安装</button>
                    <a class="btn ghost" href="index.php?step=1">返回上一步</a>
                </div>
            </form>

        <?php else: ?>
            <div class="steps"><span class="on">1 环境检测</span><span>2 数据库配置</span><span>3 安装完成</span></div>
            <table>
                <tr><th style="width:200px">检测项</th><th style="width:110px">当前状态</th><th>要求</th></tr>
                <?php foreach ($checks as $check): ?>
                    <tr>
                        <td><?= e($check['name']) ?><div class="hint"><?= e($check['hint']) ?></div></td>
                        <td class="<?= $check['pass'] ? 'ok' : 'no' ?>"><?= e($check['current']) ?></td>
                        <td><?= e($check['required']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if ($allPass): ?>
                <div class="alert ok">环境检测全部通过，可以继续安装。</div>
                <div class="actions"><a class="btn" href="index.php?step=2">下一步：配置数据库</a></div>
            <?php else: ?>
                <div class="alert err">存在未通过的检测项，请先处理后再继续安装。</div>
                <div class="actions"><a class="btn ghost" href="index.php?step=1">重新检测</a></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="footer">©Yangzengrong 版权所有</div>
</div>
</body>
</html>
