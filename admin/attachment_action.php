<?php
declare(strict_types=1);

use App\Controllers\Admin\AttachmentController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new AttachmentController();

switch ((string) Request::post('do', '')) {
    case 'upload':
        $controller->upload();
        break;
    case 'bind':
        $controller->bind();
        break;
    case 'delete':
        $controller->delete();
        break;
    case 'purge':
        $controller->purge();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
