<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Setting;
use App\Core\View;
use App\Models\CaseModel;
use App\Models\Feedback;

/**
 * 后台控制器基类：统一登录校验、权限校验、视图渲染与 CSRF 防护。
 */
abstract class BaseController
{
    /**
     * 请求内计数缓存。
     *
     * render() 每次渲染侧栏角标都要取三个计数（回收站 / 待审核 / 待处理反馈），
     * 早期实现是直接调用三个 COUNT 查询 —— 于是后台每一个页面都会固定多打 3 次库，
     * 包括与这些数字毫无关系的页面（设置页、用户页、附件页等）。
     *
     * 改为「按请求缓存」：同一次请求内多次 render()（含 AJAX 分支重渲染）只查一次，
     * 请求结束自动释放，无需手动清理，也不会读到过期数据。
     *
     * @var array<string,int>
     */
    private static array $badgeCache = [];

    /**
     * 读取计数（带请求内缓存 + 故障降级）。
     *
     * 角标属于「锦上添花」信息，不该让它拖垮甚至阻断页面渲染：数据库异常时
     * 返回 0（角标不显示）而不是抛出，让页面主体照常呈现。
     *
     * @param callable():int $resolver
     */
    private function badge(string $key, callable $resolver): int
    {
        if (isset(self::$badgeCache[$key])) {
            return self::$badgeCache[$key];
        }
        try {
            $value = $resolver();
        } catch (\Throwable $e) {
            $value = 0;
        }

        return self::$badgeCache[$key] = $value;
    }

    /**
     * 权限校验：未登录跳登录页，越权返回 403。
     */
    protected function guard(string $permission = ''): void
    {
        $auth = Auth::instance();

        if (!$auth->check()) {
            if (Request::isAjax()) {
                View::json(['code' => 401, 'message' => '登录状态已失效，请重新登录。'], 401);
            }
            redirect(admin_url('login.php'));
        }

        if ($permission !== '' && !$auth->can($permission)) {
            if (Request::isAjax()) {
                View::json(['code' => 403, 'message' => '当前账号无权执行该操作。'], 403);
            }
            http_response_code(403);
            $this->render('admin/error', [
                'title'   => '权限不足',
                'code'    => 403,
                'message' => '当前账号（' . role_label($auth->role()) . '）无权访问该功能，请联系超级管理员分配权限。',
            ], '权限不足');
            exit;
        }
    }

    /**
     * 仅超级管理员可执行。
     */
    protected function guardSuper(): void
    {
        $this->guard();
        if (!Auth::instance()->isSuperAdmin()) {
            if (Request::isAjax()) {
                View::json(['code' => 403, 'message' => '该操作仅超级管理员可执行。'], 403);
            }
            http_response_code(403);
            $this->render('admin/error', [
                'title'   => '权限不足',
                'code'    => 403,
                'message' => '该操作仅超级管理员可执行。',
            ], '权限不足');
            exit;
        }
    }

    /**
     * 所有 POST 写操作统一校验 CSRF。
     */
    protected function guardPost(): void
    {
        if (Request::isPost()) {
            Csrf::verifyOrFail();
        }
    }

    /**
     * 渲染后台视图。
     *
     * @param array<string,mixed> $data
     */
    protected function render(string $view, array $data = [], string $title = ''): void
    {
        $auth = Auth::instance();
        $data['currentUser'] = $auth->user();
        $data['pageTitle']   = $title;
        $data['flash']       = flash();
        $data['activeMenu']  = $data['activeMenu'] ?? '';
        $data['siteName']    = (string) Setting::get('site.name', 'MES系统知识库');
        $data['copyright']   = (string) Setting::get('site.copyright', '©Yangzengrong 版权所有');

        // 侧栏角标：按权限条件化 —— 无权限用户不触发对应的 COUNT 查询，
        // 且同一次请求内只查一次（见 $badgeCache 注释）。
        $data['trashCount'] = $auth->can('case.view')
            ? $this->badge('trash', static fn (): int => CaseModel::trashCount())
            : 0;
        $data['pendingCount'] = $auth->isSuperAdmin()
            ? $this->badge('pending', static fn (): int => CaseModel::statusCounts()['pending'] ?? 0)
            : 0;
        $data['pendingFeedbackCount'] = $auth->can('feedback.view')
            ? $this->badge('pendingFeedback', static fn (): int => Feedback::pendingCount())
            : 0;

        View::render($view, $data, 'admin/layout');
    }

    /**
     * 写操作完成后跳转并带一次性提示。
     *
     * @param array<string,string> $params
     */
    protected function back(string $path, string $type, string $message, array $params = []): void
    {
        flash($type, $message);
        $url = admin_url($path);
        if ($params !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        redirect($url);
    }

    /**
     * 记录操作日志。
     *
     * @param mixed $targetId
     */
    protected function log(string $action, ?string $targetType, $targetId, string $detail): void
    {
        Logger::operation($action, $targetType, $targetId, $detail);
    }

    /**
     * 保存表单数据用于回填，并跳回表单页。
     *
     * @param array<string,mixed> $input
     */
    protected function backWithErrors(array $input, array $errors, string $path): void
    {
        unset($input['_token'], $input['password'], $input['password_confirm'], $input['admin_password']);
        $_SESSION['_old']    = $input;
        $_SESSION['_errors'] = $errors;
        redirect(admin_url($path));
    }
}
