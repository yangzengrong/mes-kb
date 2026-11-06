<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Models\LogModel;

/**
 * 系统日志记录（需求 4.6）。
 * 操作日志与登录日志仅超级管理员可见；运维管理员仅可查看自己的操作记录。
 */
class LogController extends BaseController
{
    public function operations(): void
    {
        $this->guard('log.view_own');
        $auth = Auth::instance();

        $filters = [
            'keyword'    => (string) Request::get('keyword', ''),
            'action'     => (string) Request::get('action', ''),
            'start_date' => (string) Request::get('start_date', ''),
            'end_date'   => (string) Request::get('end_date', ''),
            'user_id'    => $auth->isSuperAdmin() ? 0 : $auth->id(),
        ];

        $page   = max(1, Request::int('page', 1));
        $result = LogModel::paginateOperations($filters, $page, 30);

        $this->render('admin/logs', [
            'activeMenu' => 'logs',
            'tab'        => 'operations',
            'filters'    => $filters,
            'result'     => $result,
            'summary'    => LogModel::summary(),
            'isSuper'    => $auth->isSuperAdmin(),
        ], '操作日志');
    }

    public function access(): void
    {
        $this->guardSuper();

        $filters = [
            'keyword'    => (string) Request::get('keyword', ''),
            'action'     => (string) Request::get('action', ''),
            'start_date' => (string) Request::get('start_date', ''),
            'end_date'   => (string) Request::get('end_date', ''),
        ];

        $page   = max(1, Request::int('page', 1));
        $result = LogModel::paginateAccess($filters, $page, 30);

        $this->render('admin/logs', [
            'activeMenu' => 'logs',
            'tab'        => 'access',
            'filters'    => $filters,
            'result'     => $result,
            'summary'    => LogModel::summary(),
            'isSuper'    => true,
        ], '访问日志');
    }

    public function loginAttempts(): void
    {
        $this->guardSuper();

        $page   = max(1, Request::int('page', 1));
        $result = LogModel::paginateLoginAttempts($page, 30);

        $this->render('admin/logs', [
            'activeMenu' => 'logs',
            'tab'        => 'login',
            'filters'    => [],
            'result'     => $result,
            'summary'    => LogModel::summary(),
            'isSuper'    => true,
        ], '登录日志');
    }

    /**
     * 导出操作日志为 CSV（需求 4.6 支持导出备份）。
     */
    public function export(): void
    {
        $this->guardSuper();

        $filters = [
            'keyword'    => (string) Request::get('keyword', ''),
            'action'     => (string) Request::get('action', ''),
            'start_date' => (string) Request::get('start_date', ''),
            'end_date'   => (string) Request::get('end_date', ''),
        ];

        $csv      = LogModel::exportOperationsCsv($filters, 5000);
        $filename = 'operation_logs_' . date('YmdHis') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;

        $this->log('log.export', 'log', null, '导出操作日志 ' . $filename);
        exit;
    }
}
