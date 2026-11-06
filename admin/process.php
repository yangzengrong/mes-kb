<?php
declare(strict_types=1);

use App\Controllers\Admin\ProcessController;

require __DIR__ . '/_init.php';

(new ProcessController())->index();
