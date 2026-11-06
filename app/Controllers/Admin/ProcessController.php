<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Validator;
use App\Models\Process;

/**
 * 工序分类管理（线缆专属改版：一级分类 = 生产工序）。
 */
class ProcessController extends BaseController
{
    public function index(): void
    {
        $this->guard('category.view');

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        $this->render('admin/processes', [
            'activeMenu' => 'processes',
            'processes'  => Process::withCaseCount(),
            'errors'     => $errors,
            'old'        => $old,
            'isSuper'    => \App\Core\Auth::instance()->isSuperAdmin(),
        ], '工序管理');
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
        $validator->rules(['name' => 'required|maxlen:60'], ['name' => '工序名称']);
        if (!$validator->passes()) {
            $this->backWithErrors($_POST, $validator->errors(), 'process.php');
        }

        // 工序名称唯一（uk_name 兜底，这里提前给出可读错误）
        $duplicate = (int) \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM processes WHERE name = :n AND id <> :id',
            ['n' => $name, 'id' => $id]
        );
        if ($duplicate > 0) {
            $this->backWithErrors($_POST, ['name' => '工序「' . $name . '」已存在。'], 'process.php');
        }

        $payload = [
            'name'       => $name,
            'sort'       => $sort,
            'status'     => $status,
            'updated_at' => now(),
        ];

        if ($id > 0) {
            if (Process::find($id) === null) {
                $this->back('process.php', 'error', '工序不存在。');
            }
            Process::modify($id, $payload);
            $this->log('process.update', 'process', $id, '更新工序：' . $name);
            $this->back('process.php', 'success', '工序「' . $name . '」已更新。');
        }

        $payload['created_at'] = now();
        $newId = Process::create($payload);
        $this->log('process.create', 'process', $newId, '新增工序：' . $name);
        $this->back('process.php', 'success', '工序「' . $name . '」已创建。');
    }

    public function toggle(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id      = Request::int('id');
        $status  = Request::int('status');
        $process = Process::find($id);

        if ($process === null) {
            $this->back('process.php', 'error', '工序不存在。');
        }

        Process::modify($id, [
            'status'     => $status === 1 ? 1 : 0,
            'updated_at' => now(),
        ]);
        $this->log('process.toggle', 'process', $id,
            ($status === 1 ? '启用' : '停用') . '工序：' . $process['name']);
        $this->back('process.php', 'success',
            '工序「' . $process['name'] . '」已' . ($status === 1 ? '启用' : '停用') . '。');
    }

    public function delete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id      = Request::int('id');
        $process = Process::find($id);

        if ($process === null) {
            $this->back('process.php', 'error', '工序不存在。');
        }

        $caseCount = Process::caseCount($id);
        if ($caseCount > 0) {
            $this->back('process.php', 'error',
                '该工序下仍有 ' . $caseCount . ' 条文档，请先转移或解绑相关文档。');
        }

        \App\Core\Database::execute('DELETE FROM processes WHERE id = :id', ['id' => $id]);
        $this->log('process.delete', 'process', $id, '删除工序：' . $process['name']);
        $this->back('process.php', 'success', '工序「' . $process['name'] . '」已删除。');
    }
}
