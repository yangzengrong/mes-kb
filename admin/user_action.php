<?php
declare(strict_types=1);

use App\Controllers\Admin\UserController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new UserController();

switch ((string) Request::post('do', '')) {
    case 'save':
        $controller->save();
        break;
    case 'reset_password':
        $controller->resetPassword();
        break;
    case 'toggle':
        $controller->toggleStatus();
        break;
    case 'delete':
        $controller->delete();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
