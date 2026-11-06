<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Core\XlsxReader;
use App\Models\DeviceIp;
use App\Models\Process;

/**
 * 车间设备采集 IP 地址管理（后台）：维护设备名称 / IP / 类型 / 工序 / 状态，
 * 审核前台报备，支持 xlsx 合并导入（只补缺失行，不覆盖手工维护的数据）。
 */
class DeviceIpController extends BaseController
{
    /** 导入草稿保留时长（秒），超时自动清理 */
    private const DRAFT_TTL = 7200;

    public function index(): void
    {
        $this->guard('device.view');

        $keyword    = trim((string) Request::get('keyword', ''));
        $deviceType = trim((string) Request::get('type', ''));
        $process    = trim((string) Request::get('process', ''));
        $page       = max(1, Request::int('page', 1));
        // status=-1 表示全部状态
        $status     = Request::int('status', -1);

        if (!in_array($deviceType, DeviceIp::TYPES, true)) {
            $deviceType = '';
        }
        if ($status < -1 || $status > 2) {
            $status = -1;
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

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        $this->render('admin/device_ips', [
            'activeMenu'      => 'device_ips',
            'result'          => DeviceIp::adminSearch($keyword, $deviceType, $status, $process, $page, 20),
            'processes'       => Process::enabled(),
            'customProcesses' => DeviceIp::customProcessNames(),
            'pending'         => DeviceIp::pendingList(50),
            'keyword'         => $keyword,
            'type'            => $deviceType,
            'status'          => $status,
            'process'         => $process,
            'stats'           => DeviceIp::statusCounts(),
            'conflicts'       => DeviceIp::ipConflicts(),
            'missingIp'       => DeviceIp::missingIpList(),
            'errors'          => $errors,
            'old'             => $old,
            'isSuper'         => Auth::instance()->isSuperAdmin(),
            'import'          => $this->readImportDraft(),
        ], '设备IP管理');
    }

    /**
     * 新增 / 编辑设备 IP 记录。
     */
    public function save(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id = Request::int('id');

        $fields = [
            'device_name' => trim((string) Request::post('device_name', '')),
            'ip_address'  => trim((string) Request::post('ip_address', '')),
            'device_type' => trim((string) Request::post('device_type', '其他')),
            'status'      => Request::int('status', DeviceIp::STATUS_ACTIVE),
            'remark'      => trim((string) Request::post('remark', '')),
        ];

        $validator = new Validator($fields);
        $validator->rules([
            'device_name' => 'required|maxlen:200',
            'ip_address'  => 'maxlen:64',
            'device_type' => 'in:' . implode(',', DeviceIp::TYPES),
            'remark'      => 'maxlen:500',
        ], [
            'device_name' => '设备名称',
            'ip_address'  => 'IP 地址',
            'device_type' => '设备类型',
            'remark'      => '备注',
        ]);
        if (!$validator->passes()) {
            $this->backWithErrors($_POST, $validator->errors(), 'device_ips.php');
        }

        if (!in_array($fields['status'], [DeviceIp::STATUS_ACTIVE, DeviceIp::STATUS_RETIRED, DeviceIp::STATUS_PENDING], true)) {
            $fields['status'] = DeviceIp::STATUS_ACTIVE;
        }

        // 关联工序：标准工序（按名绑定）/ 自定义新工序 / 未绑定
        $processChoice = trim((string) Request::post('process_choice', '0'));
        $processCustom = trim((string) Request::post('process_custom', ''));
        $resolved      = DeviceIp::resolveProcess(Process::enabled(), $processChoice, $processCustom);
        if ($processChoice === DeviceIp::CUSTOM_CHOICE && $resolved['custom_process'] === '') {
            $this->backWithErrors($_POST, ['process_custom' => '请填写自定义工序名称（如：辐照、包装或新产线名）。'], 'device_ips.php');
        }
        if (mb_strlen($resolved['custom_process'], 'UTF-8') > 100) {
            $this->backWithErrors($_POST, ['process_custom' => '自定义工序名称不能超过 100 字。'], 'device_ips.php');
        }
        $fields['process_id']     = $resolved['process_id'];
        $fields['custom_process'] = $resolved['custom_process'];

        // IP 可为空（源表中存在尚未配置 IP 的设备），非空时校验格式
        if ($fields['ip_address'] !== '' && filter_var($fields['ip_address'], FILTER_VALIDATE_IP) === false) {
            $this->backWithErrors($_POST, ['ip_address' => 'IP 地址格式不正确（留空表示尚未配置）。'], 'device_ips.php');
        }

        if (DeviceIp::isDuplicate($fields, $id)) {
            $this->backWithErrors($_POST, ['device_name' => '已存在完全相同的记录（名称 + IP + 备注一致），请勿重复录入。'], 'device_ips.php');
        }

        if ($id > 0) {
            $existing = DeviceIp::find($id);
            if ($existing === null) {
                $this->back('device_ips.php', 'error', '设备记录不存在。');
            }
            DeviceIp::modify($id, $fields);
            $this->log('device_ip.update', 'device_ip', $id,
                '更新设备IP：' . $this->label($fields));
            $this->back('device_ips.php', 'success', '设备「' . $fields['device_name'] . '」已更新。');
        }

        $fields['source'] = 'manual';
        $newId = DeviceIp::create($fields);
        $this->log('device_ip.create', 'device_ip', $newId,
            '新增设备IP：' . $this->label($fields));
        $this->back('device_ips.php', 'success', '设备「' . $fields['device_name'] . '」已录入。');
    }

    /**
     * 删除设备记录（按精确 id，绝不整表操作）。
     */
    public function delete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id    = Request::int('id');
        $device = DeviceIp::find($id);

        if ($device === null) {
            $this->back('device_ips.php', 'error', '设备记录不存在。');
        }

        Database::execute('DELETE FROM device_ips WHERE id = :id', ['id' => $id]);
        $this->log('device_ip.delete', 'device_ip', $id,
            '删除设备IP：' . $this->label($device));
        $this->back('device_ips.php', 'success', '设备「' . (string) $device['device_name'] . '」已删除。');
    }

    /**
     * 审核通过前台报备（待审核 → 在用）。
     */
    public function approve(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $device = DeviceIp::find($id);

        if ($device === null) {
            $this->back('device_ips.php', 'error', '设备记录不存在。');
        }

        DeviceIp::modify($id, ['status' => DeviceIp::STATUS_ACTIVE]);
        $this->log('device_ip.approve', 'device_ip', $id,
            '审核通过设备IP：' . $this->label($device));
        $this->back('device_ips.php', 'success', '已审核通过「' . (string) $device['device_name'] . '」。');
    }

    /**
     * 驳回前台报备：仅允许删除「待审核」状态的报备记录（带 status 条件的精确删除）。
     */
    public function reject(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $device = DeviceIp::find($id);

        if ($device === null) {
            $this->back('device_ips.php', 'error', '设备记录不存在。');
        }
        if ((int) $device['status'] !== DeviceIp::STATUS_PENDING) {
            $this->back('device_ips.php', 'error', '只有「待审核」的前台报备才能驳回。');
        }

        Database::execute(
            'DELETE FROM device_ips WHERE id = :id AND status = :st',
            ['id' => $id, 'st' => DeviceIp::STATUS_PENDING]
        );
        $this->log('device_ip.reject', 'device_ip', $id,
            '驳回前台报备：' . $this->label($device));
        $this->back('device_ips.php', 'success',
            '已驳回「' . (string) $device['device_name'] . '」的报备，该记录已删除。');
    }

    /**
     * 标记为已更换（在用 → 历史，IP 仍保留供反查）。
     */
    public function retire(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $device = DeviceIp::find($id);

        if ($device === null) {
            $this->back('device_ips.php', 'error', '设备记录不存在。');
        }

        DeviceIp::modify($id, ['status' => DeviceIp::STATUS_RETIRED]);
        $this->log('device_ip.retire', 'device_ip', $id,
            '标记设备已更换：' . $this->label($device));
        $this->back('device_ips.php', 'success',
            '「' . (string) $device['device_name'] . '」已标记为已更换，历史 IP 仍可查询。');
    }

    /**
     * 恢复为在用（历史 → 在用）。
     */
    public function restore(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $device = DeviceIp::find($id);

        if ($device === null) {
            $this->back('device_ips.php', 'error', '设备记录不存在。');
        }

        DeviceIp::modify($id, ['status' => DeviceIp::STATUS_ACTIVE]);
        $this->log('device_ip.restore', 'device_ip', $id,
            '恢复设备为在用：' . $this->label($device));
        $this->back('device_ips.php', 'success', '「' . (string) $device['device_name'] . '」已恢复为在用。');
    }

    // =========================================================
    // xlsx 批量导入（开发商更新清单后一次性补充）
    // =========================================================

    /**
     * 导入第一步：解析上传文件并给出预检结果（不写库）。
     */
    public function importPreview(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $file = $_FILES['device_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->back('device_ips.php', 'error', '请选择要导入的 xlsx 文件（上传失败或未选择文件）。');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->back('device_ips.php', 'error', '上传文件校验失败，请重新选择文件。');
        }

        $sizeMb = (int) ceil(((int) ($file['size'] ?? 0)) / 1048576);
        if ($sizeMb > 10) {
            $this->back('device_ips.php', 'error', 'xlsx 文件过大（超过 10MB），请精简后再导入。');
        }

        $origName = (string) ($file['name'] ?? 'device.xlsx');
        if (strtolower((string) pathinfo($origName, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->back('device_ips.php', 'error', '仅支持 .xlsx 格式；老式 .xls 请先用 Excel 另存为 .xlsx。');
        }

        try {
            $parsed = XlsxReader::read($tmpName);
        } catch (\Throwable $e) {
            $this->back('device_ips.php', 'error', '解析失败：' . $e->getMessage());
        }

        $rows = $this->normalizeRows($parsed['rows']);
        if ($rows === []) {
            $this->back('device_ips.php', 'error', '未从文件中解析出有效数据行，请确认表格包含「设备名称 / IP地址」列。');
        }

        $preview = DeviceIp::previewImport($rows);

        $token = bin2hex(random_bytes(8));
        $draft = [
            'token'      => $token,
            'file'       => $origName,
            'sheet'      => (string) $parsed['sheet'],
            'parsed'     => count($rows),
            'new'        => (int) $preview['new'],
            'dup'        => (int) $preview['dup'],
            'newRows'    => array_slice($preview['newRows'], 0, 50),
            'created_at' => now(),
            'rows'       => $rows,
        ];

        $this->writeImportDraft($token, $draft);

        $this->back('device_ips.php', 'success',
            '解析完成：共 ' . count($rows) . ' 行，其中可新增 ' . (int) $preview['new']
            . ' 条、已存在 ' . (int) $preview['dup'] . ' 条。请在页面下方确认导入。',
            ['import' => $token]);
    }

    /**
     * 导入第二步：确认写入（合并导入，不覆盖手工数据）。
     */
    public function importConfirm(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $token = (string) Request::post('token', '');
        $draft = $this->readImportDraft($token);

        if ($draft === null) {
            $this->back('device_ips.php', 'error', '导入草稿已失效（服务器临时文件被清理），请重新上传文件。');
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows   = is_array($draft['rows'] ?? null) ? $draft['rows'] : [];
        $result = DeviceIp::mergeImport($rows);

        $this->deleteImportDraft($token);

        $this->log('device_ip.import', 'device_ip', null,
            'xlsx 批量导入：' . (string) ($draft['file'] ?? '') . '（工作表：' . (string) ($draft['sheet'] ?? '')
            . '，新增 ' . (int) $result['inserted'] . ' 条，跳过已存在 ' . (int) $result['skipped'] . ' 条）');

        $this->back('device_ips.php', 'success',
            '导入完成：新增 ' . (int) $result['inserted'] . ' 条，跳过已存在 ' . (int) $result['skipped']
            . ' 条；后台手工维护的数据未被改动。');
    }

    /**
     * 取消导入：清理草稿。
     */
    public function importCancel(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $token = (string) Request::post('token', '');
        $this->deleteImportDraft($token);
        $this->back('device_ips.php', 'success', '已取消本次导入，未改动任何数据。');
    }

    // =========================================================
    // 内部方法
    // =========================================================

    /**
     * 归一化解析结果：识别表头列顺序、跳过空行、自动推断类型与工序。
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        // 默认列顺序（与《车间设备采集IP地址.xlsx》一致）：设备名称 / IP地址 / 备注
        $map   = ['device_name' => 0, 'ip_address' => 1, 'remark' => 2];
        $start = 0;

        if ($rows !== [] && $this->looksLikeHeader($rows[0])) {
            $start    = 1;
            $detected = [];
            foreach ($rows[0] as $index => $cell) {
                $cell = (string) $cell;
                foreach ([
                    'device_name' => ['设备名称', '设备', '名称'],
                    'ip_address'  => ['IP地址', 'IP', 'ip'],
                    'remark'      => ['备注', '说明', '日期'],
                ] as $field => $needles) {
                    if (isset($detected[$field])) {
                        continue;
                    }
                    foreach ($needles as $needle) {
                        if (mb_stripos($cell, $needle) !== false) {
                            $detected[$field] = $index;
                            break;
                        }
                    }
                }
            }
            // 至少识别出名称与 IP 两列才采用表头映射
            if (isset($detected['device_name']) && isset($detected['ip_address'])) {
                $map = array_merge($map, $detected);
            }
        }

        $result = [];
        for ($i = $start; $i < count($rows); $i++) {
            $row   = $rows[$i];
            $name  = mb_substr(trim((string) ($row[$map['device_name']] ?? '')), 0, 200, 'UTF-8');
            $ip    = mb_substr(trim((string) ($row[$map['ip_address']] ?? '')), 0, 64, 'UTF-8');
            $note  = mb_substr(trim((string) ($row[$map['remark']] ?? '')), 0, 500, 'UTF-8');

            // 名称与 IP 均为空的行视为空行跳过；只有其一的行保留（源表存在此类记录）
            if ($name === '' && $ip === '') {
                continue;
            }

            $result[] = [
                'device_name' => $name,
                'ip_address'  => $ip,
                'device_type' => DeviceIp::guessType($name !== '' ? $name : $ip),
                'process_id'  => DeviceIp::guessProcessId($name),
                'remark'      => $note,
            ];
        }

        return $result;
    }

    /**
     * 首行是否表头（含设备/IP/备注等字样）。
     *
     * @param array<int,string> $row
     */
    private function looksLikeHeader(array $row): bool
    {
        $joined = implode('|', array_map('strval', $row));
        foreach (['设备名称', 'IP地址', '设备', 'IP', '备注'] as $needle) {
            if (mb_stripos($joined, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 日志用简洁标识。
     *
     * @param array<string,mixed> $fields
     */
    private function label(array $fields): string
    {
        $name = (string) ($fields['device_name'] ?? '');
        $ip   = (string) ($fields['ip_address'] ?? '');
        return $name !== '' ? $name . ($ip !== '' ? '（' . $ip . '）' : '') : 'IP ' . $ip;
    }

    /**
     * 草稿目录（storage/tmp，Web 不可访问）。
     */
    private function draftDir(): string
    {
        $dir = STORAGE_PATH . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeImportDraft(string $token, array $data): void
    {
        $dir = $this->draftDir();

        foreach (glob($dir . '/device_import_*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::DRAFT_TTL) {
                @unlink($file);
            }
        }

        @file_put_contents($dir . '/device_import_' . $token . '.json',
            (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 读取导入草稿（不传 token 时取最近一份，用于刷新后仍显示预检结果）。
     *
     * @return array<string,mixed>|null
     */
    private function readImportDraft(string $token = ''): ?array
    {
        if ($token === '') {
            $token = (string) Request::get('import', '');
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }

        $file = $this->draftDir() . '/device_import_' . $token . '.json';
        if (!is_file($file)) {
            return null;
        }
        if (filemtime($file) < time() - self::DRAFT_TTL) {
            @unlink($file);
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function deleteImportDraft(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return;
        }
        @unlink($this->draftDir() . '/device_import_' . $token . '.json');
    }
}
