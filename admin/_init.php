<?php
/**
 * 后台入口公共引导（需求 2.3 独立后台入口，前后端路由隔离）。
 * 所有 /admin 下的入口文件都先引入本文件，再执行业务控制器。
 *
 * ©Yangzengrong 版权所有
 */

declare(strict_types=1);

use App\Core\Firewall;

require dirname(__DIR__) . '/app/bootstrap.php';

// 需求 4.7：IP 访问限制（后台白名单），未启用时直接放行
Firewall::enforceAdmin();
