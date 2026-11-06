<?php
declare(strict_types=1);

use App\Controllers\Admin\SettingController;
use App\Core\Request;
use App\Core\View;

require __DIR__ . '/_init.php';

$controller = new SettingController();

if (Request::method() === 'GET') {
    // 备份文件下载走 GET
    if ((string) Request::get('do', '') === 'download_backup') {
        $controller->downloadBackup();
        exit;
    }
    View::json(['code' => 400, 'message' => '不支持的请求方式。'], 400);
}

switch ((string) Request::post('do', '')) {
    case 'save':
        $controller->save();
        break;
    case 'backup':
        $controller->backup();
        break;
    case 'delete_backup':
        $controller->deleteBackup();
        break;
    default:
        View::json(['code' => 400, 'message' => '未知的操作类型。'], 400);
}
