<?php
declare(strict_types=1);

use App\Controllers\Admin\DeviceIpController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new DeviceIpController();

switch ((string) Request::post('do', '')) {
    case 'save':
        $controller->save();
        break;
    case 'delete':
        $controller->delete();
        break;
    case 'approve':
        $controller->approve();
        break;
    case 'reject':
        $controller->reject();
        break;
    case 'retire':
        $controller->retire();
        break;
    case 'restore':
        $controller->restore();
        break;
    case 'import_preview':
        $controller->importPreview();
        break;
    case 'import_confirm':
        $controller->importConfirm();
        break;
    case 'import_cancel':
        $controller->importCancel();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
