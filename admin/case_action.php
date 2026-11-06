<?php
/**
 * 案例相关写操作统一入口：新增/更新、审核、状态流转、回收站、版本回滚。
 * 通过 POST 参数 do 区分具体动作，全部要求 POST + CSRF 令牌。
 */

declare(strict_types=1);

use App\Controllers\Admin\CaseController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new CaseController();

switch ((string) Request::post('do', '')) {
    case 'save':
        $controller->save();
        break;
    case 'review':
        $controller->review();
        break;
    case 'status':
        $controller->status();
        break;
    case 'trash':
        $controller->trash();
        break;
    case 'restore':
        $controller->restore();
        break;
    case 'force_delete':
        $controller->forceDelete();
        break;
    case 'rollback':
        $controller->rollback();
        break;
    case 'bulk_process':
        $controller->bulkProcess();
        break;
    case 'bulk_status':
        $controller->bulkStatus();
        break;
    case 'bulk_items':
        $controller->bulkItems();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
