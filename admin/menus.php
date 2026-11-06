<?php
declare(strict_types=1);

use App\Controllers\Admin\MesMenuController;

require __DIR__ . '/_init.php';

(new MesMenuController())->index();
