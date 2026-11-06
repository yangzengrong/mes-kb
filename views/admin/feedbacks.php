<?php
/**
 * 员工反馈列表（后台）。
 *
 * @var array<string,mixed> $filters
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<string,int>   $counts
 * @var array<string,string> $statuses
 * @var array<string,string> $urgencies
 * @var array<int,string>   $processes
 */
$isTrash = !empty($filters['trashed']);
$counts  = $counts ?? [];
$months  = $months ?? [];
$query   = array_filter([
    'keyword'    => (string) ($filters['keyword'] ?? ''),
    'status'     => (string) ($filters['status'] ?? ''),
    'urgency'    => (string) ($filters['urgency'] ?? ''),
    'process_id' => (int) ($filters['process_id'] ?? 0) ?: '',
    'month'      => (string) ($filters['month'] ?? ''),
    'trashed'    => $isTrash ? 1 : '',
], static function ($value): bool {
    return $value !== '' && $value !== null;
});
?>
<div class="card">
    <div class="fb-counts">
        <?php foreach (($statuses ?? feedback_status_map()) as $key => $label): ?>
            <a class="fb-count-chip<?= (string) ($filters['status'] ?? '') === (string) $key ? ' active' : '' ?>"
               href="<?= e(admin_url('feedbacks.php?status=' . urlencode((string) $key))) ?>">
                <?= e((string) $label) ?><em><?= (int) ($counts[$key] ?? 0) ?></em>
            </a>
        <?php endforeach; ?>
        <a class="fb-count-chip<?= (string) ($filters['status'] ?? '') === '' ? ' active' : '' ?>"
           href="<?= e(admin_url('feedbacks.php')) ?>">全部<em><?= (int) ($counts['total'] ?? 0) ?></em></a>
    </div>
</div>

<div class="card">
    <form class="filter-bar" method="get" action="<?= e(admin_url('feedbacks.php')) ?>">
        <?php if ($isTrash): ?>
            <input type="hidden" name="trashed" value="1">
        <?php endif; ?>
        <input type="text" name="keyword" placeholder="搜索单号 / 标题 / 提交人 / 部门"
               value="<?= e((string) ($filters['keyword'] ?? '')) ?>">
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach (($statuses ?? feedback_status_map()) as $key => $label): ?>
                <option value="<?= e((string) $key) ?>"<?= (string) ($filters['status'] ?? '') === (string) $key ? ' selected' : '' ?>>
                    <?= e((string) $label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="urgency">
            <option value="">全部紧急程度</option>
            <?php foreach (($urgencies ?? feedback_urgency_map()) as $key => $label): ?>
                <option value="<?= e((string) $key) ?>"<?= (string) ($filters['urgency'] ?? '') === (string) $key ? ' selected' : '' ?>>
                    <?= e((string) $label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="process_id">
            <option value="">全部工序</option>
            <?php foreach (($processes ?? []) as $pid => $pname): ?>
                <option value="<?= (int) $pid ?>"<?= (int) ($filters['process_id'] ?? 0) === (int) $pid ? ' selected' : '' ?>>
                    <?= e((string) $pname) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="month">
            <option value="">全部月份</option>
            <?php foreach (($months ?? []) as $m): ?>
                <option value="<?= e((string) $m['month']) ?>"<?= (string) ($filters['month'] ?? '') === (string) $m['month'] ? ' selected' : '' ?>>
                    <?= e((string) $m['month']) ?>（<?= (int) $m['total'] ?>）
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedbacks.php')) ?>">重置</a>
    </form>

    <?php if (!$isTrash): ?>
    <form class="filter-bar" method="get" action="<?= e(admin_url('feedback_export.php')) ?>" style="margin-top:12px">
        <?php foreach ($query as $qk => $qv): ?>
            <input type="hidden" name="<?= e((string) $qk) ?>" value="<?= e((string) $qv) ?>">
        <?php endforeach; ?>
        <span class="muted small">导出（按提交时间）：</span>
        <input type="date" name="start" aria-label="提交开始日期">
        <span class="muted small">至</span>
        <input type="date" name="end" aria-label="提交结束日期">
        <select name="loop">
            <option value="">全部处理状态</option>
            <option value="closed">仅已闭环（已解决 / 已关闭）</option>
            <option value="open">仅未闭环（待受理 / 处理中）</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">导出 CSV</button>
        <span class="muted small">默认沿用上方筛选；日期与闭环状态为导出附加条件</span>
    </form>
    <?php endif; ?>
</div>

<?php if (!$isTrash && ($months ?? []) !== []): ?>
<div class="card fb-archive">
    <div class="card-head">
        <h2 class="card-title">按月归档</h2>
        <span class="muted small">点击某月即筛选该月反馈</span>
    </div>
    <div class="fb-archive-row">
        <?php foreach ($months as $m): ?>
            <a class="fb-archive-item<?= (string) ($filters['month'] ?? '') === (string) $m['month'] ? ' active' : '' ?>"
               href="<?= e(admin_url('feedbacks.php?month=' . urlencode((string) $m['month']))) ?>">
                <span class="fb-archive-month"><?= e((string) $m['month']) ?></span>
                <span class="fb-archive-total"><?= (int) $m['total'] ?></span>
                <span class="fb-archive-split">待<?= (int) $m['pending'] ?> · 已<?= (int) $m['resolved'] ?> · 关<?= (int) $m['closed'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">
            <?= $isTrash ? '反馈回收站' : '反馈列表' ?>
            <em class="muted">共 <?= (int) $result['total'] ?> 条</em>
        </h2>
        <div class="card-actions">
            <?php if ($isTrash): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedbacks.php')) ?>">返回列表</a>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedbacks.php?trashed=1')) ?>">回收站</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state">
            <p><?= $isTrash ? '回收站是空的。' : '还没有员工反馈，前台「员工反馈」页提交后会显示在这里。' ?></p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:120px">单号</th>
                    <th>问题标题</th>
                    <th style="width:120px">提交人</th>
                    <th style="width:90px">工序</th>
                    <th style="width:80px">紧急度</th>
                    <th style="width:90px">状态</th>
                    <th style="width:140px">提交时间</th>
                    <th style="width:140px">完成时间</th>
                    <th style="width:150px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['items'] as $item): ?>
                <?php
                $fbId     = (int) $item['id'];
                $status   = (string) $item['status'];
                $urgency  = (string) $item['urgency'];
                ?>
                <tr>
                    <td class="fb-code"><?= e((string) $item['code']) ?></td>
                    <td>
                        <a class="link-strong" href="<?= e(admin_url('feedback_detail.php?id=' . $fbId)) ?>">
                            <?= e(excerpt((string) $item['title'], 42)) ?>
                        </a>
                        <div class="muted small">
                            <?php if ((string) $item['device'] !== ''): ?>
                                模块：<?= e(excerpt((string) $item['device'], 18)) ?> ·
                            <?php endif; ?>
                            <?php if ((string) ($item['related_module'] ?? '') !== ''): ?>
                                界面：<?= e(excerpt((string) $item['related_module'], 14)) ?>
                            <?php endif; ?>
                            <?php if ((int) $item['case_id'] > 0): ?>
                                已生成案例 #<?= (int) $item['case_id'] ?>
                            <?php else: ?>
                                未生成案例
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?= e((string) $item['employee_name']) ?>
                        <div class="muted small"><?= e((string) $item['department']) ?></div>
                    </td>
                    <td>
                        <?php if ((string) ($item['process_name'] ?? '') !== ''): ?>
                            <span class="tag tag-process"><?= e((string) $item['process_name']) ?></span>
                        <?php else: ?>
                            <span class="muted small">未指定</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fb-urgency fb-urgency-<?= e($urgency) ?>"><?= e(feedback_urgency_label($urgency)) ?></span>
                    </td>
                    <td><span class="fb-status fb-status-<?= e($status) ?>"><?= e(feedback_status_label($status)) ?></span></td>
                    <td class="small muted"><?= e((string) $item['created_at']) ?></td>
                    <td class="small muted"><?= ($item['completed_at'] ?? '') !== '' ? e((string) $item['completed_at']) : '—' ?></td>
                    <td>
                        <a class="btn btn-ghost btn-xs" href="<?= e(admin_url('feedback_detail.php?id=' . $fbId)) ?>">处理</a>
                        <?php if ($isTrash): ?>
                            <form method="post" action="<?= e(admin_url('feedback_action.php')) ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="restore">
                                <input type="hidden" name="id" value="<?= $fbId ?>">
                                <button type="submit" class="btn btn-ghost btn-xs">恢复</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(admin_url('feedback_action.php')) ?>" style="display:inline"
                                  onsubmit="return confirm('确认移入回收站？');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="do" value="trash">
                                <input type="hidden" name="id" value="<?= $fbId ?>">
                                <button type="submit" class="btn btn-danger btn-xs">删除</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => admin_url('feedbacks.php'),
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>
