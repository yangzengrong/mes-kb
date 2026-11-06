<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Upload;
use App\Core\View;
use App\Models\Attachment;
use App\Models\CaseModel;

/**
 * 附件文件精细化管理（需求 4.4）。
 */
class AttachmentController extends BaseController
{
    public function index(): void
    {
        $this->guard('attachment.view');

        $filters = [
            'keyword' => (string) Request::get('keyword', ''),
            'ext'     => (string) Request::get('ext', ''),
            'case_id' => Request::int('case_id'),
        ];

        $page   = max(1, Request::int('page', 1));
        $result = Attachment::paginateByFilters($filters, $page, 20);

        $this->render('admin/attachments', [
            'activeMenu'  => 'attachments',
            'filters'     => $filters,
            'result'      => $result,
            'extStats'    => Attachment::countByExtension(),
            'storage'     => Attachment::storageUsage(),
            'allowedExt'  => implode('、', Upload::allowedExtensions()),
            'maxSizeMb'   => (int) round(Upload::maxBytes() / 1048576),
            'caseOptions' => $this->caseOptions(),
            'isSuper'     => Auth::instance()->isSuperAdmin(),
        ], '附件管理');
    }

    /**
     * 富文本编辑器插图 / 插文件上传（JSON 接口，供 admin/editor_upload.php 调用）。
     * 图片插入 <img>；文档等文件插入下载链接 <a>，均以 case_id = 0 落库登记，
     * 磁盘文件经前台 /media/{name} 路由访问。
     */
    public function uploadImage(): void
    {
        $this->guard();
        $this->guardPost();

        $file = Request::file('file');
        if ($file === null) {
            View::json(['ok' => false, 'message' => '没有收到文件，请重新选择。'], 422);
        }

        $extension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $isImage   = in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true);

        $result = $isImage ? Upload::storeEditorImage($file) : Upload::storeEditorFile($file);
        if (!$result['ok']) {
            View::json(['ok' => false, 'message' => $result['message']], 422);
        }

        $data = $result['data'];
        $id   = Attachment::create([
            'case_id'     => 0,
            'orig_name'   => $data['orig_name'],
            'stored_path' => $data['stored_path'],
            'ext'         => $data['ext'],
            'mime'        => $data['mime'],
            'size'        => $data['size'],
            'uploader_id' => Auth::instance()->id(),
            'created_at'  => now(),
        ]);

        $this->log('attachment.editor_upload', 'case', $id,
            ($isImage ? '编辑器插图：' : '编辑器插入文件：') . $data['orig_name']);

        View::json([
            'ok'   => true,
            'id'   => $id,
            'kind' => $isImage ? 'image' : 'file',
            'name' => (string) $data['orig_name'],
            'url'  => base_url('media/' . $data['stored_name']),
        ]);
    }

    /**
     * 独立上传（可指定绑定案例）。
     */
    public function upload(): void
    {
        $this->guard('attachment.upload');
        $this->guardPost();

        $caseId = Request::int('case_id');
        if ($caseId > 0 && CaseModel::find($caseId) === null) {
            $this->back('attachments.php', 'error', '指定的案例不存在。');
        }

        $file = Request::file('file');
        if ($file === null) {
            $this->back('attachments.php', 'error', '请选择要上传的文件。');
        }

        $result = Upload::store($file);
        if (!$result['ok']) {
            $this->back('attachments.php', 'error', $result['message']);
        }

        $data = $result['data'];
        $id   = Attachment::create([
            'case_id'     => $caseId,
            'orig_name'   => $data['orig_name'],
            'stored_path' => $data['stored_path'],
            'ext'         => $data['ext'],
            'mime'        => $data['mime'],
            'size'        => $data['size'],
            'uploader_id' => Auth::instance()->id(),
            'created_at'  => now(),
        ]);

        $this->log('attachment.upload', 'attachment', $id, '上传附件：' . $data['orig_name']);
        $this->back('attachments.php', 'success', '附件「' . $data['orig_name'] . '」上传成功。');
    }

    /**
     * 重新绑定 / 解绑案例。
     */
    public function bind(): void
    {
        $this->guard('attachment.manage_own');
        $this->guardPost();

        $id     = Request::int('id');
        $caseId = Request::int('case_id');
        $file   = Attachment::find($id);

        if ($file === null || $file['deleted_at'] !== null) {
            $this->back('attachments.php', 'error', '附件不存在。');
        }
        if ($caseId > 0 && CaseModel::find($caseId) === null) {
            $this->back('attachments.php', 'error', '指定的案例不存在。');
        }

        Attachment::modify($id, ['case_id' => $caseId]);
        $this->log('attachment.bind', 'attachment', $id,
            $caseId > 0 ? '绑定到案例 #' . $caseId : '解除案例绑定');
        $this->back('attachments.php', 'success',
            $caseId > 0 ? '附件已重新绑定到案例 #' . $caseId . '。' : '附件已解除案例绑定。');
    }

    public function delete(): void
    {
        $this->guard('attachment.manage_own');
        $this->guardPost();

        $id   = Request::int('id');
        $file = Attachment::find($id);

        if ($file === null || $file['deleted_at'] !== null) {
            $this->back('attachments.php', 'error', '附件不存在。');
        }

        $auth = Auth::instance();
        if (!$auth->isSuperAdmin() && (int) $file['uploader_id'] !== $auth->id()) {
            $this->back('attachments.php', 'error', '只能删除自己上传的附件。');
        }

        Attachment::softDelete($id);
        $this->log('attachment.delete', 'attachment', $id, '删除附件：' . $file['orig_name']);
        $this->back('attachments.php', 'success', '附件「' . $file['orig_name'] . '」已删除。');
    }

    /**
     * 物理清理：删除记录 + 磁盘文件（仅超管）。
     */
    public function purge(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id   = Request::int('id');
        $file = Attachment::find($id);

        if ($file === null) {
            $this->back('attachments.php', 'error', '附件不存在。');
        }

        $path = STORAGE_PATH . '/' . ltrim((string) $file['stored_path'], '/');
        if (is_file($path)) {
            @unlink($path);
        }
        Database::execute('DELETE FROM attachments WHERE id = :id', ['id' => $id]);
        $this->log('attachment.purge', 'attachment', $id, '彻底删除附件：' . $file['orig_name']);
        $this->back('attachments.php', 'success', '附件「' . $file['orig_name'] . '」已彻底删除。');
    }

    /**
     * 案例下拉选项（用于绑定附件）。
     *
     * @return array<int,string>
     */
    private function caseOptions(): array
    {
        $rows = Database::select(
            'SELECT id, title FROM cases WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 200'
        );
        $options = [0 => '（不绑定案例）'];
        foreach ($rows as $row) {
            $options[(int) $row['id']] = '#' . $row['id'] . ' ' . excerpt((string) $row['title'], 30);
        }
        return $options;
    }
}
