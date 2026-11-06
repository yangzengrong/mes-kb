<?php
declare(strict_types=1);

use App\Controllers\Admin\LogController;
use App\Core\Request;

require __DIR__ . '/_init.php';

$controller = new LogController();

switch ((string) Request::get('tab', 'operations')) {
    case 'access':
        $controller->access();
        break;
    case 'login':
        $controller->loginAttempts();
        break;
    default:
        $controller->operations();
}
