<?php
/**
 * 车间设备采集 IP 地址查询页。
 *
 * 数据来源：MES 开发商《车间设备采集IP地址.xlsx》，后台「设备IP管理」维护。
 * 用途：设备损坏更换后，按设备名称 / IP / 类型 / 工序反查历史内网地址。
 *
 * @var string $keyword
 * @var string $type
 * @var string $process                  关联工序名（标准工序或自定义工序，'' = 不限）
 * @var int    $status                   1=在用 0=已更换
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int} $result
 * @var array<string,int>                $typeCounts
 * @var array{active:int,retired:int,pending:int,total:int} $stats
 * @var array<int,array{ip_address:string,total:int,names:string}> $conflicts
 * @var array<int,array<string,mixed>>   $processes
 * @var array<int,string>                $customProcesses 已录入的自定义工序名
 * @var array<string,mixed>              $oldInput
 * @var array<string,string>             $errors
 * @var array<string,string>|null        $flashMsg
 */

use App\Models\DeviceIp;

$errors   = $errors ?? [];
$oldInput = $oldInput ?? [];
$val      = static function (string $key, string $default = '') use ($oldInput): string {
    return (string) ($oldInput[$key] ?? $default);
};

$isHistory = (int) $status === DeviceIp::STATUS_RETIRED;

/** 工序 id => 工序名（结果行展示用） */
$processNames = [];
foreach ($processes as $proc) {
    $processNames[(int) $proc['id']] = (string) $proc['name'];
}

$query = array_filter([
    'keyword' => $keyword,
    'type'    => $type,
    'process' => $process !== '' ? $process : null,
    'status'  => $isHistory ? 'history' : null,
], static function ($v): bool {
    return $v !== '' && $v !== null;
});

$qs = static function (array $overrides = []) use ($query): string {
    return base_url('device-ip?' . http_build_query(array_merge($query, $overrides)));
};

$activeFilters = ($keyword !== '' ? 1 : 0) + ($type !== '' ? 1 : 0) + ($process !== '' ? 1 : 0);
?>

<div class="page-head dip-head">
    <div class="dip-head-main">
        <h1>车间设备采集 IP 地址</h1>
        <p class="page-sub">
            在用 <strong><?= (int) $stats['active'] ?></strong> 台设备
            · 历史 <strong><?= (int) $stats['retired'] ?></strong> 台
            <span class="dip-sub-note">（设备损坏更换后，可按设备名称或 IP 反查该位置历史使用的内网地址）</span>
        </p>
        <p class="dip-data-source">本页设备 IP 地址数据由 MES 系统采集提供。</p>
    </div>
    <a class="dip-head-action" href="#report-form">报备新设备</a>
</div>

<?php if ($flashMsg !== null): ?>
    <div class="alert alert-<?= e((string) $flashMsg['type']) ?> front-alert">
        <?= e((string) $flashMsg['message']) ?>
    </div>
<?php endif; ?>

<?php if ($conflicts !== []): ?>
    <div class="dip-conflict-tip">
        <strong>IP 冲突提示：</strong>以下 IP 当前被多条在用记录占用，请联系设备管理员核实。
        <?php foreach ($conflicts as $conflict): ?>
            <div class="dip-conflict-item">
                <code><?= e((string) $conflict['ip_address']) ?></code>
                <span><?= e((string) $conflict['names']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="dip-search-card">
    <form class="dip-search" method="get" action="<?= e(base_url('device-ip')) ?>">
        <?php if ($type !== ''): ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
        <?php endif; ?>
        <?php if ($process !== ''): ?>
            <input type="hidden" name="process" value="<?= e($process) ?>">
        <?php endif; ?>
        <?php if ($isHistory): ?>
            <input type="hidden" name="status" value="history">
        <?php endif; ?>
        <input type="text" name="keyword" placeholder="输入设备名称 / IP 地址，如：绞线14线、192.168.1.11"
               value="<?= e($keyword) ?>" autofocus>
        <button type="submit">查询</button>
        <?php if ($activeFilters > 0 || $isHistory): ?>
            <a class="btn-line btn-line-sm" href="<?= e(base_url('device-ip')) ?>">清空筛选</a>
        <?php endif; ?>
    </form>

    <div class="dip-filter-bar">
        <a class="dip-chip<?= $type === '' ? ' active' : '' ?>" href="<?= e($qs(['type' => '', 'page' => null])) ?>">
            全部类型
        </a>
        <?php foreach (DeviceIp::TYPES as $t): ?>
            <?php if ((int) ($typeCounts[$t] ?? 0) > 0 || $type === $t): ?>
                <a class="dip-chip<?= $type === $t ? ' active' : '' ?>"
                   href="<?= e($qs(['type' => $type === $t ? '' : $t, 'page' => null])) ?>"
                   title="查看【<?= e($t) ?>】全部设备">
                    <?= e($t) ?><em><?= (int) ($typeCounts[$t] ?? 0) ?></em>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="dip-filter-bar dip-filter-bar-2">
        <select name="process" form="dipFilterForm" onchange="document.getElementById('dipFilterForm').submit()">
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
        <a class="dip-view-toggle<?= $isHistory ? ' active' : '' ?>"
           href="<?= e($qs(['status' => $isHistory ? '' : 'history', 'page' => null])) ?>">
            <?= $isHistory ? '当前在用设备' : '查看历史 IP（已更换）' ?>
        </a>
        <form id="dipFilterForm" method="get" action="<?= e(base_url('device-ip')) ?>" hidden>
            <?php if ($keyword !== ''): ?><input type="hidden" name="keyword" value="<?= e($keyword) ?>"><?php endif; ?>
            <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
            <?php if ($isHistory): ?><input type="hidden" name="status" value="history"><?php endif; ?>
        </form>
    </div>
</section>

<?php if ($result['items'] === []): ?>
    <div class="empty-state empty-large">
        <h2><?= $isHistory ? '暂无历史 IP 记录' : '未匹配到设备' ?></h2>
        <p>换个更短的关键词试试，例如「绞线」「成缆」「水槽」或 IP 的一段（如 1.11）。</p>
        <div class="empty-actions">
            <a class="btn-line" href="<?= e(base_url('device-ip')) ?>">清空条件重新查询</a>
        </div>
    </div>
<?php else: ?>
    <p class="dip-result-count">
        <?= $isHistory ? '历史 IP' : '在用设备' ?>共 <strong><?= (int) $result['total'] ?></strong> 条
        <?php if ($keyword !== ''): ?>· 关键字「<?= e($keyword) ?>」<?php endif; ?>
        <?php if ($type !== ''): ?>· 类型「<?= e($type) ?>」<?php endif; ?>
        <?php if ($process !== ''): ?>· 工序「<?= e($process) ?>」<?php endif; ?>
    </p>

    <div class="dip-table-wrap">
        <table class="dip-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>设备名称</th>
                    <th>IP 地址</th>
                    <th>类型</th>
                    <th>工序</th>
                    <th>备注</th>
                </tr>
            </thead>
            <tbody>
                <?php $seq = ($result['page'] - 1) * 20; ?>
                <?php foreach ($result['items'] as $item): ?>
                    <?php $seq++; ?>
                    <tr>
                        <td class="muted" data-label="#"><?= $seq ?></td>
                        <td data-label="设备">
                            <span class="dip-device-name"><?= highlight_keyword((string) $item['device_name'], $keyword) ?></span>
                            <?php if ((string) $item['device_name'] === ''): ?>
                                <span class="muted">未命名设备</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="IP">
                            <?php if ((string) $item['ip_address'] !== ''): ?>
                                <code class="dip-ip"><?= highlight_keyword((string) $item['ip_address'], $keyword) ?></code>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="类型">
                            <?php $rowType = (string) $item['device_type']; ?>
                            <?php if ($rowType !== ''): ?>
                                <a class="dip-type-tag" href="<?= e($qs(['type' => $type === $rowType ? '' : $rowType, 'page' => null])) ?>"><?= e($rowType) ?></a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="工序">
                            <?php $rowProcess = DeviceIp::processLabel($item, $processNames); ?>
                            <?php if ($rowProcess !== ''): ?>
                                <?= e($rowProcess) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted dip-remark" data-label="备注"><?= e((string) $item['remark']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="dip-pager">
        <?= App\Core\View::capture('partials/pagination', [
            'result'      => ['total' => $result['total'], 'page' => $result['page'], 'last_page' => $result['pages']],
            'basePath'    => base_url('device-ip'),
            'queryParams' => $query,
        ]) ?>
    </div>
<?php endif; ?>

<section class="block dip-report" id="report-form">
    <div class="block-head">
        <h2>报备新设备 IP</h2>
    </div>
    <p class="block-sub">
        新增采集设备、或设备更换后配置了新 IP，可在此报备；<strong>提交后需管理员审核</strong>，通过即在页面上方列表展示。
    </p>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-error front-alert"><?= e((string) $errors['general']) ?></div>
    <?php endif; ?>

    <form class="dip-form" method="post" action="<?= e(base_url('device-ip')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="dip-honeypot" aria-hidden="true">
            <input type="text" name="website" tabindex="-1" autocomplete="off" placeholder="请勿填写">
        </div>
        <div class="dip-form-grid">
            <label class="dip-field">
                <span>设备名称 <em>*</em></span>
                <input type="text" name="device_name" required maxlength="200" placeholder="如：护套9线-放线机PLC"
                       value="<?= e($val('device_name')) ?>" class="<?= isset($errors['device_name']) ? 'has-error' : '' ?>">
                <?php if (isset($errors['device_name'])): ?>
                    <span class="field-error"><?= e((string) $errors['device_name']) ?></span>
                <?php endif; ?>
            </label>
            <label class="dip-field">
                <span>IP 地址 <em>*</em></span>
                <input type="text" name="ip_address" required maxlength="64" placeholder="如：192.168.1.240"
                       value="<?= e($val('ip_address')) ?>" class="<?= isset($errors['ip_address']) ? 'has-error' : '' ?>">
                <?php if (isset($errors['ip_address'])): ?>
                    <span class="field-error"><?= e((string) $errors['ip_address']) ?></span>
                <?php endif; ?>
            </label>
            <label class="dip-field">
                <span>设备类型</span>
                <select name="device_type">
                    <?php foreach (DeviceIp::TYPES as $t): ?>
                        <option value="<?= e($t) ?>"<?= $val('device_type') === $t ? ' selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">留默认即可，类型会按名称自动判断</span>
            </label>
            <label class="dip-field">
                <span>关联工序</span>
                <select name="process_choice" id="dipProcessChoice">
                    <option value="0"<?= $val('process_choice', '0') === '0' ? ' selected' : '' ?>>未绑定 / 辅助设备</option>
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
                <input type="text" name="process_custom" id="dipProcessCustom" maxlength="100"
                       placeholder="输入新工序名称，如：辐照、包装、新产线名"
                       value="<?= e($val('process_custom')) ?>"
                       class="<?= isset($errors['process_custom']) ? 'has-error' : '' ?>" hidden>
                <?php if (isset($errors['process_custom'])): ?>
                    <span class="field-error"><?= e((string) $errors['process_custom']) ?></span>
                <?php endif; ?>
                <span class="field-hint">新采购设备可选「自定义新工序」，名称保存后自动出现在上方筛选名单</span>
            </label>
            <label class="dip-field dip-field-wide">
                <span>备注</span>
                <input type="text" name="remark" maxlength="500" placeholder="登记日期、安装位置、更换说明等"
                       value="<?= e($val('remark')) ?>">
            </label>
        </div>
        <div class="dip-form-actions">
            <button type="submit" class="btn btn-primary">提交报备</button>
            <span class="muted">同一网络每小时最多报备 5 条</span>
        </div>
    </form>
</section>

<script>
/* 关联工序：选中「自定义新工序」时才显示名称输入框 */
(function () {
    var sel = document.getElementById('dipProcessChoice');
    var box = document.getElementById('dipProcessCustom');
    if (sel === null || box === null) {
        return;
    }
    var toggle = function () {
        box.hidden = sel.value !== '<?= e(DeviceIp::CUSTOM_CHOICE) ?>';
    };
    sel.addEventListener('change', toggle);
    toggle();
})();
</script>
