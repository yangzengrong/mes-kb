<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Models\User;

/**
 * 用户权限管理（需求 4.1 分级权限体系）。
 * 本模块所有操作仅超级管理员可执行。
 */
class UserController extends BaseController
{
    public function index(): void
    {
        $this->guardSuper();

        $filters = [
            'keyword' => (string) Request::get('keyword', ''),
            'role'    => (string) Request::get('role', ''),
            'status'  => (string) Request::get('status', ''),
        ];

        $page   = max(1, Request::int('page', 1));
        $result = User::paginateByFilters($filters, $page, 15);

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        $this->render('admin/users', [
            'activeMenu' => 'users',
            'filters'    => $filters,
            'result'     => $result,
            'roleCounts' => User::roleCounts(),
            'errors'     => $errors,
            'old'        => $old,
        ], '用户权限管理');
    }

    public function save(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id       = Request::int('id');
        $username = (string) Request::post('username', '');
        $realName = (string) Request::post('real_name', '');
        $role     = (string) Request::post('role', '');
        $status   = Request::post('status') === '0' ? 0 : 1;
        $remark   = (string) Request::post('remark', '');
        $password = (string) Request::post('password', '');

        $rules = [
            'username' => 'required|username',
            'real_name' => 'required|maxlen:50',
            'role'     => 'in:super_admin,op_admin,guest,gm',
        ];
        if ($id === 0) {
            $rules['password'] = 'required|password';
        }

        $validator = new Validator([
            'username'  => $username,
            'real_name' => $realName,
            'role'      => $role,
            'password'  => $password,
        ]);
        $validator->rules($rules, [
            'username'  => '登录账号',
            'real_name' => '姓名',
            'role'      => '角色',
            'password'  => '登录密码',
        ]);

        if (!$validator->passes()) {
            $this->backWithErrors($_POST, $validator->errors(), 'users.php');
        }

        if (User::existsUsername($username, $id)) {
            $this->backWithErrors($_POST, ['username' => '该登录账号已存在。'], 'users.php');
        }

        $auth = Auth::instance();

        // 防止把自己降级或禁用，导致系统失去可用超管
        if ($id === $auth->id() && ($role !== Auth::ROLE_SUPER || $status !== 1)) {
            $this->backWithErrors($_POST, ['role' => '不能修改当前登录账号的角色或状态。'], 'users.php');
        }

        $payload = [
            'username'   => $username,
            'real_name'  => $realName,
            'role'       => $role,
            'status'     => $status,
            'remark'     => mb_substr($remark, 0, 255, 'UTF-8'),
            'updated_at' => now(),
        ];

        if ($id > 0) {
            $target = User::find($id);
            if ($target === null) {
                $this->back('users.php', 'error', '用户不存在。');
            }
            if ($target['role'] === Auth::ROLE_SUPER && $role !== Auth::ROLE_SUPER
                && $this->superAdminCount() <= 1) {
                $this->backWithErrors($_POST, ['role' => '系统至少需要保留一名超级管理员。'], 'users.php');
            }
            if ($password !== '') {
                $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            User::modify($id, $payload);
            $this->log('user.update', 'user', $id, '更新账号：' . $username . '（' . role_label($role) . '）');
            $this->back('users.php', 'success', '账号「' . $username . '」已更新。');
        }

        $payload['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $payload['created_at']    = now();
        $newId = User::create($payload);
        $this->log('user.create', 'user', $newId, '新增账号：' . $username . '（' . role_label($role) . '）');
        $this->back('users.php', 'success', '账号「' . $username . '」已创建。');
    }

    public function resetPassword(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id       = Request::int('id');
        $password = (string) Request::post('password', '');
        $target   = User::find($id);

        if ($target === null) {
            $this->back('users.php', 'error', '用户不存在。');
        }
        if (mb_strlen($password, 'UTF-8') < 6) {
            $this->backWithErrors([], ['password' => '新密码长度不能少于 6 位。'], 'users.php');
        }

        User::modify($id, [
            'password_hash'    => password_hash($password, PASSWORD_DEFAULT),
            'login_fail_count' => 0,
            'locked_until'     => null,
            'updated_at'       => now(),
        ]);
        $this->log('user.reset_password', 'user', $id, '重置密码：' . $target['username']);
        $this->back('users.php', 'success', '账号「' . $target['username'] . '」的密码已重置。');
    }

    public function toggleStatus(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $status = Request::int('status');
        $target = User::find($id);
        $auth   = Auth::instance();

        if ($target === null) {
            $this->back('users.php', 'error', '用户不存在。');
        }
        if ($id === $auth->id()) {
            $this->back('users.php', 'error', '不能禁用当前登录账号。');
        }
        if ($status !== 1 && $target['role'] === Auth::ROLE_SUPER && $this->superAdminCount() <= 1) {
            $this->back('users.php', 'error', '系统至少需要保留一名启用状态的超级管理员。');
        }

        User::modify($id, [
            'status'           => $status === 1 ? 1 : 0,
            'login_fail_count' => 0,
            'locked_until'     => null,
            'updated_at'       => now(),
        ]);
        $this->log('user.toggle', 'user', $id,
            ($status === 1 ? '启用' : '禁用') . '账号：' . $target['username']);
        $this->back('users.php', 'success',
            '账号「' . $target['username'] . '」已' . ($status === 1 ? '启用' : '禁用') . '。');
    }

    public function delete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $target = User::find($id);
        $auth   = Auth::instance();

        if ($target === null) {
            $this->back('users.php', 'error', '用户不存在。');
        }
        if ($id === $auth->id()) {
            $this->back('users.php', 'error', '不能删除当前登录账号。');
        }
        if ($target['role'] === Auth::ROLE_SUPER && $this->superAdminCount() <= 1) {
            $this->back('users.php', 'error', '系统至少需要保留一名超级管理员。');
        }

        $owned = (int) Database::scalar(
            'SELECT COUNT(*) FROM cases WHERE author_id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
        if ($owned > 0) {
            $this->back('users.php', 'error',
                '该账号名下仍有 ' . $owned . ' 条案例，请先转移案例归属或改用「禁用」。');
        }

        Database::execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
        $this->log('user.delete', 'user', $id, '删除账号：' . $target['username']);
        $this->back('users.php', 'success', '账号「' . $target['username'] . '」已删除。');
    }

    private function superAdminCount(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM users WHERE role = :role AND status = 1',
            ['role' => Auth::ROLE_SUPER]
        );
    }
}
