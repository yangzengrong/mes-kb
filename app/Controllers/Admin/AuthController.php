<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Captcha;
use App\Core\Database;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Setting;
use App\Core\View;

/**
 * 后台登录（需求 4.7 后台登录安全：账号密码 + 验证码 + 防暴力破解）。
 */
class AuthController extends BaseController
{
    public function login(): void
    {
        if (Auth::instance()->check()) {
            redirect(admin_url('index.php'));
        }

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        /* Split Screen 品牌区（2026-10-27）：信任数字动态取库，登录页也能传递内容规模。
           三条 COUNT 一次取完；失败不影响登录（包了 try，回退 0 + 「+」由视图处理）。 */
        try {
            $statCases     = (int) Database::scalar("SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND status = 'published'");
            $statProcesses = (int) Database::scalar('SELECT COUNT(*) FROM processes WHERE status = 1');
            $statMenus     = (int) Database::scalar('SELECT COUNT(*) FROM mes_menus');
        } catch (\Throwable $e) {
            $statCases = $statProcesses = $statMenus = 0;
        }

        View::render('admin/login', [
            'errors'        => $errors,
            'old'           => $old,
            'siteName'      => (string) Setting::get('site.name', 'MES系统知识库'),
            'copyright'     => (string) Setting::get('site.copyright', '©Yangzengrong 版权所有'),
            'lockedHint'    => (string) Request::get('locked', ''),
            'statCases'     => $statCases,
            'statProcesses' => $statProcesses,
            'statMenus'     => $statMenus,
        ]);
    }

    public function authenticate(): void
    {
        Csrf::verifyOrFail();

        $username = (string) Request::post('username', '');
        $password = (string) Request::post('password', '');
        $captcha  = (string) Request::post('captcha', '');

        $errors = [];

        if ($username === '') {
            $errors['username'] = '请输入登录账号。';
        }
        if ($password === '') {
            $errors['password'] = '请输入登录密码。';
        }
        if (!Captcha::verify($captcha)) {
            $errors['captcha'] = '验证码错误或已失效，请重新输入。';
        }

        if ($errors !== []) {
            $this->backWithErrors(['username' => $username], $errors, 'login.php');
        }

        $result = Auth::instance()->attempt($username, $password, Request::ip());
        if (!$result['ok']) {
            $this->backWithErrors(['username' => $username], ['general' => $result['message']], 'login.php');
        }

        redirect(admin_url('index.php'));
    }

    public function captcha(): void
    {
        Captcha::output();
        exit;
    }

    public function logout(): void
    {
        Auth::instance()->logout();
        Csrf::rotate();
        flash('success', '已安全退出登录。');
        redirect(admin_url('login.php'));
    }
}
