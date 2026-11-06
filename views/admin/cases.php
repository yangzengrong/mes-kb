<?php
/**
 * 故障案例列表 / 回收站（需求 4.3）。
 *
 * @var array<string,mixed> $filters
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<int,string> $categories
 * @var array<int,array<int,array<string,mixed>>> $attachments
 * @var bool $isSuper
 */
$isTrash = !empty($filters['trashed']);
$docType = (string) ($filters['doc_type'] ?? '');
$isFault = $docType === ''; // 空 = 故障案例（全部类型）；manual/changelog = 独立管理入口
// 看板「发布后无人看」下钻模式（2026-10-26）
$isZeroView = !empty($filters['zero_view']);

/* 按文档类型适配文案 */
$typeConfig = [
    ''          => ['list' => '案例列表',      'add' => '新增案例', 'empty' => '还没有案例，点击「新增案例」开始沉淀运维经验。', 'col' => '故障标题'],
    'manual'    => ['list' => '手册列表',      'add' => '新增手册', 'empty' => '还没有系统操作手册，点击「新增手册」开始编写。', 'col' => '手册标题'],
    'changelog' => ['list' => '版本更新日志列表', 'add' => '新增更新日志', 'empty' => '还没有版本更新日志，点击「新增更新日志」开始记录。', 'col' => '日志标题'],
];
$typeCfg = $typeConfig[$docType];

$query   = [
    'keyword'     => $filters['keyword'],
    'category_id' => $filters['category_id'] ?: '',
    'process_id'  => (int) ($filters['process_id'] ?? 0) ?: '',
    'status'      => $filters['status'],
    'doc_type'    => $docType,
    // 分页 / 批量操作提交时都要带上，否则翻页就跳出「零浏览」视图
    'zero_view'   => $isZeroView ? 1 : '',
];
if ($isTrash) {
    $query['trashed'] = 1;
}
$query = array_filter($query, static function ($value): bool {
    return $value !== '' && $value !== null;
});

/**
 * 批量操作的可用范围。
 *
 * 【2026-10-17 改动：解除 $isFault 限制】
 * 原实现把 checkbox 和批量表单都锁在「仅故障案例 + 仅超管」：
 *   (!$isTrash && $isFault && $isSuper)
 * 于是手册、更新日志这两个同样有 16 条和 5 条数据的列表**完全没有批量入口**，
 * 而审核 / 下架 / 入回收站这些动作对它们同样适用 —— 限制的理由不成立。
 *
 * 现在：三种文档类型都能勾选；状态流转类动作（审核/下架/上架）限超管，
 * 移入回收站/恢复沿用单条版口径（超管或本人负责的内容），由后端逐条校验。
 */
$canBulkState = !$isTrash && $isSuper;   // 审核 / 下架 / 上架：仅超管
$canBulkItems = !$isTrash;                 // 移入回收站：后端按 canEdit 逐条判
$canBulkTrashView = $isTrash;              // 回收站内：恢复 / 彻底删除
?>

<div class="card">
    <form class="filter-bar" method="get" action="<?= e(admin_url('cases.php')) ?>">
        <?php if ($isTrash): ?>
            <input type="hidden" name="trashed" value="1">
        <?php endif; ?>
        <?php if ($isZeroView): ?>
            <input type="hidden" name="zero_view" value="1">
        <?php endif; ?>
        <?php if (!$isFault): ?>
            <input type="hidden" name="doc_type" value="<?= e($docType) ?>">
        <?php endif; ?>
        <input type="text" name="keyword" placeholder="搜索标题 / 现象关键词"
               value="<?= e((string) $filters['keyword']) ?>">
        <?php if ($isFault): ?>
        <select name="process_id">
            <option value="">全部工序</option>
            <?php foreach (($processes ?? []) as $pid => $pname): ?>
                <option value="<?= (int) $pid ?>"<?= (int) ($filters['process_id'] ?? 0) === (int) $pid ? ' selected' : '' ?>>
                    <?= e($pname) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="category_id">
            <option value="">全部分类</option>
            <?php foreach ($categories as $id => $name): ?>
                <option value="<?= (int) $id ?>"<?= (int) $filters['category_id'] === (int) $id ? ' selected' : '' ?>>
                    <?= e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!$isZeroView): ?>
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach (case_status_map() as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('cases.php')) ?>">重置</a>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2 class="card-title">
            <?= $isTrash ? '回收站' : $typeCfg['list'] ?>
            <em class="muted">共 <?= (int) $result['total'] ?> 条</em>
        </h2>
        <div class="card-actions">
            <?php if ($isTrash): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('cases.php' . (!$isFault ? '?doc_type=' . $docType : ''))) ?>">返回列表</a>
            <?php else: ?>
                <?php if (auth()->can('case.create')): ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(admin_url('case_form.php' . (!$isFault ? '?doc_type=' . $docType : ''))) ?>">+ <?= $typeCfg['add'] ?></a>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('cases.php?trashed=1')) ?>">
                    回收站（<?= (int) ($trashCount ?? 0) ?>）
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isZeroView): ?>
        <?php /* 下钻说明条（2026-10-26）：数字本身不是目标，能定位到具体内容才是。
           这里同时给出「为什么会出现」的判断线索，避免管理员只看到一堆 0 浏览却无从下手。 */ ?>
        <div class="zero-view-notice">
            <div class="zero-view-notice-text">
                <strong>发布后无人看：<?= (int) $result['total'] ?> 条</strong>
                <span>
                    这些内容已发布，但浏览量仍是 0 —— 说明发布后从未被任何人打开过。
                    常见原因：标题里没有现场会搜的词（报错原文、设备型号）、没绑定工序/故障类型导致筛选不到、
                    或首页与检索结果里排位太靠后。勾选后可批量下架重写，也可逐条编辑补关键词。
                </span>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('cases.php')) ?>">查看全部内容</a>
        </div>
    <?php endif; ?>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state">
            <p><?= $isTrash ? '回收站是空的。' : $typeCfg['empty'] ?></p>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <?php if ($canBulkState || $canBulkItems || $canBulkTrashView): ?><th style="width:36px"><input type="checkbox" id="bulkCheckAll" title="全选"></th><?php endif; ?>
                    <th style="width:52px">ID</th>
                    <th><?= $typeCfg['col'] ?></th>
                    <?php if ($isFault): ?>
                    <th style="width:90px">工序</th>
                    <th style="width:110px">故障类型</th>
                    <th style="width:150px">设备</th>
                    <?php endif; ?>
                    <th style="width:90px">状态</th>
                    <th style="width:120px">附件</th>
                    <th style="width:80px">浏览</th>
                    <th style="width:150px">更新时间</th>
                    <th style="width:220px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['items'] as $item): ?>
                <?php
                $caseId      = (int) $item['id'];
                $status      = (string) $item['status'];
                $caseFiles   = $attachments[$caseId] ?? [];
                $canEditCase = $isSuper || (int) $item['author_id'] === auth()->id();
                ?>
                <tr>
                    <?php if ($canBulkState || $canBulkItems || $canBulkTrashView): ?>
                        <td><input type="checkbox" class="bulk-check" form="bulkForm" name="ids[]" value="<?= $caseId ?>"></td>
                    <?php endif; ?>
                    <td class="muted"><?= $caseId ?></td>
                    <td>
                        <a class="link-strong" href="<?= e(admin_url('case_form.php?id=' . $caseId)) ?>">
                            <?= e(excerpt((string) $item['title'], 40)) ?>
                        </a>
                        <?php if ((int) $item['is_top'] === 1): ?><span class="tag tag-top">置顶</span><?php endif; ?>
                        <?php if ($item['deleted_at'] !== null): ?><span class="tag tag-trash">回收站</span><?php endif; ?>
                        <?php if ($docType === 'manual'): ?>
                            <span class="tag tag-process"><?= e((string) ($positions[(int) ($item['position_id'] ?? 0)] ?? '未设岗位')) ?></span>
                        <?php endif; ?>
                        <div class="muted small">
                            作者：<?= e((string) ($item['author_name'] ?? '未知')) ?>
                            · v<?= (int) $item['version_no'] ?>
                            <?php if (!empty($item['review_note'])): ?>
                                · 审核意见：<?= e(excerpt((string) $item['review_note'], 20)) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php if ($isFault): ?>
                    <td>
                        <?php $rowProcess = (string) ($item['process_name'] ?? ''); ?>
                        <?php if ($rowProcess !== ''): ?>
                            <span class="tag tag-process"><?= e($rowProcess) ?></span>
                        <?php else: ?>
                            <span class="muted small">未绑定</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($item['category_name'] ?? '未分类')) ?></td>
                    <td class="small">
                        <?= e((string) $item['device'] !== '' ? (string) $item['device'] : '—') ?>
                    </td>
                    <?php endif; ?>
                    <td><span class="badge badge-<?= e($status) ?>"><?= e(case_status_label($status)) ?></span></td>
                    <td class="small">
                        <?php if ($caseFiles === []): ?>
                            <span class="muted">无</span>
                        <?php else: ?>
                            <?php foreach (array_slice($caseFiles, 0, 2) as $file): ?>
                                <span class="tag"><?= e((string) $file['ext']) ?></span>
                            <?php endforeach; ?>
                            <?php if (count($caseFiles) > 2): ?>
                                <span class="muted">+<?= count($caseFiles) - 2 ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $item['views'] ?></td>
                    <td class="small muted"><?= e((string) $item['updated_at']) ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if ($item['deleted_at'] !== null): ?>
                                <?php if ($canEditCase): ?>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="restore">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-ghost btn-xs">恢复</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isSuper): ?>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form"
                                          data-confirm="彻底删除后案例及其附件将无法恢复，确认继续？">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="force_delete">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">彻底删除</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <a class="btn btn-ghost btn-xs" href="<?= e(admin_url('case_form.php?id=' . $caseId)) ?>">
                                    <?= $canEditCase ? '编辑' : '查看' ?>
                                </a>
                                <?php if ($isSuper && $status === 'pending'): ?>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="review">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-success btn-xs">审核通过</button>
                                    </form>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="review">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-ghost btn-xs">驳回</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isSuper && $status === 'published'): ?>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="status">
                                        <input type="hidden" name="status" value="offline">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-ghost btn-xs">下架</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isSuper && $status === 'offline'): ?>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="status">
                                        <input type="hidden" name="status" value="published">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-success btn-xs">发布</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canEditCase): ?>
                                    <form method="post" action="<?= e(admin_url('case_action.php')) ?>" class="inline-form"
                                          data-confirm="确认将该案例移入回收站？">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="do" value="trash">
                                        <input type="hidden" name="id" value="<?= $caseId ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">删除</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canBulkState || $canBulkItems || $canBulkTrashView): ?>
    <?php /* 批量操作栏。
         设计要点：单个 form 承载全部动作（do + action + status 三个隐藏域切换），
         而不是每个动作一个 form —— 多 form 会导致「勾了但忘了点哪个」，
         单一提交按钮 + JS 改隐藏域的方案，语义更清楚：勾选 → 选动作 → 提交。
         绑工序单独放一个 form，因为它需要额外的下拉框且用途特殊（历史数据兼容）。 */ ?>
    <div class="bulk-bar" id="bulkBar" hidden>
        <form id="bulkForm" method="post" action="<?= e(admin_url('case_action.php')) ?>" class="bulk-bar-main">
            <?= csrf_field() ?>
            <input type="hidden" id="bulkDo" name="do" value="bulk_status">
            <input type="hidden" id="bulkAction" name="action" value="">

            <span class="bulk-count">已选 <strong id="bulkCount">0</strong> 条</span>

            <div class="bulk-ops">
                <?php if ($canBulkState && $isFault): ?>
                    <?php /* 「审核通过」只对故障案例有意义 ——
                         手册与更新日志是运营主动录入的正式文档，创建即发布，
                         没有「待审核」这一环。给它们套审核流程会写入无意义的
                         reviewed_by / review_note 记录，污染数据。
                         下架/重新发布则是通用动作（都可能误发需要下架），三种类型都保留。 */ ?>
                    <button type="submit" class="btn btn-success btn-sm"
                            data-bulk-do="bulk_status" data-bulk-action=""
                            data-bulk-status="published"
                            data-confirm="确认将勾选的案例批量审核通过并发布到前台？">
                        审核通过
                    </button>
                <?php endif; ?>

                <?php if ($canBulkState): ?>
                    <button type="submit" class="btn btn-ghost btn-sm"
                            data-bulk-do="bulk_status" data-bulk-action=""
                            data-bulk-status="offline"
                            data-confirm="确认将勾选的内容批量下架？下架后前台将不可见。">
                        下架
                    </button>
                    <button type="submit" class="btn btn-ghost btn-sm"
                            data-bulk-do="bulk_status" data-bulk-action=""
                            data-bulk-status="published"
                            data-confirm="确认将勾选的内容批量重新发布？">
                        重新发布
                    </button>
                <?php endif; ?>

                <?php if ($canBulkItems): ?>
                    <button type="submit" class="btn btn-ghost btn-sm"
                            data-bulk-do="bulk_items" data-bulk-action="trash"
                            data-confirm="确认将勾选的内容移入回收站？可在回收站中恢复。">
                        移入回收站
                    </button>
                <?php endif; ?>

                <?php if ($canBulkTrashView): ?>
                    <button type="submit" class="btn btn-ghost btn-sm"
                            data-bulk-do="bulk_items" data-bulk-action="restore"
                            data-confirm="确认恢复勾选的内容？">
                        恢复
                    </button>
                    <?php if ($isSuper): ?>
                        <button type="submit" class="btn btn-danger btn-sm"
                                data-bulk-do="bulk_items" data-bulk-action="force_delete"
                                data-confirm="彻底删除后内容及其附件将无法恢复，确认继续？此操作不可撤销。">
                            彻底删除
                        </button>
                    <?php endif; ?>
                <?php endif; ?>

                <button type="button" class="btn btn-ghost btn-sm" id="bulkClear">取消选择</button>
            </div>
        </form>

        <?php /* 「绑工序」只在正常的故障案例列表里有意义：
         ① 回收站里的案例已 deleted_at 非空，绑工序不会生效，恢复后还要重来一次
         ② 手册/更新日志本来就没有工序维度（表单里已隐藏该字段）
         之前条件只判了 $isFault，漏了 !$isTrash，在回收站页会露出一个无效控件。 */ ?>
        <?php if ($isFault && !$isTrash && !empty($processes)): ?>
        <form id="bulkProcessForm" method="post" action="<?= e(admin_url('case_action.php')) ?>"
              class="bulk-bar-sub" data-confirm="确认把勾选的案例批量绑定到所选工序？">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="bulk_process">
            <span class="bulk-count">批量绑定工序 →</span>
            <select name="process_id" required>
                <option value="">选择工序</option>
                <?php foreach ($processes as $pid => $pname): ?>
                    <option value="<?= (int) $pid ?>"><?= e($pname) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">绑定</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => admin_url('cases.php'),
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>

<?php if ($canBulkState || $canBulkItems || $canBulkTrashView): ?>
<script>
/* 案例列表批量操作。
 *
 * 【为什么操作栏要跟着勾选状态显隐】
 * 原实现把批量表单固定放在表格下方，勾选后要往下滚才能看到 ——
 * 表格有 15 行时几乎等于「藏起来」。改成勾选即出现（sticky 吸顶），
 * 视线不需要离开勾选的那一行。
 *
 * 【为什么用一个 form 承载全部动作】
 * 每个动作一个 form 会让人搞不清「我该点哪个按钮对应哪个表单」。
 * 这里所有按钮都是同一个 form 里的 submit，点了由 JS 改写隐藏域
 * do / action / status 再提交 —— 语义收敛成「勾选 → 选动作 → 提交」。
 */
(function () {
    var all    = document.getElementById('bulkCheckAll');
    var boxes  = document.querySelectorAll('.bulk-check');
    var count  = document.getElementById('bulkCount');
    var bar    = document.getElementById('bulkBar');
    var form   = document.getElementById('bulkForm');
    var doEl   = document.getElementById('bulkDo');
    var actEl  = document.getElementById('bulkAction');
    var clear  = document.getElementById('bulkClear');
    if (all === null || boxes.length === 0) {
        return;
    }

    function refresh() {
        var n = 0;
        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].checked) { n++; }
        }
        if (count !== null) { count.textContent = String(n); }
        /* 没勾选时隐藏整个操作栏，避免空表单占位 */
        if (bar !== null) { bar.hidden = (n === 0); }
        /* 全选框的 indeterminate：部分选中时显示横杠，
           比保持空白更能表达「选了一部分」 */
        var some = 0;
        for (var j = 0; j < boxes.length; j++) {
            if (boxes[j].checked) { some++; }
        }
        all.checked = (n > 0 && n === boxes.length);
        all.indeterminate = (n > 0 && n < boxes.length);
    }

    all.addEventListener('change', function () {
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = all.checked; }
        refresh();
    });
    for (var k = 0; k < boxes.length; k++) {
        boxes[k].addEventListener('change', refresh);
    }

    if (clear !== null) {
        clear.addEventListener('click', function () {
            for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
            refresh();
        });
    }

    /* 点击动作按钮时把该动作的参数写进隐藏域 */
    if (form !== null) {
        var ops = form.querySelectorAll('[data-bulk-do]');
        for (var m = 0; m < ops.length; m++) {
            ops[m].addEventListener('click', function () {
                if (doEl !== null)   { doEl.value  = this.getAttribute('data-bulk-do') || ''; }
                if (actEl !== null)  { actEl.value = this.getAttribute('data-bulk-action') || ''; }
                /* status 不在隐藏域里（单条版用同名参数），用同名隐藏域承接 */
                var st = this.getAttribute('data-bulk-status');
                var stEl = form.querySelector('input[name="status"]');
                if (stEl === null) {
                    stEl = document.createElement('input');
                    stEl.type = 'hidden';
                    stEl.name = 'status';
                    form.appendChild(stEl);
                }
                stEl.value = st || '';
            });
        }
    }

    refresh();
})();
</script>
<?php endif; ?>
