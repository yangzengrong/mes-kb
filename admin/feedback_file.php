<?php
/**
 * 后台预览 / 下载员工反馈附件（未发布案例的附件前台不可见，此处走后台鉴权）。
 */

declare(strict_types=1);

use App\Controllers\Admin\FeedbackController;

require __DIR__ . '/_init.php';

(new FeedbackController())->file();
