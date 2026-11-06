<?php
/**
 * 工序分类管理（线缆专属改版：一级分类 = 生产工序）。
 *
 * @var array<int,array<string,mixed>> $processes
 * @var array<string,string> $errors
 * @var array<string,mixed> $old
 * @var bool $isSuper
 */
$val = static function (string $field, string $default = '') use ($old) {
    return array_key_exists($field, $old) ? (string) $old[$field] : $default;
};
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    工序是一级分类：前台顶部标签、筛选、卡片主标签、看板统计均以工序为第一维度。
    文档在新增/编辑时必须绑定所属工序；历史文档可在「故障案例」列表批量绑定。
</div>

<?php if ($isSuper): ?>
<div class="card">
    <h2 class="card-title" id="processFormTitle">新增工序</h2>
    <form class="filter-bar" method="post" action="<?= e(admin_url('process_action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" id="processId" value="<?= e($val('id', '')) ?>">
        <input type="text" name="name" id="processName" required maxlength="60"
               placeholder="工序名称，例如：绞线"
               value="<?= e($val('name', '')) ?>" class="<?= isset($errors['name']) ? 'has-error' : '' ?>">
        <input type="number" name="sort" id="processSort" placeholder="排序"
               value="<?= e($val('sort', '100')) ?>" style="max-width:110px">
        <select name="status" id="processStatus" style="max-width:130px">
            <option value="1">启用</option>
            <option value="0">停用</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
        <button type="button" class="btn btn-ghost btn-sm" id="processReset"
                onclick="window.location.href='<?= e(admin_url('process.php')) ?>'">重置</button>
    </form>
    <?php if (isset($errors['name'])): ?>
        <span class="field-error"><?= e($errors['name']) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">工序列表 <em class="muted">共 <?= count($processes) ?> 个</em></h2>
    </div>

    <?php if ($processes === []): ?>
        <div class="empty-state"><p>还没有工序，请先新增。预置 9 大工序可通过迁移脚本一键写入。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>工序名称</th>
                    <th style="width:110px">已发布文档</th>
                    <th style="width:110px">全部文档</th>
                    <th style="width:70px">排序</th>
                    <th style="width:80px">状态</th>
                    <?php if ($isSuper): ?><th style="width:200px">操作</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($processes as $process): ?>
                <?php
                $pid    = (int) $process['id'];
                $status = (int) $process['status'];
                ?>
                <tr>
                    <td class="muted"><?= $pid ?></td>
                    <td><strong><?= e((string) $process['name']) ?></strong></td>
                    <td><?= (int) $process['published_count'] ?></td>
                    <td><?= (int) $process['case_count'] ?></td>
                    <td class="muted"><?= (int) $process['sort'] ?></td>
                    <td>
                        <span class="badge badge-<?= $status === 1 ? 'published' : 'offline' ?>">
                            <?= $status === 1 ? '启用' : '停用' ?>
                        </span>
                    </td>
                    <?php if ($isSuper): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn btn-ghost btn-xs process-edit"
                                    data-id="<?= $pid ?>"
                                    data-name="<?= e((string) $process['name']) ?>"
                                    data-sort="<?= (int) $process['sort'] ?>"
                                    data-status="<?= $status ?>">编辑</button>
                            <form method="post" action="<?= e(admin_url('process_action.php')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="toggle">
                                <input type="hidden" name="id" value="<?= $pid ?>">
                                <input type="hidden" name="status" value="<?= $status === 1 ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-ghost btn-xs"><?= $status === 1 ? '停用' : '启用' ?></button>
                            </form>
                            <form method="post" action="<?= e(admin_url('process_action.php')) ?>" class="inline-form"
                                  data-confirm="确认删除该工序？工序下存在文档时将无法删除。">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?= $pid ?>">
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
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('.process-edit');
    if (button === null) {
        return;
    }
    document.getElementById('processFormTitle').textContent = '编辑工序';
    document.getElementById('processId').value = button.dataset.id || '';
    document.getElementById('processName').value = button.dataset.name || '';
    document.getElementById('processSort').value = button.dataset.sort || '100';
    document.getElementById('processStatus').value = button.dataset.status === '0' ? '0' : '1';
    document.getElementById('processName').focus();
});
</script>
