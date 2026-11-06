<?php
/**
 * 分类管理（需求 4.2 故障案例分类管理）。
 *
 * @var array<int,array<string,mixed>> $tree
 * @var array<int,string> $options
 * @var string $scope                  case=故障类型 / manual=手册功能模块
 * @var array<string,string> $errors
 * @var array<string,mixed> $old
 * @var bool $isSuper
 */
$val = static function (string $field, string $default = '') use ($old) {
    return array_key_exists($field, $old) ? (string) $old[$field] : $default;
};

/**
 * 生成行内操作按钮 HTML。
 */
$rowActions = static function (array $category, int $level) use ($isSuper): string {
    if (!$isSuper) {
        return '<span class="muted small">只读</span>';
    }

    $id     = (int) $category['id'];
    $status = (int) $category['status'];

    $html = '<div class="row-actions">';
    $html .= '<button type="button" class="btn btn-ghost btn-xs category-edit"'
        . ' data-id="' . $id . '"'
        . ' data-name="' . e((string) $category['name']) . '"'
        . ' data-parent="' . (int) $category['parent_id'] . '"'
        . ' data-sort="' . (int) $category['sort'] . '"'
        . ' data-status="' . $status . '">编辑</button>';

    $html .= '<form method="post" action="' . e(admin_url('category_action.php')) . '" class="inline-form">'
        . csrf_field()
        . '<input type="hidden" name="do" value="toggle">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="status" value="' . ($status === 1 ? 0 : 1) . '">'
        . '<button type="submit" class="btn btn-ghost btn-xs">' . ($status === 1 ? '禁用' : '启用') . '</button>'
        . '</form>';

    if ($level === 0) {
        $html .= '<form method="post" action="' . e(admin_url('category_action.php')) . '" class="inline-form"'
            . ' data-confirm="确认删除该分类？分类下若存在案例或子分类将无法删除。">'
            . csrf_field()
            . '<input type="hidden" name="do" value="delete">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="btn btn-danger btn-xs">删除</button>'
            . '</form>';
    }

    return $html . '</div>';
};
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php $isManual = ($scope ?? 'case') === 'manual'; ?>

<div class="tab-bar">
    <a class="tab<?= $isManual ? '' : ' active' ?>"
       href="<?= e(admin_url('categories.php?scope=case')) ?>">故障类型（案例用）</a>
    <a class="tab<?= $isManual ? ' active' : '' ?>"
       href="<?= e(admin_url('categories.php?scope=manual')) ?>">功能模块（手册用）</a>
</div>

<div class="form-layout">
    <div class="form-main">
        <div class="card">
            <div class="card-head">
                <h2 class="card-title"><?= $isManual ? '手册功能模块' : '故障分类树' ?>
                    <em class="muted">一级 / 二级分类</em></h2>
            </div>

            <?php if ($tree === []): ?>
                <div class="empty-state"><p>还没有分类，请在右侧创建第一个分类。</p></div>
            <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:60px">ID</th>
                            <th>分类名称</th>
                            <th style="width:90px"><?= $isManual ? '手册数' : '案例数' ?></th>
                            <th style="width:70px">排序</th>
                            <th style="width:80px">状态</th>
                            <th style="width:220px">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tree as $parent): ?>
                        <tr class="row-parent">
                            <td class="muted"><?= (int) $parent['id'] ?></td>
                            <td><strong><?= e((string) $parent['name']) ?></strong></td>
                            <td><?= (int) $parent['case_count'] ?></td>
                            <td><?= (int) $parent['sort'] ?></td>
                            <td>
                                <span class="badge badge-<?= (int) $parent['status'] === 1 ? 'published' : 'offline' ?>">
                                    <?= (int) $parent['status'] === 1 ? '启用' : '禁用' ?>
                                </span>
                            </td>
                            <td><?= $rowActions($parent, 0) ?></td>
                        </tr>
                        <?php foreach (($parent['children'] ?? []) as $child): ?>
                            <tr class="row-child">
                                <td class="muted"><?= (int) $child['id'] ?></td>
                                <td>└ <?= e((string) $child['name']) ?></td>
                                <td><?= (int) $child['case_count'] ?></td>
                                <td><?= (int) $child['sort'] ?></td>
                                <td>
                                    <span class="badge badge-<?= (int) $child['status'] === 1 ? 'published' : 'offline' ?>">
                                        <?= (int) $child['status'] === 1 ? '启用' : '禁用' ?>
                                    </span>
                                </td>
                                <td><?= $rowActions($child, 1) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isSuper): ?>
    <aside class="form-side">
        <div class="card">
            <h2 class="card-title" id="categoryFormTitle">新增分类</h2>
            <form method="post" action="<?= e(admin_url('category_action.php')) ?>" id="categoryForm">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="save">
                <input type="hidden" name="id" id="categoryId" value="0">
                <input type="hidden" name="scope" value="<?= e($scope ?? 'case') ?>">

                <div class="field">
                    <label for="categoryName"><?= $isManual ? '模块名称' : '分类名称' ?> <span class="req">*</span></label>
                    <input type="text" id="categoryName" name="name" maxlength="60" required
                           placeholder="<?= $isManual ? '例如：工单管理 / 设备管理' : '例如：设备故障 / 程序报错' ?>"
                           value="<?= e($val('name')) ?>">
                </div>

                <div class="field">
                    <label for="categoryParent">上级分类</label>
                    <select id="categoryParent" name="parent_id">
                        <option value="0">作为一级分类</option>
                        <?php foreach ($tree as $parent): ?>
                            <option value="<?= (int) $parent['id'] ?>"><?= e((string) $parent['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="categorySort">排序值</label>
                    <input type="number" id="categorySort" name="sort" value="<?= e($val('sort', '0')) ?>">
                    <span class="field-hint">数值越小越靠前。</span>
                </div>

                <div class="field">
                    <label for="categoryStatus">状态</label>
                    <select id="categoryStatus" name="status">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                    <span class="field-hint">禁用后前台不再展示该分类下的案例入口。</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">保存分类</button>
                    <button type="button" class="btn btn-ghost btn-block" id="categoryFormReset">清空表单</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 class="card-title"><?= $isManual ? '模块设计说明' : '分类设计建议' ?></h2>
            <p class="hint">
                <?php if ($isManual): ?>
                    功能模块描述的是「哪个功能怎么用」，与案例的故障类型（描述「出了什么问题」）
                    语义不同，两套树互不通用。手册只在新建/编辑手册时可选这里的模块。
                <?php else: ?>
                    当前标准故障类型（11 类）：系统参数、数据采集、物料消耗、标签无法打印、
                    无法上料、无法下料、无法结束工单、工单异常、委外异常、辐照异常、质检异常。
                    各工序共用这套类型；旧分类（设备故障、程序报错等）已停用，历史案例仍可正常显示。
                <?php endif; ?>
            </p>
        </div>
    </aside>
    <?php endif; ?>
</div>
