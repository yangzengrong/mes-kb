<?php
declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Upload;
use App\Models\Attachment;
use App\Models\CaseModel;

/**
 * 附件下载与在线预览（需求 4.4 前端在线预览）。
 * 仅允许访问「已发布且未删除」案例下的附件。
 */
class FileController
{
    public function download(array $params): void
    {
        $attachment = $this->resolve((int) ($params['id'] ?? 0));
        if ($attachment === null) {
            $this->deny();
            return;
        }

        $path = $this->absolutePath((string) $attachment['stored_path']);
        if ($path === null) {
            $this->deny();
            return;
        }

        $caseId = (int) $attachment['case_id'];
        Attachment::incrementDownload((int) $attachment['id']);
        CaseModel::incrementDownloads($caseId);
        Logger::access('download', $caseId, (string) $attachment['orig_name']);

        $this->stream($path, (string) $attachment['orig_name'], (string) $attachment['mime'], true);
    }

    /**
     * 在线预览：PDF / 图片 / TXT 直接在浏览器中打开，无需下载。
     */
    public function preview(array $params): void
    {
        $attachment = $this->resolve((int) ($params['id'] ?? 0));
        if ($attachment === null) {
            $this->deny();
            return;
        }

        $extension = strtolower((string) $attachment['ext']);
        if (!Upload::isPreviewable($extension)) {
            // 不可预览的格式回落到下载
            redirect(base_url('file/' . (int) $attachment['id'] . '/download'));
        }

        $path = $this->absolutePath((string) $attachment['stored_path']);
        if ($path === null) {
            $this->deny();
            return;
        }

        Logger::access('preview', (int) $attachment['case_id'], (string) $attachment['orig_name']);

        $mime = $this->previewMime($extension, (string) $attachment['mime']);
        $this->stream($path, (string) $attachment['orig_name'], $mime, false);
    }

    /**
     * 富文本编辑器插图 / 插入文件访问：/media/{name}。
     * 文件名为「14位时间戳_16位随机hex.扩展名」，不可猜测；
     * 扩展名白名单 = 编辑器图片 + 系统配置的附件格式。
     */
    public function media(array $params): void
    {
        $name = (string) ($params['name'] ?? '');
        if (preg_match('/^[0-9]{14}_[0-9a-f]{16}\.(png|jpg|jpeg|gif|pdf|doc|docx|xls|xlsx|txt|log)$/', $name) !== 1) {
            $this->deny();
            return;
        }

        $relative = 'uploads/editor/' . $name;
        $path     = $this->absolutePath($relative);
        if ($path === null) {
            $this->deny();
            return;
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        // 可在线预览的格式直接内联输出；文档等格式强制以附件方式下载
        $inline = Upload::isPreviewable($extension);
        $mime   = $this->previewMime($extension, 'application/octet-stream');
        $this->stream($path, $name, $mime, !$inline);
    }

    /**
     * 取附件并校验其所属案例是否对前台可见。
     *
     * @return array<string,mixed>|null
     */
    private function resolve(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $attachment = Attachment::find($id);
        if ($attachment === null || $attachment['deleted_at'] !== null) {
            return null;
        }

        $caseId = (int) $attachment['case_id'];
        if ($caseId <= 0) {
            return null;
        }

        return CaseModel::findPublished($caseId) === null ? null : $attachment;
    }

    /**
     * 解析磁盘绝对路径，并确保没有越出 storage 目录（防目录穿越）。
     */
    private function absolutePath(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            return null;
        }

        $full    = STORAGE_PATH . '/' . $relative;
        $real    = realpath($full);
        $storage = realpath(STORAGE_PATH);

        if ($real === false || $storage === false || !is_file($real)) {
            return null;
        }
        if (strpos($real, $storage) !== 0) {
            return null;
        }
        return $real;
    }

    private function previewMime(string $extension, string $fallback): string
    {
        $map = [
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'txt'  => 'text/plain; charset=utf-8',
            'log'  => 'text/plain; charset=utf-8',
        ];
        return $map[$extension] ?? ($fallback !== '' ? $fallback : 'application/octet-stream');
    }

    private function stream(string $path, string $filename, string $mime, bool $asAttachment): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $disposition = $asAttachment ? 'attachment' : 'inline';
        $encoded     = rawurlencode($filename);

        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($path));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            preg_replace('/[^\x20-\x7E]/', '_', $filename),
            $encoded
        ));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($path);
        exit;
    }

    private function deny(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '文件不存在或无权访问。';
        exit;
    }
}
