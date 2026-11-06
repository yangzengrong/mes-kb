<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Setting;
use App\Core\View;
use App\Models\Attachment;
use App\Models\CaseEffectiveness;
use App\Models\CaseModel;
use App\Models\Category;
use App\Models\Process;

/**
 * 前台案例详情（需求 4.8 案例详情页优化：浏览量统计、附件展示）。
 */
class CaseController
{
    public function detail(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $case = CaseModel::findPublished($id);

        if ($case === null) {
            $this->notFound();
            return;
        }

        CaseModel::incrementViews($id);
        Logger::access('view', $id, (string) $case['title']);

        $attachments = Attachment::byCase($id);
        $neighbours  = CaseModel::neighbours($id);

        // 相关案例：同分类优先，稀疏分类按同工序补位；手册页额外带同分类案例
        // （2026-10-23 由单来源 featured 改为 relatedTo，稀疏分类下列表不再只剩一两条）
        $related = CaseModel::relatedTo($case, 5);

        View::render('front/case', [
            'pageTitle'   => (string) $case['title'],
            'case'        => $case,
            'attachments' => $attachments,
            'neighbours'  => $neighbours,
            'related'     => $related,
            'categories'  => Category::tree(true),
            'siteName'    => (string) Setting::get('site.name', 'MES系统知识库'),
            'siteIntro'   => (string) Setting::get('site.intro', ''),
            'copyright'   => (string) Setting::get('site.copyright', '©Yangzengrong 版权所有'),
            'notice'      => (string) Setting::get('site.notice', ''),
            'noticeOn'    => Setting::bool('home.notice_enabled', true),
            // 顶部工序导航：高亮案例所属工序
            'navProcesses'       => Process::enabled(),
            'navActiveProcessId' => (int) ($case['process_id'] ?? 0),
            // 有效性反馈（断点D）：当前案例的投票统计 + 访客自己的选择
            'effectiveness'      => CaseEffectiveness::statsFor($id),
            // 投票结果提示（断点D）：只取白名单值，避免任意串进模板
            'votedFlag'          => in_array(Request::get('voted', ''), ['helpful', 'unhelpful', 'error'], true)
                ? (string) Request::get('voted', '')
                : '',
            'votedMsg'           => mb_substr((string) Request::get('msg', ''), 0, 60, 'UTF-8'),
        ], 'front/layout');
    }

    /**
     * 有效性反馈提交（断点D）。
     *
     * 【为什么用「返回详情页」而不是 AJAX】
     * 内网环境一线用的浏览器杂（IE 兼容模式、旧版 Edge、微信内置浏览器），
     * fetch/XHR 的兼容性风险高于收益。用普通表单 POST + 302 跳转最稳。
     *
     * 【防刷】
     * 唯一索引保证「同一人对同一条只算一票」，无需额外限流器。
     * 蜜罐字段挡掉最粗糙的机器人。
     */
    public function vote(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);

        if (!Csrf::check(Request::post('_token'))) {
            $this->backWithErrors($id, '页面令牌已失效，请刷新页面后重试。');
        }

        // 蜜罐：正常用户看不到也不会填
        if (trim((string) Request::post('website', '')) !== '') {
            $this->backWithErrors($id, '提交被拒绝。');
        }

        $case = CaseModel::findPublished($id);
        if ($case === null) {
            $this->notFound();
            return;
        }

        $helpful = Request::post('helpful') === '1';
        $note    = (string) Request::post('note', '');
        if (!$helpful && $note === '') {
            $note = '（未填写原因）';       // 记下「没帮到」，原因留空也保留信号
        }

        CaseEffectiveness::record($id, $helpful, $note);

        // 【为什么用 URL 参数而不是 flash()】
        // 前台 layout 不渲染 flash（全站只有反馈页自己传 flashMsg），
        // 要么给 layout 加渲染逻辑（影响所有前台页面），要么把结果写在 URL 上。
        // 投票后必然回到这条案例详情页，用 ?voted= 参数最省事，且刷新后自动消失。
        $flag = $helpful ? 'helpful' : 'unhelpful';
        redirect(base_url('case/' . $id . '?voted=' . $flag));
    }

    /**
     * 反馈失败时的回退。
     *
     * 不用 BaseController 的 back()（它面向后台 flash 设计）。
     * 错误文案通过 URL 传递并截断到 60 字 —— 不能让任意长文本进 URL，
     * 也不能让任意文本直接进模板（虽然已 e() 转义，但截断是第二道保险）。
     */
    private function backWithErrors(int $id, string $message): void
    {
        if ($id <= 0) {
            $this->notFound();
            return;
        }
        $short = mb_substr($message, 0, 60, 'UTF-8');
        redirect(base_url('case/' . $id . '?voted=error&msg=' . urlencode($short)));
    }

    /**
     * 打印视图：白底、无导航，供现场工程师打印或「另存为 PDF」。
     *
     * 不套用前台布局、不累加浏览量，避免打印推动统计失真。
     */
    public function printView(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $case = CaseModel::findPublished($id);

        if ($case === null) {
            $this->notFound();
            return;
        }

        View::render('front/case_print', [
            'pageTitle'   => (string) $case['title'],
            'case'        => $case,
            'attachments' => Attachment::byCase($id),
            'siteName'    => (string) Setting::get('site.name', 'MES系统知识库'),
            'copyright'   => (string) Setting::get('site.copyright', '©Yangzengrong 版权所有'),
            'printUrl'    => base_url('case/' . $id),
        ]);
    }

    /**
     * 分享卡片图（2026-10-17）。
     *
     * 【为什么是「卡片图」而不是「调微信分享 API」】
     * 微信的两条硬限制决定了只能这么做：
     *   ① 「分享到朋友圈」不允许网页程序触发 —— 微信明确禁止，没有任何 API 能绕过，
     *      只能生成一张可长按转发的图片，引导用户自己操作。
     *   ② 微信 JS-SDK（wx.config）需要「已备案公网域名 + 认证公众号」，
     *      当前站点在内网 192.168.90.217，SDK 必然失败。
     * 而「生成卡片图 + 长按转发」这条路**内网现在就能用，外网开放后也不用改** ——
     * 长按转发是微信的基础能力，不依赖任何 SDK。
     *
     * 【为什么缓存到磁盘】
     * 卡片图每请求一次要做「编码 + GF 运算 + 位图渲染 + PNG 压缩」，实测约 40~80ms。
     * 案例内容基本不变，按「案例 id + 版本号」缓存成文件，命中就直接返回。
     * 版本号进文件名，内容更新后自然换了文件名，不会出现「图旧了内容新了」。
     */
    public function shareCard(array $params): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $case = CaseModel::findPublished($id);

        if ($case === null) {
            $this->notFound();
            return;
        }

        $kind = (string) ($params['kind'] ?? 'card');
        if (!in_array($kind, ['card', 'qr'], true)) {
            $kind = 'card';
        }

        /* 后台关闭分享时，端点也要拦住 ——
           否则按钮虽然藏了，知道地址的人仍能取到图（缓存失效后就是新的）。
           开关在「系统配置 → 分享到微信」。 */
        if (Setting::bool('share.enabled', true) === false) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '分享功能已关闭。';
            return;
        }

        try {
            $file = $this->buildShareImage($case, $kind);
        } catch (\Throwable $ex) {
            /* 分享功能失败不该让用户看到 500 —— 给出可操作的提示并返回 503。
               记到 PHP error_log 而不是 Logger::operation()：
               那张表是「操作审计」，分享图渲染失败是技术故障不是人的操作，
               混进去会污染审计记录（而且 Logger 没有 error() 方法）。 */
            error_log('[share_card] case=' . $id . ' kind=' . $kind . ' err=' . $ex->getMessage());
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '分享图生成失败，请稍后重试或改用「复制链接」。';
            return;
        }

        $mime  = 'image/png';
        $bytes = (string) file_get_contents($file);

        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        /* 内容随案例版本变化，用 ETag 让浏览器与微信自带缓存能复用 */
        header('ETag: "' . md5($bytes) . '"');
        header('Cache-Control: private, max-age=86400');
        echo $bytes;
    }

    /**
     * 生成（或复用）分享图片，返回文件路径。
     *
     * @param array<string,mixed> $case
     */
    private function buildShareImage(array $case, string $kind): string
    {
        $id = (int) $case['id'];
        $dir = STORAGE_PATH . '/share';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        /* 文件名带版本号：案例内容改了 → 版本号变 → 文件名变 → 自然失效 */
        $file = $dir . '/case-' . $id . '-v' . (int) $case['version_no'] . '-' . $kind . '.png';
        if (is_file($file) && filesize($file) > 100) {
            return $file;
        }

        $shareUrl = $this->absoluteUrl('case/' . $id);
        $qr = \App\Support\QrCode::png($shareUrl, 8, 4, 'M');

        if ($kind === 'qr') {
            $tmp = $file . '.tmp';
            file_put_contents($tmp, $qr);
            @rename($tmp, $file);
            return $file;
        }

        $title = (string) $case['title'];

        /* 标签取前 4 个，卡片上放不下更多，且多反而看不清。
           注意 CaseTag::parse() 返回的是**扁平标签数组**（已去重、已过滤非法值），
           不是 ['labels'=>...] 这种结构 —— 早先按结构体取值会拿到空数组。 */
        $tags = \App\Core\CaseTag::parse((string) ($case['tags'] ?? ''));
        $chips = array_slice($tags, 0, 4);
        if ($chips === []) {
            $chips = array_values(array_filter([
                (string) ($case['process_name'] ?? ''),
                (string) ($case['category_name'] ?? ''),
            ]));
        }

        $card = new \App\Support\ShareCard($title, $qr);
        /* 副标题留空则回退到站点名称 —— 后台不填时不该显示空白行。
           配置项见「系统配置 → 分享到微信」。 */
        $subtitle = trim((string) Setting::get('share.card_title', ''));
        if ($subtitle === '') {
            $subtitle = (string) Setting::get('site.name', 'MES · 制造系统知识库');
        }
        $card->subtitle($subtitle);
        if ($chips !== []) {
            $card->meta($chips);
        }
        $hint = trim((string) Setting::get('share.card_hint', ''));
        $card->footer($hint !== '' ? $hint : '扫码或长按图片转发');

        $png = $card->png();
        $tmp = $file . '.tmp';
        file_put_contents($tmp, $png);
        @rename($tmp, $file);

        return $file;
    }

    /**
     * 生成含协议与 host 的绝对地址。
     *
     * 【为什么不能直接用 base_url()】
     * base_url 返回的是**相对路径**（'/case/37'），二维码必须是绝对 URL 才能被扫描枪识别。
     * host 从请求头取，因此内网访问时生成内网地址、外网开放后自动变成外网地址 ——
     * 不需要改配置。这里做了 X-Forwarded-Proto 的兼容（若将来放到 nginx 反代后面）。
     */
    private function absoluteUrl(string $path): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $https ? 'https' : 'http';

        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            /* 取不到 host 就退回 localhost，至少保证二维码可扫（生产环境会由 HTTP_HOST 覆盖） */
            $host = 'localhost';
        }
        /* 防止 Host 头被注入恶意内容（会落进二维码，扫出来是攻击者的站点） */
        $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host) ?? '';

        return $scheme . '://' . $host . base_url($path);
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('front/error', [
            'pageTitle'  => '案例不存在',
            'siteName'   => (string) Setting::get('site.name', 'MES系统知识库'),
            'siteIntro'  => (string) Setting::get('site.intro', ''),
            'copyright'  => (string) Setting::get('site.copyright', '©Yangzengrong 版权所有'),
            'notice'     => '',
            'noticeOn'   => false,
            'message'    => '该案例不存在、已下架或尚未通过审核。',
        ], 'front/layout');
    }
}
