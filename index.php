<?php
/**
 * 前台入口（需求 3.1 首页展示 / 3.2 模糊搜索）。
 *
 * ©Yangzengrong 版权所有
 */

declare(strict_types=1);

use App\Controllers\Front\CaseController;
use App\Controllers\Front\DeviceIpController;
use App\Controllers\Front\FeedbackController;
use App\Controllers\Front\FileController;
use App\Controllers\Front\HomeController;
use App\Core\Router;
use App\Core\Setting;
use App\Core\View;

require __DIR__ . '/app/bootstrap.php';

$router = new Router();
$router->setBasePath(base_url());

$router->get('/', [HomeController::class, 'index']);
$router->get('/index.php', [HomeController::class, 'index']);
$router->get('/search', [HomeController::class, 'search']);
// 故障案例导航（工序 hub）：全局导航「故障案例」的落地页
$router->get('/cases', [HomeController::class, 'cases']);
// MES → U9 同步报错速查（43 条错误码定位）
$router->get('/u9-sync', [HomeController::class, 'u9Sync']);
$router->get('/updates', [HomeController::class, 'updates']);
// 操作手册：按功能模块归类浏览（此前手册只在首页露出最新 5 条，分类导航里不可见）
$router->get('/manuals', [HomeController::class, 'manuals']);
$router->get('/permissions', [HomeController::class, 'permissions']);
// 车间设备采集 IP 地址：查询页 + 前台报备（报备后待管理员审核）
$router->get('/device-ip', [DeviceIpController::class, 'index']);
$router->post('/device-ip', [DeviceIpController::class, 'submit']);
$router->get('/suggest', [HomeController::class, 'suggest']);
// 员工反馈：提交页 / 提交动作 / 进度查询 / 验证码
$router->get('/feedback', [FeedbackController::class, 'form']);
$router->post('/feedback', [FeedbackController::class, 'submit']);
$router->get('/feedback/track', [FeedbackController::class, 'track']);
$router->get('/feedback/captcha', [FeedbackController::class, 'captcha']);
// 员工反馈统计（前台公开聚合页：只放汇总数字，不含个人信息）
$router->get('/feedback/stats', [FeedbackController::class, 'stats']);
$router->get('/case/{id}', [CaseController::class, 'detail']);
// 有效性反馈（断点D）：普通表单 POST，不用 AJAX —— 内网浏览器兼容性优先
$router->post('/case/{id}/vote', [CaseController::class, 'vote']);
$router->get('/case/{id}/print', [CaseController::class, 'printView']);
/* 分享卡片图：kind=card 整张卡片，kind=qr 只出二维码。
   之所以分成两个路由而不是一个参数 —— 二维码要被页面多处引用
   （弹层大图、桌面端悬浮小图），单独出一个更短、更易缓存。 */
$router->get('/case/{id}/share/{kind}', [CaseController::class, 'shareCard']);
$router->get('/file/{id}/download', [FileController::class, 'download']);
$router->get('/file/{id}/preview', [FileController::class, 'preview']);
$router->get('/media/{name}', [FileController::class, 'media']);

$router->fallback(static function (): void {
    http_response_code(404);
    View::render('front/error', [
        'pageTitle'  => '页面不存在',
        'siteName'   => (string) Setting::get('site.name', 'MES系统知识库'),
        'siteIntro'  => (string) Setting::get('site.intro', ''),
        'copyright'  => (string) Setting::get('site.copyright', '©Yangzengrong 版权所有'),
        'notice'     => '',
        'noticeOn'   => false,
        'message'    => '你访问的页面不存在，请检查地址是否正确。',
    ], 'front/layout');
});

$router->dispatch();
