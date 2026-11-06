<?php
declare(strict_types=1);

use App\Controllers\Admin\DashboardController;

require __DIR__ . '/_init.php';

(new DashboardController())->index();
