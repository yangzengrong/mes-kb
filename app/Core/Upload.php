<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 附件上传处理（对应需求 4.4 附件文件精细化管理）。
 * 职责：格式白名单校验、体积校验、真实 MIME 嗅探、按日期分目录落盘、原名安全化。
 */
class Upload
{
    /** 扩展名 -> 允许的 MIME 前缀（用于二次嗅探，防伪造扩展名） */
    private const MIME_WHITELIST = [
        'doc'  => ['application/msword', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'txt'  => ['text/plain', 'text/x-log', 'application/octet-stream'],
        'log'  => ['text/plain', 'text/x-log', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /**
     * 执行上传。
     *
     * @param array<string,mixed> $file $_FILES 单项
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    public static function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return self::fail(self::errorMessage($error));
        }

        $originalName = (string) ($file['name'] ?? '');
        $tmpPath      = (string) ($file['tmp_name'] ?? '');
        $size         = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return self::fail('上传文件无效，请重新选择。');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !isset(self::MIME_WHITELIST[$extension])) {
            return self::fail('不支持的文件格式：' . ($extension === '' ? '未知' : $extension)
                . '。允许的格式：' . implode('、', self::allowedExtensions()) . '。');
        }

        $allowedExtensions = self::allowedExtensions();
        if (!in_array($extension, $allowedExtensions, true)) {
            return self::fail('当前系统配置不允许上传 .' . $extension . ' 文件，允许的格式：'
                . implode('、', $allowedExtensions) . '。');
        }

        $maxBytes = self::maxBytes();
        if ($size <= 0) {
            return self::fail('文件内容为空，无法上传。');
        }
        if ($size > $maxBytes) {
            return self::fail('文件体积 ' . human_size($size) . ' 超过系统上限 ' . human_size($maxBytes) . '。');
        }

        // 真实 MIME 嗅探：扩展名与内容不符时拒绝，防止恶意文件伪装
        $mime = self::detectMime($tmpPath);
        $expect = self::MIME_WHITELIST[$extension];
        $matched = false;
        foreach ($expect as $prefix) {
            if (stripos($mime, $prefix) === 0) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return self::fail('文件内容与扩展名不匹配（检测到 ' . $mime . '），已拒绝上传。');
        }

        $relativeDir = 'uploads/' . date('Ym');
        $absoluteDir = STORAGE_PATH . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return self::fail('服务器存储目录创建失败，请检查 storage/uploads 目录权限。');
        }

        $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $target     = $absoluteDir . '/' . $storedName;

        if (!@move_uploaded_file($tmpPath, $target)) {
            return self::fail('文件写入失败，请检查服务器目录权限。');
        }
        @chmod($target, 0644);

        return [
            'ok'      => true,
            'message' => '上传成功。',
            'data'    => [
                'orig_name'   => self::sanitizeName($originalName),
                'stored_path' => $relativeDir . '/' . $storedName,
                'ext'         => $extension,
                'mime'        => $mime,
                'size'        => $size,
            ],
        ];
    }

    /**
     * 编辑器配图上传（独立于附件配置，始终允许 png/jpg/jpeg/gif）。
     * 落盘至 storage/uploads/editor/，文件名不可猜测，经 /media/{name} 路由访问。
     *
     * @param array<string,mixed> $file $_FILES 单项
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    public static function storeEditorImage(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return self::fail(self::errorMessage($error));
        }

        $originalName = (string) ($file['name'] ?? '');
        $tmpPath      = (string) ($file['tmp_name'] ?? '');
        $size         = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return self::fail('上传文件无效，请重新选择。');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            return self::fail('编辑器仅支持插入 png / jpg / jpeg / gif 图片。');
        }

        if ($size <= 0) {
            return self::fail('图片内容为空，无法上传。');
        }
        if ($size > self::maxBytes()) {
            return self::fail('图片体积 ' . human_size($size) . ' 超过系统上限 ' . human_size(self::maxBytes()) . '。');
        }

        // 真实 MIME 嗅探，防止伪装成图片的恶意文件
        $mime = self::detectMime($tmpPath);
        $expect = ['image/png', 'image/jpeg', 'image/gif'];
        $matched = false;
        foreach ($expect as $prefix) {
            if (stripos($mime, $prefix) === 0) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return self::fail('文件内容不是有效图片（检测到 ' . $mime . '），已拒绝上传。');
        }

        $relativeDir = 'uploads/editor';
        $absoluteDir = STORAGE_PATH . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return self::fail('服务器存储目录创建失败，请检查 storage/uploads 目录权限。');
        }

        // 14 位时间戳 + 16 位随机十六进制，保证 URL 不可猜测
        $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target     = $absoluteDir . '/' . $storedName;

        if (!@move_uploaded_file($tmpPath, $target)) {
            return self::fail('图片写入失败，请检查服务器目录权限。');
        }
        @chmod($target, 0644);

        return [
            'ok'      => true,
            'message' => '上传成功。',
            'data'    => [
                'orig_name'   => self::sanitizeName($originalName),
                'stored_path' => $relativeDir . '/' . $storedName,
                'stored_name' => $storedName,
                'ext'         => $extension,
                'mime'        => $mime,
                'size'        => $size,
            ],
        ];
    }

    /**
     * 富文本编辑器「插入文件」上传（文档 / 表格 / 日志等，非图片）。
     * 与图片同样落 storage/uploads/editor，经 /media/{name} 路由访问。
     */
    public static function storeEditorFile(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return self::fail(self::errorMessage($error));
        }

        $originalName = (string) ($file['name'] ?? '');
        $tmpPath      = (string) ($file['tmp_name'] ?? '');
        $size         = (int) ($file['size'] ?? 0);

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return self::fail('上传文件无效，请重新选择。');
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed   = self::allowedExtensions();
        if (!in_array($extension, $allowed, true)) {
            return self::fail('编辑器暂不支持插入 .' . $extension . ' 文件，允许的格式：'
                . implode(' / ', $allowed) . '。');
        }

        if ($size <= 0) {
            return self::fail('文件内容为空，无法上传。');
        }
        if ($size > self::maxBytes()) {
            return self::fail('文件体积 ' . human_size($size) . ' 超过系统上限 ' . human_size(self::maxBytes()) . '。');
        }

        // 真实 MIME 嗅探：扩展名与内容不符时拒绝，防止伪装
        $mime    = self::detectMime($tmpPath);
        $expect  = self::MIME_WHITELIST[$extension] ?? [];
        $matched = false;
        foreach ($expect as $prefix) {
            if (stripos($mime, $prefix) === 0) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return self::fail('文件内容与扩展名不匹配（检测到 ' . $mime . '），已拒绝上传。');
        }

        $relativeDir = 'uploads/editor';
        $absoluteDir = STORAGE_PATH . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return self::fail('服务器存储目录创建失败，请检查 storage/uploads 目录权限。');
        }

        // 与编辑器配图一致的不可猜测命名，确保 /media/{name} 路由校验通过
        $storedName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target     = $absoluteDir . '/' . $storedName;

        if (!@move_uploaded_file($tmpPath, $target)) {
            return self::fail('文件写入失败，请检查服务器目录权限。');
        }
        @chmod($target, 0644);

        return [
            'ok'      => true,
            'message' => '上传成功。',
            'data'    => [
                'orig_name'   => self::sanitizeName($originalName),
                'stored_path' => $relativeDir . '/' . $storedName,
                'stored_name' => $storedName,
                'ext'         => $extension,
                'mime'        => $mime,
                'size'        => $size,
            ],
        ];
    }

    /**
     * 允许的扩展名（由系统配置驱动，对应需求 4.9 文件上传配置）。
     *
     * @return array<int,string>
     */
    public static function allowedExtensions(): array
    {
        $configured = (string) setting('upload.allowed_ext', 'doc,docx,pdf,png,jpg,jpeg,txt,log,xls,xlsx');
        $items      = array_filter(array_map(static function (string $item): string {
            return strtolower(trim($item));
        }, explode(',', $configured)));

        $items = array_values(array_intersect($items, array_keys(self::MIME_WHITELIST)));
        return $items === [] ? ['pdf'] : $items;
    }

    /**
     * 单文件体积上限（MB -> Byte）。
     */
    public static function maxBytes(): int
    {
        $megabytes = (int) setting('upload.max_size_mb', 20);
        if ($megabytes < 1) {
            $megabytes = 1;
        }
        if ($megabytes > 512) {
            $megabytes = 512;
        }
        return $megabytes * 1024 * 1024;
    }

    public static function isPreviewable(string $extension): bool
    {
        return in_array(strtolower($extension), ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'log'], true);
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        $mime = @mime_content_type($path);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}\._\-\(\)\[\] ]+/u', '_', $name) ?? 'attachment';
        return mb_substr($name, 0, 180, 'UTF-8');
    }

    private static function errorMessage(int $code): string
    {
        $map = [
            UPLOAD_ERR_INI_SIZE   => '文件体积超过 php.ini 中 upload_max_filesize 的限制。',
            UPLOAD_ERR_FORM_SIZE  => '文件体积超过表单限制。',
            UPLOAD_ERR_PARTIAL    => '文件只上传了一部分，请重试。',
            UPLOAD_ERR_NO_FILE    => '没有选择要上传的文件。',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录，请联系管理员。',
            UPLOAD_ERR_CANT_WRITE => '服务器写入磁盘失败，请联系管理员。',
            UPLOAD_ERR_EXTENSION  => '上传被 PHP 扩展中断。',
        ];
        return $map[$code] ?? '文件上传失败（错误码 ' . $code . '）。';
    }

    /**
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'data' => []];
    }
}
