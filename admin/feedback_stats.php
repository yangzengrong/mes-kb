<?php
/**
 * 员工反馈统计 / 台账（后台）。
 */

declare(strict_types=1);

use App\Controllers\Admin\FeedbackController;

require __DIR__ . '/_init.php';

(new FeedbackController())->stats();
