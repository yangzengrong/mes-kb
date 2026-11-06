<?php
declare(strict_types=1);

use App\Controllers\Admin\PositionController;

require __DIR__ . '/_init.php';

(new PositionController())->index();
