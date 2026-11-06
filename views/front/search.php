<?php
/**
 * 前台检索结果（需求 3.2 模糊搜索 / 导航栏优化：高级筛选多选 + 已筛选标签）。
 *
 * @var string $keyword
 * @var int $processId
 * @var int $positionId
 * @var array<int,int> $categoryIds
 * @var array<int,string> $docTypes
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<int,array<string,mixed>> $categories
 * @var array<int,array<string,mixed>> $processes
 * @var array<int,array<string,mixed>> $positions
 * @var array<int,string> $suggestions
 * @var array<int,string> $synonymTerms 命中的同义词（同组词，用于提示）
 * @var array{term:string,count:int,alts:array<string,int>,result:array<string,mixed>}|null $degraded
 *      整句未命中时的降级检索结果（改用哪个词、备选词及各自命中数）
 */

/** 当前筛选条件组装成查询串（供分页使用） */
$tag = (string) ($tag ?? '');
$degraded = $degraded ?? null;
// 降级检索时按「实际命中的词」高亮，否则拿整句去高亮会一个字都标不上
$highlightWord = $degraded !== null ? (string) $degraded['term'] : $keyword;
$query = [];
if ($keyword !== '') {
    $query['keyword'] = $keyword;
}
if ($processId > 0) {
    $query['process_id'] = $processId;
}
if ($positionId > 0) {
    $query['position_id'] = $positionId;
}
if (($tag ?? '') !== '') {
    $query['tag'] = $tag;
}
foreach ($categoryIds as $categoryId) {
    $query['category_id'][] = $categoryId;
}
foreach ($docTypes as $docType) {
    $query['doc_type'][] = $docType;
}

/** 生成「去掉某一项筛选」后的检索地址（已筛选标签单独关闭） */
$filterBaseUrl = static function (array $params): string {
    $params = array_filter($params, static function ($value): bool {
        return $value !== '' && $value !== null && $value !== [];
    });
    $queryString = http_build_query($params);
    return base_url('search' . ($queryString !== '' ? '?' . $queryString : ''));
};

$tagUrlWithoutKeyword = static function () use ($filterBaseUrl, $processId, $positionId, $categoryIds, $docTypes, $tag): string {
    return $filterBaseUrl([
        'process_id'  => $processId ?: '',
        'position_id' => $positionId ?: '',
        'category_id' => $categoryIds,
        'doc_type'    => $docTypes,
        'tag'         => $tag,
    ]);
};
$tagUrlWithoutProcess = static function () use ($filterBaseUrl, $keyword, $positionId, $categoryIds, $docTypes, $tag): string {
    return $filterBaseUrl([
        'keyword'     => $keyword,
        'position_id' => $positionId ?: '',
        'category_id' => $categoryIds,
        'doc_type'    => $docTypes,
        'tag'         => $tag,
    ]);
};
$tagUrlWithoutPosition = static function () use ($filterBaseUrl, $keyword, $processId, $categoryIds, $docTypes, $tag): string {
    return $filterBaseUrl([
        'keyword'     => $keyword,
        'process_id'  => $processId ?: '',
        'category_id' => $categoryIds,
        'doc_type'    => $docTypes,
        'tag'         => $tag,
    ]);
};
$tagUrlWithoutCategory = static function (int $removedId) use ($filterBaseUrl, $keyword, $processId, $positionId, $categoryIds, $docTypes, $tag): string {
    $remaining = array_values(array_filter($categoryIds, static function (int $id) use ($removedId): bool {
        return $id !== $removedId;
    }));
    return $filterBaseUrl([
        'keyword'     => $keyword,
        'process_id'  => $processId ?: '',
        'position_id' => $positionId ?: '',
        'category_id' => $remaining,
        'doc_type'    => $docTypes,
        'tag'         => $tag,
    ]);
};
$tagUrlWithoutDocType = static function (string $removedType) use ($filterBaseUrl, $keyword, $processId, $positionId, $categoryIds, $docTypes, $tag): string {
    $remaining = array_values(array_filter($docTypes, static function (string $type) use ($removedType): bool {
        return $type !== $removedType;
    }));
    return $filterBaseUrl([
        'keyword'     => $keyword,
        'process_id'  => $processId ?: '',
        'position_id' => $positionId ?: '',
        'category_id' => $categoryIds,
        'doc_type'    => $remaining,
        'tag'         => $tag,
    ]);
};
$tagUrlWithoutTag = static function () use ($filterBaseUrl, $keyword, $processId, $positionId, $categoryIds, $docTypes): string {
    return $filterBaseUrl([
        'keyword'     => $keyword,
        'process_id'  => $processId ?: '',
        'position_id' => $positionId ?: '',
        'category_id' => $categoryIds,
        'doc_type'    => $docTypes,
    ]);
};

/** 展开的分类树（父 + 子平铺，供高级筛选勾选） */
$flatCategories = [];
foreach ($categories as $category) {
    $flatCategories[] = ['id' => (int) $category['id'], 'name' => (string) $category['name'], 'depth' => 0];
    foreach (($category['children'] ?? []) as $child) {
        $flatCategories[] = ['id' => (int) $child['id'], 'name' => (string) $child['name'], 'depth' => 1];
    }
}

$activeProcessName = '';
foreach ($processes as $process) {
    if ((int) $process['id'] === $processId) {
        $activeProcessName = (string) $process['name'];
    }
}

$activePositionName = '';
foreach ($positions as $position) {
    if ((int) $position['id'] === $positionId) {
        $activePositionName = (string) $position['name'];
    }
}

$activeCategoryNames = [];
foreach ($flatCategories as $flatCategory) {
    if (in_array($flatCategory['id'], $categoryIds, true)) {
        $activeCategoryNames[$flatCategory['id']] = $flatCategory['name'];
    }
}

$activeDocTypeLabels = [];
foreach ($docTypes as $docType) {
    $activeDocTypeLabels[$docType] = doc_type_label($docType);
}

$advSelectedCount = count($categoryIds) + count($docTypes) + ($positionId > 0 ? 1 : 0);
$hasAnyFilter = $keyword !== '' || $processId > 0 || $positionId > 0 || $tag !== '' || $advSelectedCount > 0;
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('/')) ?>">首页</a>
    <span>/</span>
    <?php if ($activeProcessName !== ''): ?>
        <em><?= e($activeProcessName) ?></em>
    <?php elseif ($activePositionName !== ''): ?>
        <em><?= e($activePositionName) ?></em>
    <?php else: ?>
        <em>全部案例</em>
    <?php endif; ?>
    <?php if ($keyword !== ''): ?>
        <span>/</span>
        <em>检索「<?= e($keyword) ?>」</em>
    <?php endif; ?>
</nav>

<div class="page-head">
    <h1>
        <?php if ($keyword !== ''): ?>
            检索「<?= e($keyword) ?>」
        <?php elseif ($activeProcessName !== '' || $activePositionName !== ''): ?>
            <?= e(trim(($activeProcessName !== '' ? $activeProcessName . ' · ' : '') . $activePositionName, ' ·')) ?> · 全部文档
        <?php elseif (!$hasAnyFilter): ?>
            全部文档
        <?php else: ?>
            筛选结果
        <?php endif; ?>
    </h1>
    <p class="page-sub">共找到 <strong><?= (int) $result['total'] ?></strong> 条匹配文档</p>
</div>

<div class="search-panel">
    <form class="search-form" method="get" action="<?= e(base_url('search')) ?>" autocomplete="off">
        <input type="text" name="keyword" id="searchInput" placeholder="例如：绞线报工、成缆扫码、实验室数据采集报错"
               value="<?= e($keyword) ?>">
        <button type="submit">检索</button>
        <div class="suggest-box" id="suggestBox"></div>
    </form>

    <details class="adv-filter"<?= $advSelectedCount > 0 ? ' open' : '' ?>>
        <summary>高级筛选<?= $advSelectedCount > 0 ? '（已选 ' . $advSelectedCount . ' 项）' : '' ?></summary>
        <form method="get" action="<?= e(base_url('search')) ?>" autocomplete="off" class="adv-form">
            <?php if ($keyword !== ''): ?>
                <input type="hidden" name="keyword" value="<?= e($keyword) ?>">
            <?php endif; ?>
            <?php if ($tag !== ''): ?>
                <input type="hidden" name="tag" value="<?= e($tag) ?>">
            <?php endif; ?>
            <div class="adv-groups">
                <?php /* 工序筛选（2026-10-22 全局导航改版：工序从顶部导航下沉到内容区） */ ?>
                <div class="adv-group">
                    <h4>工序</h4>
                    <div class="adv-checks">
                        <select name="process_id" class="adv-select">
                            <option value="">全部工序</option>
                            <?php foreach ($processes as $process): ?>
                                <option value="<?= (int) $process['id'] ?>"<?= $processId === (int) $process['id'] ? ' selected' : '' ?>>
                                    <?= e((string) $process['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="adv-group">
                    <h4>岗位（系统操作手册可按岗位筛选）</h4>
                    <div class="adv-checks">
                        <select name="position_id" class="adv-select">
                            <option value="">全部岗位</option>
                            <?php foreach ($positions as $position): ?>
                                <option value="<?= (int) $position['id'] ?>"<?= $positionId === (int) $position['id'] ? ' selected' : '' ?>>
                                    <?= e((string) $position['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="adv-group">
                    <h4>故障类型（可多选）</h4>
                    <div class="adv-checks">
                        <?php foreach ($flatCategories as $flatCategory): ?>
                            <label class="adv-check">
                                <input type="checkbox" name="category_id[]" value="<?= $flatCategory['id'] ?>"
                                    <?= in_array($flatCategory['id'], $categoryIds, true) ? ' checked' : '' ?>>
                                <span><?= $flatCategory['depth'] > 0 ? '└ ' : '' ?><?= e($flatCategory['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="adv-group">
                    <h4>文档类型（可多选）</h4>
                    <div class="adv-checks">
                        <?php foreach (doc_type_map() as $docTypeValue => $docTypeLabel): ?>
                            <label class="adv-check">
                                <input type="checkbox" name="doc_type[]" value="<?= e($docTypeValue) ?>"
                                    <?= in_array($docTypeValue, $docTypes, true) ? ' checked' : '' ?>>
                                <span><?= e($docTypeLabel) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="adv-actions">
                <button type="submit" class="adv-confirm">确定筛选</button>
                <a class="adv-clear" href="<?= e(base_url('search')) ?>">清空筛选</a>
            </div>
        </form>
    </details>

    <?php if ($hasAnyFilter): ?>
        <div class="filter-tags">
            <span class="filter-tags-label">已筛选：</span>
            <?php if ($keyword !== ''): ?>
                <span class="tag">关键词「<?= e($keyword) ?>」
                    <a href="<?= e($tagUrlWithoutKeyword()) ?>" title="关闭该筛选条件">×</a>
                </span>
                <?php if (!empty($synonymTerms)): ?>
                    <span class="tag tag-synonym" title="同义词扩展：这些词的相关文章也会一并显示">
                        同义词：<?= e(implode(' / ', (array) $synonymTerms)) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($pinyinTerms)): ?>
                    <span class="tag tag-pinyin" title="拼音首字母联想：按输入的字母缩写匹配到的中文术语">
                        拼音 <?= e(strtoupper((string) $keyword)) ?> →
                        <?php $pinyinLinks = []; ?>
                        <?php foreach ((array) $pinyinTerms as $pinyinPair): ?>
                            <?php $pinyinLinks[] = '<a href="' . e(base_url('search?keyword=' . rawurlencode((string) $pinyinPair['word']))) . '">'
                                . e((string) $pinyinPair['word']) . '</a>'; ?>
                        <?php endforeach; ?>
                        <?= $pinyinLinks !== [] ? implode(' / ', $pinyinLinks) : '' ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($activeProcessName !== ''): ?>
                <span class="tag">工序：<?= e($activeProcessName) ?>
                    <a href="<?= e($tagUrlWithoutProcess()) ?>" title="关闭该筛选条件">×</a>
                </span>
            <?php endif; ?>
            <?php if ($activePositionName !== ''): ?>
                <span class="tag">岗位：<?= e($activePositionName) ?>
                    <a href="<?= e($tagUrlWithoutPosition()) ?>" title="关闭该筛选条件">×</a>
                </span>
            <?php endif; ?>
            <?php if ($tag !== ''): ?>
                <span class="tag">标签「<?= e($tag) ?>」
                    <a href="<?= e($tagUrlWithoutTag()) ?>" title="关闭该筛选条件">×</a>
                </span>
            <?php endif; ?>
            <?php foreach ($activeCategoryNames as $catId => $catName): ?>
                <span class="tag">故障类型：<?= e($catName) ?>
                    <a href="<?= e($tagUrlWithoutCategory((int) $catId)) ?>" title="关闭该筛选条件">×</a>
                </span>
            <?php endforeach; ?>
            <?php foreach ($activeDocTypeLabels as $docTypeValue => $docTypeLabel): ?>
                <span class="tag">文档：<?= e($docTypeLabel) ?>
                    <a href="<?= e($tagUrlWithoutDocType((string) $docTypeValue)) ?>" title="关闭该筛选条件">×</a>
                </span>
            <?php endforeach; ?>
            <a class="filter-tags-clear" href="<?= e(base_url('search')) ?>">清空全部</a>
        </div>
    <?php endif; ?>

    <?php if ($suggestions !== []): ?>
        <div class="search-suggest">
            <span>相关检索：</span>
            <?php foreach ($suggestions as $item): ?>
                <a href="<?= e(base_url('search?keyword=' . urlencode($item))) ?>"><?= e($item) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($result['items'] === []): ?>
    <div class="empty-state empty-large">
        <h2>没有找到匹配的故障案例</h2>
        <p>建议尝试更短的关键词，例如只用设备名称或故障现象；也可以切换到「全部案例」浏览。</p>
        <?php /* 关键词命中 U9 同步报错表时，先指路速查表——「搜不到案例」和「同步报错」是两回事（2026-10-23） */ ?>
        <?php if (!empty($u9Hint)): ?>
        <p>这个词可能不是案例问题，而是 <strong>MES → U9 同步</strong>异常——速查表里有 <?= (int) $u9Hint['count'] ?> 条相关报错与处理步骤。</p>
        <?php endif; ?>
        <div class="empty-actions">
            <?php if (!empty($u9Hint)): ?>
            <a class="btn-line" href="<?= e(base_url('u9-sync?q=' . urlencode((string) $keyword))) ?>">查同步报错速查表</a>
            <?php endif; ?>
            <?php if (feedback_enabled()): ?>
            <a class="btn-line<?= empty($u9Hint) ? '' : ' ghost' ?>" href="<?= e(base_url('feedback')) ?>">没找到？提交给运维</a>
            <?php endif; ?>
            <a class="btn-line<?= feedback_enabled() || !empty($u9Hint) ? ' ghost' : '' ?>" href="<?= e(base_url('search')) ?>">浏览全部案例</a>
            <a class="btn-line ghost" href="<?= e(base_url('/')) ?>">返回首页</a>
        </div>
    </div>
<?php else: ?>
    <?php if ($degraded !== null): ?>
        <div class="search-degraded">
            <p>
                整句「<?= e($keyword) ?>」没有完整匹配，已改用其中最具体的关键词
                <strong><?= e((string) $degraded['term']) ?></strong> 检索（<?= (int) $degraded['count'] ?> 条）。
            </p>
            <?php if (!empty($degraded['alts'])): ?>
                <p class="search-degraded-alts">
                    <span>也可以看：</span>
                    <?php foreach ((array) $degraded['alts'] as $altWord => $altCount): ?>
                        <a class="chip" href="<?= e(base_url('search?keyword=' . urlencode((string) $altWord))) ?>">
                            <?= e((string) $altWord) ?> <?= (int) $altCount ?> 条
                        </a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($u9Hint)): ?>
                <p>另外，这个词也可能是 <strong>MES → U9 同步</strong>异常——
                    速查表里有 <?= (int) $u9Hint['count'] ?> 条相关报错与处理步骤。
                    <a href="<?= e(base_url('u9-sync?q=' . urlencode($keyword))) ?>">去速查表 →</a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="result-list">
        <?php foreach ($result['items'] as $item): ?>
            <?php $caseId = (int) $item['id']; ?>
            <article class="result-item">
                <div class="result-head">
                    <a class="result-title" href="<?= e(base_url('case/' . $caseId)) ?>">
                        <?= highlight_keyword((string) $item['title'], $highlightWord) ?>
                    </a>
                    <?php $rowProcess = (string) ($item['process_name'] ?? ''); ?>
                    <?php if ($rowProcess !== ''): ?>
                        <span class="case-process"><?= e($rowProcess) ?></span>
                    <?php endif; ?>
                    <?php $rowPosition = (string) ($item['position_name'] ?? ''); ?>
                    <?php if ($rowPosition !== ''): ?>
                        <span class="case-position"><?= e($rowPosition) ?></span>
                    <?php endif; ?>
                    <span class="case-cat"><?= e((string) ($item['category_name'] ?? '未分类')) ?></span>
                    <?php if ((string) ($item['doc_type'] ?? 'case') !== 'case'): ?>
                        <span class="case-doctype"><?= e(doc_type_label((string) $item['doc_type'])) ?></span>
                    <?php endif; ?>
                    <button type="button" class="btn-copy-mini js-copy-link"
                            data-path="<?= e(base_url('case/' . $caseId)) ?>"
                            data-title="<?= e(strip_tags((string) $item['title'])) ?>"
                            title="复制该文章标题和链接，发给同事打开即见">
                        <span class="copy-icon">⧉</span><span class="copy-text">复制链接</span>
                    </button>
                </div>

                <p class="result-desc">
                    <?= highlight_keyword(excerpt((string) ($item['phenomenon'] ?? ''), 110), $highlightWord) ?>
                </p>

                <?php if ((string) ($item['solution'] ?? '') !== ''): ?>
                    <p class="result-solution">
                        <span class="label">解决方案</span>
                        <?= highlight_keyword(excerpt((string) $item['solution'], 110), $highlightWord) ?>
                    </p>
                <?php endif; ?>

                <div class="result-meta">
                    <?php if ((string) $item['device'] !== ''): ?>
                        <span class="meta-item">设备：<?= highlight_keyword((string) $item['device'], $highlightWord) ?></span>
                    <?php endif; ?>
                    <?php foreach (array_filter(array_map('trim', explode(',', (string) ($item['tags'] ?? '')))) as $resultTag): ?>
                        <a class="meta-item meta-tag" href="<?= e(base_url('search?tag=' . urlencode($resultTag))) ?>">#<?= e($resultTag) ?></a>
                    <?php endforeach; ?>
                    <span class="meta-item meta-views"><?= (int) $item['views'] ?> 次浏览</span>
                    <span class="meta-item meta-date">
                        <?= e(date('Y-m-d', strtotime((string) ($item['published_at'] ?? $item['updated_at'])))) ?>
                    </span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => base_url('search'),
        'queryParams' => $query,
    ]) ?>
<?php endif; ?>
