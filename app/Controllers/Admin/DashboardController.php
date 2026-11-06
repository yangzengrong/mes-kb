<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Models\Attachment;
use App\Models\CaseEffectiveness;
use App\Models\CaseModel;
use App\Models\LogModel;

/**
 * 数据看板（需求 4.5 数据统计与看板模块）。
 */
class DashboardController extends BaseController
{
    public function index(): void
    {
        $this->guard('dashboard.view');

        // 处理超期案例，保证看板数据与实际状态一致
        CaseModel::expireOverdue();

        /* 趋势周期切换（14 / 30 / 90 天，2026-10-18）。
           【为什么加切换而不是只调大默认值】
           实测默认 14 天时：14 根柱子里 10 根是 0，柱子全是 2px 最小高度，
           视觉上就是一片平坦的线 —— 因为内容集中在 9 月批量录入。
           但改成默认 90 天也不对：平时只想看「最近还在不在录」时，
           90 天会把偶发的一次批量录入摊薄得看不出来。
           所以让使用者自己选，默认仍 14 天（贴近日常运营视角）。 */
        $trendDays = Request::int('trend_days', 14);
        if (!in_array($trendDays, [14, 30, 90], true)) {
            $trendDays = 14;
        }
        $trend = CaseModel::dailyTrend($trendDays);

        $statusCounts = CaseModel::statusCounts();
        $storage      = Attachment::storageUsage();
        $auth         = Auth::instance();

        $myStats = ['total' => 0, 'published' => 0, 'pending' => 0];
        if (!$auth->isSuperAdmin()) {
            $row = Database::selectOne(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = \'published\' THEN 1 ELSE 0 END) AS published,
                        SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pending
                 FROM cases WHERE deleted_at IS NULL AND author_id = :uid',
                ['uid' => $auth->id()]
            );
            $myStats = [
                'total'     => (int) ($row['total'] ?? 0),
                'published' => (int) ($row['published'] ?? 0),
                'pending'   => (int) ($row['pending'] ?? 0),
            ];
        }

        $this->render('admin/dashboard', [
            'activeMenu'    => 'dashboard',
            'statusCounts'  => $statusCounts,
            'processStats'  => CaseModel::countByProcess(),
            // 下钻：单个工序内 7 类故障类型分布（?drill=<process_id>）
            'drillId'       => Request::int('drill'),
            'drillStats'    => Request::int('drill') > 0
                ? CaseModel::countByCategoryForProcess(Request::int('drill'))
                : [],
            'categoryStats' => CaseModel::countByCategory(),
            'monthCount'    => CaseModel::countThisMonth(),
            'topViewed'     => CaseModel::topViewed(10),
            'topKeywords'   => CaseModel::topKeywords(10),
            'noResultWords' => CaseModel::noResultKeywords(10),
            'trend'         => $trend,
            'trendDays'     => $trendDays,
            'trendSummary'  => CaseModel::trendSummary($trend),
            // 与案例列表同口径：运维管理员只统计本人内容，避免「看板 17 → 列表 3」
            'zeroViewCount' => CaseModel::zeroViewCount($auth->isSuperAdmin() ? 0 : $auth->id()),
            'storage'       => $storage,
            'logSummary'    => LogModel::summary(),
            'trashCount'    => CaseModel::trashCount(),
            'userCount'     => (int) Database::scalar('SELECT COUNT(*) FROM users'),
            'categoryCount' => (int) Database::scalar('SELECT COUNT(*) FROM categories'),
            'processCount'  => (int) Database::scalar('SELECT COUNT(*) FROM processes WHERE status = 1'),
            'myStats'       => $myStats,
            // 内容保鲜（断点D）：有效性反馈待复核清单 + 整体概况
            'reviewQueue'   => CaseEffectiveness::reviewQueue(8, 20),
            'effOverview'   => CaseEffectiveness::overview(20),
        ], '数据看板');
    }
}
