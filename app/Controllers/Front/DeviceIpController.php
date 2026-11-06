<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Setting;
use App\Core\Validator;
use App\Core\View;
use App\Models\DeviceIp;
use App\Models\Process;

/**
 * 车间设备采集 IP 地址（前台）：按设备名称 / IP / 类型 / 工序检索历史内网地址，
 * 并支持前台报备新设备（提交后待管理员审核）。
 *
 * 数据来源：MES 开发商《车间设备采集IP地址.xlsx》，后台「设备IP管理」维护。
 */
class DeviceIpController
{
    /** 同 IP 每小时最多报备条数 */
    private const SUBMIT_LIMIT = 5;

    /**
     * 设备 IP 查询页（GET）。
     */
    public function index(): void
    {
        $keyword  = trim((string) Request::get('keyword', ''));
        $type     = trim((string) Request::get('type', ''));
        $process  = trim((string) Request::get('process', ''));
        $page     = max(1, Request::int('page', 1));
        // status=history 看历史 IP（设备已更换）；默认只看在用
        $status   = Request::get('status', '') === 'history'
            ? DeviceIp::STATUS_RETIRED
            : DeviceIp::STATUS_ACTIVE;

        if (!in_array($type, DeviceIp::TYPES, true)) {
            $type = '';
        }

        // 兼容旧链接：?process_id=N → 按工序名过滤
        if ($process === '') {
            $legacyPid = Request::int('process_id');
            if ($legacyPid > 0) {
                $legacy = Process::find($legacyPid);
                if ($legacy !== null) {
                    $process = (string) $legacy['name'];
                }
            }
        }

        $result = DeviceIp::search($keyword, $type, $process, $status, $page, 20);

        $this->render('front/device_ip', [
            'keyword'         => $keyword,
            'type'            => $type,
            'process'         => $process,
            'status'          => $status,
            'result'          => $result,
            'typeCounts'      => DeviceIp::typeCounts($status),
            'stats'           => DeviceIp::statusCounts(),
            'conflicts'       => DeviceIp::ipConflicts(),
            'processes'       => Process::enabled(),
            'customProcesses' => DeviceIp::customProcessNames(),
            'oldInput'        => $this->takeOld(),
            'errors'          => $this->takeErrors(),
            'flashMsg'        => flash(),
        ], '设备IP查询');
    }

    /**
     * 前台报备新设备 IP（POST）：进入待审核队列，管理员审核后转为在用。
     */
    public function submit(): void
    {
        if (!Csrf::check(Request::post('_token'))) {
            $this->backWithErrors([], ['general' => '页面令牌已失效，请刷新页面后重试。']);
        }

        // 蜜罐字段：正常用户看不到也不会填
        if (trim((string) Request::post('website', '')) !== '') {
            $this->backWithErrors([], ['general' => '提交被拒绝（疑似机器提交）。']);
        }

        // 同 IP 频率限制
        if (DeviceIp::countRecentByIp(Request::ip(), 60) >= self::SUBMIT_LIMIT) {
            $this->backWithErrors([], ['general' => '提交过于频繁，同一网络每小时最多报备 ' . self::SUBMIT_LIMIT . ' 条，请稍后再试。']);
        }

        $input = [
            'device_name'    => trim((string) Request::post('device_name', '')),
            'ip_address'     => trim((string) Request::post('ip_address', '')),
            'device_type'    => trim((string) Request::post('device_type', '')),
            'process_choice' => trim((string) Request::post('process_choice', '0')),
            'process_custom' => trim((string) Request::post('process_custom', '')),
            'remark'         => trim((string) Request::post('remark', '')),
        ];

        $validator = new Validator($input);
        $validator->rules([
            'device_name'    => 'required|maxlen:200',
            'ip_address'     => 'required|maxlen:64',
            'device_type'    => 'in:' . implode(',', DeviceIp::TYPES),
            'process_custom' => 'maxlen:100',
            'remark'         => 'maxlen:500',
        ], [
            'device_name'    => '设备名称',
            'ip_address'     => 'IP 地址',
            'device_type'    => '设备类型',
            'process_custom' => '自定义工序名称',
            'remark'         => '备注',
        ]);

        if (!$validator->passes()) {
            $this->backWithErrors($input, $validator->errors());
        }

        // IP 合法性（仅接受 IPv4 / IPv6，避免把备注文本写进 IP 列）
        if (filter_var($input['ip_address'], FILTER_VALIDATE_IP) === false) {
            $this->backWithErrors($input, ['ip_address' => 'IP 地址格式不正确，请填写如 192.168.1.11 的内网地址。']);
        }

        // 关联工序：标准工序（按名绑定）/ 自定义新工序 / 未绑定
        $resolved = DeviceIp::resolveProcess(Process::enabled(), $input['process_choice'], $input['process_custom']);
        if ($input['process_choice'] === DeviceIp::CUSTOM_CHOICE && $resolved['custom_process'] === '') {
            $this->backWithErrors($input, ['process_custom' => '请填写自定义工序名称（如：辐照、包装或新产线名）。']);
        }

        DeviceIp::create([
            'device_name'    => $input['device_name'],
            'ip_address'     => $input['ip_address'],
            'device_type'    => in_array($input['device_type'], DeviceIp::TYPES, true)
                ? $input['device_type'] : DeviceIp::guessType($input['device_name']),
            'process_id'     => $resolved['process_id'],
            'custom_process' => $resolved['custom_process'],
            'status'         => DeviceIp::STATUS_PENDING,
            'remark'         => $input['remark'],
            'source'         => 'submit',
            'submit_ip'      => Request::ip(),
        ]);

        flash('success', '报备成功：' . $input['device_name'] . '（' . $input['ip_address']
            . '）已提交，待管理员审核后展示。');
        redirect(base_url('device-ip'));
    }

    // =========================================================
    // 内部方法
    // =========================================================

    /**
     * @return array<string,string>
     */
    private function takeErrors(): array
    {
        $errors = $_SESSION['_dev_errors'] ?? [];
        unset($_SESSION['_dev_errors']);
        return is_array($errors) ? $errors : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function takeOld(): array
    {
        $old = $_SESSION['_dev_old'] ?? [];
        unset($_SESSION['_dev_old']);
        return is_array($old) ? $old : [];
    }

    /**
     * 回跳表单页：保留已填内容并给出错误提示。
     *
     * @param array<string,mixed> $input
     * @param array<string,string> $errors
     */
    private function backWithErrors(array $input, array $errors): void
    {
        if ($input !== []) {
            unset($input['_token']);
            $_SESSION['_dev_old'] = $input;
        }
        $_SESSION['_dev_errors'] = $errors;
        redirect(base_url('device-ip#report-form'));
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
