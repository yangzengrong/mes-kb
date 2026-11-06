<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Validator;
use App\Models\Position;

/**
 * 岗位分类管理（岗位操作手册维度）。
 */
class PositionController extends BaseController
{
    public function index(): void
    {
        $this->guard('category.view');

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        $this->render('admin/positions', [
            'activeMenu' => 'positions',
            'positions'  => Position::withManualCount(),
            'errors'     => $errors,
            'old'        => $old,
            'isSuper'    => \App\Core\Auth::instance()->isSuperAdmin(),
        ], '岗位管理');
    }

    public function save(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $name   = (string) Request::post('name', '');
        $sort   = Request::int('sort');
        $status = Request::post('status') === '0' ? 0 : 1;

        $validator = new Validator(['name' => $name]);
        $validator->rules(['name' => 'required|maxlen:60'], ['name' => '岗位名称']);
        if (!$validator->passes()) {
            $this->backWithErrors($_POST, $validator->errors(), 'position.php');
        }

        // 岗位名称唯一
        $duplicate = (int) \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM positions WHERE name = :n AND id <> :id',
            ['n' => $name, 'id' => $id]
        );
        if ($duplicate > 0) {
            $this->backWithErrors($_POST, ['name' => '岗位「' . $name . '」已存在。'], 'position.php');
        }

        $payload = [
            'name'       => $name,
            'sort'       => $sort,
            'status'     => $status,
            'updated_at' => now(),
        ];

        if ($id > 0) {
            if (Position::find($id) === null) {
                $this->back('position.php', 'error', '岗位不存在。');
            }
            Position::modify($id, $payload);
            $this->log('position.update', 'position', $id, '更新岗位：' . $name);
            $this->back('position.php', 'success', '岗位「' . $name . '」已更新。');
        }

        $payload['created_at'] = now();
        $newId = Position::create($payload);
        $this->log('position.create', 'position', $newId, '新增岗位：' . $name);
        $this->back('position.php', 'success', '岗位「' . $name . '」已创建。');
    }

    public function toggle(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id       = Request::int('id');
        $status   = Request::int('status');
        $position = Position::find($id);

        if ($position === null) {
            $this->back('position.php', 'error', '岗位不存在。');
        }

        Position::modify($id, [
            'status'     => $status === 1 ? 1 : 0,
            'updated_at' => now(),
        ]);
        $this->log('position.toggle', 'position', $id,
            ($status === 1 ? '启用' : '停用') . '岗位：' . $position['name']);
        $this->back('position.php', 'success',
            '岗位「' . $position['name'] . '」已' . ($status === 1 ? '启用' : '停用') . '。');
    }

    public function delete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id       = Request::int('id');
        $position = Position::find($id);

        if ($position === null) {
            $this->back('position.php', 'error', '岗位不存在。');
        }

        $caseCount = Position::caseCount($id);
        if ($caseCount > 0) {
            $this->back('position.php', 'error',
                '该岗位下仍有 ' . $caseCount . ' 条文档，请先转移或解绑相关文档。');
        }

        \App\Core\Database::execute('DELETE FROM positions WHERE id = :id', ['id' => $id]);
        $this->log('position.delete', 'position', $id, '删除岗位：' . $position['name']);
        $this->back('position.php', 'success', '岗位「' . $position['name'] . '」已删除。');
    }
}
