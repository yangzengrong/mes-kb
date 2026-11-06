<?php
/**
 * MES → U9 同步报错速查数据（2026-10-22 建档）。
 *
 * 数据来源：`MES-U9报错汇总.xlsx`（2026-05-15 版，43 条），已按解析稿归一：
 *   - 补齐空值（A 列继承式空值按分组回填；R16 责任方补 FPK；R41 空报错归一为「无消耗记录」）；
 *   - 普通报表述收敛为「报错现象」短句，处理步骤进 action；
 *   - 「问题环节」归一为 8 类，供页面按环节筛选；
 *   - 单据类型存疑的 1 条（原 R44）标记 note，未擅自改归属。
 *
 * 维护方式：本文件为纯数据（无逻辑），改条目直接改数组；
 * 后续若条目增多到需要后台维护，再迁 `u9_errors` 表，页面渲染层无需改动。
 *
 * ©Yangzengrong 版权所有
 */

declare(strict_types=1);

namespace App\Data;

final class U9Errors
{
    /** 错误码大类（建议规则：大类 2 字母 + 序号） */
    public const GROUPS = [
        'GR' => ['label' => '工单 / 领料', 'short' => '工单领料'],
        'XS' => ['label' => '销售 / 出货', 'short' => '销售'],
        'ZD' => ['label' => '主数据（料号 / BOM）', 'short' => '主数据'],
        'RK' => ['label' => '入库', 'short' => '入库'],
        'WW' => ['label' => '委外', 'short' => '委外'],
        'TK' => ['label' => '退料', 'short' => '退料'],
        'ZB' => ['label' => '转拨', 'short' => '转拨'],
        'CG' => ['label' => '采购', 'short' => '采购'],
    ];

    /** 问题环节（归一为 8 类，页面上按此筛选） */
    public const STAGES = [
        'fix_upstream' => '先修上游',
        'mes_data'     => 'MES 补数据',
        'stock'        => '库存对账',
        'erp_make'     => 'ERP 补单',
        'close_sync'   => '平台关同步',
        'confirm'      => '双方确认',
        'mes_check'    => 'MES 排查',
        'fpk'          => 'FPK 处理',
    ];

    /**
     * 43 条速查条目。
     *
     * @return array<int,array{code:string,group:string,doc:string,symptom:string,stage:string,action:string,owner:string,note:string}>
     */
    public static function all(): array
    {
        return self::ROWS;
    }

    /**
     * 按错误码取单条（用于「查一下」直达）。
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $code): ?array
    {
        $code = strtoupper(trim($code));
        foreach (self::ROWS as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }
        return null;
    }

    /**
     * 各大类条数。
     *
     * @return array<string,int>
     */
    public static function groupCounts(): array
    {
        $counts = array_fill_keys(array_keys(self::GROUPS), 0);
        foreach (self::ROWS as $row) {
            $counts[$row['group']] = ($counts[$row['group']] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * 各问题环节条数。
     *
     * @return array<string,int>
     */
    public static function stageCounts(): array
    {
        $counts = array_fill_keys(array_keys(self::STAGES), 0);
        foreach (self::ROWS as $row) {
            $counts[$row['stage']] = ($counts[$row['stage']] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * 关键词命中条数（案例检索无结果时，指路同步报错速查表用）。
     *
     * 匹配语义与 HomeController::u9Sync() 逐字一致：多词按 OR 切分，
     * 错误码形态（如 GR-05）走精确匹配。两处逻辑重复是有意的——
     * u9Sync 还要叠加 group/stage 前置筛选，抽公共方法势必要改它的主链路。
     *
     * @return array{count:int,first:string} count=命中条数；first=首条错误码（无命中为空串）
     */
    public static function matchCount(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['count' => 0, 'first' => ''];
        }
        $terms        = preg_split('/[\s\x{3000},，。;；、:：|]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isCodeLookup = preg_match('/^[A-Za-z]{2}-\d{1,3}$/', $q) === 1;

        $count = 0;
        $first = '';
        foreach (self::ROWS as $row) {
            if ($isCodeLookup) {
                $hit = strcasecmp($row['code'], $q) === 0;
            } elseif ($terms !== []) {
                $hay = $row['code'] . ' ' . $row['doc'] . ' ' . $row['symptom'] . ' '
                    . $row['action'] . ' ' . $row['owner'] . ' ' . $row['note'] . ' '
                    . (self::STAGES[$row['stage']] ?? '') . ' '
                    . (self::GROUPS[$row['group']]['label'] ?? '');
                $hit = false;
                foreach ($terms as $term) {
                    // 第三参是偏移量，不是编码（PHP 8 曾在此踩过 TypeError）
                    if (mb_stripos($hay, (string) $term) !== false) {
                        $hit = true;
                        break;
                    }
                }
            } else {
                $hit = false;
            }
            if ($hit) {
                $count++;
                if ($first === '') {
                    $first = (string) $row['code'];
                }
            }
        }
        return ['count' => $count, 'first' => $first];
    }

    /** @var array<int,array{code:string,group:string,doc:string,symptom:string,stage:string,action:string,owner:string,note:string}> */
    private const ROWS = [
        // ---------- A. 工单 / 领料（11 条） ----------
        ['code' => 'GR-01', 'group' => 'GR', 'doc' => '工单用料清单', 'symptom' => '产品缺少产品流程', 'stage' => 'mes_data',
         'action' => '在 MES 产品流程中维护该料号的流程', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-02', 'group' => 'GR', 'doc' => '工单用料清单', 'symptom' => '备料不可以和母件料品相同', 'stage' => 'mes_data',
         'action' => '工单料件需求里，子项料号不能与产品料号相同，改掉重复项', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-03', 'group' => 'GR', 'doc' => '工单用料清单', 'symptom' => '请传入备料信息', 'stage' => 'mes_data',
         'action' => '工单料件需求中维护子项料号', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-04', 'group' => 'GR', 'doc' => '工单用料清单', 'symptom' => '工单流程缺少制程信息', 'stage' => 'mes_data',
         'action' => '工单信息中维护生产流程（制程）', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-05', 'group' => 'GR', 'doc' => '工单用料清单', 'symptom' => '未能找到料品', 'stage' => 'fix_upstream',
         'action' => '①查料件增补单是否同步失败；②查同步平台有无该记录，无则补同步料号增补单', 'owner' => 'FPK', 'note' => '最高频报错之一，先查上游'],
        ['code' => 'GR-06', 'group' => 'GR', 'doc' => '工单发料单', 'symptom' => '业务类型与领料单不一致', 'stage' => 'confirm',
         'action' => '先确认 MES 与 ERP 哪一方的订单类型正确，由错的一方修改后重同步', 'owner' => 'FPK/MES/ERP', 'note' => ''],
        ['code' => 'GR-07', 'group' => 'GR', 'doc' => '工单发料单', 'symptom' => '缺少工单用料需求', 'stage' => 'mes_data',
         'action' => '工单料件需求中维护缺少的子项料号', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-08', 'group' => 'GR', 'doc' => '工单发料单', 'symptom' => '来源生产订单不存在', 'stage' => 'fix_upstream',
         'action' => '①查工单用料清单是否同步失败；②查同步平台记录，无则补同步', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-09', 'group' => 'GR', 'doc' => '工单发料单', 'symptom' => '未能找到备料信息', 'stage' => 'mes_data',
         'action' => '工单料件需求中维护子项料号', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'GR-10', 'group' => 'GR', 'doc' => '工单发料单', 'symptom' => 'Data is Null（报错不明确）', 'stage' => 'mes_check',
         'action' => '联系 MES 运维定位具体空字段', 'owner' => 'MES', 'note' => '无自助排查步骤'],
        ['code' => 'GR-11', 'group' => 'GR', 'doc' => '工单发料单', 'symptom' => '实发数量超过限额数量', 'stage' => 'fpk',
         'action' => '联系 FPK 维护人员核对发料限额', 'owner' => 'FPK', 'note' => ''],

        // ---------- B. 销售 / 出货（8 条） ----------
        ['code' => 'XS-01', 'group' => 'XS', 'doc' => '销售订单', 'symptom' => '价税合计 0，不可小于零', 'stage' => 'mes_data',
         'action' => '维护报错行的行总价与行单价', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'XS-02', 'group' => 'XS', 'doc' => '销售订单', 'symptom' => '请传入行-最终价', 'stage' => 'mes_data',
         'action' => '维护报错单据的总价', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'XS-03', 'group' => 'XS', 'doc' => '销售订单', 'symptom' => '未能找到料品', 'stage' => 'fix_upstream',
         'action' => '同 GR-05：查料号增补单同步状态 → 查平台记录 → 补同步', 'owner' => 'FPK', 'note' => '原表责任方为空，按同簇补 FPK'],
        ['code' => 'XS-04', 'group' => 'XS', 'doc' => '出货单', 'symptom' => '负库存（成品仓）', 'stage' => 'stock',
         'action' => '①查成品入库单是否同步失败；②MES 与 ERP 库存流水逐笔对比、核期初期末一致性；③仍不一致找运维', 'owner' => 'FPK/MES/ERP', 'note' => '负库存通用三步'],
        ['code' => 'XS-05', 'group' => 'XS', 'doc' => '出货单', 'symptom' => '未能找到销售订单行', 'stage' => 'erp_make',
         'action' => '①ERP 手动加行；②若尚未发货，可在 ERP 删单后从 MES 重新同步', 'owner' => 'FPK/MES/ERP', 'note' => ''],
        ['code' => 'XS-06', 'group' => 'XS', 'doc' => '出货单', 'symptom' => '未能找到销售订单', 'stage' => 'fix_upstream',
         'action' => '①先修同步失败的销售订单；②历史单据（早于 2026-03-01）联系 ERP/FPK 在 ERP 补录', 'owner' => 'FPK/ERP', 'note' => ''],
        ['code' => 'XS-07', 'group' => 'XS', 'doc' => '销售退货单', 'symptom' => '当前单据已生成', 'stage' => 'close_sync',
         'action' => '已在 ERP 生成的，联系 FPK 在同步平台关闭本单同步；未生成的找 MES/ERP 运维', 'owner' => 'FPK/MES/ERP', 'note' => '重复同步典型'],
        ['code' => 'XS-08', 'group' => 'XS', 'doc' => '销售退货单', 'symptom' => '未能找到出货单', 'stage' => 'fix_upstream',
         'action' => '①先修复货单；②历史单据在 ERP 手工做单', 'owner' => 'FPK', 'note' => ''],

        // ---------- C. 主数据（7 条） ----------
        ['code' => 'ZD-01', 'group' => 'ZD', 'doc' => '料号增补', 'symptom' => '当前料件不存在料件形态', 'stage' => 'mes_data',
         'action' => '在料件信息中维护料件形态', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'ZD-02', 'group' => 'ZD', 'doc' => '料号增补', 'symptom' => '当前料件不存在规格', 'stage' => 'mes_data',
         'action' => '在料件信息中维护规格', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'ZD-03', 'group' => 'ZD', 'doc' => '料号增补', 'symptom' => '单位配置不在 AppSetting', 'stage' => 'mes_check',
         'action' => '联系 MES 运维补充单位配置', 'owner' => 'MES', 'note' => ''],
        ['code' => 'ZD-04', 'group' => 'ZD', 'doc' => '料号更新单', 'symptom' => '体积单位参数不允许修改', 'stage' => 'confirm',
         'action' => 'ERP 确认哪部分不可修改并复原，MES 侧做后续卡控', 'owner' => 'FPK/ERP', 'note' => ''],
        ['code' => 'ZD-05', 'group' => 'ZD', 'doc' => 'BOM 新增', 'symptom' => '未能找到子项料品', 'stage' => 'mes_data',
         'action' => '在对应 BOM 中更新该子项料号', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'ZD-06', 'group' => 'ZD', 'doc' => 'BOM 新增', 'symptom' => '空引用（BOM 料号或单据被删）', 'stage' => 'mes_check',
         'action' => '联系 FPK 处理空引用记录', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'ZD-07', 'group' => 'ZD', 'doc' => 'BOM 新增', 'symptom' => '版本日期范围重叠', 'stage' => 'confirm',
         'action' => 'ERP 无该版本则找双方运维；有则核对版本号：相同则关同步、不同找运维', 'owner' => 'FPK/MES/ERP', 'note' => ''],

        // ---------- D. 入库（5 条） ----------
        ['code' => 'RK-01', 'group' => 'RK', 'doc' => '收料入库单', 'symptom' => '仓库信息为空', 'stage' => 'mes_data',
         'action' => '该收料料号尚未入库，联系 FPK 收料人员在 MES 做收料入库', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'RK-02', 'group' => 'RK', 'doc' => '收料入库单', 'symptom' => '未能找到采购订单', 'stage' => 'erp_make',
         'action' => '历史单据（早于 2026-03-01）联系 ERP/FPK 在 ERP 添加；若 PO 被删，退回全部料、ERP 重下 PO 再收料', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'RK-03', 'group' => 'RK', 'doc' => '收料入库单', 'symptom' => '料品与采购订单行不一致', 'stage' => 'mes_data',
         'action' => '①删 ERP PO 对应行；②MES 做收料退回；③ERP 重下 PO 后重新收料', 'owner' => 'FPK', 'note' => '三步顺序不能颠倒'],
        ['code' => 'RK-04', 'group' => 'RK', 'doc' => '成品入库单', 'symptom' => '来源生产订单不存在', 'stage' => 'fix_upstream',
         'action' => '同 GR-08：先查工单用料清单同步状态，再补同步', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'RK-05', 'group' => 'RK', 'doc' => '成品入库单', 'symptom' => '生产订单与完工报告业务类型不一致', 'stage' => 'confirm',
         'action' => '同 GR-06：先确认哪方类型正确，改错的一方', 'owner' => 'FPK/MES/ERP', 'note' => ''],

        // ---------- E. 委外（4 条） ----------
        ['code' => 'WW-01', 'group' => 'WW', 'doc' => '委外采购领料单', 'symptom' => '工单已被删除', 'stage' => 'fpk',
         'action' => '联系 FPK 恢复或重建工单后重同步', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'WW-02', 'group' => 'WW', 'doc' => '委外采购领料单', 'symptom' => '委外仓负库存', 'stage' => 'stock',
         'action' => '①查半成品入库单等上游是否同步失败；②MES 与 ERP 库存流水对比；③仍不一致找运维', 'owner' => 'FPK/MES/ERP', 'note' => ''],
        ['code' => 'WW-03', 'group' => 'WW', 'doc' => '委外采购领料单', 'symptom' => 'Object reference not set（报错不明确）', 'stage' => 'mes_check',
         'action' => '联系 MES 运维抓日志定位空对象', 'owner' => 'MES', 'note' => '无自助排查步骤'],
        ['code' => 'WW-04', 'group' => 'WW', 'doc' => '委外回收', 'symptom' => '无效委外入库单号 / 工单没有流程', 'stage' => 'mes_check',
         'action' => '①查委外回收单的产品条码状态，为空找 MES 运维；②查生产订单流程，为空由 FPK 补流程', 'owner' => 'FPK/MES', 'note' => '一条混两个成因，按条码状态/流程分两步查'],

        // ---------- F. 退料（3 条） ----------
        ['code' => 'TK-01', 'group' => 'TK', 'doc' => '库存退料单', 'symptom' => '未能找到收货单', 'stage' => 'fix_upstream',
         'action' => '历史单据由 ERP 补录；先修同步失败的上游收料入库单，再同步本单', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'TK-02', 'group' => 'TK', 'doc' => '库存退料单', 'symptom' => '负库存（物料仓）', 'stage' => 'stock',
         'action' => '同 XS-04 负库存三步：查上游同步 → 库存流水对比 → 找运维', 'owner' => 'FPK/MES/ERP', 'note' => ''],
        ['code' => 'TK-03', 'group' => 'TK', 'doc' => '工单退料单', 'symptom' => '报错为空（无消耗记录，未同步）', 'stage' => 'fix_upstream',
         'action' => '查工单发料单是否同步失败，先修上游再同步本单', 'owner' => 'FPK', 'note' => '原表报错为空，已归一为显式关键字'],

        // ---------- G. 转拨（3 条） ----------
        ['code' => 'ZB-01', 'group' => 'ZB', 'doc' => '工单发料转拨单', 'symptom' => '负库存', 'stage' => 'stock',
         'action' => '同 XS-04 负库存三步', 'owner' => 'FPK/MES/ERP', 'note' => ''],
        ['code' => 'ZB-02', 'group' => 'ZB', 'doc' => '库存转拨单', 'symptom' => '负库存', 'stage' => 'stock',
         'action' => '同 XS-04 负库存三步', 'owner' => 'FPK/MES/ERP', 'note' => ''],
        ['code' => 'ZB-03', 'group' => 'ZB', 'doc' => '库存转拨单', 'symptom' => '未能找到料品', 'stage' => 'fix_upstream',
         'action' => '同 GR-05：查料号增补单同步状态后补同步', 'owner' => 'FPK', 'note' => '原表单据类型列为空，归属待人工确认'],

        // ---------- H. 采购（2 条） ----------
        ['code' => 'CG-01', 'group' => 'CG', 'doc' => '采购需求', 'symptom' => '未能找到料品', 'stage' => 'fix_upstream',
         'action' => '同 GR-05：查料号增补单同步状态后补同步', 'owner' => 'FPK', 'note' => ''],
        ['code' => 'CG-02', 'group' => 'CG', 'doc' => '采购需求', 'symptom' => '当前单据已生成', 'stage' => 'close_sync',
         'action' => '同 XS-07：ERP 已有则联系 FPK 关闭同步平台该单', 'owner' => 'FPK', 'note' => ''],
    ];
}
