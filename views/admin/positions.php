<?php
/**
 * 岗位分类管理（岗位操作手册维度）。
 *
 * @var array<int,array<string,mixed>> $positions
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
    岗位是「系统操作手册」的可选匹配维度：销售、研发、质量、物控、生产、仓库、技术、设备、实验室。
    编写系统操作手册时可指定匹配岗位（不指定即全岗位通用），员工也可在手册检索页按岗位筛选。
    MES 开发商新增职责板块时，可在此处补充岗位。
</div>

<?php if ($isSuper): ?>
<div class="card">
    <h2 class="card-title" id="positionFormTitle">新增岗位</h2>
    <form class="filter-bar" method="post" action="<?= e(admin_url('position_action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" id="positionId" value="<?= e($val('id', '')) ?>">
        <input type="text" name="name" id="positionName" required maxlength="60"
               placeholder="岗位名称，例如：生产"
               value="<?= e($val('name', '')) ?>" class="<?= isset($errors['name']) ? 'has-error' : '' ?>">
        <input type="number" name="sort" id="positionSort" placeholder="排序"
               value="<?= e($val('sort', '100')) ?>" style="max-width:110px">
        <select name="status" id="positionStatus" style="max-width:130px">
            <option value="1">启用</option>
            <option value="0">停用</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
        <button type="button" class="btn btn-ghost btn-sm"
                onclick="window.location.href='<?= e(admin_url('position.php')) ?>'">重置</button>
    </form>
    <?php if (isset($errors['name'])): ?>
        <span class="field-error"><?= e($errors['name']) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">岗位列表 <em class="muted">共 <?= count($positions) ?> 个</em></h2>
    </div>

    <?php if ($positions === []): ?>
        <div class="empty-state"><p>还没有岗位，请先新增。预置 9 大岗位可通过迁移脚本一键写入。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>岗位名称</th>
                    <th style="width:110px">已发布手册</th>
                    <th style="width:110px">全部文档</th>
                    <th style="width:70px">排序</th>
                    <th style="width:80px">状态</th>
                    <?php if ($isSuper): ?><th style="width:200px">操作</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($positions as $position): ?>
                <?php
                $pid    = (int) $position['id'];
                $status = (int) $position['status'];
                ?>
                <tr>
                    <td class="muted"><?= $pid ?></td>
                    <td><strong><?= e((string) $position['name']) ?></strong></td>
                    <td><?= (int) $position['published_count'] ?></td>
                    <td><?= (int) $position['manual_count'] ?></td>
                    <td class="muted"><?= (int) $position['sort'] ?></td>
                    <td>
                        <span class="badge badge-<?= $status === 1 ? 'published' : 'offline' ?>">
                            <?= $status === 1 ? '启用' : '停用' ?>
                        </span>
                    </td>
                    <?php if ($isSuper): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn btn-ghost btn-xs position-edit"
                                    data-id="<?= $pid ?>"
                                    data-name="<?= e((string) $position['name']) ?>"
                                    data-sort="<?= (int) $position['sort'] ?>"
                                    data-status="<?= $status ?>">编辑</button>
                            <form method="post" action="<?= e(admin_url('position_action.php')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="toggle">
                                <input type="hidden" name="id" value="<?= $pid ?>">
                                <input type="hidden" name="status" value="<?= $status === 1 ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-ghost btn-xs"><?= $status === 1 ? '停用' : '启用' ?></button>
                            </form>
                            <form method="post" action="<?= e(admin_url('position_action.php')) ?>" class="inline-form"
                                  data-confirm="确认删除该岗位？岗位下存在文档时将无法删除。">
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
    var button = event.target.closest('.position-edit');
    if (button === null) {
        return;
    }
    document.getElementById('positionFormTitle').textContent = '编辑岗位';
    document.getElementById('positionId').value = button.dataset.id || '';
    document.getElementById('positionName').value = button.dataset.name || '';
    document.getElementById('positionSort').value = button.dataset.sort || '100';
    document.getElementById('positionStatus').value = button.dataset.status === '0' ? '0' : '1';
    document.getElementById('positionName').focus();
});
</script>
