<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\CaseTag;
use App\Core\HtmlSanitizer;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Setting;
use App\Core\Upload;
use App\Core\Validator;
use App\Core\View;
use App\Models\Attachment;
use App\Models\CaseModel;
use App\Models\Category;
use App\Models\Position;
use App\Models\Process;
use App\Models\PinyinTerm;

/**
 * 故障案例全生命周期管理（需求 4.3）。
 */
class CaseController extends BaseController
{
    /** 允许提交的案例字段（报错代码字段已按需求取消，不再读写） */
    private const FIELDS = [
        'title', 'category_id', 'device', 'tags', 'phenomenon',
        'cause', 'solution', 'prevention', 'handler', 'handled_at',
    ];

    public function index(): void
    {
        $this->guard('case.view');
        $auth = Auth::instance();

        $docType = (string) Request::get('doc_type', '');
        if (!array_key_exists($docType, doc_type_map())) {
            $docType = '';
        }

        $filters = [
            'keyword'     => (string) Request::get('keyword', ''),
            'category_id' => Request::int('category_id'),
            'process_id'  => Request::int('process_id'),
            'status'      => (string) Request::get('status', ''),
            'doc_type'    => $docType,
            'trashed'     => Request::get('trashed') === '1',
            // 看板「发布后无人看」下钻：?zero_view=1（2026-10-26）
            'zero_view'   => Request::get('zero_view') === '1',
            // 运维管理员只能看到自己创建的案例
            'author_id'   => $auth->isSuperAdmin() ? 0 : $auth->id(),
        ];

        $page   = max(1, Request::int('page', 1));
        $result = CaseModel::paginateByFilters($filters, $page, 15);

        // 列表页一次性取回本页案例的附件，避免逐条查询
        $attachments = Attachment::groupByCases(array_column($result['items'], 'id'));

        $activeMenu = $filters['trashed'] ? 'recycle' : (['manual' => 'manuals', 'changelog' => 'changelogs'][$docType] ?? 'cases');
        $listTitles = [
            ''          => '故障案例管理',
            'manual'    => '系统操作手册管理',
            'changelog' => 'MES版本更新管理',
        ];

        $this->render('admin/cases', [
            'activeMenu'  => $activeMenu,
            'docType'     => $docType,
            'filters'     => $filters,
            'result'      => $result,
            // 列表筛选下拉与文档类型配套：手册管理页筛功能模块，案例页筛故障类型
            'categories'  => Category::options(false, $docType === 'manual' ? 'manual' : 'case'),
            'processes'   => Process::options(),
            'positions'   => Position::options(),
            'attachments' => $attachments,
            'isSuper'     => $auth->isSuperAdmin(),
        ], $listTitles[$docType]);
    }

    public function form(array $params): void
    {
        $this->guard('case.view');
        $id   = (int) ($params['id'] ?? 0);
        $case = null;

        if ($id > 0) {
            $case = CaseModel::findWithMeta($id);
            if ($case === null) {
                $this->back('cases.php', 'error', '案例不存在或已被彻底删除。');
            }
            if (!$this->canEdit($case)) {
                $this->back('cases.php', 'error', '只能编辑自己负责的案例。');
            }
        } else {
            $this->guard('case.create');
        }

        // 新增时允许从独立管理入口带入文档类型（?doc_type=manual/changelog）
        $initDocType = (string) ($case['doc_type'] ?? Request::get('doc_type', 'case'));
        if (!array_key_exists($initDocType, doc_type_map())) {
            $initDocType = 'case';
        }
        $activeMenu = ['manual' => 'manuals', 'changelog' => 'changelogs'][$initDocType] ?? 'cases';

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        $this->render('admin/case_form', [
            'activeMenu' => $activeMenu,
            'case'       => $case,
            'old'        => $old,
            'initDocType' => $initDocType,
            'errors'     => $errors,
            // 手册用功能模块树，案例/更新日志用故障类型树：
            // 语义不同，混在一起会让「新建手册」时可选到「程序报错」这类无关项
            'categories' => Category::options(false, $initDocType === 'manual' ? 'manual' : 'case'),
            'processes'  => Process::options(true),
            'positions'  => Position::options(true),
            'attachments' => $case !== null ? Attachment::byCase((int) $case['id']) : [],
            'versions'   => $case !== null ? CaseModel::versions((int) $case['id']) : [],
            'maxBytes'   => Upload::maxBytes(),
            'allowedExt' => implode('、', Upload::allowedExtensions()),
        ], $id > 0 ? '编辑案例' : '新增案例');
    }

    public function save(): void
    {
        $this->guardPost();

        $id   = Request::int('id');
        $auth = Auth::instance();
        $case = null;

        if ($id > 0) {
            $case = CaseModel::findWithMeta($id);
            if ($case === null) {
                $this->back('cases.php', 'error', '案例不存在。');
            }
            if (!$this->canEdit($case)) {
                $this->back('cases.php', 'error', '只能编辑自己负责的案例。');
            }
        } else {
            $this->guard('case.create');
        }

        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = (string) Request::post($field, '');
        }
        // 富文本字段：服务端白名单净化（防粘贴内容携带脚本 / 事件属性）
        foreach (['phenomenon', 'cause', 'solution', 'prevention'] as $richField) {
            $input[$richField] = HtmlSanitizer::clean($input[$richField]);
        }
        // 标签关键字：统一由 CaseTag 归一化。
        //
        // 【为什么改用 CaseTag】原实现在这里自己 split('/[,，;；]+/')，漏了顿号「、」和斜杠，
        // 而员工习惯用顿号录入（库里 29 条有 14 条如此）。结果「MES、ERP、同步平台」
        // 被当成一个标签存库，按标签搜索「MES」精确匹配不到 —— 这是检索无结果词
        // 里 MES 高居第一的根因。现在分隔符与校验规则收敛到 CaseTag 一处。
        //
        // 【适用性标签】形如「护套:8线」「版本:V3.2」，与关键词标签同存于 tags 列，
        // 靠半角冒号区分，不新增字段（零表结构变更是本项目硬约束）。
        // 适用性标签（断点B）：表单用独立的 scope_tags 输入框录入，
        // 此处与关键词标签合并后统一归一化 —— 两类标签共用 tags 列，
        // 前台按半角冒号区分。分开录入是为了让管理员不必记住「维度:取值」写法。
        $scopeInput    = (string) Request::post('scope_tags', '');
        $merged        = trim($input['tags'] . CaseTag::SEPARATOR . $scopeInput);
        $input['tags'] = CaseTag::normalize($merged);
        $input['category_id'] = (int) $input['category_id'];
        $input['is_top']      = Request::bool('is_top') ? 1 : 0;
        $input['expired_at']  = (string) Request::post('expired_at', '');
        $input['change_note'] = (string) Request::post('change_note', '');
        $input['submit']      = (string) Request::post('submit', 'pending');

        // 线缆专属改版：工序 + 文档类型；岗位操作手册体系：岗位维度
        $input['process_id']  = (int) Request::post('process_id', '0');
        $input['position_id'] = (int) Request::post('position_id', '0');
        $input['doc_type']    = (string) Request::post('doc_type', 'case');
        if (!in_array($input['doc_type'], ['case', 'manual', 'changelog'], true)) {
            $input['doc_type'] = 'case';
        }
        $isFaultCase = $input['doc_type'] === 'case';
        $isManual    = $input['doc_type'] === 'manual';

        // 手册 / 版本日志不使用工序维度（表单已隐藏该字段），提交值一律归零，
        // 避免切换文档类型后残留的工序值入库导致前台统计失真
        if (!$isFaultCase) {
            $input['process_id'] = 0;
        }

        // 内容字段：表单只有一个整体编辑器（phenomenon），
        // cause / solution / prevention 不再由表单提交，强制归空防止残留旧值入库
        $input['cause']      = '';
        $input['solution']   = '';
        $input['prevention'] = '';

        // 按文档类型映射内容字段标题（手册/版本日志使用业务化措辞）
        $contentLabels = [
            'case'      => '案例内容',
            'manual'    => '操作手册内容',
            'changelog' => '更新内容',
        ];
        $contentLabel = $contentLabels[$input['doc_type']];

        $validator = new Validator($input);
        $rules = [
            'title'       => 'required|maxlen:200',
            'phenomenon'  => 'required|maxlen:200000',
            'handled_at'  => 'date',
            'expired_at'  => 'date',
            'submit'      => 'in:pending,published',
        ];
        $labels = [
            'title'       => '标题',
            'phenomenon'  => $contentLabel,
            'handled_at'  => '处理时间',
            'expired_at'  => '过期时间',
        ];
        // 故障案例：工序必填（系统操作手册的匹配岗位改为可选，不选=全岗位通用）
        if ($isFaultCase) {
            $rules['process_id']  = 'required|int';
            $labels['process_id'] = '所属工序';
        }
        $validator->rules($rules, $labels);

        // 故障类型（categories）仅对「故障案例」必填；手册 / 版本日志可选
        if (!$isFaultCase && $input['category_id'] <= 0) {
            unset($input['category_id']);
        }

        // 富文本必填兜底：只有空段落 / 空白（如 <p><br></p>）且无配图时视为未填写
        $textOnly = trim(str_replace(["\xc2\xa0", ' '], '', strip_tags($input['phenomenon'])));
        if ($textOnly === '' && stripos($input['phenomenon'], '<img') === false) {
            $this->backWithErrors(array_merge($input, ['id' => $id]), ['phenomenon' => $contentLabel . '不能为空。'],
                $id > 0 ? 'case_form.php?id=' . $id : 'case_form.php');
        }

        if (!$validator->passes()) {
            $this->backWithErrors(array_merge($input, ['id' => $id]), $validator->errors(),
                $id > 0 ? 'case_form.php?id=' . $id : 'case_form.php');
        }

        // 工序校验：仅故障案例必选且必须存在（手册 / 版本日志已归零）
        if ($isFaultCase && ($input['process_id'] <= 0 || Process::find($input['process_id']) === null)) {
            $this->backWithErrors(array_merge($input, ['id' => $id]), ['process_id' => '故障案例必须选择所属工序。'],
                $id > 0 ? 'case_form.php?id=' . $id : 'case_form.php');
        }
        // 匹配岗位校验：可选（0 = 全岗位通用）；填写了则必须存在
        if ($input['position_id'] > 0 && Position::find($input['position_id']) === null) {
            $this->backWithErrors(array_merge($input, ['id' => $id]), ['position_id' => '所选岗位不存在。'],
                $id > 0 ? 'case_form.php?id=' . $id : 'case_form.php');
        }
        if ($isFaultCase && $input['category_id'] <= 0) {
            $this->backWithErrors(array_merge($input, ['id' => $id]), ['category_id' => '故障案例必须选择故障类型。'],
                $id > 0 ? 'case_form.php?id=' . $id : 'case_form.php');
        }
        if ($isFaultCase && Category::find($input['category_id']) === null) {
            $this->backWithErrors(array_merge($input, ['id' => $id]), ['category_id' => '所选分类不存在。'],
                $id > 0 ? 'case_form.php?id=' . $id : 'case_form.php');
        }

        $payload = [
            'title'       => $input['title'],
            'process_id'  => $input['process_id'],
            'position_id' => $input['position_id'],
            'doc_type'    => $input['doc_type'],
            'category_id' => isset($input['category_id']) ? (int) $input['category_id'] : 0,
            'device'      => $input['device'],
            'tags'        => $input['tags'],
            'phenomenon'  => $input['phenomenon'],
            'cause'       => $input['cause'],
            'solution'    => $input['solution'],
            'prevention'  => $input['prevention'],
            'handler'     => $input['handler'],
            'handled_at'  => $input['handled_at'] !== '' ? $input['handled_at'] : null,
            'expired_at'  => $input['expired_at'] !== '' ? $input['expired_at'] . ' 23:59:59' : null,
            'is_top'      => $input['is_top'],
            'updated_at'  => now(),
        ];

        if ($id > 0) {
            // 更新前留档，保证内容可追溯（需求 4.3 保留版本更新记录）
            CaseModel::snapshot(
                $id,
                $case,
                $auth->id(),
                (string) ($auth->user()['username'] ?? ''),
                $input['change_note'] !== '' ? $input['change_note'] : '内容更新'
            );
            $payload['version_no'] = (int) $case['version_no'] + 1;

            CaseModel::modify($id, $payload);
            $this->log('case.update', 'case', $id, '更新案例：' . $input['title']);
            PinyinTerm::flush();
            $message = '案例已更新，版本号 v' . $payload['version_no'] . '。';
        } else {
            // 系统配置开启「提交即发布」时，运维人员提交的案例直接进入已发布状态
            $autoApprove = Setting::bool('case.auto_approve', false);

            $payload['author_id']  = $auth->id();
            $payload['status']     = $autoApprove ? CaseModel::STATUS_PUBLISHED : CaseModel::STATUS_PENDING;
            $payload['version_no'] = 1;
            $payload['created_at'] = now();
            if ($autoApprove) {
                $payload['published_at'] = now();
                $payload['review_note']  = '系统配置为提交即发布';
            }
            $id = CaseModel::create($payload);

            CaseModel::snapshot($id, $payload, $auth->id(), (string) ($auth->user()['username'] ?? ''), '创建案例');
            $this->log('case.create', 'case', $id, '新增案例：' . $input['title']);
            PinyinTerm::flush();
            $message = $autoApprove ? '案例已提交并直接发布。' : '案例已提交，状态为待审核。';
        }

        // 附件上传（需求 4.4）
        $uploadErrors = $this->handleUploads($id);
        if ($uploadErrors !== []) {
            flash('error', '案例已保存，但部分附件上传失败：' . implode('；', $uploadErrors));
            redirect(admin_url('case_form.php?id=' . $id));
        }

        // 超级管理员可直接发布
        if ($input['submit'] === 'published' && $auth->isSuperAdmin()) {
            $this->applyStatus($id, CaseModel::STATUS_PUBLISHED, '保存时直接发布');
            $message = $input['doc_type'] === 'manual'
                ? '操作手册已保存并发布。'
                : ($input['doc_type'] === 'changelog' ? '版本更新日志已保存并发布。' : '案例已保存并发布。');
        }

        // 跳回对应文档类型的管理列表，保留上下文
        $this->back('cases.php' . ($input['doc_type'] !== 'case' ? '?doc_type=' . $input['doc_type'] : ''), 'success', $message);
    }

    /**
     * 审核：通过 / 驳回（需求 4.3 案例状态管理）。
     */
    public function review(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id     = Request::int('id');
        $action = (string) Request::post('action', '');
        $note   = (string) Request::post('note', '');
        $case   = CaseModel::findWithMeta($id);

        if ($case === null) {
            $this->back('cases.php', 'error', '案例不存在。');
        }

        $auth = Auth::instance();

        if ($action === 'approve') {
            CaseModel::modify($id, [
                'status'      => CaseModel::STATUS_PUBLISHED,
                'reviewed_by' => $auth->id(),
                'reviewed_at' => now(),
                'review_note' => $note !== '' ? $note : '审核通过',
                'published_at' => $case['published_at'] ?? now(),
                'updated_at'  => now(),
            ]);
            $this->log('case.approve', 'case', $id, '审核通过并发布：' . $case['title']);
            $this->back('cases.php', 'success', '案例已审核通过并发布到前台。');
        }

        if ($action === 'reject') {
            CaseModel::modify($id, [
                'status'      => CaseModel::STATUS_OFFLINE,
                'reviewed_by' => $auth->id(),
                'reviewed_at' => now(),
                'review_note' => $note !== '' ? $note : '审核未通过',
                'updated_at'  => now(),
            ]);
            $this->log('case.reject', 'case', $id, '审核驳回：' . $case['title']);
            $this->back('cases.php', 'success', '案例已驳回并置为已下架状态。');
        }

        $this->back('cases.php', 'error', '未知的审核操作。');
    }

    /**
     * 状态流转：下架 / 重新发布 / 标记过期。
     */
    public function status(): void
    {
        $this->guardPost();
        $this->guardSuper();

        $id     = Request::int('id');
        $target = (string) Request::post('status', '');
        $case   = CaseModel::findWithMeta($id);

        if ($case === null) {
            $this->back('cases.php', 'error', '案例不存在。');
        }
        if (!array_key_exists($target, case_status_map())) {
            $this->back('cases.php', 'error', '非法的状态值。');
        }

        $this->applyStatus($id, $target, '手动调整状态');
        $this->back('cases.php', 'success', '案例状态已更新为「' . case_status_label($target) . '」。');
    }

    /**
     * 移入回收站（需求 4.3 案例删除与回收站）。
     */
    public function trash(): void
    {
        $this->guardPost();
        $id   = Request::int('id');
        $case = CaseModel::findWithMeta($id);

        if ($case === null) {
            $this->back('cases.php', 'error', '案例不存在。');
        }
        if (!$this->canEdit($case)) {
            $this->back('cases.php', 'error', '只能删除自己负责的案例。');
        }
        if ($case['deleted_at'] !== null) {
            $this->back('cases.php', 'error', '该案例已在回收站中。');
        }

        CaseModel::moveToTrash($id);
        $this->log('case.trash', 'case', $id, '移入回收站：' . $case['title']);
        $this->back('cases.php', 'success', '案例已移入回收站，可在回收站中恢复。');
    }

    public function restore(): void
    {
        $this->guardPost();
        $id   = Request::int('id');
        $case = CaseModel::findWithMeta($id);

        if ($case === null) {
            $this->back('cases.php?trashed=1', 'error', '案例不存在。');
        }
        if (!$this->canEdit($case)) {
            $this->back('cases.php?trashed=1', 'error', '只能恢复自己负责的案例。');
        }

        CaseModel::restore($id);
        $this->log('case.restore', 'case', $id, '从回收站恢复：' . $case['title']);
        $this->back('cases.php?trashed=1', 'success', '案例已恢复。');
    }

    /**
     * 彻底删除（仅超级管理员，需求 4.3）。
     */
    public function forceDelete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id   = Request::int('id');
        $case = CaseModel::findWithMeta($id);

        if ($case === null) {
            $this->back('cases.php?trashed=1', 'error', '案例不存在。');
        }
        if ($case['deleted_at'] === null) {
            $this->back('cases.php', 'error', '请先将案例移入回收站，再执行彻底删除。');
        }

        // 同步清理磁盘附件
        foreach (Attachment::byCase($id) as $attachment) {
            $path = STORAGE_PATH . '/' . ltrim((string) $attachment['stored_path'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
        }

        CaseModel::forceDelete($id);
        $this->log('case.force_delete', 'case', $id, '彻底删除案例：' . $case['title']);
        $this->back('cases.php?trashed=1', 'success', '案例及其附件已彻底删除，不可恢复。');
    }

    /**
     * 批量绑定所属工序（线缆专属改版：历史数据兼容）。
     * 仅超级管理员可操作；勾选多条案例一次性绑定到指定工序。
     */
    public function bulkProcess(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $ids       = array_map('intval', (array) Request::post('ids', []));
        $ids       = array_values(array_filter(array_unique($ids), static fn (int $v): bool => $v > 0));
        $processId = (int) Request::post('process_id', '0');

        if ($ids === []) {
            $this->back('cases.php', 'error', '请先勾选要绑定的案例。');
        }
        if ($processId <= 0 || Process::find($processId) === null) {
            $this->back('cases.php', 'error', '请选择要绑定的工序。');
        }

        $placeholders = [];
        $params       = ['pid' => $processId, 't' => now()];
        foreach ($ids as $index => $caseId) {
            $key                 = 'id' . $index;
            $placeholders[]      = ':' . $key;
            $params[$key]        = $caseId;
        }

        $affected = \App\Core\Database::execute(
            'UPDATE cases SET process_id = :pid, updated_at = :t WHERE id IN (' . implode(',', $placeholders) . ')',
            $params
        );

        $process = Process::find($processId);
        $this->log('case.bulk_process', 'case', null,
            '批量绑定工序「' . $process['name'] . '」，涉及 ' . count($ids) . ' 条案例');
        $this->back('cases.php', 'success',
            '已将 ' . $affected . ' 条案例绑定到工序「' . $process['name'] . '」。');
    }

    /**
     * 批量状态流转（审核通过 / 驳回 / 下架 / 上架 / 标记过期）。
     *
     * 【为什么不写成一条 UPDATE ... WHERE id IN (...)】
     * 单条版 status() 走 applyStatus()，里面有三件事一条 SQL 做不到：
     *   ① 上架时算 published_at 与 expired_at（依赖每条自己的历史状态）
     *   ② 记录 reviewed_by / reviewed_at 审核人
     *   ③ 逐条写操作日志
     * 图快写成一条 UPDATE 会丢掉以上全部，事后无法追溯是谁在什么时候上架的。
     * 数据量是 79 条级别，逐条调用完全够快，正确性优先。
     *
     * 【为什么部分失败不中断】
     * 批量场景下用户期望「能改的都改了」并知道哪些没改成。
     * 因此逐条校验、逐条执行，最后汇总成功/跳过/失败三个数字回报，
     * 而不是遇到第一条就 die —— 后者会让用户白勾一遍。
     */
    public function bulkStatus(): void
    {
        $this->guardPost();
        $this->guardSuper();

        $ids     = $this->bulkIds();
        $target  = (string) Request::post('status', '');
        $note    = (string) Request::post('note', '');

        if ($ids === []) {
            $this->back('cases.php', 'error', '请先勾选要处理的案例。');
        }
        if (!array_key_exists($target, case_status_map())) {
            $this->back('cases.php', 'error', '非法的目标状态。');
        }

        $ok        = 0;
        $skipped   = 0;
        $failed    = [];
        $isApprove = $target === CaseModel::STATUS_PUBLISHED;

        foreach ($ids as $caseId) {
            $case = CaseModel::findWithMeta($caseId);
            if ($case === null) {
                $failed[] = '#' . $caseId . ' 不存在';
                continue;
            }
            /* 已在回收站的条目不参与状态流转 —— 它该去的是「恢复」 */
            if ($case['deleted_at'] !== null) {
                $skipped++;
                continue;
            }
            /* 已经是目标状态就跳过，避免把 updated_at 和日志刷成噪音 */
            if ((string) $case['status'] === $target) {
                $skipped++;
                continue;
            }

            if ($isApprove) {
                CaseModel::modify($caseId, [
                    'status'       => CaseModel::STATUS_PUBLISHED,
                    'reviewed_by'  => Auth::instance()->id(),
                    'reviewed_at'  => now(),
                    'review_note'  => $note !== '' ? $note : '批量审核通过',
                    'published_at' => $case['published_at'] ?? now(),
                    'updated_at'   => now(),
                ]);
                $this->log('case.bulk_approve', 'case', $caseId,
                    '批量审核通过：' . $case['title']);
            } else {
                $this->applyStatus($caseId, $target, '批量调整状态');
            }
            $ok++;
        }

        $label = case_status_label($target);
        $msg   = '已将 ' . $ok . ' 条案例更新为「' . $label . '」。';
        if ($skipped > 0) {
            $msg .= ' 跳过 ' . $skipped . ' 条（已在回收站或状态已是目标值）。';
        }
        if ($failed !== []) {
            $msg .= ' 失败 ' . count($failed) . ' 条：' . implode('、', array_slice($failed, 0, 3));
        }
        $this->back('cases.php', $ok > 0 ? 'success' : 'error', $msg);
    }

    /**
     * 批量移入回收站 / 批量恢复 / 批量彻底删除（回收站视图用）。
     *
     * 三个动作放一起是因为它们共用同一套「取 ids → 逐条校验 → 逐条执行 → 汇总回报」结构。
     * 与单条版的差别只有 action 分支和校验条件，拆成三个方法反而重复三遍同样的样板。
     */
    public function bulkItems(): void
    {
        $this->guardPost();

        $ids    = $this->bulkIds();
        $action = (string) Request::post('action', '');

        if ($ids === []) {
            $this->back('cases.php', 'error', '请先勾选要处理的案例。');
        }
        if (!in_array($action, ['trash', 'restore', 'force_delete'], true)) {
            $this->back('cases.php', 'error', '未知的批量操作。');
        }

        /* 彻底删除是不可逆的破坏性操作，必须仅超管可执行 ——
           单条版 forceDelete() 也是这么要求的，两处保持一致。
           移入回收站/恢复允许普通编辑者操作自己负责的内容（沿用单条版 canEdit 口径）。 */
        if ($action === 'force_delete') {
            $this->guardSuper();
        }

        $ok      = 0;
        $skipped = 0;
        $failed  = [];

        foreach ($ids as $caseId) {
            $case = CaseModel::findWithMeta($caseId);
            if ($case === null) {
                $failed[] = '#' . $caseId . ' 不存在';
                continue;
            }

            if ($action === 'force_delete') {
                if ($case['deleted_at'] === null) {
                    $skipped++;   // 未进回收站的要先走回收站，避免误删在用内容
                    continue;
                }
                /* 必须先清磁盘附件：CaseModel::forceDelete 只删数据库记录，
                   少了这段会把上传的文件永久留在磁盘上成为孤儿文件。
                   与单条版 forceDelete() 的处理保持一致。 */
                foreach (Attachment::byCase($caseId) as $attachment) {
                    $file = STORAGE_PATH . '/' . ltrim((string) $attachment['stored_path'], '/');
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                CaseModel::forceDelete($caseId);
                $this->log('case.bulk_force_delete', 'case', $caseId, '批量彻底删除：' . $case['title']);
                $ok++;
                continue;
            }

            if (!$this->canEdit($case)) {
                $failed[] = '#' . $caseId . ' 无权限';
                continue;
            }

            if ($action === 'trash') {
                if ($case['deleted_at'] !== null) {
                    $skipped++;
                    continue;
                }
                CaseModel::moveToTrash($caseId);
                $this->log('case.bulk_trash', 'case', $caseId, '批量移入回收站：' . $case['title']);
                $ok++;
                continue;
            }

            /* restore */
            if ($case['deleted_at'] === null) {
                $skipped++;
                continue;
            }
            CaseModel::restore($caseId);
            $this->log('case.bulk_restore', 'case', $caseId, '批量恢复：' . $case['title']);
            $ok++;
        }

        $names = [
            'trash'        => '移入回收站',
            'restore'      => '恢复',
            'force_delete' => '彻底删除',
        ];
        $name    = $names[$action];
        $msg     = '已' . $name . ' ' . $ok . ' 条。';
        if ($skipped > 0) {
            $msg .= ' 跳过 ' . $skipped . ' 条（状态不符）。';
        }
        if ($failed !== []) {
            $msg .= ' 失败 ' . count($failed) . ' 条：' . implode('、', array_slice($failed, 0, 3));
        }
        $this->back('cases.php' . ($action !== 'trash' ? '?trashed=1' : ''),
            $ok > 0 ? 'success' : 'error', $msg);
    }

    /**
     * 取出并归一化批量选择的 id 列表。
     *
     * 三个批量动作共用。单独抽出来是为了让「去重 / 过滤非法值」只写一处 ——
     * 之前 bulkProcess 里内联了同样的逻辑，再写三遍就是第四遍。
     *
     * @return array<int,int>
     */
    private function bulkIds(): array
    {
        $ids = array_map('intval', (array) Request::post('ids', []));
        return array_values(array_filter(array_unique($ids), static fn (int $v): bool => $v > 0));
    }

    /**
     * 版本回滚：将历史版本内容写回案例。
     */
    public function rollback(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $caseId    = Request::int('case_id');
        $versionId = Request::int('version_id');
        $case      = CaseModel::findWithMeta($caseId);

        if ($case === null) {
            $this->back('cases.php', 'error', '案例不存在。');
        }

        $version = \App\Core\Database::selectOne(
            'SELECT * FROM case_versions WHERE id = :id AND case_id = :cid LIMIT 1',
            ['id' => $versionId, 'cid' => $caseId]
        );
        if ($version === null) {
            $this->back('case_form.php?id=' . $caseId, 'error', '该版本记录不存在。');
        }

        $snapshot = json_decode((string) $version['snapshot'], true);
        if (!is_array($snapshot)) {
            $this->back('case_form.php?id=' . $caseId, 'error', '版本快照已损坏，无法回滚。');
        }

        $auth = Auth::instance();
        CaseModel::snapshot($caseId, $case, $auth->id(), (string) ($auth->user()['username'] ?? ''),
            '回滚前留档（回滚至 v' . $version['version_no'] . '）');

        $payload = [
            'title'       => (string) ($snapshot['title'] ?? $case['title']),
            'category_id' => (int) ($snapshot['category_id'] ?? $case['category_id']),
            'device'      => (string) ($snapshot['device'] ?? ''),
            'error_code'  => (string) ($snapshot['error_code'] ?? ''),
            'tags'        => (string) ($snapshot['tags'] ?? $case['tags'] ?? ''),
            'phenomenon'  => (string) ($snapshot['phenomenon'] ?? ''),
            'cause'       => (string) ($snapshot['cause'] ?? ''),
            'solution'    => (string) ($snapshot['solution'] ?? ''),
            'prevention'  => (string) ($snapshot['prevention'] ?? ''),
            'handler'     => (string) ($snapshot['handler'] ?? ''),
            'handled_at'  => $snapshot['handled_at'] ?? null,
            'version_no'  => (int) $case['version_no'] + 1,
            'updated_at'  => now(),
        ];

        CaseModel::modify($caseId, $payload);
        $this->log('case.rollback', 'case', $caseId, '回滚至 v' . $version['version_no']);
        $this->back('case_form.php?id=' . $caseId, 'success',
            '已回滚至 v' . $version['version_no'] . ' 的内容，并生成新版本 v' . $payload['version_no'] . '。');
    }

    // =========================================================
    // 内部方法
    // =========================================================

    /**
     * 案例编辑权限：超管全量，运维管理员仅限本人创建。
     *
     * @param array<string,mixed> $case
     */
    private function canEdit(array $case): bool
    {
        $auth = Auth::instance();
        if ($auth->isSuperAdmin()) {
            return true;
        }
        return (int) $case['author_id'] === $auth->id() && $auth->can('case.edit_own');
    }

    private function applyStatus(int $id, string $status, string $reason): void
    {
        $payload = ['status' => $status, 'updated_at' => now()];

        if ($status === CaseModel::STATUS_PUBLISHED) {
            $existing = CaseModel::find($id);
            if ($existing !== null && $existing['published_at'] === null) {
                $payload['published_at'] = now();
            }
            // 未单独指定过期时间时，按系统配置的默认有效期自动计算
            $expireDays = Setting::int('case.expire_days', 0);
            if ($expireDays > 0 && $existing !== null && empty($existing['expired_at'])) {
                $payload['expired_at'] = date('Y-m-d H:i:s', strtotime('+' . $expireDays . ' days'));
            }
            $payload['reviewed_by'] = Auth::instance()->id();
            $payload['reviewed_at'] = now();
        }

        CaseModel::modify($id, $payload);
        $this->log('case.status', 'case', $id, $reason . ' -> ' . case_status_label($status));
    }

    /**
     * 处理表单附件上传。
     *
     * @return array<int,string> 错误信息列表
     */
    private function handleUploads(int $caseId): array
    {
        if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments'])) {
            return [];
        }

        $files  = $_FILES['attachments'];
        $errors = [];
        $auth   = Auth::instance();

        // 归一化为逐个文件：单文件上传时 $_FILES 是一维结构（name 为字符串），
        // 直接按下标取会取到首字符导致上传失败，这里统一转成列表再处理。
        $list = [];
        if (is_array($files['name'])) {
            foreach (array_keys($files['name']) as $index) {
                $list[] = [
                    'name'     => $files['name'][$index] ?? '',
                    'type'     => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error'    => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$index] ?? 0,
                ];
            }
        } else {
            $list[] = $files;
        }

        foreach ($list as $single) {
            if ((int) ($single['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $result = Upload::store($single);
            if (!$result['ok']) {
                $errors[] = (string) $single['name'] . '：' . $result['message'];
                continue;
            }

            $data = $result['data'];
            Attachment::create([
                'case_id'     => $caseId,
                'orig_name'   => $data['orig_name'],
                'stored_path' => $data['stored_path'],
                'ext'         => $data['ext'],
                'mime'        => $data['mime'],
                'size'        => $data['size'],
                'uploader_id' => $auth->id(),
                'created_at'  => now(),
            ]);
            Logger::operation('attachment.upload', 'case', $caseId, '上传附件：' . $data['orig_name']);
        }

        return $errors;
    }
}
