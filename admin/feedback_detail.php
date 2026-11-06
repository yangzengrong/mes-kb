<?php
/**
 * 员工反馈详情（受理 / 生成案例）。
 */

declare(strict_types=1);

use App\Controllers\Admin\FeedbackController;

require __DIR__ . '/_init.php';

(new FeedbackController())->detail();
