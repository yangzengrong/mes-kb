<?php
/**
 * UEditor 富文本编辑器后端适配入口（JSON 接口）。
 *
 * 登录校验 + CSRF 令牌（经 serverUrl 查询参数携带）在 UeditorController::serve() 内完成。
 *
 * ©Yangzengrong 版权所有
 */

declare(strict_types=1);

use App\Controllers\Admin\UeditorController;

require __DIR__ . '/_init.php';

(new UeditorController())->serve();
