<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * MES 系统菜单权限明细模型。
 *
 * 数据来源：《系统菜单明细MES系统权限.xlsx》按层级浏览 sheet（255 条），
 * 后续由后台「菜单权限库」手动维护：MES 开发商新增大板块/子板块时，
 * 第一时间在后台录入，保持知识库与 MES 系统菜单对称。
 * 注：url 列仅保留历史数据，前台不再展示与检索「功能页面」。
 */
class MesMenu extends Model
{
    /** @var string */
    protected static $table = 'mes_menus';

    /** 关键字命中字段（不含 url，前台已取消「功能页面」列） */
    private const SEARCH_FIELDS = ['menu_name', 'menu_l2', 'menu_l3', 'menu_l4', 'menu_code', 'role_name', 'module'];

    /**
     * 关键字 + 模块 + 角色过滤检索（分页）。
     *
     * 关键字支持两组扩展：同义词（后台维护）与拼音首字母（smq → 扫码枪），
     * 各词之间 OR，任一命中即返回。
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function search(string $keyword, string $module, string $role, int $page, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $where   = ['1 = 1'];
        $params  = [];

        if ($keyword !== '') {
            $terms = Synonym::expand($keyword);
            foreach (PinyinTerm::matchAbbr($keyword, 3) as $pinyinWord) {
                if (!in_array($pinyinWord, $terms, true)) {
                    $terms[] = $pinyinWord;
                }
            }

            $groups = [];
            foreach ($terms as $termIndex => $term) {
                $like     = '%' . self::escapeLike($term) . '%';
                $clauses  = [];
                foreach (self::SEARCH_FIELDS as $fieldIndex => $field) {
                    // 原生预处理下占位符不可复用，逐词逐字段生成唯一名字
                    $key                = 'kw' . $termIndex . '_' . $fieldIndex;
                    $clauses[]          = sprintf('%s LIKE :%s', $field, $key);
                    $params[$key]       = $like;
                }
                $groups[] = '(' . implode(' OR ', $clauses) . ')';
            }
            $where[] = '(' . implode(' OR ', $groups) . ')';
        }
        if ($module !== '') {
            $where[]         = 'module = :f_module';
            $params['f_module'] = $module;
        }
        if ($role !== '') {
            $where[]        = 'role_name = :f_role';
            $params['f_role']  = $role;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Database::scalar(
            "SELECT COUNT(*) FROM mes_menus WHERE {$whereSql}",
            $params
        );

        $offset      = ($page - 1) * $perPage;
        $itemsParams               = $params;
        $itemsParams['lim_per_page'] = $perPage;
        $itemsParams['off_offset']   = $offset;

        $items = Database::select(
            "SELECT * FROM mes_menus WHERE {$whereSql}
             ORDER BY module ASC, menu_l2 ASC, menu_l3 ASC, menu_name ASC
             LIMIT :lim_per_page OFFSET :off_offset",
            $itemsParams
        );

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * 模块（一级菜单）及菜单数量，按数量降序。
     *
     * @return array<int,array{module:string,total:int}>
     */
    public static function modules(): array
    {
        return Database::select(
            'SELECT module, COUNT(*) AS total FROM mes_menus
             GROUP BY module ORDER BY total DESC, module ASC'
        );
    }

    /**
     * 角色权限及可访问菜单数量（用于权限速查云）。
     *
     * @param string $keyword 可选：仅统计名称命中的角色
     * @return array<int,array{role:string,total:int}>
     */
    public static function roles(string $keyword = ''): array
    {
        if ($keyword !== '') {
            return Database::select(
                'SELECT role_name AS role, COUNT(*) AS total FROM mes_menus
                 WHERE role_name <> \'\' AND role_name <> \'-\'
                   AND (role_name LIKE :kw OR menu_name LIKE :kw_name)
                 GROUP BY role_name ORDER BY total DESC, role ASC LIMIT 60',
                ['kw' => '%' . $keyword . '%', 'kw_name' => '%' . $keyword . '%']
            );
        }
        return Database::select(
            'SELECT role_name AS role, COUNT(*) AS total FROM mes_menus
             WHERE role_name <> \'\' AND role_name <> \'-\'
             GROUP BY role_name ORDER BY total DESC, role ASC'
        );
    }

    /**
     * 汇总统计（页面顶部概览）。
     *
     * @return array{menus:int,modules:int,roles:int}
     */
    public static function stats(): array
    {
        return [
            'menus'   => (int) Database::scalar('SELECT COUNT(*) FROM mes_menus'),
            'modules' => (int) Database::scalar('SELECT COUNT(DISTINCT module) FROM mes_menus'),
            'roles'   => (int) Database::scalar('SELECT COUNT(DISTINCT role_name) FROM mes_menus WHERE role_name <> \'\' AND role_name <> \'-\''),
        ];
    }

    /**
     * 后台「菜单权限库」列表：关键字 + 模块过滤（分页）。
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public static function adminSearch(string $keyword, string $module, int $page, int $perPage = 20): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if ($keyword !== '') {
            $like               = '%' . $keyword . '%';
            $params['akw_name'] = $like;
            $params['akw_l2']   = $like;
            $params['akw_l3']   = $like;
            $params['akw_l4']   = $like;
            $params['akw_code'] = $like;
            $params['akw_role'] = $like;
            $params['akw_mod']  = $like;
            $where[] = '(menu_name LIKE :akw_name OR menu_l2 LIKE :akw_l2 OR menu_l3 LIKE :akw_l3
                         OR menu_l4 LIKE :akw_l4 OR menu_code LIKE :akw_code OR role_name LIKE :akw_role
                         OR module LIKE :akw_mod)';
        }
        if ($module !== '') {
            $where[]          = 'module = :amodule';
            $params['amodule'] = $module;
        }

        return self::paginate(
            implode(' AND ', $where),
            $params,
            'module ASC, menu_l2 ASC, menu_l3 ASC, menu_l4 ASC, menu_name ASC, id ASC',
            $page,
            $perPage
        );
    }

    /**
     * 完全重复判断：同模块 + 各级菜单名 + 菜单名 + 编码 + 角色均一致视为重复。
     */
    public static function isDuplicate(array $fields, int $excludeId = 0): bool
    {        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM mes_menus
             WHERE module = :d_module AND menu_l2 = :d_l2 AND menu_l3 = :d_l3
               AND menu_l4 = :d_l4 AND menu_name = :d_name AND menu_code = :d_code
               AND role_name = :d_role AND id <> :d_id',
            [
                'd_module' => $fields['module'],
                'd_l2'     => $fields['menu_l2'],
                'd_l3'     => $fields['menu_l3'],
                'd_l4'     => $fields['menu_l4'],
                'd_name'   => $fields['menu_name'],
                'd_code'   => $fields['menu_code'],
                'd_role'   => $fields['role_name'],
                'd_id'     => $excludeId,
            ]
        );
        return $count > 0;
    }

    /**
     * 导入判重键：模块 + 各级菜单 + 叶子名 + 编码 + 角色（与后台手写查重口径一致，不含 url）。
     *
     * @param array<string,mixed> $row
     */
    public static function importKey(array $row): string
    {
        $parts = [];
        foreach (['module', 'menu_l2', 'menu_l3', 'menu_l4', 'menu_name', 'menu_code', 'role_name'] as $field) {
            $parts[] = trim((string) ($row[$field] ?? ''));
        }
        return implode("\x1f", $parts);
    }

    /**
     * 批量导入预检：只统计，不写库。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{new:int,dup:int,newRows:array<int,array<string,mixed>>}
     */
    public static function previewImport(array $rows): array
    {
        $existing = self::existingKeys();
        $new      = 0;
        $dup      = 0;
        $newRows  = [];

        foreach ($rows as $row) {
            $key = self::importKey($row);
            if (isset($existing[$key])) {
                $dup++;
                continue;
            }
            $existing[$key] = true;
            $new++;
            $newRows[] = $row;
        }

        return ['new' => $new, 'dup' => $dup, 'newRows' => $newRows];
    }

    /**
     * 合并导入（xlsx 批量补充）：
     * 仅补入库中尚不存在的行，后台手工新增或编辑过的数据一律保留，绝不清表。
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{inserted:int,skipped:int}
     */
    public static function mergeImport(array $rows): array
    {
        $existing = self::existingKeys();
        $inserted = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $key = self::importKey($row);
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }

            self::create([
                'module'    => (string) ($row['module'] ?? ''),
                'menu_l2'   => (string) ($row['menu_l2'] ?? ''),
                'menu_l3'   => (string) ($row['menu_l3'] ?? ''),
                'menu_l4'   => (string) ($row['menu_l4'] ?? ''),
                'menu_name' => (string) ($row['menu_name'] ?? ''),
                'menu_code' => (string) ($row['menu_code'] ?? ''),
                'role_name' => (string) ($row['role_name'] ?? ''),
                'url'       => (string) ($row['url'] ?? ''),
            ]);

            $existing[$key] = true;
            $inserted++;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * 现有记录判重键集合。
     *
     * @return array<string,bool>
     */
    private static function existingKeys(): array
    {
        $keys = [];
        $rows = Database::select(
            'SELECT module, menu_l2, menu_l3, menu_l4, menu_name, menu_code, role_name FROM mes_menus'
        );
        foreach ($rows as $row) {
            $keys[self::importKey($row)] = true;
        }
        return $keys;
    }

    /**
     * LIKE 转义：避免关键字中的 % 与 _ 被当作通配符。
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
