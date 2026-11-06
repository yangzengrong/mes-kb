<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 日志记录（对应需求 4.6 系统日志记录模块）。
 * 三类日志：管理员操作日志、用户访问日志、检索关键词日志。
 * 日志写入失败一律静默处理，不得影响主业务流程。
 */
class Logger
{
    /**
     * 管理员操作日志。
     *
     * @param mixed $targetId
     */
    public static function operation(string $action, ?string $targetType = null, $targetId = null, string $detail = ''): void
    {
        try {
            $user = Auth::instance()->user();
            Database::insert('operation_logs', [
                'user_id'     => $user !== null ? (int) $user['id'] : null,
                'username'    => $user !== null ? (string) $user['username'] : '未登录',
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId !== null ? (string) $targetId : null,
                'detail'      => mb_substr($detail, 0, 500, 'UTF-8'),
                'ip'          => Request::ip(),
                'user_agent'  => Request::userAgent(),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            self::fallback('operation', $action . ' | ' . $detail);
        }
    }

    /**
     * 用户访问日志（前台检索、浏览、下载）。
     *
     * @param mixed $caseId
     */
    public static function access(string $action, $caseId = null, string $extra = ''): void
    {
        try {
            Database::insert('access_logs', [
                'case_id'    => $caseId !== null ? (int) $caseId : null,
                'action'     => $action,
                'extra'      => mb_substr($extra, 0, 255, 'UTF-8'),
                'ip'         => Request::ip(),
                'url'        => Request::url(),
                'referer'    => Request::referer(),
                'user_agent' => Request::userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            self::fallback('access', $action . ' | ' . $extra);
        }
    }

    /**
     * 检索关键词日志（含无结果检索，用于反哺案例补充）。
     */
    public static function search(string $keyword, int $resultCount): void
    {
        if ($keyword === '') {
            return;
        }
        try {
            Database::insert('search_logs', [
                'keyword'      => mb_substr($keyword, 0, 100, 'UTF-8'),
                'result_count' => $resultCount,
                'ip'           => Request::ip(),
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            self::fallback('search', $keyword . ' => ' . $resultCount);
        }
    }

    /**
     * 数据库不可用时的兜底：落文件，保证不丢日志。
     */
    private static function fallback(string $channel, string $message): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/fallback.log',
            '[' . now() . '] [' . $channel . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
