<?php
/**
 * 富文本编辑器配图上传入口（JSON 接口）。
 *
 * ©Yangzengrong 版权所有
 */

declare(strict_types=1);

use App\Controllers\Admin\AttachmentController;

require __DIR__ . '/_init.php';

(new AttachmentController())->uploadImage();
