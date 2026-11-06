<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Request;
use App\Models\Attachment;
use App\Models\CaseModel;
use App\Models\Category;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\Process;
use App\Models\PinyinTerm;

/**
 * 员工反馈管理（后台）：受理反馈、补解决方案、一键生成故障案例并发布。
 */
class FeedbackController extends BaseController
{
    public function index(): void
    {
        $this->guard('feedback.view');

        $filters = [
            'keyword'    => (string) Request::get('keyword', ''),
            'status'     => (string) Request::get('status', ''),
            'urgency'    => (string) Request::get('urgency', ''),
            'process_id' => Request::int('process_id'),
            'trashed'    => Request::get('trashed') === '1',
        ];

        $page   = max(1, Request::int('page', 1));
        $result = Feedback::paginateByFilters($filters, $page, 15);

        $this->render('admin/feedbacks', [
            'activeMenu'  => $filters['trashed'] ? 'feedback_trash' : 'feedbacks',
            'filters'     => $filters,
            'result'      => $result,
            'counts'      => Feedback::statusCounts(),
            'statuses'    => feedback_status_map(),
            'urgencies'   => feedback_urgency_map(),
            'processes'   => Process::options(true),
        ], '员工反馈');
    }

    /**
     * 反馈统计 / 台账（后台登录可见）：
     * 概览 KPI + 维度汇总计数表 + 工序×状态交叉矩阵 + 明细台账（附件仅提供可点击查看链接，不内嵌图片）。
     * 追加 ?export=csv 导出当前筛选条件下的全量明细为 Excel 兼容 CSV。
     */
    public function stats(): void
    {
        $this->guard('feedback.view');

        if (Request::get('export') === 'csv') {
            $this->exportCsv();
            return;
        }

        $filters = $this->statsFilters();

        $page   = max(1, Request::int('page', 1));
        $ledger = Feedback::paginateByFilters($filters, $page, 50);

        $fbIds       = array_column($ledger['items'], 'id');
        $attachments = Attachment::groupByFeedbacks($fbIds);

        $this->render('admin/feedback_stats', [
            'activeMenu'  => 'feedback_stats',
            'filters'     => $filters,
            'summary'     => Feedback::stats($filters),
            'matrix'      => Feedback::processStatusMatrix($filters),
            'ledger'      => $ledger,
            'attachments' => $attachments,
            'statuses'    => feedback_status_map(),
            'urgencies'   => feedback_urgency_map(),
            'processes'   => Process::options(true),
            'months'      => Feedback::availableMonths(),
            'thisMonth'   => date('Y-m'),
            'lastMonth'   => date('Y-m', strtotime('first day of last month')),
        ], '反馈统计');
    }

    /**
     * 统计页 / 导出共用的筛选条件。
     * month 仅接受 YYYY-MM，closure 仅接受 open / closed，非法值忽略。
     *
     * @return array<string,mixed>
     */
    private function statsFilters(): array
    {
        $month = trim((string) Request::get('month', ''));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $month = '';
        }

        $closure = (string) Request::get('closure', '');
        if (!in_array($closure, ['open', 'closed'], true)) {
            $closure = '';
        }

        return [
            'keyword'    => (string) Request::get('keyword', ''),
            'status'     => (string) Request::get('status', ''),
            'urgency'    => (string) Request::get('urgency', ''),
            'process_id' => Request::int('process_id'),
            'month'      => $month,
            'closure'    => $closure,
        ];
    }

    /**
     * 导出明细台账为 CSV（UTF-8 BOM，Excel 直接打开中文不乱码）。
     */
    private function exportCsv(): void
    {
        $filters     = $this->statsFilters();
        $items       = Feedback::allByFilters($filters);
        $attachments = Attachment::groupByFeedbacks(array_column($items, 'id'));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="feedback_stats_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            '序号', '反馈单号', '提交人', '归属部门', '工号', '联系方式',
            '发生工序', '问题类型', '涉及设备/模块', '问题标题',
            '紧急程度', '状态', '是否闭环', '提交时间', '处理人', '处理时间', '附件',
        ]);

        $seq = 0;
        foreach ($items as $it) {
            $seq++;
            $files = $attachments[(int) $it['id']] ?? [];
            $att   = [];
            foreach ($files as $f) {
                $att[] = (string) $f['orig_name'];
            }
            fputcsv($out, [
                $seq,
                (string) $it['code'],
                (string) $it['employee_name'],
                (string) $it['department'],
                (string) $it['employee_no'],
                (string) $it['contact'],
                (string) ($it['process_name'] ?? ''),
                (string) ($it['category_name'] ?? ''),
                (string) $it['device'],
                (string) $it['title'],
                feedback_urgency_label((string) $it['urgency']),
                feedback_status_label((string) $it['status']),
                in_array((string) $it['status'], ['resolved', 'closed'], true) ? '已闭环' : '未闭环',
                (string) $it['created_at'],
                (string) ($it['handler_name'] ?? ''),
                (string) ($it['handled_at'] ?? ''),
                implode('; ', $att),
            ]);
        }
        fclose($out);
        exit;
    }

    /**
     * 反馈详情：内容 + 附件 + 受理/关闭 + 生成案例。
     */
    public function detail(): void
    {
        $this->guard('feedback.view');

        $id       = Request::int('id');
        $feedback = Feedback::findWithMeta($id);
        if ($feedback === null) {
            $this->back('feedbacks.php', 'error', '反馈记录不存在。');
        }

        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        // 已关联案例时给出提示，避免重复生成
        $linkedCase = null;
        if ((int) $feedback['case_id'] > 0) {
            $linkedCase = CaseModel::findWithMeta((int) $feedback['case_id']);
        }

        $this->render('admin/feedback_detail', [
            'activeMenu'  => 'feedbacks',
            'feedback'    => $feedback,
            'attachments' => Attachment::byFeedback($id),
            'linkedCase'  => $linkedCase,
            'categories'  => Category::options(),
            'processes'   => Process::options(true),
            'positions'   => Position::options(true),
            'statuses'    => feedback_status_map(),
            'urgencies'   => feedback_urgency_map(),
            'errors'      => $errors,
        ], '反馈详情 · ' . (string) $feedback['code']);
    }

    /**
     * 受理 / 关闭：更新状态与处理说明。
     */
    public function status(): void
    {
        $this->guard('feedback.handle');
        $this->guardPost();

        $id     = Request::int('id');
        $target = (string) Request::post('status', '');
        $note   = trim((string) Request::post('admin_note', ''));
        $auth   = Auth::instance();

        $feedback = Feedback::findWithMeta($id);
        if ($feedback === null) {
            $this->back('feedbacks.php', 'error', '反馈记录不存在。');
        }
        if (!array_key_exists($target, feedback_status_map())) {
            $this->back('feedbacks.php', 'error', '非法的状态值。');
        }
        if ($target === Feedback::STATUS_RESOLVED) {
            $this->back('feedback_detail.php?id=' . $id, 'error', '「已解决」由生成案例时自动置位，请直接在下方生成案例。');
        }
        if ($target === Feedback::STATUS_CLOSED && $note === '') {
            $this->back('feedback_detail.php?id=' . $id, 'error', '关闭反馈时必须填写原因，员工会看到该说明。');
        }

        Feedback::updateStatus($id, $target, $auth->id(), $note);
        $this->log('feedback.status', 'feedback', $id,
            '反馈 ' . $feedback['code'] . ' 状态改为「' . feedback_status_label($target) . '」');

        $this->back('feedback_detail.php?id=' . $id, 'success', '状态已更新为「' . feedback_status_label($target) . '」。');
    }

    /**
     * 生成故障案例并发布：把员工描述作为故障现象，管理员补充解决方案。
     */
    public function toCase(): void
    {
        $this->guard('feedback.handle');
        $this->guardPost();

        $id     = Request::int('id');
        $auth   = Auth::instance();
        $feedback = Feedback::findWithMeta($id);

        if ($feedback === null) {
            $this->back('feedbacks.php', 'error', '反馈记录不存在。');
        }
        if ((int) $feedback['case_id'] > 0) {
            $this->back('feedback_detail.php?id=' . $id, 'error', '该反馈已生成过案例，请勿重复操作。');
        }

        $title      = trim((string) Request::post('title', ''));
        $device     = trim((string) Request::post('device', ''));
        $tags       = trim((string) Request::post('tags', ''));
        $note       = trim((string) Request::post('admin_note', ''));
        $processId  = Request::int('process_id');
        $categoryId = Request::int('category_id');
        /* 员工提交时若自选了问题类型，未指定时自动预填 */
        if ($categoryId <= 0) {
            $categoryId = (int) ($feedback['category_id'] ?? 0);
        }
        $solution   = HtmlSanitizer::clean((string) Request::post('solution', ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = '案例标题不能为空。';
        }
        if ($processId <= 0 || Process::find($processId) === null) {
            $errors['process_id'] = '请选择所属工序。';
        }
        if ($categoryId <= 0 || Category::find($categoryId) === null) {
            $errors['category_id'] = '请选择故障类型。';
        }
        $solutionText = trim(str_replace(["\xc2\xa0", ' '], '', strip_tags($solution)));
        if ($solutionText === '' && stripos($solution, '<img') === false) {
            $errors['solution'] = '解决方案不能为空，请写明具体处置步骤。';
        }
        if ($errors !== []) {
            $_SESSION['_errors'] = $errors;
            $this->back('feedback_detail.php?id=' . $id, 'error', '请补全案例信息后再生成。');
        }

        // 标签规范化：中英文逗号/分号统一为英文逗号，去空格、去重、限 10 个
        $tagParts = preg_split('/[,，;；]+/u', $tags) ?: [];
        $tagParts = array_map(static function (string $part): string {
            $part = trim($part);
            return mb_strlen($part, 'UTF-8') > 20 ? mb_substr($part, 0, 20, 'UTF-8') : $part;
        }, $tagParts);
        $tagParts = implode(',', array_slice(array_values(array_unique(array_filter($tagParts, static function (string $part): bool {
            return $part !== '';
        }))), 0, 10));

        // 故障现象 = 员工原始描述 + 来源标注，保证知识库可溯源
        $phenomenon = Feedback::textToHtml((string) $feedback['content']);
        $sourceNote = '<p><em>来源：员工反馈 ' . e((string) $feedback['code'])
            . '，提交人 ' . e((string) $feedback['employee_name'])
            . '（' . e((string) $feedback['department']) . '），提交时间 '
            . e((string) $feedback['created_at']) . '。</em></p>';
        $phenomenon .= $sourceNote;

        $now = now();
        $caseId = CaseModel::create([
            'title'       => $title,
            'process_id'  => $processId,
            'position_id' => 0,
            'doc_type'    => 'case',
            'category_id' => $categoryId,
            'device'      => $device,
            'error_code'  => '',
            'tags'        => $tagParts,
            'phenomenon'  => $phenomenon,
            'cause'       => '',
            'solution'    => $solution,
            'prevention'  => '',
            'handler'     => (string) ($auth->user()['real_name'] ?? ''),
            'handled_at'  => $now,
            'status'      => CaseModel::STATUS_PUBLISHED,
            'views'       => 0,
            'downloads'   => 0,
            'is_top'      => 0,
            'author_id'   => $auth->id(),
            'version_no'  => 1,
            'reviewed_by' => $auth->id(),
            'reviewed_at' => $now,
            'review_note' => '源自员工反馈 ' . (string) $feedback['code'],
            'published_at' => $now,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        CaseModel::snapshot($caseId, [
            'title'      => $title,
            'category_id' => $categoryId,
            'device'     => $device,
            'error_code' => '',
            'phenomenon' => $phenomenon,
            'cause'      => '',
            'solution'   => $solution,
            'prevention' => '',
            'handler'    => (string) ($auth->user()['real_name'] ?? ''),
            'handled_at' => $now,
            'version_no' => 1,
        ], $auth->id(), (string) ($auth->user()['username'] ?? ''),
            '源自员工反馈 ' . (string) $feedback['code']);

        // 附件随案例一起发布（保留 feedback_id 便于溯源）
        Attachment::bindToCase($id, $caseId);
        Feedback::linkCase($id, $caseId, $auth->id(), $note);
        PinyinTerm::flush();

        $this->log('feedback.to_case', 'feedback', $id,
            '反馈 ' . $feedback['code'] . ' 已生成案例 #' . $caseId . ' 并发布');

        $this->back('feedback_detail.php?id=' . $id, 'success',
            '已生成案例 #' . $caseId . ' 并发布，前台「最新故障案例」立即可见。');
    }

    /**
     * 移入回收站。
     */
    public function trash(): void
    {
        $this->guard('feedback.handle');
        $this->guardPost();

        $id       = Request::int('id');
        $feedback = Feedback::findWithMeta($id);
        if ($feedback === null) {
            $this->back('feedbacks.php', 'error', '反馈记录不存在。');
        }

        Feedback::moveToTrash($id);
        $this->log('feedback.trash', 'feedback', $id, '移入回收站：' . $feedback['code']);
        $this->back('feedbacks.php', 'success', '反馈已移入回收站。');
    }

    public function restore(): void
    {
        $this->guard('feedback.handle');
        $this->guardPost();

        $id = Request::int('id');
        Feedback::restore($id);
        $this->log('feedback.restore', 'feedback', $id, '从回收站恢复');
        $this->back('feedbacks.php?trashed=1', 'success', '反馈已恢复。');
    }

    /**
     * 后台查看反馈附件（未发布案例的附件前台不可见，这里走后台鉴权）。
     */
    public function file(): void
    {
        $this->guard('feedback.view');

        $id       = Request::int('id');
        $mode     = (string) Request::get('mode', 'preview');
        $attachment = Attachment::find($id);

        if ($attachment === null || $attachment['deleted_at'] !== null || (int) $attachment['feedback_id'] <= 0) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            exit('附件不存在或无权访问。');
        }

        $relative = ltrim(str_replace('\\', '/', (string) $attachment['stored_path']), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            $this->denyFile();
        }
        $path    = STORAGE_PATH . '/' . $relative;
        $real    = realpath($path);
        $storage = realpath(STORAGE_PATH);
        if ($real === false || $storage === false || !is_file($real) || strpos($real, $storage) !== 0) {
            $this->denyFile();
        }

        $extension = strtolower((string) $attachment['ext']);
        $inline    = $mode !== 'download' && \App\Core\Upload::isPreviewable($extension);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $mimeMap = [
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'txt'  => 'text/plain; charset=utf-8',
            'log'  => 'text/plain; charset=utf-8',
        ];
        $mime = $mimeMap[$extension] ?? ((string) $attachment['mime'] !== '' ? (string) $attachment['mime'] : 'application/octet-stream');
        $name = (string) $attachment['orig_name'];

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            preg_replace('/[^\x20-\x7E]/', '_', $name),
            rawurlencode($name)
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($real);
        exit;
    }

    private function denyFile(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        exit('附件不存在或无权访问。');
    }
}
