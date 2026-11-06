<?php
/**
 * 系统日志（需求 4.6 系统日志记录模块）。
 *
 * @var string $tab
 * @var array<string,mixed> $filters
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<string,int> $summary
 * @var bool $isSuper
 */
$tabs = [
    'operations' => ['label' => '操作日志', 'url' => admin_url('logs.php?tab=operations')],
    'access'     => ['label' => '访问日志', 'url' => admin_url('logs.php?tab=access')],
    'login'      => ['label' => '登录日志', 'url' => admin_url('logs.php?tab=login')],
];
if (!$isSuper) {
    $tabs = ['operations' => $tabs['operations']];
}

$basePath = admin_url('logs.php');
$query    = array_filter([
    'tab'        => $tab,
    'keyword'    => $filters['keyword'] ?? '',
    'action'     => $filters['action'] ?? '',
    'start_date' => $filters['start_date'] ?? '',
    'end_date'   => $filters['end_date'] ?? '',
], static function ($value): bool {
    return $value !== '' && $value !== null;
});
?>

<div class="stat-grid stat-grid-sm">
    <div class="stat-card">
        <span class="stat-label">操作日志</span>
        <span class="stat-value"><?= (int) $summary['operations'] ?></span>
        <span class="stat-foot">今日 <?= (int) $summary['today_operations'] ?> 条</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">访问日志</span>
        <span class="stat-value"><?= (int) $summary['accesses'] ?></span>
        <span class="stat-foot">今日 <?= (int) $summary['today_accesses'] ?> 条</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">检索记录</span>
        <span class="stat-value"><?= (int) $summary['searches'] ?></span>
        <span class="stat-foot">用于反哺案例补充</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">登录失败</span>
        <span class="stat-value"><?= (int) $summary['login_failed'] ?></span>
        <span class="stat-foot">含防爆破锁定记录</span>
    </div>
</div>

<div class="card">
    <div class="tab-bar">
        <?php foreach ($tabs as $key => $item): ?>
            <a class="tab<?= $tab === $key ? ' active' : '' ?>" href="<?= e($item['url']) ?>">
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab !== 'login'): ?>
    <form class="filter-bar" method="get" action="<?= e($basePath) ?>">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input type="text" name="keyword" placeholder="<?= $tab === 'access' ? '搜索 IP / 案例标题' : '搜索操作人 / 内容 / IP' ?>"
               value="<?= e((string) ($filters['keyword'] ?? '')) ?>">
        <?php if ($tab === 'operations'): ?>
            <input type="text" name="action" placeholder="操作类型前缀，如 case."
                   value="<?= e((string) ($filters['action'] ?? '')) ?>">
        <?php else: ?>
            <select name="action">
                <option value="">全部行为</option>
                <?php foreach (['view' => '浏览案例', 'preview' => '在线预览', 'download' => '下载附件'] as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= ($filters['action'] ?? '') === $key ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input type="date" name="start_date" value="<?= e((string) ($filters['start_date'] ?? '')) ?>">
        <input type="date" name="end_date" value="<?= e((string) ($filters['end_date'] ?? '')) ?>">
        <button type="submit" class="btn btn-primary btn-sm">查询</button>
        <a class="btn btn-ghost btn-sm" href="<?= e($basePath . '?tab=' . $tab) ?>">重置</a>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">
            <?= e($tabs[$tab]['label'] ?? '日志') ?>
            <em class="muted">共 <?= (int) $result['total'] ?> 条</em>
        </h2>
        <?php if ($tab === 'operations' && $isSuper): ?>
            <div class="card-actions">
                <a class="btn btn-ghost btn-sm"
                   href="<?= e(admin_url('log_export.php') . '?' . http_build_query($query)) ?>">
                    导出 CSV
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state"><p>没有符合条件的日志记录。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <?php if ($tab === 'operations'): ?>
                <thead>
                    <tr>
                        <th style="width:70px">ID</th>
                        <th style="width:120px">操作人</th>
                        <th style="width:150px">操作类型</th>
                        <th>操作内容</th>
                        <th style="width:130px">IP 地址</th>
                        <th style="width:150px">时间</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td class="muted"><?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['username']) ?></td>
                        <td><span class="tag"><?= e((string) $item['action']) ?></span></td>
                        <td class="small">
                            <?= e((string) $item['detail']) ?>
                            <?php if ((string) $item['target_type'] !== ''): ?>
                                <div class="muted">
                                    <?= e((string) $item['target_type']) ?>
                                    <?= (string) $item['target_id'] !== '' ? '#' . e((string) $item['target_id']) : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="small muted"><?= e((string) $item['ip']) ?></td>
                        <td class="small muted"><?= e((string) $item['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            <?php elseif ($tab === 'access'): ?>
                <thead>
                    <tr>
                        <th style="width:70px">ID</th>
                        <th style="width:90px">行为</th>
                        <th>案例 / 附件</th>
                        <th style="width:130px">IP 地址</th>
                        <th style="width:150px">时间</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td class="muted"><?= (int) $item['id'] ?></td>
                        <td><span class="tag"><?= e((string) $item['action']) ?></span></td>
                        <td class="small">
                            <?php if ((int) $item['case_id'] > 0): ?>
                                <a href="<?= e(admin_url('case_form.php?id=' . (int) $item['case_id'])) ?>">
                                    <?= e(excerpt((string) ($item['case_title'] ?? ('#' . $item['case_id'])), 30)) ?>
                                </a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <?php if ((string) $item['extra'] !== ''): ?>
                                <div class="muted"><?= e(excerpt((string) $item['extra'], 40)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small muted"><?= e((string) $item['ip']) ?></td>
                        <td class="small muted"><?= e((string) $item['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>

            <?php else: ?>
                <thead>
                    <tr>
                        <th style="width:70px">ID</th>
                        <th style="width:160px">尝试账号</th>
                        <th style="width:100px">结果</th>
                        <th style="width:140px">IP 地址</th>
                        <th>客户端</th>
                        <th style="width:150px">时间</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td class="muted"><?= (int) $item['id'] ?></td>
                        <td><?= e((string) $item['username']) ?></td>
                        <td>
                            <span class="badge badge-<?= (int) $item['success'] === 1 ? 'published' : 'offline' ?>">
                                <?= (int) $item['success'] === 1 ? '成功' : '失败' ?>
                            </span>
                        </td>
                        <td class="small muted"><?= e((string) $item['ip']) ?></td>
                        <td class="small muted"><?= e(excerpt((string) $item['user_agent'], 60)) ?></td>
                        <td class="small muted"><?= e((string) $item['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            <?php endif; ?>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => $basePath,
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>
