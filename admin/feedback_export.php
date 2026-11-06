<?php
/**
 * 员工反馈导出入口：按当前筛选条件导出 CSV。
 */

declare(strict_types=1);

use App\Controllers\Admin\FeedbackController;

require __DIR__ . '/_init.php';

(new FeedbackController())->export();
