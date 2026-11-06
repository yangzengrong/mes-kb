<?php
declare(strict_types=1);

use App\Controllers\Admin\DeviceIpController;

require __DIR__ . '/_init.php';

(new DeviceIpController())->index();
