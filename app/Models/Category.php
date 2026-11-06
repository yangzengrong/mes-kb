<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * 分类模型（需求 4.2 故障案例分类管理）。
 * 支持一级 / 二级分类，后台可新增、编辑、排序、启用禁用、删除。
 */
class Category extends Model
{
    protected static $table = 'categories';

    /**
     * 分类树（一级 -> 其下二级）。
     *
     * 【scope 隔离】categories 表用 scope 区分两套互不通用语义的树：
     *   'case'   = 故障类型（出了什么问题），案例用；
     *   'manual' = 功能模块（哪个功能怎么用），操作手册用。
     * 手册挂到「程序报错」下是错的，不挂又会在分类导航里消失——
     * 这两件事曾同时发生在 20 条手册身上，故用 scope 分开维护。
     * 默认 'case' 保证所有既有调用点行为不变。
     *
     * @param bool   $onlyEnabled 是否仅取启用分类（前台使用）
     * @param string $scope       case / manual
     * @return array<int,array<string,mixed>>
     */
    public static function tree(bool $onlyEnabled = false, string $scope = 'case'): array
    {
        $conds = ['scope = :scope'];
        if ($onlyEnabled) {
            $conds[] = 'status = 1';
        }
        $rows = Database::select(
            'SELECT * FROM categories WHERE ' . implode(' AND ', $conds)
            . ' ORDER BY sort ASC, id ASC',
            ['scope' => $scope]
        );

        $parents = [];
        $children = [];
        foreach ($rows as $row) {
            $parentId = (int) $row['parent_id'];
            if ($parentId === 0) {
                $row['children'] = [];
                $parents[(int) $row['id']] = $row;
            } else {
                $children[$parentId][] = $row;
            }
        }

        foreach ($children as $parentId => $items) {
            if (isset($parents[$parentId])) {
                $parents[$parentId]['children'] = $items;
            } else {
                // 父分类被禁用或删除时，二级分类降级为独立项，避免案例“消失”
                foreach ($items as $item) {
                    $item['children'] = [];
                    $parents[(int) $item['id']] = $item;
                }
            }
        }

        return array_values($parents);
    }

    /**
     * 下拉选项：id => 带层级缩进的名称。
     *
     * @param bool   $onlyEnabled 是否仅取启用分类
     * @param string $scope       case / manual（见 tree() 的 scope 隔离说明）
     * @return array<int,string>
     */
    public static function options(bool $onlyEnabled = false, string $scope = 'case'): array
    {
        $options = [];
        foreach (self::tree($onlyEnabled, $scope) as $parent) {
            $options[(int) $parent['id']] = (string) $parent['name'];
            foreach (($parent['children'] ?? []) as $child) {
                $options[(int) $child['id']] = '　└ ' . (string) $child['name'];
            }
        }
        return $options;
    }

    /**
     * 分类及其文档数量（后台分类管理页使用）。
     *
     * @param string $scope case / manual
     * @return array<int,array<string,mixed>>
     */
    public static function withCaseCount(string $scope = 'case'): array
    {
        $rows = Database::select(
            'SELECT c.*, (
                SELECT COUNT(*) FROM cases k WHERE k.category_id = c.id AND k.deleted_at IS NULL
             ) AS case_count
             FROM categories c
             WHERE c.scope = :scope
             ORDER BY c.sort ASC, c.id ASC',
            ['scope' => $scope]
        );

        $parents  = [];
        $children = [];
        foreach ($rows as $row) {
            if ((int) $row['parent_id'] === 0) {
                $row['children'] = [];
                $parents[(int) $row['id']] = $row;
            } else {
                $children[(int) $row['parent_id']][] = $row;
            }
        }
        foreach ($children as $parentId => $items) {
            if (isset($parents[$parentId])) {
                $parents[$parentId]['children'] = $items;
            }
        }
        return array_values($parents);
    }

    /**
     * 该分类（含其二级分类）下的案例总数，删除前做占用校验。
     */
    public static function caseCount(int $id): int
    {
        return (int) Database::scalar(
            // PDO 原生预处理下同一命名占位符不能复用，拆成两个参数（防 HY093）。
            'SELECT COUNT(*) FROM cases WHERE deleted_at IS NULL AND (category_id = :id_self OR category_id IN (
                SELECT id FROM categories WHERE parent_id = :id_child
             ))',
            ['id_self' => $id, 'id_child' => $id]
        );
    }

    public static function hasChildren(int $id): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM categories WHERE parent_id = :id', ['id' => $id]) > 0;
    }

    /**
     * 手册功能模块及其手册数（前台手册列表页的模块导航）。
     *
     * 只返回「至少有 1 条已发布手册」的模块：空模块在导航里是纯噪音——
     * 现有 15 个故障类型里就有 7 个是零文档的死分类，不该让手册页重蹈覆辙。
     *
     * @return array<int,array{id:int,name:string,total:int}>
     */
    public static function manualModules(bool $onlyEnabled = true): array
    {
        $statusSql = $onlyEnabled ? "AND k.status = 'published'" : '';
        return Database::select(
            "SELECT c.id, c.name, COUNT(k.id) AS total
             FROM categories c
             INNER JOIN cases k ON k.category_id = c.id AND k.doc_type = 'manual'
                  AND k.deleted_at IS NULL $statusSql
             WHERE c.scope = 'manual'" . ($onlyEnabled ? ' AND c.status = 1' : '') . '
             GROUP BY c.id, c.name
             HAVING total > 0
             ORDER BY c.sort ASC, c.id ASC'
        );
    }

    /**
     * @return array<int,string>
     */
    public static function namesByIds(): array
    {
        $rows   = Database::select('SELECT id, name FROM categories');
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (string) $row['name'];
        }
        return $result;
    }
}
