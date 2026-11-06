<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Logger;
use App\Core\QueryTerms;
use App\Core\Request;
use App\Core\Setting;
use App\Core\View;
use App\Data\U9Errors;
use App\Models\CaseModel;
use App\Models\Category;
use App\Models\MesMenu;
use App\Models\Position;
use App\Models\Process;
use App\Models\PinyinTerm;
use App\Models\Synonym;

/**
 * 前台首页与检索（需求 3.1 首页展示 / 3.2 模糊搜索 / 4.8 前端用户交互）。
 */
class HomeController
{
    public function index(): void
    {
        // 访问前先流转超期案例，避免前台展示过期内容
        CaseModel::expireOverdue();

        $sort        = (string) Setting::get('home.sort', 'latest');
        $latestCount = Setting::int('home.latest_count', 9);
        $hotCount    = Setting::int('home.hot_count', 8);
        $categoryId  = Request::int('category_id');

        // 首页双栏（系统操作手册 / MES 版本更新）共用同一上限，保证左右条数一致、布局不失衡
        $sideLimit   = 10;

        $this->render('front/home', [
            'categories'   => Category::tree(true),
            'processes'    => Process::withCaseCount(true),
            'positions'    => Position::withManualCount(true),
            'changelogs'   => CaseModel::search(['doc_types' => ['changelog']], 1, $sideLimit)['items'],
            'latestCases'  => CaseModel::featured('latest', $latestCount, $categoryId),
            'weeklyTop'    => CaseModel::weeklyTop(Setting::int('home.week_count', 5)),
            'hotCases'     => CaseModel::featured('hot', $hotCount),
            // 系统操作手册：首页只放单一入口 + 最新若干条（与右栏版本更新条数保持一致，避免双栏失衡）
            'manualTotal'         => Position::manualTotal(true),
            'manualPositionCount' => Position::manualPositionCount(),
            'latestManuals'       => CaseModel::latestManuals($sideLimit),
            'activeSort'   => $sort,
            'categoryId'   => $categoryId,
            'totalCases'   => CaseModel::statusCounts()['published'] ?? 0,
            'suggestions'  => CaseModel::suggestions('', 8),
            'topKeywords'  => CaseModel::topKeywords(8),
            // 首页底部「MES→U9 同步查询」入口（2026-10-22 替换原「按工序分类浏览」网格）
            'u9Groups'      => U9Errors::GROUPS,
            'u9GroupCounts' => U9Errors::groupCounts(),
            'u9Total'       => count(U9Errors::all()),
        ], '首页');
    }

    /**
     * 故障案例：工序按钮筛选 + 案例列表（2026-10-22 二次改版）。
     *
     * 第一版是「工序卡片 hub，点卡再跳检索」，实测多一次跳转、卡片占满首屏，
     * 而用户要的是「直接看文章、想看某工序就点一下」。改为：
     * 顶部一排工序按钮（全部 + 定稿 7 工序 + 其他分类），下方直接是案例列表；
     * 点按钮走 ?process_id=X（服务端渲染，无 JS 也能用），只换下半页内容。
     */
    public function cases(): void
    {
        $processId = Request::int('process_id');
        $page      = max(1, Request::int('page', 1));

        $processes = Process::enabled();
        // 按钮篇数与列表同口径：都只算 doc_type='case' 的已发布文档
        $counts = Process::publishedDocCounts('case', true);

        // 定稿 7 工序：顺序按线缆生产流程，不按数据库 id（id 顺序是历史遗留，读起来是乱的）
        $hubOrder = [
            '绞线'     => '多根单线绞合成导体，控制节距、张力与绞向',
            '绝缘押出' => '导体外挤包绝缘层，控制线径、偏心与外观',
            '成缆'     => '多芯线绞合成缆，含填充、包带与节径控制',
            '编织'     => '屏蔽层编织，控制覆盖率、密度与张力',
            '护套押出' => '缆芯外挤包护套，控制厚度、外观与印字',
            '倒线'     => '大盘转小盘分切，处理接头、计米与盘具',
            '数据缆'   => '网线等数据缆的成缆与电气性能验证（试验项目）',
        ];

        $byName = [];
        foreach ($processes as $row) {
            $byName[(string) $row['name']] = $row;
        }

        $tabs       = [];
        $known      = [];
        $activeName = '';
        foreach ($hubOrder as $name => $desc) {
            if (!isset($byName[$name])) {
                continue;
            }
            $pid         = (int) $byName[$name]['id'];
            $known[$pid] = true;
            $tabs[]      = [
                'id'    => $pid,
                'name'  => $name,
                'desc'  => $desc,
                'count' => (int) ($counts[$pid] ?? 0),
            ];
            if ($pid === $processId) {
                $activeName = $name;
            }
        }

        // 非定稿工序若仍有已发布案例（现网「其它」「标签」「仓库」），
        // 收进一条弱化的「其他分类」，否则这批文档在导航里彻底消失。
        $others = [];
        foreach ($processes as $row) {
            $pid = (int) $row['id'];
            if (isset($known[$pid])) {
                continue;
            }
            $count = (int) ($counts[$pid] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $others[] = ['id' => $pid, 'name' => (string) $row['name'], 'count' => $count];
            if ($pid === $processId) {
                $activeName = (string) $row['name'];
            }
        }

        // 传了不存在/已停用的 process_id：归零回「全部」，
        // 否则会出现「列表空、没有任何按钮高亮」的迷惑状态
        if ($processId > 0 && $activeName === '') {
            $processId = 0;
        }

        $totalAll = (int) CaseModel::search(['doc_types' => ['case']], 1, 1)['total'];

        $this->render('front/cases_hub', [
            'tabs'            => $tabs,
            'others'          => $others,
            'totalAll'        => $totalAll,
            'activeProcessId' => $processId,
            'activeName'      => $activeName,
            'result'          => CaseModel::search(
                ['process_id' => $processId, 'doc_types' => ['case']],
                $page,
                12
            ),
        ], $activeName !== '' ? $activeName . ' · 故障案例' : '故障案例');
    }

    /**
     * MES → U9 同步报错速查页（43 条）。
     *
     * 现场报错后先在速查表定位「哪个环节的问题、怎么处理、找谁」，
     * 比在群里问一圈快，也避免同一个问题反复问。
     */
    public function u9Sync(): void
    {
        $q     = trim((string) Request::get('q', ''));
        $group = strtoupper(trim((string) Request::get('group', '')));
        $stage = trim((string) Request::get('stage', ''));

        if (!isset(U9Errors::GROUPS[$group])) {
            $group = '';
        }
        if (!isset(U9Errors::STAGES[$stage])) {
            $stage = '';
        }

        // 多词按 OR 匹配：粘贴整句报错时，用空格/标点切开任一词命中即可
        $terms = $q === ''
            ? []
            : preg_split('/[\s\x{3000},，。;；、:：|]+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        // 输入的是错误码（如 GR-05）时只按错误码精确匹配，
        // 否则「同 GR-05」这类交叉引用会把 4 条一起捞出来，反而找不到自己那一条
        $isCodeLookup = preg_match('/^[A-Za-z]{2}-\d{1,3}$/', $q) === 1;

        $visible = [];
        foreach (U9Errors::all() as $row) {
            $hit = true;
            if ($group !== '' && $row['group'] !== $group) {
                $hit = false;
            }
            if ($hit && $stage !== '' && $row['stage'] !== $stage) {
                $hit = false;
            }
            if ($hit && $isCodeLookup) {
                $hit = strcasecmp($row['code'], $q) === 0;
            } elseif ($hit && $terms !== []) {
                $hay = $row['code'] . ' ' . $row['doc'] . ' ' . $row['symptom'] . ' '
                    . $row['action'] . ' ' . $row['owner'] . ' ' . $row['note'] . ' '
                    . (U9Errors::STAGES[$row['stage']] ?? '') . ' '
                    . (U9Errors::GROUPS[$row['group']]['label'] ?? '');
                $hit = false;
                foreach ($terms as $term) {
                    // 第三参是偏移量，不是编码（PHP 8 曾在此踩过 TypeError）
                    if (mb_stripos($hay, (string) $term) !== false) {
                        $hit = true;
                        break;
                    }
                }
            }
            $visible[$row['code']] = $hit;
        }

        $this->render('front/u9_sync', [
            'q'           => $q,
            'group'       => $group,
            'stage'       => $stage,
            'rows'        => U9Errors::all(),
            'groups'      => U9Errors::GROUPS,
            'stages'      => U9Errors::STAGES,
            'groupCounts' => U9Errors::groupCounts(),
            'stageCounts' => U9Errors::stageCounts(),
            'visible'     => $visible,
        ], 'MES → U9 同步排查');
    }

    /**
     * MES 版本更新页：按更新时间倒序展示版本更新日志（更新内容 / 更新后的变化）。
     */
    public function updates(): void
    {
        $page   = max(1, Request::int('page', 1));
        $result = CaseModel::search(['doc_types' => ['changelog']], $page, 10);

        $this->render('front/updates', [
            'result'      => $result,
            'suggestions' => CaseModel::suggestions('', 8),
            'topKeywords' => CaseModel::topKeywords(8),
        ], 'MES 版本更新');
    }

    /**
     * 操作手册：按「功能模块」筛选 + 手册列表（2026-10-24 新建）。
     *
     * 为什么要有这一页：此前 20 条手册只在首页露出最新 5 条，且 100% category_id=0
     * 导致分类导航里不可见——浏览量最高的一条只有 6 次，不是内容没用，是找不到。
     * 手册与案例的导航逻辑刻意保持一致（顶部一排模块按钮 + 下方直接是列表，
     * 点按钮只换下半页，走 ?module=X 服务端渲染，无 JS 也能用）。
     *
     * 模块来自 categories 里 scope='manual' 的功能模块树（与案例的故障类型隔离）。
     */
    public function manuals(): void
    {
        $moduleId = Request::int('module');
        $page     = max(1, Request::int('page', 1));

        // 只展示「至少有 1 条已发布手册」的模块：空模块在导航里是纯噪音
        $modules    = Category::manualModules(true);
        $activeName = '';
        foreach ($modules as $module) {
            if ((int) $module['id'] === $moduleId) {
                $activeName = (string) $module['name'];
            }
        }
        // 传了不存在/已停用的 module：归零回「全部」，
        // 否则会出现「列表空、没有任何按钮高亮」的迷惑状态
        if ($moduleId > 0 && $activeName === '') {
            $moduleId = 0;
        }

        $totalAll = (int) CaseModel::search(['doc_types' => ['manual']], 1, 1)['total'];

        $this->render('front/manuals', [
            'modules'    => $modules,
            'activeId'   => $moduleId,
            'activeName' => $activeName,
            'totalAll'   => $totalAll,
            'result'     => CaseModel::search(
                ['doc_types' => ['manual'], 'category_id' => $moduleId],
                $page,
                12
            ),
        ], $activeName !== '' ? $activeName . ' · 操作手册' : '操作手册');
    }

    /**
     * MES 菜单权限查询页：按关键字匹配菜单 / 编码 / 角色权限，便于给员工账号授权。
     */
    public function permissions(): void
    {
        $keyword = trim((string) Request::get('keyword', ''));
        $module  = trim((string) Request::get('module', ''));
        $role    = trim((string) Request::get('role', ''));
        $page    = max(1, Request::int('page', 1));

        $result = MesMenu::search($keyword, $module, $role, $page, 20);

        $this->render('front/permissions', [
            'keyword'    => $keyword,
            'module'     => $module,
            'role'       => $role,
            'result'     => $result,
            'modules'    => MesMenu::modules(),
            'roles'      => MesMenu::roles(),
            'roleHits'   => $keyword !== '' ? MesMenu::roles($keyword) : [],
            'stats'      => MesMenu::stats(),
            'pinyinTerms' => PinyinTerm::hintPairs($keyword),
        ], 'MES 菜单权限查询');
    }

    public function search(): void
    {
        // trim 是必需的：搜索框粘贴容易带首尾空格/换行，未 trim 时「扫码枪 」与「扫码枪」
        // 在检索上是两个词，既查不到结果，又会在 search_logs 里留一条本不该存在的「无结果词」，
        // 直接污染后台知识缺口队列的统计口径。
        $keyword    = trim((string) Request::get('keyword', ''));
        $processId  = Request::int('process_id');
        $positionId = Request::int('position_id');
        // 标签关键字（案例详情页标签点击进入）
        $tag = trim((string) Request::get('tag', ''));

        // 高级筛选：故障类型支持多选（category_id 可为标量或数组，兼容旧链接单选）
        $rawCategory = $_GET['category_id'] ?? [];
        $rawCategory = is_array($rawCategory) ? $rawCategory : [$rawCategory];
        $categoryIds = [];
        foreach ($rawCategory as $value) {
            $value = (int) $value;
            if ($value > 0) {
                $categoryIds[$value] = $value;
            }
        }
        $categoryIds = array_values($categoryIds);

        // 高级筛选：文档类型支持多选（doc_type 可为标量或数组）
        $rawDocType = $_GET['doc_type'] ?? '';
        $rawDocType = is_array($rawDocType) ? $rawDocType : [$rawDocType];
        $docTypes   = array_values(array_intersect(
            ['case', 'manual', 'changelog'],
            array_map('strval', $rawDocType)
        ));

        $page = max(1, Request::int('page', 1));

        $filters = [
            'keyword'      => $keyword,
            'category_ids' => $categoryIds,
            'process_id'   => $processId,
            'position_id'  => $positionId,
            'doc_types'    => $docTypes,
            'tag'          => $tag,
        ];

        $result = CaseModel::search($filters, $page, 10);

        // 整句未命中时降级：按词切分后改用「最具体且有结果」的那个词重查（2026-10-24）。
        // 用户常把报错原文整句粘进搜索框（「扫码枪 MSQ-200 故障」），整串 LIKE 必然 0 条，
        // 但拆开看内容其实存在。降级后日志记的是降级结果数——用户确实看到了内容，
        // 再记 0 会在后台知识缺口队列里留一条假缺口。
        $degraded = null;
        if ($keyword !== '' && (int) $result['total'] === 0) {
            $degraded = $this->degradeKeyword($filters, $page);
            if ($degraded !== null) {
                $result = $degraded['result'];
            }
        }

        // 记录检索行为（含无结果检索，供后台反哺案例补充）
        if ($keyword !== '') {
            Logger::search($keyword, (int) $result['total']);
        }

        // 无结果且关键词命中同步报错表时，指路 U9 速查表（2026-10-23）：
        // 「搜不到案例」和「这是 MES→U9 同步问题」是两件事，后者有专门的 43 条速查表
        $u9Hint = null;
        if ($keyword !== '' && (int) $result['total'] === 0) {
            $match = U9Errors::matchCount($keyword);
            if ((int) $match['count'] > 0) {
                $u9Hint = ['count' => (int) $match['count'], 'first' => (string) $match['first']];
            }
        }

        $this->render('front/search', [
            'keyword'     => $keyword,
            'processId'   => $processId,
            'positionId'  => $positionId,
            'tag'         => $tag,
            'categoryIds' => $categoryIds,
            'docTypes'    => $docTypes,
            'degraded'    => $degraded,
            'result'       => $result,
            'synonymTerms' => $keyword !== '' ? Synonym::extraTerms($keyword) : [],
            'pinyinTerms'  => PinyinTerm::hintPairs($keyword),
            'categories'  => Category::tree(true),
            'processes'   => Process::enabled(),
            'positions'   => Position::enabled(),
            'suggestions' => CaseModel::suggestions($keyword, 8),
            'topKeywords' => CaseModel::topKeywords(8),
            'u9Hint'      => $u9Hint,
        ], $keyword !== '' ? '检索「' . $keyword . '」' : ($tag !== '' ? '标签「' . $tag . '」' : '案例检索'));
    }

    /**
     * 整句未命中时的降级检索：切词后改用「有结果且命中最少」的词重查。
     *
     * 选「命中最少」而不是「全部词 OR」是为了压噪声：搜「扫码枪 MSQ-200 故障」时，
     * 若把「故障」也并进 OR，会一次涌出十几条只沾「故障」二字的案例，反而更难找。
     * 命中数并列时取更长的词——更长的词通常更具体。
     *
     * @param array<string,mixed> $filters 原检索条件（分类/工序/文档类型等保持不变，只换关键词）
     * @return array{term:string,count:int,alts:array<string,int>,result:array<string,mixed>}|null
     */
    private function degradeKeyword(array $filters, int $page): ?array
    {
        $terms = QueryTerms::candidates((string) ($filters['keyword'] ?? ''));
        if ($terms === []) {
            return null;
        }

        $scored = [];
        foreach ($terms as $term) {
            $filters['keyword'] = $term;
            $hit = (int) CaseModel::search($filters, 1, 1)['total'];
            if ($hit > 0) {
                $scored[$term] = $hit;
            }
        }
        if ($scored === []) {
            return null;
        }

        $best      = '';
        $bestCount = PHP_INT_MAX;
        foreach ($scored as $term => $count) {
            if ($count < $bestCount
                || ($count === $bestCount && mb_strlen($term, 'UTF-8') > mb_strlen($best, 'UTF-8'))) {
                $best      = $term;
                $bestCount = $count;
            }
        }

        // 其余有结果的词作为备选入口交给用户，避免「降级后仍不是我想要的」成为死路
        $alts = $scored;
        unset($alts[$best]);
        arsort($alts);
        $alts = array_slice($alts, 0, 4, true);

        $filters['keyword'] = $best;

        return [
            'term'   => $best,
            'count'  => $bestCount,
            'alts'   => $alts,
            'result' => CaseModel::search($filters, $page, 10),
        ];
    }

    /**
     * 检索联想接口（需求 4.8 检索联想提示）。
     */
    public function suggest(): void
    {
        $keyword = (string) Request::get('keyword', '');
        $items   = CaseModel::suggestions($keyword, 8);
        View::json(['code' => 0, 'data' => $items]);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function render(string $view, array $data, string $title): void
    {
        $data['pageTitle']  = $title;
        $data['siteName']   = (string) Setting::get('site.name', 'MES系统知识库');
        $data['siteIntro']  = (string) Setting::get('site.intro', '');
        $data['copyright']  = (string) Setting::get('site.copyright', '©Yangzengrong 版权所有');
        $data['notice']     = (string) Setting::get('site.notice', '');
        $data['noticeOn']   = Setting::bool('home.notice_enabled', true);
        // 顶部工序导航（导航栏优化：一级导航按工序展示）
        $data['navProcesses']       = Process::enabled();
        $data['navActiveProcessId'] = (int) ($data['processId'] ?? 0);
        View::render($view, $data, 'front/layout');
    }
}
