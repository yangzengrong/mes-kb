<?php
declare(strict_types=1);

use App\Controllers\Admin\SettingController;

require __DIR__ . '/_init.php';

(new SettingController())->index();
