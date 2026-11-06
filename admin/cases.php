<?php
declare(strict_types=1);

use App\Controllers\Admin\CaseController;

require __DIR__ . '/_init.php';

(new CaseController())->index();
