<?php
/**
 * MES → U9 同步报错速查页（2026-10-22）。
 *
 * 用途：同步报错后在这里定位「哪个环节的问题、怎么处理、找谁」，而不是群里问一圈。
 * 交互：筛选条件走 URL（无 JS 也能用），有 JS 时即时过滤、不刷新页面。
 *
 * @var string $q
 * @var string $group
 * @var string $stage
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,array{label:string,short:string}> $groups
 * @var array<string,string> $stages
 * @var array<string,int> $groupCounts
 * @var array<string,int> $stageCounts
 * @var array<string,bool> $visible
 */

$total     = count($rows);
$shown     = 0;
foreach ($visible as $flag) {
    if ($flag) {
        $shown++;
    }
}

/** 组装筛选地址：保留未修改的条件 */
$buildUrl = static function (array $params) use ($q, $group, $stage): string {
    $merged = array_merge(['q' => $q, 'group' => $group, 'stage' => $stage], $params);
    $merged = array_filter($merged, static function ($value): bool {
        return $value !== '' && $value !== null;
    });
    $queryString = http_build_query($merged);
    return base_url('u9-sync' . ($queryString !== '' ? '?' . $queryString : ''));
};

$hasFilter = $q !== '' || $group !== '' || $stage !== '';

/* 按大类分组，保持 43 条原始顺序；同时统计每组可见条数（用于隐藏整组空表） */
$byGroup      = [];
$groupVisible = [];
foreach ($rows as $row) {
    $byGroup[$row['group']][] = $row;
    if (!empty($visible[$row['code']])) {
        $groupVisible[$row['group']] = ($groupVisible[$row['group']] ?? 0) + 1;
    }
}
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('/')) ?>">首页</a>
    <span>/</span>
    <em>MES → U9 同步排查</em>
</nav>

<div class="page-head u9-head">
    <h1>MES → U9 同步排查速查</h1>
    <p class="page-sub">
        共 <strong><?= (int) $total ?></strong> 条已知报错。记下「单据类型 + 报错关键字」，先定位环节，再按处理要点执行。
    </p>
</div>

<section class="u9-panel">
    <form class="u9-search" method="get" action="<?= e(base_url('u9-sync')) ?>" autocomplete="off">
        <?php if ($group !== ''): ?><input type="hidden" name="group" value="<?= e($group) ?>"><?php endif; ?>
        <?php if ($stage !== ''): ?><input type="hidden" name="stage" value="<?= e($stage) ?>"><?php endif; ?>
        <input type="text" name="q" id="u9SearchInput" value="<?= e($q) ?>"
               placeholder="粘贴报错原文、错误码或单据名，如：GR-05 / 未能找到料品 / 负库存">
        <button type="submit">查询</button>
        <?php if ($hasFilter): ?>
            <a class="u9-reset" href="<?= e(base_url('u9-sync')) ?>">清空条件</a>
        <?php endif; ?>
    </form>

    <div class="u9-filters">
        <div class="u9-filter-row">
            <span class="u9-filter-label">单据大类</span>
            <a class="u9-chip<?= $group === '' ? ' is-on' : '' ?>" href="<?= e($buildUrl(['group' => ''])) ?>">
                全部<em><?= (int) $total ?></em>
            </a>
            <?php foreach ($groups as $key => $meta): ?>
                <a class="u9-chip<?= $group === $key ? ' is-on' : '' ?>"
                   data-group="<?= e($key) ?>"
                   href="<?= e($buildUrl(['group' => $key])) ?>">
                    <?= e($key) ?> <?= e($meta['short']) ?><em><?= (int) ($groupCounts[$key] ?? 0) ?></em>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="u9-filter-row">
            <span class="u9-filter-label">问题环节</span>
            <a class="u9-chip<?= $stage === '' ? ' is-on' : '' ?>" href="<?= e($buildUrl(['stage' => ''])) ?>">
                全部
            </a>
            <?php foreach ($stages as $key => $label): ?>
                <a class="u9-chip<?= $stage === $key ? ' is-on' : '' ?>"
                   data-stage="<?= e($key) ?>"
                   href="<?= e($buildUrl(['stage' => $key])) ?>">
                    <?= e($label) ?><em><?= (int) ($stageCounts[$key] ?? 0) ?></em>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<details class="u9-tips">
    <summary>三类高频报错的万能排查顺序（照着走即可）</summary>
    <ol>
        <li>
            <strong>「未能找到 X / X 不存在」</strong>（12 条，最高频）：
            查上游单据是否同步失败 → 查同步平台有无记录 → 历史单据（早于 2026-03-01）找 ERP/FPK 补 →
            仍无效找 MES/ERP 运维。
        </li>
        <li>
            <strong>负库存</strong>（5 条）：
            查上游入库/收料/成品入库是否同步失败 → MES 与 ERP 库存流水逐笔对比、核期初期末一致性 → 找运维。
        </li>
        <li>
            <strong>空引用 / Null / Object reference</strong>（3 条）：
            MES 报错不明确，无自助步骤，直接找 MES 运维抓日志定位。
        </li>
    </ol>
</details>

<div class="u9-result-head">
    <span class="u9-count" id="u9Count" data-total="<?= (int) $total ?>">当前显示 <strong><?= (int) $shown ?></strong> / <?= (int) $total ?> 条</span>
    <span class="u9-hint">错误码按「大类缩写 + 序号」编制：GR 工单 / XS 销售 / ZD 主数据 / RK 入库 / WW 委外 / TK 退料 / ZB 转拨 / CG 采购</span>
</div>

<?php foreach ($groups as $groupKey => $groupMeta): ?>
    <?php if (empty($byGroup[$groupKey])) { continue; } ?>
    <section class="u9-group" data-group="<?= e($groupKey) ?>"<?= ($groupVisible[$groupKey] ?? 0) > 0 ? '' : ' hidden' ?>>
        <h2 class="u9-group-title">
            <span class="u9-group-code"><?= e($groupKey) ?></span>
            <?= e($groupMeta['label']) ?>
            <em><?= (int) ($groupCounts[$groupKey] ?? 0) ?> 条</em>
        </h2>
        <div class="u9-table-wrap">
            <table class="u9-table">
                <thead>
                    <tr>
                        <th>错误码</th>
                        <th>单据</th>
                        <th>报错现象</th>
                        <th>问题环节</th>
                        <th>处理要点</th>
                        <th>责任方</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($byGroup[$groupKey] as $row): ?>
                    <tr data-group="<?= e($row['group']) ?>" data-stage="<?= e($row['stage']) ?>"
                        <?= !empty($visible[$row['code']]) ? '' : 'hidden' ?>>
                        <td data-label="错误码"><span class="u9-code"><?= e($row['code']) ?></span></td>
                        <td data-label="单据"><?= e($row['doc']) ?></td>
                        <td data-label="报错现象">
                            <span class="u9-symptom"><?= e($row['symptom']) ?></span>
                            <?php if ((string) $row['note'] !== ''): ?>
                                <span class="u9-note"><?= e((string) $row['note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="问题环节">
                            <span class="u9-stage u9-stage-<?= e($row['stage']) ?>"><?= e($stages[$row['stage']] ?? $row['stage']) ?></span>
                        </td>
                        <td data-label="处理要点"><?= e($row['action']) ?></td>
                        <td data-label="责任方"><span class="u9-owner"><?= e($row['owner']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<div class="u9-empty" id="u9Empty"<?= $shown > 0 ? ' hidden' : '' ?>>
    <p>没有匹配的条目。换个说法试试：只输入报错里的关键词（如「负库存」「采购订单」），或清空筛选条件。</p>
    <p class="u9-empty-actions">
        <a class="btn-ghost" href="<?= e(base_url('u9-sync')) ?>">清空条件</a>
        <a class="btn-ghost" href="<?= e(base_url('feedback')) ?>">提交问题反馈</a>
    </p>
</div>

<p class="u9-foot">
    表里没有的报错？<a href="<?= e(base_url('feedback')) ?>">提交问题反馈</a> 并附上报错截图，
    运维处理后会补进这张表。
</p>

<script>
/* 即时过滤：默认已由服务端渲染好 hidden，JS 只做后续切换（无 JS 也能用） */
(function () {
    var input  = document.getElementById('u9SearchInput');
    var countEl = document.getElementById('u9Count');
    var emptyEl = document.getElementById('u9Empty');
    var groups  = [].slice.call(document.querySelectorAll('.u9-group'));
    if (input === null || countEl === null) { return; }

    var state = { group: '', stage: '' };

    function norm(text) {
        return (text || '').replace(/\s+/g, ' ').toLowerCase();
    }

    function apply() {
        var terms = norm(input.value).split(/[\s,，。;；、:：|]+/).filter(function (t) { return t !== ''; });
        var shown = 0;

        groups.forEach(function (section) {
            var rows = [].slice.call(section.querySelectorAll('tbody tr'));
            var visibleRows = 0;

            rows.forEach(function (tr) {
                var ok = true;
                if (state.group !== '' && tr.dataset.group !== state.group) { ok = false; }
                if (ok && state.stage !== '' && tr.dataset.stage !== state.stage) { ok = false; }
                if (ok && terms.length > 0) {
                    var hay = norm(tr.textContent);
                    ok = terms.some(function (term) { return hay.indexOf(term) !== -1; });
                }
                tr.hidden = !ok;
                if (ok) { visibleRows++; }
            });

            section.hidden = visibleRows === 0;
            shown += visibleRows;
        });

        countEl.innerHTML = '当前显示 <strong>' + shown + '</strong> / ' + countEl.dataset.total + ' 条';
        emptyEl.hidden = shown !== 0;
    }

    countEl.dataset.total = countEl.dataset.total || '0';

    groups.forEach(function (section) {
        section.dataset.total = section.querySelectorAll('tbody tr').length;
    });

    input.addEventListener('input', apply);

    /* 筛选 chip：有 JS 时即时过滤并同步地址栏，无 JS 时按 href 服务端筛选 */
    document.querySelectorAll('.u9-chip').forEach(function (chip) {
        chip.addEventListener('click', function (ev) {
            var isGroup = chip.dataset.group !== undefined;
            var isStage = chip.dataset.stage !== undefined;
            if (!isGroup && !isStage) { return; } /* 「全部」按链接跳转，交给服务端 */
            ev.preventDefault();
            if (isGroup) { state.group = (state.group === chip.dataset.group) ? '' : chip.dataset.group; }
            if (isStage) { state.stage = (state.stage === chip.dataset.stage) ? '' : chip.dataset.stage; }

            document.querySelectorAll('.u9-chip[data-group], .u9-chip[data-stage]').forEach(function (other) {
                other.classList.remove('is-on');
            });
            chip.classList.add('is-on');

            apply();

            var params = new URLSearchParams();
            if (input.value.trim() !== '') { params.set('q', input.value.trim()); }
            if (state.group !== '') { params.set('group', state.group); }
            if (state.stage !== '') { params.set('stage', state.stage); }
            var qs = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs !== '' ? '?' + qs : ''));
        });
    });

    /* 打开带筛选条件的链接时，把 chip 状态同步到 JS 状态 */
    var onGroup = document.querySelector('.u9-chip.is-on[data-group]');
    var onStage = document.querySelector('.u9-chip.is-on[data-stage]');
    if (onGroup !== null) { state.group = onGroup.dataset.group; }
    if (onStage !== null) { state.stage = onStage.dataset.stage; }
})();
</script>
