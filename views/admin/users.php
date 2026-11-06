<?php
/**
 * 用户权限管理（需求 4.1）。
 *
 * @var array<string,mixed> $filters
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<string,int> $roleCounts
 * @var array<string,string> $errors
 * @var array<string,mixed> $old
 */
$val   = static function (string $field, string $default = '') use ($old) {
    return array_key_exists($field, $old) ? (string) $old[$field] : $default;
};
$query = array_filter([
    'keyword' => $filters['keyword'],
    'role'    => $filters['role'],
    'status'  => $filters['status'],
], static function ($value): bool {
    return $value !== '' && $value !== null;
});

$roleDesc = [
    'super_admin' => '系统全部权限，可管理账号、审核案例、配置系统',
    'op_admin'    => '可新增/编辑本人案例、管理自有附件，无系统配置与账号管理权限',
    'guest'       => '仅前台检索、预览、下载，无后台操作权限',
    'gm'          => '仅可查看「员工反馈」与「反馈统计」，不能修改任何数据',
];
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="stat-grid stat-grid-sm">
    <?php foreach (role_map() as $key => $label): ?>
        <div class="stat-card">
            <span class="stat-label"><?= e($label) ?></span>
            <span class="stat-value"><?= (int) ($roleCounts[$key] ?? 0) ?></span>
            <span class="stat-foot"><?= e($roleDesc[$key] ?? '') ?></span>
        </div>
    <?php endforeach; ?>
</div>

<div class="form-layout">
    <div class="form-main">
        <div class="card">
            <form class="filter-bar" method="get" action="<?= e(admin_url('users.php')) ?>">
                <input type="text" name="keyword" placeholder="搜索账号 / 姓名"
                       value="<?= e((string) $filters['keyword']) ?>">
                <select name="role">
                    <option value="">全部角色</option>
                    <?php foreach (role_map() as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $filters['role'] === $key ? ' selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="1"<?= $filters['status'] === '1' ? ' selected' : '' ?>>启用</option>
                    <option value="0"<?= $filters['status'] === '0' ? ' selected' : '' ?>>禁用</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('users.php')) ?>">重置</a>
            </form>
        </div>

        <div class="card">
            <h2 class="card-title">账号列表 <em class="muted">共 <?= (int) $result['total'] ?> 个</em></h2>

            <?php if ($result['items'] === []): ?>
                <div class="empty-state"><p>没有符合条件的账号。</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:52px">ID</th>
                            <th>账号 / 姓名</th>
                            <th style="width:110px">角色</th>
                            <th style="width:80px">状态</th>
                            <th style="width:170px">最后登录</th>
                            <th style="width:260px">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['items'] as $item): ?>
                        <?php $uid = (int) $item['id']; $locked = $item['locked_until'] !== null && strtotime((string) $item['locked_until']) > time(); ?>
                        <tr>
                            <td class="muted"><?= $uid ?></td>
                            <td>
                                <strong><?= e((string) $item['username']) ?></strong>
                                <span class="muted">（<?= e((string) $item['real_name']) ?>）</span>
                                <?php if ($locked): ?><span class="tag tag-danger">已锁定</span><?php endif; ?>
                                <?php if ((string) $item['remark'] !== ''): ?>
                                    <div class="muted small"><?= e((string) $item['remark']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-role"><?= e(role_label((string) $item['role'])) ?></span></td>
                            <td>
                                <span class="badge badge-<?= (int) $item['status'] === 1 ? 'published' : 'offline' ?>">
                                    <?= (int) $item['status'] === 1 ? '启用' : '禁用' ?>
                                </span>
                            </td>
                            <td class="small muted">
                                <?= e((string) ($item['last_login_at'] ?? '从未登录')) ?><br>
                                <?= e((string) $item['last_login_ip']) ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="btn btn-ghost btn-xs user-edit"
                                            data-id="<?= $uid ?>"
                                            data-username="<?= e((string) $item['username']) ?>"
                                            data-real-name="<?= e((string) $item['real_name']) ?>"
                                            data-role="<?= e((string) $item['role']) ?>"
                                            data-status="<?= (int) $item['status'] ?>"
                                            data-remark="<?= e((string) $item['remark']) ?>">编辑</button>

                                    <details class="inline-details">
                                        <summary class="btn btn-ghost btn-xs">重置密码</summary>
                                        <form method="post" action="<?= e(admin_url('user_action.php')) ?>"
                                              class="popover-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="do" value="reset_password">
                                            <input type="hidden" name="id" value="<?= $uid ?>">
                                            <input type="text" name="password" placeholder="新密码（≥6 位）" required>
                                            <button type="submit" class="btn btn-primary btn-xs">确认重置</button>
                                        </form>
                                    </details>

                                    <form method="post" action="<?= e(admin_url('user_action.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="toggle">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <input type="hidden" name="status" value="<?= (int) $item['status'] === 1 ? 0 : 1 ?>">
                                        <button type="submit" class="btn btn-ghost btn-xs">
                                            <?= (int) $item['status'] === 1 ? '禁用' : '启用' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="<?= e(admin_url('user_action.php')) ?>" class="inline-form"
                                          data-confirm="确认删除该账号？名下若存在案例将无法删除。">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="delete">
                                        <input type="hidden" name="id" value="<?= $uid ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= App\Core\View::capture('partials/pagination', [
                'result'      => $result,
                'basePath'    => admin_url('users.php'),
                'queryParams' => $query,
            ]) ?>
            <?php endif; ?>
        </div>
    </div>

    <aside class="form-side">
        <div class="card">
            <h2 class="card-title" id="userFormTitle">新增账号</h2>
            <form method="post" action="<?= e(admin_url('user_action.php')) ?>" id="userForm">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" id="userId" value="0">

                <div class="field">
                    <label for="userUsername">登录账号 <span class="req">*</span></label>
                    <input type="text" id="userUsername" name="username" maxlength="20" required
                           placeholder="3-20 位字母、数字或下划线"
                           value="<?= e($val('username')) ?>">
                </div>

                <div class="field">
                    <label for="userRealName">姓名 <span class="req">*</span></label>
                    <input type="text" id="userRealName" name="real_name" maxlength="50" required
                           value="<?= e($val('real_name')) ?>">
                </div>

                <div class="field">
                    <label for="userPassword">登录密码</label>
                    <input type="password" id="userPassword" name="password" autocomplete="new-password"
                           placeholder="新增账号时必填，不少于 6 位">
                    <span class="field-hint">编辑账号时留空表示不修改密码。</span>
                </div>

                <div class="field">
                    <label for="userRole">角色 <span class="req">*</span></label>
                    <select id="userRole" name="role" required>
                        <?php foreach (role_map() as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $val('role', 'op_admin') === $key ? ' selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="field-hint" id="roleHint"><?= e($roleDesc['op_admin']) ?></span>
                </div>

                <div class="field">
                    <label for="userStatus">状态</label>
                    <select id="userStatus" name="status">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>

                <div class="field">
                    <label for="userRemark">备注</label>
                    <input type="text" id="userRemark" name="remark" maxlength="255"
                           value="<?= e($val('remark')) ?>">
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">保存账号</button>
                    <button type="button" class="btn btn-ghost btn-block" id="userFormReset">清空表单</button>
                </div>
            </form>
        </div>
    </aside>
</div>
