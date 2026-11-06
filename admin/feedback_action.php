<?php
/**
 * 员工反馈写操作统一入口：受理/关闭、生成案例、回收站。
 * 通过 POST 参数 do 区分具体动作，全部要求 POST + CSRF 令牌。
 */

declare(strict_types=1);

use App\Controllers\Admin\FeedbackController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new FeedbackController();

switch ((string) Request::post('do', '')) {
    case 'status':
        $controller->status();
        break;
    case 'to_case':
        $controller->toCase();
        break;
    case 'trash':
        $controller->trash();
        break;
    case 'restore':
        $controller->restore();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
