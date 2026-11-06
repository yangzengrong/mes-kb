<?php
declare(strict_types=1);

use App\Controllers\Admin\PositionController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new PositionController();

switch ((string) Request::post('do', '')) {
    case 'save':
        $controller->save();
        break;
    case 'toggle':
        $controller->toggle();
        break;
    case 'delete':
        $controller->delete();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
