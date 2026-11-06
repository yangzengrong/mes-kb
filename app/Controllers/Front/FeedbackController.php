<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Captcha;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Setting;
use App\Core\Upload;
use App\Core\Validator;
use App\Core\View;
use App\Models\Attachment;
use App\Models\Feedback;
use App\Models\Process;

/**
 * 员工反馈（前台）：提交 MES 疑难问题、按单号查进度。
 *
 * 免登录提交，防护手段：CSRF 令牌 + 图形验证码 + 同 IP 每小时提交上限 + 蜜罐字段。
 */
class FeedbackController
{
    /** 单次提交最多附件数 */
    private const MAX_FILES = 5;

    /** 验证码命名空间，避免与后台登录验证码互相覆盖 */
    private const CAPTCHA_NS = 'feedback';

    /**
     * 反馈首页：提交表单 + 进度查询。
     */
    public function form(): void
    {
        $doneCode = trim((string) Request::get('done', ''));
        $submitted = null;
        if ($doneCode !== '') {
            $submitted = Feedback::findForTrack($doneCode, (string) ($_SESSION['_fb_name'] ?? ''));
            if ($submitted === null) {
                // 姓名未记住时退化为按单号查询（同会话内刚提交，风险可控）
                $submitted = \App\Core\Database::selectOne(
                    'SELECT f.*, p.name AS process_name FROM feedbacks f
                     LEFT JOIN processes p ON p.id = f.process_id
                     WHERE f.code = :c AND f.deleted_at IS NULL LIMIT 1',
                    ['c' => $doneCode]
                );
            }
        }

        $this->render('front/feedback', [
            'submitted'  => $submitted,
            'departments' => feedback_departments(),
            'processes'  => Process::enabled(),
            'faultTypes' => \App\Models\Category::tree(true),
            'urgencies'  => feedback_urgency_map(),
            'errors'     => $this->takeErrors(),
            'oldInput'   => $this->takeOld(),
            'flashMsg'   => flash(),
            'trackResult' => null,
            'trackQuery' => ['code' => '', 'name' => ''],
            'maxBytes'   => Upload::maxBytes(),
            'allowedExt' => implode('、', Upload::allowedExtensions()),
            'tip'        => (string) Setting::get('feedback.tip', ''),
            'enabled'    => feedback_enabled(),
        ], '员工反馈');
    }

    /**
     * 提交反馈（POST）。
     */
    public function submit(): void
    {
        if (!feedback_enabled()) {
            flash('error', '员工反馈功能已关闭，请联系系统管理员。');
            redirect(base_url('feedback'));
        }

        if (!Csrf::check(Request::post('_token'))) {
            $this->backWithErrors([], ['general' => '页面令牌已失效，请刷新页面后重试。']);
        }

        // 蜜罐字段：正常用户看不到也不会填，机器人填写即判定为垃圾提交
        if (trim((string) Request::post('website', '')) !== '') {
            $this->backWithErrors([], ['general' => '提交被拒绝（疑似机器提交）。']);
        }

        $input = [
            'employee_name' => (string) Request::post('employee_name', ''),
            'department'    => (string) Request::post('department', ''),
            'employee_no'   => (string) Request::post('employee_no', ''),
            'contact'       => (string) Request::post('contact', ''),
            'device'        => (string) Request::post('device', ''),
            'title'         => (string) Request::post('title', ''),
            'content'       => (string) Request::post('content', ''),
            'urgency'       => (string) Request::post('urgency', 'normal'),
            'process_id'    => (string) Request::post('process_id', '0'),
            'category_id'   => (string) Request::post('category_id', '0'),
            'captcha'       => (string) Request::post('captcha', ''),
        ];

        // 图形验证码（一次性，校验后立即失效）
        if (!Captcha::verify($input['captcha'], self::CAPTCHA_NS)) {
            $this->backWithErrors($input, ['captcha' => '验证码不正确或已过期，请点击图片刷新后重填。']);
        }

        // 同 IP 频率限制
        $limit = max(1, (int) Setting::get('feedback.limit_per_hour', '5'));
        if (Feedback::countRecentByIp(Request::ip(), 60) >= $limit) {
            $this->backWithErrors($input, ['general' => '提交过于频繁，同一网络每小时最多提交 ' . $limit . ' 条，请稍后再试。']);
        }

        $validator = new Validator($input);
        $validator->rules([
            'employee_name' => 'required|maxlen:50',
            'department'    => 'required|maxlen:60',
            'employee_no'   => 'maxlen:40',
            'contact'       => 'maxlen:60',
            'device'        => 'maxlen:120',
            'title'         => 'required|maxlen:200',
            'content'       => 'required|minlen:5|maxlen:5000',
            'urgency'       => 'in:low,normal,high,urgent',
        ], [
            'employee_name' => '员工姓名',
            'department'    => '归属部门',
            'employee_no'   => '工号',
            'contact'       => '联系方式',
            'device'        => '涉及设备 / 模块',
            'title'         => '问题标题',
            'content'       => '问题描述',
            'urgency'       => '紧急程度',
        ]);

        if (!$validator->passes()) {
            $this->backWithErrors($input, $validator->errors());
        }

        $processId = (int) $input['process_id'];
        if ($processId < 0) {
            $processId = 0;
        }

        $categoryId = (int) $input['category_id'];
        if ($categoryId > 0 && \App\Models\Category::find($categoryId) === null) {
            $categoryId = 0;
        }

        $feedbackId = Feedback::create([
            'code'          => Feedback::generateCode(),
            'employee_name' => $input['employee_name'],
            'department'    => $input['department'],
            'employee_no'   => $input['employee_no'],
            'contact'       => $input['contact'],
            'process_id'    => $processId,
            'category_id'   => $categoryId,
            'device'        => $input['device'],
            'title'         => $input['title'],
            'content'       => $input['content'],
            'urgency'       => array_key_exists($input['urgency'], feedback_urgency_map()) ? $input['urgency'] : 'normal',
            'status'        => Feedback::STATUS_PENDING,
            'ip'            => Request::ip(),
            'user_agent'    => Request::userAgent(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $code     = (string) \App\Core\Database::scalar('SELECT code FROM feedbacks WHERE id = :id', ['id' => $feedbackId]);
        $uploads  = $this->handleUploads($feedbackId);

        Logger::operation('feedback.submit', 'feedback', $feedbackId,
            '员工提交反馈：' . $input['title'] . '（' . $input['employee_name'] . ' / ' . $input['department'] . '）');

        // 记住姓名，便于提交成功页直接展示处理进度
        $_SESSION['_fb_name'] = $input['employee_name'];

        $message = '提交成功，反馈单号：' . $code . '。请留存单号，可在页面下方查询处理进度。';
        if ($uploads['errors'] !== []) {
            $message .= '（部分附件上传失败：' . implode('；', $uploads['errors']) . '）';
        }
        flash('success', $message);
        redirect(base_url('feedback?done=' . urlencode($code)));
    }

    /**
     * 按「反馈单号 + 员工姓名」查询处理进度。
     */
    public function track(): void
    {
        $code = trim((string) Request::get('code', ''));
        $name = trim((string) Request::get('name', ''));
        $result = null;

        if ($code !== '' || $name !== '') {
            if ($code === '' || $name === '') {
                $this->render('front/feedback', $this->baseData([
                    'trackResult' => null,
                    'trackQuery'  => ['code' => $code, 'name' => $name],
                    'errors'      => ['track' => '单号与姓名都需要填写才能查询。'],
                ]), '进度查询');
                return;
            }
            $result = Feedback::findForTrack($code, $name);
            if ($result === null) {
                $this->render('front/feedback', $this->baseData([
                    'trackResult' => null,
                    'trackQuery'  => ['code' => $code, 'name' => $name],
                    'errors'      => ['track' => '未找到匹配的反馈记录，请核对单号与姓名（例如 F260912-0007）。'],
                ]), '进度查询');
                return;
            }
        }

        $this->render('front/feedback', $this->baseData([
            'trackResult' => $result,
            'trackQuery'  => ['code' => $code, 'name' => $name],
        ]), '进度查询');
    }

    /**
     * 反馈统计（前台公开页）：只呈现聚合数字，不含任何个人信息。
     *
     * 与后台「反馈统计」同源（Feedback::stats([])，纯聚合），
     * 保证前台看到的数字与后台台账永远一致；趋势为前台额外聚合的
     * 近 30 天「提交 / 解决」成对数据（解决按办结时间计）。
     */
    public function stats(): void
    {
        // 趋势窗口：默认 30 天，可切 90 天。
        // 【为什么给切换】反馈是低频数据，30 天窗口常常只有一两天有柱，
        // 切到 90 天才能看出真实节奏（2026-10-23 实测窗口内仅 1 天有数据）。
        $days = Request::int('days');
        $days = in_array($days, [30, 90], true) ? $days : 30;

        $this->render('front/feedback_stats', [
            'summary'   => Feedback::stats([]),
            'trend'     => $this->recentTrend($days),
            'trendDays' => $days,
            'enabled'   => feedback_enabled(),
        ], '反馈统计');
    }

    /**
     * 近 N 天趋势：按天聚合提交数与解决数（解决按办结时间计，不含已删除）。
     *
     * @return array<int,array{day:string,created:int,resolved:int}>
     */
    private function recentTrend(int $days): array
    {
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00';

        $created = [];
        foreach (\App\Core\Database::select(
            'SELECT DATE(f.created_at) AS d, COUNT(*) AS c
             FROM feedbacks f
             WHERE f.created_at >= :s AND f.deleted_at IS NULL
             GROUP BY DATE(f.created_at)',
            ['s' => $start]
        ) as $row) {
            $created[(string) $row['d']] = (int) $row['c'];
        }

        $resolved = [];
        foreach (\App\Core\Database::select(
            'SELECT DATE(f.handled_at) AS d, COUNT(*) AS c
             FROM feedbacks f
             WHERE f.handled_at >= :s AND f.deleted_at IS NULL
             GROUP BY DATE(f.handled_at)',
            ['s' => $start]
        ) as $row) {
            $resolved[(string) $row['d']] = (int) $row['c'];
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $out[] = [
                'day'      => $day,
                'created'  => $created[$day] ?? 0,
                'resolved' => $resolved[$day] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * 前台验证码图片（命名空间 feedback）。
     */
    public function captcha(): void
    {
        Captcha::output(self::CAPTCHA_NS);
    }

    // =========================================================
    // 内部方法
    // =========================================================

    /**
     * 反馈附件上传。
     *
     * @return array{ok:int,errors:array<int,string>}
     */
    private function handleUploads(int $feedbackId): array
    {
        $ok     = 0;
        $errors = [];

        if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments'])) {
            return ['ok' => 0, 'errors' => $errors];
        }

        $files = $_FILES['attachments'];
        // 单文件时 $_FILES 是「一维」结构（name 为字符串），多文件时才是数组。
        // 直接按下标取会把字符串当数组，取到首字符导致上传失败，故先统一归一成列表。
        $list = [];
        if (is_array($files['name'])) {
            foreach (array_keys($files['name']) as $index) {
                $list[] = [
                    'name'     => $files['name'][$index] ?? '',
                    'type'     => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error'    => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$index] ?? 0,
                ];
            }
        } else {
            $list[] = $files;
        }

        $count = 0;
        foreach ($list as $single) {
            if ($count >= self::MAX_FILES) {
                $errors[] = '最多上传 ' . self::MAX_FILES . ' 个附件，超出的已忽略。';
                break;
            }

            if ((int) ($single['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $count++;
            $result = Upload::store($single);
            if (!$result['ok']) {
                $errors[] = (string) $single['name'] . '：' . $result['message'];
                continue;
            }

            $data = $result['data'];
            Attachment::create([
                'case_id'     => 0,
                'feedback_id' => $feedbackId,
                'orig_name'   => $data['orig_name'],
                'stored_path' => $data['stored_path'],
                'ext'         => $data['ext'],
                'mime'        => $data['mime'],
                'size'        => $data['size'],
                'uploader_id' => 0,
                'created_at'  => now(),
            ]);
            $ok++;
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    /**
     * 回跳表单页：保留已填内容（不含验证码）并给出错误提示。
     *
     * @param array<string,string> $input  已填内容，用于回填
     * @param array<string,string> $errors 字段级 / 全局错误提示
     */
    private function backWithErrors(array $input, array $errors): void
    {
        if ($input !== []) {
            unset($input['captcha']);
            $_SESSION['_fb_old'] = $input;
        }
        $_SESSION['_fb_errors'] = $errors;
        redirect(base_url('feedback#feedback-form'));
    }

    /**
     * @return array<string,string>
     */
    private function takeErrors(): array
    {
        $errors = $_SESSION['_fb_errors'] ?? [];
        unset($_SESSION['_fb_errors']);
        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string,string>
     */
    private function takeOld(): array
    {
        $old = $_SESSION['_fb_old'] ?? [];
        unset($_SESSION['_fb_old']);
        return is_array($old) ? $old : [];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function baseData(array $extra = []): array
    {
        return array_merge([
            'submitted'   => null,
            'departments' => feedback_departments(),
            'processes'   => Process::enabled(),
            'faultTypes'  => \App\Models\Category::tree(true),
            'urgencies'   => feedback_urgency_map(),
            'errors'      => $this->takeErrors(),
            'oldInput'    => $this->takeOld(),
            'flashMsg'    => flash(),
            'trackResult' => null,
            'trackQuery'  => ['code' => '', 'name' => ''],
            'maxBytes'    => Upload::maxBytes(),
            'allowedExt'  => implode('、', Upload::allowedExtensions()),
            'tip'         => (string) Setting::get('feedback.tip', ''),
            'enabled'     => feedback_enabled(),
        ], $extra);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function render(string $view, array $data, string $title): void
    {
        $data['pageTitle']          = $title;
        $data['siteName']           = (string) Setting::get('site.name', 'MES系统知识库');
        $data['siteIntro']          = (string) Setting::get('site.intro', '');
        $data['copyright']          = (string) Setting::get('site.copyright', '©Yangzengrong 版权所有');
        $data['notice']             = (string) Setting::get('site.notice', '');
        $data['noticeOn']           = false;
        $data['navProcesses']       = Process::enabled();
        $data['navActiveProcessId'] = 0;
        $data['keyword']            = $data['keyword'] ?? '';
        View::render($view, $data, 'front/layout');
    }
}
