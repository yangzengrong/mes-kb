<?php
declare(strict_types=1);

use App\Controllers\Admin\AuthController;
use App\Core\Request;

require __DIR__ . '/_init.php';

$controller = new AuthController();

if (Request::isPost()) {
    $controller->authenticate();
    exit;
}

$controller->login();
