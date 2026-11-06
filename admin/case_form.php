<?php
declare(strict_types=1);

use App\Controllers\Admin\CaseController;
use App\Core\Request;

require __DIR__ . '/_init.php';

(new CaseController())->form(['id' => (string) Request::int('id')]);
