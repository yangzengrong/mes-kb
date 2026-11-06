<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Upload;
use App\Core\View;
use App\Models\Attachment;

/**
 * UEditor 富文本编辑器后端适配器。
 *
 * UEditor 前端会请求 serverUrl 完成上传 / 配置 / 在线资源列表，
 * 其自带 PHP 后端会把文件暴露为直链，违反本项目「附件不落 Web 根、
 * 统一经 /media/{name} 白名单路由输出」的安全设计，因此在这里做转接：
 *
 *   - uploadimage / uploadfile：复用 Upload::storeEditorImage / storeEditorFile，
 *     文件落 storage/uploads/editor/，登记 attachments 表（case_id = 0），
 *     返回 UEditor 要求的 JSON（state / url / title / original）。
 *   - listimage / listfile：返回编辑器目录下最近上传的资源，供「在线图片 / 在线文件」。
 *   - config：返回精简后端配置。
 *
 * 鉴权：登录态 + GET 上的 CSRF 令牌（UEditor 上传对话框无法带 POST 令牌，
 * 因此由页面把令牌拼进 serverUrl 的查询参数）。
 *
 * ©Yangzengrong 版权所有
 */
class UeditorController extends BaseController
{
    /** 上传动作允许的文件字段名（UEditor 固定为 upfile） */
    private const FILE_FIELD = 'upfile';

    public function serve(): void
    {
        header('Cache-Control: no-store');

        try {
            if (!Auth::instance()->check()) {
                $this->fail('登录状态已失效，请重新登录。');
            }
            if (!Csrf::check($_GET['_token'] ?? null)) {
                $this->fail('页面令牌已失效，请刷新页面后重试。');
            }

            $action = (string) ($_GET['action'] ?? '');
            switch ($action) {
                case 'config':
                    $this->ok($this->config());
                    break;
                case 'uploadimage':
                    $this->upload(true);
                    break;
                case 'uploadfile':
                    $this->upload(false);
                    break;
                case 'listimage':
                    $this->ok(['state' => 'SUCCESS', 'list' => $this->listEditorFiles(true)]);
                    break;
                case 'listfile':
                    $this->ok(['state' => 'SUCCESS', 'list' => $this->listEditorFiles(false)]);
                    break;
                default:
                    $this->fail('未知的编辑器请求动作。');
            }
        } catch (\Throwable $e) {
            $this->fail('编辑器接口异常，请稍后重试。');
        }
    }

    /**
     * 返回给 UEditor 的后端配置（仅覆盖与上传相关的项，其余用前端默认值）。
     *
     * @return array<string,mixed>
     */
    private function config(): array
    {
        $imageAllow = ['png', 'jpg', 'jpeg', 'gif'];
        $fileAllow  = Upload::allowedExtensions();

        return [
            'state' => 'SUCCESS',
            // 图片上传
            'imageActionName'    => 'uploadimage',
            'imageFieldName'     => self::FILE_FIELD,
            'imageUrlPrefix'     => '',
            'imageAllowFiles'    => $this->dotList($imageAllow),
            'imageMaxSize'       => Upload::maxBytes(),
            'imageCompressEnable'=> false,
            'imageCompressBorder'=> 1600,
            // 文件上传（PDF / 文档 / 表格 / 日志）
            'fileActionName'     => 'uploadfile',
            'fileFieldName'      => self::FILE_FIELD,
            'fileUrlPrefix'      => '',
            'fileAllowFiles'     => $this->dotList($fileAllow),
            'fileMaxSize'        => Upload::maxBytes(),
            // 在线管理
            'imageManagerActionName' => 'listimage',
            'fileManagerActionName'  => 'listfile',
            // 不提供远程图片抓取（避免服务器代抓外网内容）
            'catcherActionName'      => 'notavailable',
            'snapscreenActionName'   => 'notavailable',
            'videoActionName'        => 'notavailable',
        ];
    }

    /**
     * 处理图片 / 文件上传。
     */
    private function upload(bool $isImage): void
    {
        $file = $_FILES[self::FILE_FIELD] ?? null;
        if ($file === null || !is_array($file)) {
            $this->fail('没有收到文件，请重新选择。');
        }

        $result = $isImage
            ? Upload::storeEditorImage($file)
            : Upload::storeEditorFile($file);
        if (!$result['ok']) {
            $this->fail((string) $result['message']);
        }

        $data = $result['data'];
        Attachment::create([
            'case_id'     => 0,
            'orig_name'   => $data['orig_name'],
            'stored_path' => $data['stored_path'],
            'ext'         => $data['ext'],
            'mime'        => $data['mime'],
            'size'        => $data['size'],
            'uploader_id' => Auth::instance()->id(),
            'created_at'  => now(),
        ]);

        $payload = [
            'state'    => 'SUCCESS',
            'url'      => base_url('media/' . $data['stored_name']),
            'title'    => (string) $data['orig_name'],
            'original' => (string) $data['orig_name'],
        ];
        if (!$isImage) {
            $payload['fileType'] = '.' . (string) $data['ext'];
        }
        $this->ok($payload);
    }

    /**
     * 在线图片 / 在线文件列表（最近 60 个，按文件名倒序 = 时间倒序）。
     *
     * @return array<int,array<string,mixed>>
     */
    private function listEditorFiles(bool $imagesOnly): array
    {
        $dir = STORAGE_PATH . '/uploads/editor';
        if (!is_dir($dir)) {
            return [];
        }

        $imageExts = ['png', 'jpg', 'jpeg', 'gif'];
        $entries   = [];
        foreach ((array) @scandir($dir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!is_file($dir . '/' . $name)) {
                continue;
            }
            // 与 /media/ 路由相同的命名白名单，保证返回的 URL 一定可访问
            if (preg_match('/^[0-9]{14}_[0-9a-f]{16}\.([a-z0-9]+)$/', $name, $m) !== 1) {
                continue;
            }
            $ext = strtolower((string) $m[1]);
            if ($imagesOnly && !in_array($ext, $imageExts, true)) {
                continue;
            }
            $entries[$name] = [
                'url'   => base_url('media/' . $name),
                'mtime' => (int) @filemtime($dir . '/' . $name),
            ];
        }

        krsort($entries); // 文件名以 14 位时间戳开头，倒序即最新在前
        $entries = array_slice($entries, 0, 60, true);

        $list = [];
        foreach ($entries as $item) {
            $list[] = ['url' => $item['url'], 'mtime' => $item['mtime']];
        }
        return $list;
    }

    /**
     * @param array<int,string> $items
     * @return array<int,string>
     */
    private function dotList(array $items): array
    {
        return array_values(array_map(static function (string $item): string {
            return '.' . ltrim(strtolower($item), '.');
        }, $items));
    }

    /**
     * 输出 UEditor 要求的响应（state 为唯一状态标识，非 SUCCESS 即错误提示文案）。
     *
     * @param array<string,mixed> $payload
     */
    private function ok(array $payload): void
    {
        View::json($payload);
    }

    private function fail(string $message): void
    {
        View::json(['state' => $message]);
    }
}
