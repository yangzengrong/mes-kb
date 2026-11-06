<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Validator;
use App\Models\Category;

/**
 * 故障案例分类管理（需求 4.2）。
 */
class CategoryController extends BaseController
{
    public function index(): void
    {
        $this->guard('category.view');

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        // scope 分组：故障类型（案例用）/ 功能模块（手册用）两套树分开维护，
        // 否则新建手册时会在下拉里看到「程序报错」这类与手册语义冲突的选项。
        $scope = (string) Request::get('scope', 'case');
        if (!in_array($scope, ['case', 'manual'], true)) {
            $scope = 'case';
        }

        $this->render('admin/categories', [
            'activeMenu' => 'categories',
            'scope'      => $scope,
            'tree'       => Category::withCaseCount($scope),
            'options'    => Category::options(false, $scope),
            'errors'     => $errors,
            'old'        => $old,
            'isSuper'    => \App\Core\Auth::instance()->isSuperAdmin(),
        ], $scope === 'manual' ? '手册功能模块管理' : '故障类型管理');
    }

    public function save(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id       = Request::int('id');
        $name     = (string) Request::post('name', '');
        $parentId = Request::int('parent_id');
        $sort     = Request::int('sort');
        $status   = Request::post('status') === '0' ? 0 : 1;
        // 新增时按当前分组落到对应 scope；编辑时保持原 scope（不允许跨组迁移）
        $scope    = (string) Request::post('scope', 'case');
        if (!in_array($scope, ['case', 'manual'], true)) {
            $scope = 'case';
        }
        $backUrl = 'categories.php?scope=' . $scope;

        $validator = new Validator(['name' => $name, 'parent_id' => $parentId]);
        $validator->rules([
            'name'      => 'required|maxlen:60',
            'parent_id' => 'int',
        ], ['name' => '分类名称']);
        if (!$validator->passes()) {
            $this->backWithErrors($_POST, $validator->errors(), $backUrl);
        }

        if ($parentId > 0) {
            $parent = Category::find($parentId);
            if ($parent === null) {
                $this->backWithErrors($_POST, ['parent_id' => '上级分类不存在。'], $backUrl);
            }
            // 上级必须同 scope：故障类型的子级不能是功能模块，反之亦然
            if ((string) ($parent['scope'] ?? 'case') !== $scope) {
                $this->backWithErrors(
                    $_POST,
                    ['parent_id' => '上级分类必须属于同一分组（故障类型 / 手册模块不可混用）。'],
                    $backUrl
                );
            }
            if ((int) $parent['parent_id'] !== 0) {
                $this->backWithErrors($_POST, ['parent_id' => '系统仅支持一级、二级分类，请选择一级分类作为上级。'], $backUrl);
            }
            if ($id > 0 && $parentId === $id) {
                $this->backWithErrors($_POST, ['parent_id' => '上级分类不能是自身。'], $backUrl);
            }
        }

        $payload = [
            'name'       => $name,
            'parent_id'  => $parentId,
            'sort'       => $sort,
            'status'     => $status,
            'updated_at' => now(),
        ];

        if ($id > 0) {
            $exists = Category::find($id);
            if ($exists === null) {
                $this->back($backUrl, 'error', '分类不存在。');
            }
            Category::modify($id, $payload);
            $this->log('category.update', 'category', $id, '更新分类：' . $name);
            $this->back($backUrl, 'success', '分类「' . $name . '」已更新。');
        }

        $payload['created_at'] = now();
        $payload['scope']      = $scope;
        $newId = Category::create($payload);
        $this->log('category.create', 'category', $newId, '新增分类：' . $name);
        $this->back($backUrl, 'success', '分类「' . $name . '」已创建。');
    }

    public function toggle(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $status = Request::int('status');
        $category = Category::find($id);

        if ($category === null) {
            $this->back('categories.php', 'error', '分类不存在。');
        }

        $backUrl = 'categories.php?scope=' . (string) ($category['scope'] ?? 'case');

        Category::modify($id, [
            'status'     => $status === 1 ? 1 : 0,
            'updated_at' => now(),
        ]);
        $this->log('category.toggle', 'category', $id,
            ($status === 1 ? '启用' : '禁用') . '分类：' . $category['name']);
        $this->back($backUrl, 'success',
            '分类「' . $category['name'] . '」已' . ($status === 1 ? '启用' : '禁用') . '。');
    }

    public function delete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id       = Request::int('id');
        $category = Category::find($id);

        if ($category === null) {
            $this->back('categories.php', 'error', '分类不存在。');
        }

        $backUrl = 'categories.php?scope=' . (string) ($category['scope'] ?? 'case');

        if (Category::hasChildren($id)) {
            $this->back($backUrl, 'error', '该分类下存在二级分类，请先处理子分类。');
        }

        $caseCount = Category::caseCount($id);
        if ($caseCount > 0) {
            $this->back($backUrl, 'error',
                '该分类下仍有 ' . $caseCount . ' 条文档，请先转移或删除相关文档。');
        }

        \App\Core\Database::execute('DELETE FROM categories WHERE id = :id', ['id' => $id]);
        $this->log('category.delete', 'category', $id, '删除分类：' . $category['name']);
        $this->back($backUrl, 'success', '分类「' . $category['name'] . '」已删除。');
    }
}
