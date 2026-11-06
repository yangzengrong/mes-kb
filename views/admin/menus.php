<?php
/**
 * 菜单权限库管理（前台 /permissions 数据源）。
 *
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<int,array{module:string,total:int}> $modules
 * @var array<int,string> $roleNames
 * @var string $keyword
 * @var string $module
 * @var array<string,string> $errors
 * @var array<string,mixed> $old
 * @var bool $isSuper
 * @var array<string,mixed>|null $import 导入预检草稿（存在时展示预检面板）
 */
$val = static function (string $field, string $default = '') use ($old) {
    return array_key_exists($field, $old) ? (string) $old[$field] : $default;
};

/** 层级面包屑 */
$crumb = static function (array $m): string {
    $parts = [(string) $m['module']];
    foreach (['menu_l2', 'menu_l3', 'menu_l4'] as $key) {
        $v = (string) ($m[$key] ?? '');
        if ($v !== '') {
            $parts[] = $v;
        }
    }
    return implode(' › ', $parts);
};

$query = array_filter([
    'keyword' => $keyword,
    'module'  => $module,
], static function ($v): bool {
    return $v !== '' && $v !== null;
});
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    这里维护前台「⚿ MES菜单权限库」查询页的数据源。<strong>MES 开发商在大板块下新增子板块时，请第一时间在此录入对应菜单与角色权限</strong>，
    保持知识库与 MES 系统菜单对称，日后给员工账号授权前可按关键字检索匹配。
</div>

<?php if ($isSuper): ?>
<div class="card">
    <h2 class="card-title" id="menuFormTitle">新增菜单</h2>
    <form class="filter-bar" method="post" action="<?= e(admin_url('menu_action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" id="menuId" value="<?= e($val('id', '')) ?>">
        <input type="text" name="module" id="menuModule" required maxlength="60" list="moduleList"
               placeholder="所属系统 / 一级板块 *，如：WMS"
               value="<?= e($val('module', '')) ?>" class="<?= isset($errors['module']) ? 'has-error' : '' ?>" style="max-width:170px">
        <datalist id="moduleList">
            <?php foreach ($modules as $mod): ?>
                <option value="<?= e($mod['module']) ?>">
            <?php endforeach; ?>
        </datalist>
        <input type="text" name="menu_l2" id="menuL2" maxlength="120" placeholder="二级菜单" value="<?= e($val('menu_l2', '')) ?>" style="max-width:150px">
        <input type="text" name="menu_l3" id="menuL3" maxlength="120" placeholder="三级菜单" value="<?= e($val('menu_l3', '')) ?>" style="max-width:150px">
        <input type="text" name="menu_l4" id="menuL4" maxlength="120" placeholder="四级菜单" value="<?= e($val('menu_l4', '')) ?>" style="max-width:150px">
        <input type="text" name="menu_name" id="menuName" maxlength="120" placeholder="叶子菜单名 *，留空自动取最深层级"
               value="<?= e($val('menu_name', '')) ?>" class="<?= isset($errors['menu_name']) ? 'has-error' : '' ?>" style="max-width:200px">
        <input type="text" name="menu_code" id="menuCode" maxlength="60" placeholder="菜单编码，如：7-1"
               value="<?= e($val('menu_code', '')) ?>" style="max-width:130px">
        <input type="text" name="role_name" id="menuRole" maxlength="60" list="roleList" placeholder="角色权限，如：仓库主管"
               value="<?= e($val('role_name', '')) ?>" style="max-width:150px">
        <datalist id="roleList">
            <?php foreach ($roleNames as $role): ?>
                <option value="<?= e($role) ?>">
            <?php endforeach; ?>
        </datalist>
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
        <button type="button" class="btn btn-ghost btn-sm"
                onclick="window.location.href='<?= e(admin_url('menus.php')) ?>'">重置</button>
    </form>
    <?php if (isset($errors['module']) || isset($errors['menu_name'])): ?>
        <span class="field-error"><?= e($errors['module'] ?? $errors['menu_name']) ?></span>
    <?php endif; ?>
    <p class="muted" style="margin:8px 0 0">层级不完整可只填到实际存在的层级；「叶子菜单名」是前台检索与展示的最深层菜单名。</p>
</div>

<div class="card">
    <h2 class="card-title">批量导入（xlsx 合并导入）</h2>
    <p class="hint">
        平时手动逐条录入即可；当 MES 开发商整体改版、新增大量板块时，可上传菜单明细 xlsx 一次性补充。
        系统按「模块 + 各级菜单 + 叶子菜单名 + 编码 + 角色」判重，<strong>只补入缺失行，不会覆盖你手工录入或编辑过的数据</strong>。
    </p>
    <p class="hint">
        表格格式：第一行为表头，列依次为「一级菜单（所属系统）/ 二级 / 三级 / 四级 / 菜单编码 / 角色权限」；
        工作表名建议为「<strong>按层级浏览</strong>」（不是该名称时自动读取第一个工作表）。仅支持 .xlsx（老式 .xls 请先另存为 .xlsx）。
    </p>
    <form class="filter-bar" method="post" action="<?= e(admin_url('menu_action.php')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="import_preview">
        <input type="file" name="menu_file" accept=".xlsx" required>
        <button type="submit" class="btn btn-primary btn-sm">上传并预检</button>
    </form>
</div>

<?php if (is_array($import ?? null) && $import !== []): ?>
    <?php
    $draftNew = is_array($import['newRows'] ?? null) ? $import['newRows'] : [];
    $draftToken = (string) ($import['token'] ?? '');
    ?>
    <div class="card">
        <h2 class="card-title">导入预检结果</h2>
        <p class="hint">
            文件：<strong><?= e((string) ($import['file'] ?? '')) ?></strong>
            · 工作表：<strong><?= e((string) ($import['sheet'] ?? '')) ?></strong>
            · 解析有效行：<strong><?= (int) ($import['parsed'] ?? 0) ?></strong>
        </p>
        <div class="alert alert-info">
            可新增 <strong><?= (int) ($import['new'] ?? 0) ?></strong> 条；
            已存在（判重跳过）<strong><?= (int) ($import['dup'] ?? 0) ?></strong> 条。
            <?php if ((int) ($import['new'] ?? 0) === 0): ?>
                没有需要新增的记录，无需导入。
            <?php endif; ?>
        </div>

        <?php if ($draftNew !== []): ?>
            <p class="hint">以下为将新增的前 <?= count($draftNew) ?> 条（最多展示 50 条）：</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:46px">#</th>
                            <th>将新增的菜单</th>
                            <th style="width:130px">菜单编码</th>
                            <th style="width:150px">角色权限</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($draftNew as $index => $row): ?>
                        <tr>
                            <td class="muted"><?= $index + 1 ?></td>
                            <td>
                                <strong><?= e((string) ($row['menu_name'] ?? '')) ?></strong>
                                <span class="muted" style="display:block;font-size:12px"><?= e($crumb($row)) ?></span>
                            </td>
                            <td class="muted"><?= e((string) ($row['menu_code'] ?? '')) ?></td>
                            <td><?= e((string) ($row['role_name'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <form class="filter-bar" method="post" action="<?= e(admin_url('menu_action.php')) ?>" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($draftToken) ?>">
            <button type="submit" name="do" value="import_confirm" class="btn btn-primary btn-sm"
                    data-confirm="确认导入？只会新增缺失的菜单，已有记录不会被修改。">确认导入</button>
            <button type="submit" name="do" value="import_cancel" class="btn btn-ghost btn-sm">取消</button>
        </form>
    </div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">菜单列表 <em class="muted">共 <?= (int) $result['total'] ?> 条</em></h2>
        <form class="filter-bar" method="get" action="<?= e(admin_url('menus.php')) ?>">
            <select name="module" style="max-width:180px">
                <option value="">全部模块</option>
                <?php foreach ($modules as $mod): ?>
                    <option value="<?= e($mod['module']) ?>"<?= $module === $mod['module'] ? ' selected' : '' ?>>
                        <?= e($mod['module']) ?>（<?= (int) $mod['total'] ?>）
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="keyword" placeholder="菜单名 / 编码 / 角色" value="<?= e($keyword) ?>" style="max-width:200px">
            <button type="submit" class="btn btn-ghost btn-sm">筛选</button>
            <?php if ($query !== []): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('menus.php')) ?>">清空</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state"><p>没有匹配的菜单记录。MES 开发商新增板块后，请在上方表单录入。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:50px">ID</th>
                    <th>菜单路径</th>
                    <th style="width:130px">菜单编码</th>
                    <th style="width:150px">角色权限</th>
                    <?php if ($isSuper): ?><th style="width:150px">操作</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['items'] as $item): ?>
                <?php $mid = (int) $item['id']; ?>
                <tr>
                    <td class="muted"><?= $mid ?></td>
                    <td>
                        <strong><?= e((string) $item['menu_name']) ?></strong>
                        <span class="muted" style="display:block;font-size:12px"><?= e($crumb($item)) ?></span>
                    </td>
                    <td class="muted"><?= e((string) $item['menu_code']) ?></td>
                    <td><?= e((string) ($item['role_name'] !== '-' ? $item['role_name'] : '')) ?></td>
                    <?php if ($isSuper): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn btn-ghost btn-xs mes-menu-edit"
                                    data-id="<?= $mid ?>"
                                    data-module="<?= e((string) $item['module']) ?>"
                                    data-l2="<?= e((string) $item['menu_l2']) ?>"
                                    data-l3="<?= e((string) $item['menu_l3']) ?>"
                                    data-l4="<?= e((string) $item['menu_l4']) ?>"
                                    data-name="<?= e((string) $item['menu_name']) ?>"
                                    data-code="<?= e((string) $item['menu_code']) ?>"
                                    data-role="<?= e((string) $item['role_name']) ?>">编辑</button>
                            <form method="post" action="<?= e(admin_url('menu_action.php')) ?>" class="inline-form"
                                  data-confirm="确认删除菜单「<?= e((string) $item['menu_name']) ?>」？删除后前台将不再检索到该条。">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?= $mid ?>">
                                <button type="submit" class="btn btn-danger btn-xs">删除</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => admin_url('menus.php'),
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('.mes-menu-edit');
    if (button === null) {
        return;
    }
    document.getElementById('menuFormTitle').textContent = '编辑菜单 #' + button.dataset.id;
    document.getElementById('menuId').value = button.dataset.id || '';
    document.getElementById('menuModule').value = button.dataset.module || '';
    document.getElementById('menuL2').value = button.dataset.l2 || '';
    document.getElementById('menuL3').value = button.dataset.l3 || '';
    document.getElementById('menuL4').value = button.dataset.l4 || '';
    document.getElementById('menuName').value = button.dataset.name || '';
    document.getElementById('menuCode').value = button.dataset.code || '';
    document.getElementById('menuRole').value = button.dataset.role === '-' ? '' : (button.dataset.role || '');
    document.getElementById('menuModule').focus();
});
</script>
