<?php
/**
 * 反馈统计 / 台账（后台，登录可见）。
 *
 * 内容：
 *   1) 概览 KPI（总数 / 各状态 / 解决率 / 平均处理时长）
 *   2) 维度汇总计数表（状态 / 紧急度 / 工序 / 问题类型 / 部门，含数量与占比）
 *   3) 工序 × 状态 交叉矩阵（类 Excel 透视）
 *   4) 明细台账（类 Excel 密集表单，附件仅提供可点击「查看」链接，不内嵌图片）
 *
 * @var array<string,mixed>        $summary
 * @var array{rows:array,grand:array,statuses:array} $matrix
 * @var array{items:array,total:int,page:int,per_page:int,last_page:int} $ledger
 * @var array<int,array<int,array<string,mixed>>> $attachments
 * @var array<string,mixed>        $filters
 * @var array<string,string>       $statuses
 * @var array<string,string>       $urgencies
 * @var array<int,string>          $processes
 */

$summary     = $summary ?? [];
$matrix      = $matrix ?? ['rows' => [], 'grand' => [], 'statuses' => []];
$ledger      = $ledger ?? ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1];
$attachments = $attachments ?? [];
$filters     = $filters ?? [];
$statuses    = $statuses ?? feedback_status_map();
$urgencies   = $urgencies ?? feedback_urgency_map();
$processes   = $processes ?? [];
$total       = (int) ($summary['total'] ?? 0);

$pct = static function (int $n) use ($total): string {
    return $total > 0 ? round($n / $total * 100, 1) . '%' : '0%';
};

$months      = $months ?? [];
$thisMonth   = $thisMonth ?? date('Y-m');
$lastMonth   = $lastMonth ?? date('Y-m', strtotime('first day of last month'));
$month       = (string) ($filters['month'] ?? '');
$closure     = (string) ($filters['closure'] ?? '');
$closedCount = (int) ($summary['closed'] ?? 0);
$openCount   = (int) ($summary['open'] ?? max(0, $total - $closedCount));
$periodText  = $month !== '' ? $month . ' 月度' : '全部时间';

// 平均处理时长人性化：分钟 / 小时 / 天
$avgHours = $summary['avg_handle_hours'] ?? null;
if ($avgHours === null) {
    $avgText = '—';
} else {
    $avgMinutes = (float) $avgHours * 60;
    if ($avgMinutes < 60) {
        $avgText = max(1, (int) round($avgMinutes)) . ' 分钟';
    } elseif ($avgMinutes < 1440) {
        $avgText = round($avgMinutes / 60, 1) . ' 小时';
    } else {
        $avgText = round($avgMinutes / 1440, 1) . ' 天';
    }
}

$statusRows = [];
foreach ($statuses as $key => $label) {
    $statusRows[] = ['name' => (string) $label, 'total' => (int) ($summary['status'][$key] ?? 0)];
}
$urgencyRows = [];
foreach ($urgencies as $key => $label) {
    $urgencyRows[] = ['name' => (string) $label, 'total' => (int) ($summary['urgency'][$key] ?? 0)];
}

$renderDim = static function (string $title, array $rowsData, $pct): string {
    if ($rowsData === []) {
        return '<div class="stat-box"><h3 class="stat-box-title">' . e($title) . '</h3>'
            . '<p class="hint">暂无数据。</p></div>';
    }
    $html = '<div class="stat-box"><h3 class="stat-box-title">' . e($title) . '</h3>'
        . '<table class="stat-table"><thead><tr>'
        . '<th>维度</th><th class="ta-r">数量</th><th class="ta-r">占比</th></tr></thead><tbody>';
    foreach ($rowsData as $row) {
        $html .= '<tr><td>' . e((string) $row['name']) . '</td>'
            . '<td class="ta-r">' . (int) $row['total'] . '</td>'
            . '<td class="ta-r">' . $pct((int) $row['total']) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
};

$query = array_filter([
    'keyword'    => (string) ($filters['keyword'] ?? ''),
    'status'     => (string) ($filters['status'] ?? ''),
    'urgency'    => (string) ($filters['urgency'] ?? ''),
    'process_id' => (int) ($filters['process_id'] ?? 0) ?: '',
    'month'      => (string) ($filters['month'] ?? ''),
    'closure'    => (string) ($filters['closure'] ?? ''),
], static function ($value): bool {
    return $value !== '' && $value !== null;
});
$exportHref = admin_url('feedback_stats.php?export=csv' . (empty($query) ? '' : '&' . http_build_query($query)));
$matrixRows    = $matrix['rows'] ?? [];
$grand         = $matrix['grand'] ?? [];
$matrixStatuses = $matrix['statuses'] ?? [];
$seqStart = (max(1, (int) $ledger['page']) - 1) * (int) $ledger['per_page'];
?>
<div class="fbst">
<div class="card">
    <div class="card-head">
        <h2 class="card-title">反馈概览
            <em class="muted"><?= e($periodText) ?> · 共 <?= $total ?> 条</em>
        </h2>
        <span class="muted small">
            统计范围：<?= e($month !== ''
                ? $month . '-01 ~ ' . $month . '-' . date('t', strtotime($month . '-01')) . '（按提交时间）'
                : '全部历史记录（按提交时间）') ?>
        </span>
    </div>
    <div class="kpi-row">
        <div class="kpi"><span class="kpi-num"><?= $total ?></span><span class="kpi-label">反馈总数</span></div>
        <div class="kpi" style="border-top-color:#0F6E56"><span class="kpi-num"><?= $closedCount ?></span><span class="kpi-label">已闭环（已解决 + 已关闭）</span></div>
        <div class="kpi" style="border-top-color:#A32D2D"><span class="kpi-num"><?= $openCount ?></span><span class="kpi-label">未闭环（待受理 + 处理中）</span></div>
        <div class="kpi" style="border-top-color:#0F6E56"><span class="kpi-num"><?= e($pct($closedCount)) ?></span><span class="kpi-label">闭环率</span></div>
        <div class="kpi"><span class="kpi-num"><?= (int) ($summary['status']['pending'] ?? 0) ?></span><span class="kpi-label">待受理</span></div>
        <div class="kpi"><span class="kpi-num"><?= (int) ($summary['status']['processing'] ?? 0) ?></span><span class="kpi-label">处理中</span></div>
        <div class="kpi"><span class="kpi-num"><?= (int) ($summary['status']['closed'] ?? 0) ?></span><span class="kpi-label">已关闭</span></div>
        <div class="kpi"><span class="kpi-num"><?= e($avgText) ?></span><span class="kpi-label">平均处理时长</span></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">维度汇总计数表</h2>
        <span class="muted small">按各维度统计反馈条数与占比</span>
    </div>
    <div class="stat-grid">
        <?= $renderDim('状态分布', $statusRows, $pct) ?>
        <?= $renderDim('紧急度分布', $urgencyRows, $pct) ?>
        <?= $renderDim('工序分布（Top）', $summary['process'] ?? [], $pct) ?>
        <?= $renderDim('问题类型分布（Top）', $summary['category'] ?? [], $pct) ?>
        <?= $renderDim('部门分布（Top）', $summary['department'] ?? [], $pct) ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">工序 × 状态 交叉统计</h2>
        <span class="muted small">行：工序　列：状态　末行：合计</span>
    </div>
    <?php if ($matrixRows === []): ?>
        <p class="hint">暂无反馈数据。</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="ledger ledger-matrix">
            <thead>
                <tr>
                    <th>工序</th>
                    <?php foreach ($matrixStatuses as $sk): ?>
                        <th class="ta-r"><?= e(feedback_status_label((string) $sk)) ?></th>
                    <?php endforeach; ?>
                    <th class="ta-r">合计</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($matrixRows as $pname => $row): ?>
                <tr>
                    <td><?= e((string) $pname) ?></td>
                    <?php foreach ($matrixStatuses as $sk): ?>
                        <td class="ta-r"><?= (int) ($row[$sk] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="ta-r"><strong><?= (int) ($row['total'] ?? 0) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="ledger-total">
                    <td>合计</td>
                    <?php foreach ($matrixStatuses as $sk): ?>
                        <td class="ta-r"><?= (int) ($grand[$sk] ?? 0) ?></td>
                    <?php endforeach; ?>
                    <td class="ta-r"><strong><?= (int) ($grand['total'] ?? 0) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">反馈明细台账 <em class="muted">共 <?= (int) $ledger['total'] ?> 条</em></h2>
        <div class="card-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e($exportHref) ?>">导出 CSV</a>
        </div>
    </div>

    <form class="filter-bar" method="get" action="<?= e(admin_url('feedback_stats.php')) ?>">
        <select name="month">
            <option value="">全部月份</option>
            <?php foreach ($months as $m): ?>
                <option value="<?= e((string) $m['ym']) ?>"<?= $month === (string) $m['ym'] ? ' selected' : '' ?>>
                    <?= e((string) $m['ym']) ?>（<?= (int) $m['total'] ?> 条）
                </option>
            <?php endforeach; ?>
        </select>
        <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedback_stats.php?month=' . $lastMonth)) ?>">上个月</a>
        <select name="closure">
            <option value="">全部闭环情况</option>
            <option value="open"<?= $closure === 'open' ? ' selected' : '' ?>>仅未闭环</option>
            <option value="closed"<?= $closure === 'closed' ? ' selected' : '' ?>>仅已闭环</option>
        </select>
        <input type="text" name="keyword" placeholder="搜索单号 / 标题 / 提交人 / 部门 / 设备"
               value="<?= e((string) ($filters['keyword'] ?? '')) ?>">
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= e((string) $key) ?>"<?= (string) ($filters['status'] ?? '') === (string) $key ? ' selected' : '' ?>>
                    <?= e((string) $label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="urgency">
            <option value="">全部紧急程度</option>
            <?php foreach ($urgencies as $key => $label): ?>
                <option value="<?= e((string) $key) ?>"<?= (string) ($filters['urgency'] ?? '') === (string) $key ? ' selected' : '' ?>>
                    <?= e((string) $label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="process_id">
            <option value="">全部工序</option>
            <?php foreach ($processes as $pid => $pname): ?>
                <option value="<?= (int) $pid ?>"<?= (int) ($filters['process_id'] ?? 0) === (int) $pid ? ' selected' : '' ?>>
                    <?= e((string) $pname) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedback_stats.php')) ?>">重置</a>
    </form>

    <?php if ($ledger['items'] === []): ?>
        <div class="empty-state"><p>没有符合条件的反馈记录。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="width:48px">序号</th>
                    <th style="width:110px">单号</th>
                    <th style="width:84px">提交人</th>
                    <th style="width:96px">归属部门</th>
                    <th style="width:80px">工号</th>
                    <th style="width:110px">联系方式</th>
                    <th style="width:84px">发生工序</th>
                    <th style="width:96px">问题类型</th>
                    <th style="width:120px">涉及设备/模块</th>
                    <th>问题标题</th>
                    <th style="width:72px">紧急度</th>
                    <th style="width:84px">状态</th>
                    <th style="width:72px">闭环</th>
                    <th style="width:130px">提交时间</th>
                    <th style="width:84px">处理人</th>
                    <th style="width:130px">处理时间</th>
                    <th style="width:96px">附件</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ledger['items'] as $i => $item): ?>
                <?php
                $fbId    = (int) $item['id'];
                $status  = (string) $item['status'];
                $urgency = (string) $item['urgency'];
                $files   = $attachments[$fbId] ?? [];
                ?>
                <tr>
                    <td class="ta-r ta-strong"><?= $seqStart + $i + 1 ?></td>
                    <td class="fb-code"><?= e((string) $item['code']) ?></td>
                    <td>
                        <a class="link-strong" href="<?= e(admin_url('feedback_detail.php?id=' . $fbId)) ?>">
                            <?= e(excerpt((string) $item['employee_name'], 12)) ?>
                        </a>
                    </td>
                    <td><?= e(excerpt((string) $item['department'], 14)) ?></td>
                    <td class="ta-strong"><?= e((string) $item['employee_no']) ?></td>
                    <td class="small"><?= e(excerpt((string) $item['contact'], 16)) ?></td>
                    <td>
                        <?php if ((string) ($item['process_name'] ?? '') !== ''): ?>
                            <span class="tag tag-process"><?= e((string) $item['process_name']) ?></span>
                        <?php else: ?>
                            <span class="muted small">未指定</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((string) ($item['category_name'] ?? '') !== ''): ?>
                            <?= e(excerpt((string) $item['category_name'], 12)) ?>
                        <?php else: ?>
                            <span class="muted small">未指定</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e(excerpt((string) $item['device'], 18)) ?></td>
                    <td>
                        <a class="link-strong" href="<?= e(admin_url('feedback_detail.php?id=' . $fbId)) ?>">
                            <?= e(excerpt((string) $item['title'], 40)) ?>
                        </a>
                    </td>
                    <td><span class="fb-urgency fb-urgency-<?= e($urgency) ?>"><?= e(feedback_urgency_label($urgency)) ?></span></td>
                    <td><span class="fb-status fb-status-<?= e($status) ?>"><?= e(feedback_status_label($status)) ?></span></td>
                    <td>
                        <?php if (in_array($status, ['resolved', 'closed'], true)): ?>
                            <span class="fb-closure fb-closure-closed">已闭环</span>
                        <?php else: ?>
                            <span class="fb-closure fb-closure-open">未闭环</span>
                        <?php endif; ?>
                    </td>
                    <td class="small muted"><?= e((string) $item['created_at']) ?></td>
                    <td><?= e(excerpt((string) ($item['handler_name'] ?? ''), 8)) ?></td>
                    <td class="small muted"><?= e((string) ($item['handled_at'] ?? '')) ?></td>
                    <td class="ledger-files">
                        <?php if ($files === []): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?php foreach (array_slice($files, 0, 3) as $f): ?>
                                <a class="ledger-file" target="_blank" rel="noopener"
                                   href="<?= e(admin_url('feedback_file.php?id=' . (int) $f['id'] . '&mode=preview')) ?>">查看</a>
                            <?php endforeach; ?>
                            <?php if (count($files) > 3): ?>
                                <span class="muted">+<?= count($files) - 3 ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $ledger,
        'basePath'    => admin_url('feedback_stats.php'),
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>
</div>
