<?php
declare(strict_types=1);

use App\Controllers\Admin\LogController;

require __DIR__ . '/_init.php';

(new LogController())->export();
