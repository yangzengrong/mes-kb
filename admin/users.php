<?php
declare(strict_types=1);

use App\Controllers\Admin\UserController;

require __DIR__ . '/_init.php';

(new UserController())->index();
