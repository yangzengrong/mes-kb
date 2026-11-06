<?php
/**
 * 知识缺口（内容运营队列）后台入口。
 */

declare(strict_types=1);

use App\Controllers\Admin\GapController;

require __DIR__ . '/_init.php';

(new GapController())->index();
