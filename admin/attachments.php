<?php
declare(strict_types=1);

use App\Controllers\Admin\AttachmentController;

require __DIR__ . '/_init.php';

(new AttachmentController())->index();
