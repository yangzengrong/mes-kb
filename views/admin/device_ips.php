<?php
/**
 * 设备IP管理（后台）：车间设备采集 IP 地址的录入 / 编辑 / 审核 / 导入。
 *
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<int,array<string,mixed>>   $processes
 * @var array<int,string>                $customProcesses 已录入的自定义工序名
 * @var string $keyword
 * @var string $type
 * @var int    $status   -1=全部
 * @var string $process 关联工序名（标准或自定义，'' = 不限）
 * @var array{active:int,retired:int,pending:int,total:int} $stats
 * @var array<int,array<string,mixed>>   $pending  待审核的前台报备（页面顶部待处理面板）
 * @var array<int,array{ip_address:string,total:int,names:string}> $conflicts
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var bool $isSuper
 * @var array<string,mixed>|null $import 导入预检草稿
 */

use App\Models\DeviceIp;

$val = static function (string $field, string $default = '') use ($old) {
    return array_key_exists($field, $old) ? (string) $old[$field] : $default;
};

$statusLabels = DeviceIp::STATUS_LABELS;

/** 工序 id => 工序名（列表展示用） */
$processNames = [];
foreach ($processes as $proc) {
    $processNames[(int) $proc['id']] = (string) $proc['name'];
}

$query = array_filter([
    'keyword' => $keyword,
    'type'    => $type,
    'status'  => $status >= 0 ? $status : null,
    'process' => $process !== '' ? $process : null,
], static function ($v): bool {
    return $v !== '' && $v !== null;
});

/** 状态徽标 class（复用后台既有样式） */
$statusClass = static function (int $status): string {
    if ($status === DeviceIp::STATUS_ACTIVE) {
        return 'fb-status fb-status-resolved';
    }
    if ($status === DeviceIp::STATUS_RETIRED) {
        return 'fb-status fb-status-closed';
    }
    return 'fb-status fb-status-pending';
};
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $message): ?><div><?= e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    这里维护前台「设备IP」查询页的数据源（来源：MES 开发商《车间设备采集IP地址.xlsx》）。
    <strong>设备损坏更换后，把旧记录标记为「已更换」即可保留历史 IP 供反查</strong>，再为设备录入新 IP。
</div>

<?php if ($pending !== []): ?>
<div class="card card-pending">
    <div class="card-head">
        <h2 class="card-title">
            待审核的前台报备
            <em class="fb-status fb-status-pending"><?= count($pending) ?> 条待处理</em>
        </h2>
        <span class="hint" style="margin:0">
            确认信息无误后，直接在此「审核通过」即在前台展示；需修正信息可点「编辑」跳到下方表单，状态选「在用」等同于通过。
        </span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>设备名称</th>
                    <th style="width:140px">IP 地址</th>
                    <th style="width:86px">类型</th>
                    <th style="width:120px">关联工序</th>
                    <th style="width:190px">备注</th>
                    <th style="width:150px">报备时间</th>
                    <?php if ($isSuper): ?><th style="width:210px">操作</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pending as $pRow): ?>
                <?php $pId = (int) $pRow['id']; ?>
                <?php $pProcess = DeviceIp::processLabel($pRow, $processNames); ?>
                <tr>
                    <td><strong><?= e((string) $pRow['device_name']) ?></strong></td>
                    <td><code><?= e((string) $pRow['ip_address']) ?></code></td>
                    <td class="muted"><?= e((string) $pRow['device_type']) ?></td>
                    <td class="muted"><?= $pProcess !== '' ? e($pProcess) : '—' ?></td>
                    <td class="muted"><?= e((string) $pRow['remark']) ?></td>
                    <td class="muted">
                        <?= e((string) $pRow['created_at']) ?>
                        <br><span class="hint" style="margin:0">报备 IP <?= e((string) ($pRow['submit_ip'] ?? '')) ?></span>
                    </td>
                    <?php if ($isSuper): ?>
                    <td>
                        <div class="row-actions">
                            <form method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="approve">
                                <input type="hidden" name="id" value="<?= $pId ?>">
                                <button type="submit" class="btn btn-primary btn-xs">审核通过</button>
                            </form>
                            <button type="button" class="btn btn-ghost btn-xs device-edit"
                                    data-id="<?= $pId ?>"
                                    data-name="<?= e((string) $pRow['device_name']) ?>"
                                    data-ip="<?= e((string) $pRow['ip_address']) ?>"
                                    data-type="<?= e((string) $pRow['device_type']) ?>"
                                    data-process="<?= e($pProcess !== '' ? $pProcess : '0') ?>"
                                    data-status="<?= (int) $pRow['status'] ?>"
                                    data-remark="<?= e((string) $pRow['remark']) ?>">编辑</button>
                            <form method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" class="inline-form"
                                  data-confirm="确认驳回「<?= e((string) $pRow['device_name']) ?>」的报备？驳回后该报备记录将被删除。">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="reject">
                                <input type="hidden" name="id" value="<?= $pId ?>">
                                <button type="submit" class="btn btn-danger btn-xs">驳回</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($stats['pending'] > count($pending)): ?>
        <p class="hint" style="margin:10px 0 0">
            共 <?= (int) $stats['pending'] ?> 条待审核，此处显示最近 <?= count($pending) ?> 条，其余请在下方列表按状态「待审核」筛选查看。
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($conflicts !== []): ?>
    <div class="alert alert-warn">
        <strong>IP 冲突：</strong>以下 IP 被多条在用记录占用，请核实修正：
        <?php foreach ($conflicts as $conflict): ?>
            <div><code><?= e((string) $conflict['ip_address']) ?></code>
                <span class="muted"><?= e((string) $conflict['names']) ?></span></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (($missingIp ?? []) !== []): ?>
    <div class="alert alert-warn">
        <strong>未配置 IP：</strong><?= count($missingIp) ?> 台在用设备缺少 IP 地址，列表中显示为「未配置」。
        这些设备不参与 IP 冲突检测，也无法按地址检索，请补填：
        <div><?php foreach ($missingIp as $mi): ?><span class="muted">#<?= (int) $mi['id'] ?></span>
            <?= e((string) $mi['device_name']) ?><span class="muted">（<?= e((string) $mi['device_type']) ?>）</span>　<?php endforeach; ?></div>
    </div>
<?php endif; ?>

<?php if ($isSuper): ?>
<div class="card" id="deviceFormCard">
    <h2 class="card-title" id="deviceFormTitle">新增设备 IP</h2>
    <form class="filter-bar" method="post" action="<?= e(admin_url('device_ip_action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="save">
        <input type="hidden" name="id" id="deviceId" value="<?= e($val('id', '')) ?>">
        <input type="text" name="device_name" id="deviceName" required maxlength="200"
               placeholder="设备名称 *，如：绞线14线" value="<?= e($val('device_name', '')) ?>"
               class="<?= isset($errors['device_name']) ? 'has-error' : '' ?>" style="max-width:240px">
        <input type="text" name="ip_address" id="deviceIp" maxlength="64"
               placeholder="IP 地址，如：192.168.1.11（可留空）" value="<?= e($val('ip_address', '')) ?>"
               class="<?= isset($errors['ip_address']) ? 'has-error' : '' ?>" style="max-width:200px">
        <select name="device_type" id="deviceType" style="max-width:110px">
            <?php foreach (DeviceIp::TYPES as $t): ?>
                <option value="<?= e($t) ?>"<?= $val('device_type', '其他') === $t ? ' selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="process_choice" id="deviceProcess" style="max-width:130px">
            <option value="0"<?= $val('process_choice', '0') === '0' ? ' selected' : '' ?>>未绑定工序</option>
            <?php foreach ($processes as $proc): ?>
                <option value="<?= e((string) $proc['name']) ?>"<?= $val('process_choice') === (string) $proc['name'] ? ' selected' : '' ?>>
                    <?= e((string) $proc['name']) ?>
                </option>
            <?php endforeach; ?>
            <?php foreach ($customProcesses as $customProcess): ?>
                <option value="<?= e($customProcess) ?>"<?= $val('process_choice') === $customProcess ? ' selected' : '' ?>>
                    <?= e($customProcess) ?>
                </option>
            <?php endforeach; ?>
            <option value="<?= e(DeviceIp::CUSTOM_CHOICE) ?>"<?= $val('process_choice') === DeviceIp::CUSTOM_CHOICE ? ' selected' : '' ?>>
                ＋ 自定义新工序…
            </option>
        </select>
        <input type="text" name="process_custom" id="deviceProcessCustom" maxlength="100"
               placeholder="自定义工序名称" value="<?= e($val('process_custom')) ?>"
               class="<?= isset($errors['process_custom']) ? 'has-error' : '' ?>" style="max-width:150px" hidden>
        <select name="status" id="deviceStatus" style="max-width:110px">
            <?php foreach ($statusLabels as $sKey => $sLabel): ?>
                <option value="<?= (int) $sKey ?>"<?= (int) $val('status', '1') === (int) $sKey ? ' selected' : '' ?>>
                    <?= e((string) $sLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="remark" id="deviceRemark" maxlength="500" placeholder="备注（登记日期 / 安装位置 / 更换说明）"
               value="<?= e($val('remark', '')) ?>" style="max-width:260px">
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
        <button type="button" class="btn btn-ghost btn-sm"
                onclick="window.location.href='<?= e(admin_url('device_ips.php')) ?>'">重置</button>
    </form>
    <p class="muted" style="margin:8px 0 0">
        「在用」= 当前生效的采集地址；「已更换」= 设备已换，IP 转为历史存档（前台仍可查）；「待审核」= 前台报备待确认。
    </p>
</div>

<div class="card">
    <h2 class="card-title">批量导入（xlsx 合并导入）</h2>
    <p class="hint">
        开发商更新《车间设备采集IP地址.xlsx》后，可在此一次性补充。系统按「设备名称 + IP + 备注」判重，
        <strong>只补入缺失行，不会覆盖你手工录入或编辑过的数据</strong>。
    </p>
    <p class="hint">
        表格格式：第一行为表头，列依次为「设备名称 / IP地址 / 备注」；工作表名不限（默认读取第一个工作表）。
        仅支持 .xlsx（老式 .xls 请先另存为 .xlsx）。
    </p>
    <form class="filter-bar" method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="import_preview">
        <input type="file" name="device_file" accept=".xlsx" required>
        <button type="submit" class="btn btn-primary btn-sm">上传并预检</button>
    </form>
</div>

<?php if (is_array($import ?? null) && $import !== []): ?>
    <?php
    $draftNew   = is_array($import['newRows'] ?? null) ? $import['newRows'] : [];
    $draftToken = (string) ($import['token'] ?? '');
    ?>
    <div class="card">
        <h2 class="card-title">导入预检结果</h2>
        <p class="hint">
            文件：<strong><?= e((string) ($import['file'] ?? '')) ?></strong>
            · 工作表：<strong><?= e((string) ($import['sheet'] ?? '')) ?></strong>
            · 解析有效行：<strong><?= (int) ($import['parsed'] ?? 0) ?></strong>
        </p>
        <div class="alert alert-info">
            可新增 <strong><?= (int) ($import['new'] ?? 0) ?></strong> 条；
            已存在（判重跳过）<strong><?= (int) ($import['dup'] ?? 0) ?></strong> 条。
            <?php if ((int) ($import['new'] ?? 0) === 0): ?>
                没有需要新增的记录，无需导入。
            <?php endif; ?>
        </div>

        <?php if ($draftNew !== []): ?>
            <p class="hint">以下为将新增的前 <?= count($draftNew) ?> 条（最多展示 50 条）：</p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:46px">#</th>
                            <th>设备名称</th>
                            <th style="width:150px">IP 地址</th>
                            <th style="width:100px">类型</th>
                            <th style="width:220px">备注</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($draftNew as $index => $row): ?>
                        <tr>
                            <td class="muted"><?= $index + 1 ?></td>
                            <td><strong><?= e((string) ($row['device_name'] ?? '')) ?></strong></td>
                            <td class="muted">
                                <?php if (trim((string) ($row['ip_address'] ?? '')) !== ''): ?>
                                    <?= e((string) $row['ip_address']) ?>
                                <?php else: ?>
                                    <span class="ip-missing">未配置</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= e((string) ($row['device_type'] ?? '')) ?></td>
                            <td class="muted"><?= e((string) ($row['remark'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <form class="filter-bar" method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($draftToken) ?>">
            <button type="submit" name="do" value="import_confirm" class="btn btn-primary btn-sm"
                    data-confirm="确认导入？只会新增缺失的记录，已有记录不会被修改。">确认导入</button>
            <button type="submit" name="do" value="import_cancel" class="btn btn-ghost btn-sm">取消</button>
        </form>
    </div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">设备列表 <em class="muted">共 <?= (int) $result['total'] ?> 条</em></h2>
        <form class="filter-bar" method="get" action="<?= e(admin_url('device_ips.php')) ?>">
            <select name="status" style="max-width:120px">
                <option value="-1"<?= $status === -1 ? ' selected' : '' ?>>全部状态</option>
                <?php foreach ($statusLabels as $sKey => $sLabel): ?>
                    <option value="<?= (int) $sKey ?>"<?= $status === (int) $sKey ? ' selected' : '' ?>>
                        <?= e((string) $sLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="type" style="max-width:120px">
                <option value="">全部类型</option>
                <?php foreach (DeviceIp::TYPES as $t): ?>
                    <option value="<?= e($t) ?>"<?= $type === $t ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="process" style="max-width:130px">
                <option value="">全部工序</option>
                <?php foreach ($processes as $proc): ?>
                    <option value="<?= e((string) $proc['name']) ?>"<?= $process === (string) $proc['name'] ? ' selected' : '' ?>>
                        <?= e((string) $proc['name']) ?>
                    </option>
                <?php endforeach; ?>
                <?php foreach ($customProcesses as $customProcess): ?>
                    <option value="<?= e($customProcess) ?>"<?= $process === $customProcess ? ' selected' : '' ?>>
                        <?= e($customProcess) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="keyword" placeholder="设备名 / IP / 备注" value="<?= e($keyword) ?>" style="max-width:200px">
            <button type="submit" class="btn btn-ghost btn-sm">筛选</button>
            <?php if ($query !== []): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('device_ips.php')) ?>">清空</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state"><p>没有匹配的设备记录。可在上方表单录入，或通过 xlsx 批量导入开发商清单。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:50px">ID</th>
                    <th>设备名称</th>
                    <th style="width:150px">IP 地址</th>
                    <th style="width:90px">类型</th>
                    <th style="width:110px">工序</th>
                    <th style="width:90px">状态</th>
                    <th style="width:200px">备注</th>
                    <?php if ($isSuper): ?><th style="width:230px">操作</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['items'] as $item): ?>
                <?php $did = (int) $item['id']; ?>
                <?php $dStatus = (int) $item['status']; ?>
                <tr>
                    <td class="muted"><?= $did ?></td>
                    <td>
                        <strong><?= e((string) $item['device_name']) ?></strong>
                        <?php if ((string) $item['source'] === 'submit'): ?>
                            <span class="badge badge-role" title="来自前台报备">报备</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted">
                        <?php if (trim((string) $item['ip_address']) !== ''): ?>
                            <?= e((string) $item['ip_address']) ?>
                        <?php else: ?>
                            <span class="ip-missing">未配置</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string) $item['device_type']) ?></td>
                    <td class="muted">
                        <?php
                        $rowProcess = DeviceIp::processLabel($item, $processNames);
                        $isCustom   = (int) $item['process_id'] === 0 && trim((string) ($item['custom_process'] ?? '')) !== '';
                        ?>
                        <?php if ($rowProcess !== ''): ?>
                            <?= e($rowProcess) ?><?php if ($isCustom): ?><span class="badge badge-role" title="自定义关联工序">自定义</span><?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><em class="<?= $statusClass($dStatus) ?>"><?= e((string) ($statusLabels[$dStatus] ?? '未知')) ?></em></td>
                    <td class="muted"><?= e((string) $item['remark']) ?></td>
                    <?php if ($isSuper): ?>
                    <td>
                        <div class="row-actions">
                            <button type="button" class="btn btn-ghost btn-xs device-edit"
                                    data-id="<?= $did ?>"
                                    data-name="<?= e((string) $item['device_name']) ?>"
                                    data-ip="<?= e((string) $item['ip_address']) ?>"
                                    data-type="<?= e((string) $item['device_type']) ?>"
                                    data-process="<?= e($rowProcess !== '' ? $rowProcess : '0') ?>"
                                    data-status="<?= $dStatus ?>"
                                    data-remark="<?= e((string) $item['remark']) ?>">编辑</button>
                            <?php if ($dStatus === DeviceIp::STATUS_PENDING): ?>
                                <form method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="approve">
                                    <input type="hidden" name="id" value="<?= $did ?>">
                                    <button type="submit" class="btn btn-primary btn-xs">审核通过</button>
                                </form>
                            <?php elseif ($dStatus === DeviceIp::STATUS_ACTIVE): ?>
                                <form method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" class="inline-form"
                                      data-confirm="确认把「<?= e((string) $item['device_name']) ?>」标记为已更换？历史 IP 仍会在前台保留可查。">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="retire">
                                    <input type="hidden" name="id" value="<?= $did ?>">
                                    <button type="submit" class="btn btn-ghost btn-xs">标记更换</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="restore">
                                    <input type="hidden" name="id" value="<?= $did ?>">
                                    <button type="submit" class="btn btn-ghost btn-xs">恢复在用</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(admin_url('device_ip_action.php')) ?>" class="inline-form"
                                  data-confirm="确认删除设备「<?= e((string) $item['device_name']) ?>」？删除后不可恢复。">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="delete">
                                <input type="hidden" name="id" value="<?= $did ?>">
                                <button type="submit" class="btn btn-danger btn-xs">删除</button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => admin_url('device_ips.php'),
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('.device-edit');
    if (button === null) {
        return;
    }
    document.getElementById('deviceFormTitle').textContent = '编辑设备 #' + button.dataset.id;
    document.getElementById('deviceId').value = button.dataset.id || '';
    document.getElementById('deviceName').value = button.dataset.name || '';
    document.getElementById('deviceIp').value = button.dataset.ip || '';
    document.getElementById('deviceType').value = button.dataset.type || '其他';
    setProcessValue(button.dataset.process || '0');
    document.getElementById('deviceStatus').value = button.dataset.status || '1';
    document.getElementById('deviceRemark').value = button.dataset.remark || '';
    // 待审核面板点「编辑」时，表单在下方，滚动过去并高亮，避免找不到
    var formCard = document.getElementById('deviceFormCard');
    if (formCard !== null) {
        formCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        formCard.classList.add('card-flash');
        window.setTimeout(function () { formCard.classList.remove('card-flash'); }, 1600);
    }
    document.getElementById('deviceName').focus();
});

/* 关联工序：回填下拉值；值为自定义工序名时才显示名称输入框 */
function setProcessValue(value) {
    var sel = document.getElementById('deviceProcess');
    var box = document.getElementById('deviceProcessCustom');
    var exists = false;
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === value) {
            exists = true;
            break;
        }
    }
    if (!exists && value !== '' && value !== '0') {
        var opt = document.createElement('option');
        opt.value = value;
        opt.textContent = value;
        sel.appendChild(opt);
    }
    sel.value = value;
    toggleProcessCustom();
}

function toggleProcessCustom() {
    var sel = document.getElementById('deviceProcess');
    var box = document.getElementById('deviceProcessCustom');
    if (sel === null || box === null) {
        return;
    }
    box.hidden = sel.value !== '<?= e(DeviceIp::CUSTOM_CHOICE) ?>';
}

document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('deviceProcess');
    if (sel !== null) {
        sel.addEventListener('change', toggleProcessCustom);
        toggleProcessCustom();
    }
});
</script>
