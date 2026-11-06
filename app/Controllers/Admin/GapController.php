<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;

/**
 * 知识缺口（内容运营队列）。
 *
 * 【为什么做这个页】
 * 系统其实一直在采集两类“内容需求”信号，但都只停留在“展示”：
 *   ① search_logs 里 result_count = 0 的检索词 —— 员工搜了，库里没有；
 *   ② feedbacks 里已办结（resolved/closed）但 case_id = 0 的反馈 —— 运维解决了，没沉淀。
 * 看板能看到Top词，反馈列表能看到单条反馈，但没有任何地方把“该写什么内容”汇总成队列。
 * 本页只做聚合与跳转：不采新数据、不改原链路（转化仍走 feedback_detail 的现有表单）。
 *
 * 【门槛为什么是 2 次】单人一次手滑/错别字也会产生无结果检索，
 * 同一个词 ≥2 次无结果才构成真实缺口，避免队列被噪声灌满。
 */
class GapController extends BaseController
{
    public function index(): void
    {
        $this->guard('feedback.view');

        // 无结果检索词：按无结果次数降序（缺口深的优先），带总检索次数与最近一次时间
        $gapWords = Database::select(
            'SELECT keyword,
                    COUNT(*) AS times,
                    SUM(CASE WHEN result_count = 0 THEN 1 ELSE 0 END) AS no_result,
                    MAX(created_at) AS last_at
             FROM search_logs
             WHERE result_count = 0
             GROUP BY keyword
             HAVING no_result >= 2
             ORDER BY no_result DESC, times DESC
             LIMIT 15'
        );

        // 已办结未转案例的反馈（case_id 默认 0 = 未转；兼容历史 NULL 写法）
        $unconverted = Database::select(
            'SELECT f.id, f.code, f.title, f.department, f.handled_at, p.name AS process_name
             FROM feedbacks f
             LEFT JOIN processes p ON p.id = f.process_id
             WHERE f.deleted_at IS NULL
               AND f.status IN (\'resolved\', \'closed\')
               AND (f.case_id IS NULL OR f.case_id = 0)
             ORDER BY f.handled_at DESC, f.id DESC
             LIMIT 50'
        );

        // 近 7 天无结果检索总量（反映“查不到”的整体水位，不受 ≥2 门槛影响）
        $recentNoResult = (int) Database::scalar(
            'SELECT COUNT(*) FROM search_logs
             WHERE result_count = 0 AND created_at >= :since',
            ['since' => date('Y-m-d H:i:s', strtotime('-7 days'))]
        );

        $this->render('admin/gaps', [
            'activeMenu'     => 'gaps',
            'gapWords'       => $gapWords,
            'unconverted'    => $unconverted,
            'recentNoResult' => $recentNoResult,
        ], '知识缺口');
    }
}
