<?php
declare(strict_types=1);

use App\Controllers\Admin\CategoryController;

require __DIR__ . '/_init.php';

(new CategoryController())->index();
