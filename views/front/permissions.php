<?php
/**
 * MES 菜单权限查询页（数据来源：《系统菜单明细MES系统权限.xlsx》）。
 *
 * @var string $keyword
 * @var string $module
 * @var string $role
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int} $result
 * @var array<int,array{module:string,total:int}> $modules
 * @var array<int,array{role:string,total:int}> $roles
 * @var array<int,array{role:string,total:int}> $roleHits
 * @var array{menus:int,modules:int,roles:int} $stats
 */
$query = array_filter([
    'keyword' => $keyword,
    'module'  => $module,
    'role'    => $role,
], static function ($v): bool {
    return $v !== '' && $v !== null;
});

/** 组装查询串 */
$qs = static function (array $overrides = []) use ($query): string {
    return base_url('permissions?' . http_build_query(array_merge($query, $overrides)));
};

/** 叶子菜单名 = 最深层级名 */
$menuNameOf = static function (array $m): string {
    foreach (['menu_l4', 'menu_l3', 'menu_l2'] as $key) {
        if ((string) ($m[$key] ?? '') !== '') {
            return (string) $m[$key];
        }
    }
    return (string) ($m['module'] ?? '');
};

/** 层级面包屑 */
$crumbOf = static function (array $m) use ($menuNameOf): string {
    $parts = [(string) $m['module']];
    foreach (['menu_l2', 'menu_l3', 'menu_l4'] as $key) {
        $v = (string) ($m[$key] ?? '');
        if ($v !== '' && $v !== $menuNameOf($m)) {
            $parts[] = $v;
        }
    }
    $parts[] = $menuNameOf($m);
    return implode(' › ', array_unique($parts));
};

$activeFilters = ($keyword !== '' ? 1 : 0) + ($module !== '' ? 1 : 0) + ($role !== '' ? 1 : 0);
?>

<div class="page-head">
    <h1>MES 菜单权限查询</h1>
    <p class="page-sub">
        覆盖 <strong><?= (int) $stats['menus'] ?></strong> 条菜单
        · <strong><?= (int) $stats['modules'] ?></strong> 个系统模块
        · <strong><?= (int) $stats['roles'] ?></strong> 种角色权限
        <span class="muted">（给员工账号授权前，按关键字检索匹配对应权限）</span>
    </p>
</div>

<section class="perm-search-card">
    <form class="perm-search" method="get" action="<?= e(base_url('permissions')) ?>">
        <?php if ($module !== ''): ?>
            <input type="hidden" name="module" value="<?= e($module) ?>">
        <?php endif; ?>
        <?php if ($role !== ''): ?>
            <input type="hidden" name="role" value="<?= e($role) ?>">
        <?php endif; ?>
        <input type="text" name="keyword" placeholder="输入菜单名 / 权限名 / 菜单编码 / 系统模块，如：抽检、ESOP、7-1"
               value="<?= e($keyword) ?>" autofocus>
        <button type="submit">搜索权限</button>
        <?php if ($activeFilters > 0): ?>
            <a class="btn-line btn-line-sm" href="<?= e(base_url('permissions')) ?>">清空筛选</a>
        <?php endif; ?>
    </form>

    <?php if ($keyword !== '' && $roleHits !== []): ?>
        <div class="perm-role-hits">
            <span class="perm-hits-label">关键字命中权限（点击精确筛选）：</span>
            <?php foreach (array_slice($roleHits, 0, 12) as $hit): ?>
                <a class="perm-role-chip<?= $role === $hit['role'] ? ' active' : '' ?>"
                   href="<?= e($qs(['role' => $role === $hit['role'] ? '' : $hit['role']])) ?>">
                    <?= e((string) $hit['role']) ?><em><?= (int) $hit['total'] ?></em>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($pinyinTerms)): ?>
        <div class="perm-pinyin-hits">
            <span class="perm-hits-label">拼音 <?= e(strtoupper($keyword)) ?> 命中术语（点击检索）：</span>
            <?php foreach ((array) $pinyinTerms as $pinyinPair): ?>
                <a class="perm-pinyin-chip" href="<?= e($qs(['keyword' => (string) $pinyinPair['word'], 'page' => null])) ?>">
                    <?= e((string) $pinyinPair['word']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="perm-module-bar">
        <a class="perm-module-chip<?= $module === '' ? ' active' : '' ?>" href="<?= e($qs(['module' => '', 'page' => null])) ?>">
            全部模块
        </a>
        <?php foreach ($modules as $mod): ?>
            <a class="perm-module-chip<?= $module === $mod['module'] ? ' active' : '' ?>"
               href="<?= e($qs(['module' => $mod['module'], 'page' => null])) ?>"
               title="查看【<?= e($mod['module']) ?>】全部菜单">
                <?= e($mod['module']) ?><em><?= (int) $mod['total'] ?></em>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($role !== ''): ?>
        <div class="perm-active-bar">
            当前筛选角色：
            <span class="perm-role-chip active">
                <?= e($role) ?>
                <a class="perm-chip-close" href="<?= e($qs(['role' => ''])) ?>" title="移除该筛选">×</a>
            </span>
        </div>
    <?php endif; ?>
</section>

<?php if ($result['items'] === []): ?>
    <div class="empty-state empty-large">
        <h2>未匹配到菜单</h2>
        <p>换个更短的关键词试试，例如菜单名的一部分、角色权限名或菜单编码。</p>
        <div class="empty-actions">
            <a class="btn-line" href="<?= e(base_url('permissions')) ?>">清空条件重新查询</a>
        </div>
    </div>
<?php else: ?>
    <p class="perm-result-count">
        共匹配 <strong><?= (int) $result['total'] ?></strong> 条菜单
        <?php if ($module !== ''): ?>
            · 模块「<?= e($module) ?>」
        <?php endif; ?>
        <?php if ($role !== ''): ?>
            · 权限「<?= e($role) ?>」
        <?php endif; ?>
        <?php if ($keyword !== ''): ?>
            · 关键字「<?= e($keyword) ?>」
        <?php endif; ?>
    </p>

    <div class="perm-table-wrap">
        <table class="perm-table">
            <thead>
                <tr>
                    <th style="width:34px">#</th>
                    <th>菜单路径</th>
                    <th style="width:100px">菜单编码</th>
                    <th style="width:130px">角色权限</th>
                </tr>
            </thead>
            <tbody>
                <?php $seq = ($result['page'] - 1) * 20; ?>
                <?php foreach ($result['items'] as $item): ?>
                    <?php $seq++; ?>
                    <tr>
                        <td class="muted"><?= $seq ?></td>
                        <td>
                            <span class="perm-path"><?= highlight_keyword($crumbOf($item), $keyword) ?></span>
                        </td>
                        <td class="perm-code"><?= highlight_keyword((string) $item['menu_code'], $keyword) ?></td>
                        <td>
                            <?php $rowRole = (string) $item['role_name']; ?>
                            <?php if ($rowRole !== '' && $rowRole !== '-'): ?>
                                <a class="perm-role-chip<?= $role === $rowRole ? ' active' : '' ?>"
                                   href="<?= e($qs(['role' => $role === $rowRole ? '' : $rowRole, 'page' => null])) ?>"
                                   title="筛选权限【<?= e($rowRole) ?>】的全部菜单">
                                    <?= highlight_keyword($rowRole, $keyword) ?>
                                </a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => ['total' => $result['total'], 'page' => $result['page'], 'last_page' => $result['pages']],
        'basePath'    => base_url('permissions'),
        'queryParams' => $query,
    ]) ?>
<?php endif; ?>
