<?php
declare(strict_types=1);

use App\Controllers\Admin\AuthController;

require __DIR__ . '/_init.php';

(new AuthController())->logout();
