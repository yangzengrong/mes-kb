<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 认证与授权（对应需求 4.1 用户权限管理 + 4.7 安全防护）。
 *
 * 角色：
 *  - super_admin 超级管理员：系统全部权限
 *  - op_admin    运维管理员  ：仅自身案例的增删改与附件管理
 *  - guest       普通访客    ：仅前台检索、预览、下载
 */
class Auth
{
    public const ROLE_SUPER = 'super_admin';
    public const ROLE_OP    = 'op_admin';
    public const ROLE_GUEST = 'guest';
    public const ROLE_GM    = 'gm';

    /** 连续登录失败达到该次数即锁定账号 */
    public const MAX_LOGIN_FAILURES = 5;

    /** 锁定时长（秒） */
    public const LOCK_SECONDS = 900;

    /** @var array<string,array<int,string>> 角色 -> 权限清单 */
    private const PERMISSIONS = [
        self::ROLE_SUPER => ['*'],
        self::ROLE_OP    => [
            'dashboard.view',
            'case.view', 'case.create', 'case.edit_own', 'case.submit', 'case.offline_own',
            'attachment.view', 'attachment.upload', 'attachment.manage_own',
            'category.view',
            'device.view',
            'feedback.view', 'feedback.handle',
            'log.view_own',
        ],
        self::ROLE_GUEST => [],
        self::ROLE_GM    => ['feedback.view'],
    ];

    /** @var Auth|null */
    private static $instance = null;

    /** @var array<string,mixed>|null */
    private $user = null;

    private function __construct()
    {
        $userId = (int) ($_SESSION['admin_user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $row = Database::selectOne(
            'SELECT id, username, real_name, role, status, last_login_at, last_login_ip
             FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
        if ($row === null || (int) $row['status'] !== 1) {
            $this->forget();
            return;
        }
        $this->user = $row;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    public function role(): string
    {
        return (string) ($this->user['role'] ?? self::ROLE_GUEST);
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === self::ROLE_SUPER;
    }

    /**
     * 权限判定。超级管理员拥有通配权限。
     */
    public function can(string $permission): bool
    {
        if (!$this->check()) {
            return false;
        }
        $granted = self::PERMISSIONS[$this->role()] ?? [];
        if (in_array('*', $granted, true)) {
            return true;
        }
        return in_array($permission, $granted, true);
    }

    /**
     * 登录校验：含账号状态、锁定判断、密码校验、失败计数。
     *
     * @return array{ok:bool,message:string}
     */
    public function attempt(string $username, string $password, string $ip): array
    {
        $user = Database::selectOne('SELECT * FROM users WHERE username = :u LIMIT 1', ['u' => $username]);

        // 统一失败文案常量。
        //
        // 【为什么要统一】
        // 若「账号不存在」与「密码错误」返回不同文案，攻击者据此即可枚举出哪些账号真实存在。
        // 早期版本在账号不存在时返回「账号或密码错误。」，但账号被禁用时却返回
        // 「该账号已被禁用，请联系超级管理员。」—— 后者恰恰泄露了「该账号存在」这一事实，
        // 前面的防枚举努力被抵消。锁定提示同理：它只在账号存在且已锁定时才可能出现，
        // 也会成为账号枚举的旁路。
        //
        // 因此除「账号被锁定」外，所有失败一律返回同一句中性文案。
        $genericFail = '账号或密码错误。';

        if ($user === null) {
            $this->recordAttempt($username, $ip, false);
            return ['ok' => false, 'message' => $genericFail];
        }

        // 账号被禁用：文案同样归一化，不暴露「该账号存在」
        if ((int) $user['status'] !== 1) {
            $this->recordAttempt($username, $ip, false);
            return ['ok' => false, 'message' => $genericFail];
        }

        // 锁定提示保留：此时密码已验证到「该账号确实存在且被锁定」的程度，
        // 若连这个也隐藏，用户将无法理解自己为何突然登不进去（且这是账号主本人可见的状态）。
        $lockedUntil = $user['locked_until'] !== null ? strtotime((string) $user['locked_until']) : 0;
        if ($lockedUntil > time()) {
            $remain = (int) ceil(($lockedUntil - time()) / 60);
            return ['ok' => false, 'message' => '账号因多次密码错误已被锁定，请 ' . $remain . ' 分钟后重试。'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->recordAttempt($username, $ip, false);
            $failures = (int) $user['login_fail_count'] + 1;
            $update   = ['login_fail_count' => $failures];
            if ($failures >= self::MAX_LOGIN_FAILURES) {
                $update['locked_until']    = date('Y-m-d H:i:s', time() + self::LOCK_SECONDS);
                $update['login_fail_count'] = 0;
            }
            Database::update('users', $update, 'id = :id', ['id' => (int) $user['id']]);

            // 不再提示「还可尝试 N 次」。
            // 剩余次数是对攻击者有价值的信息：它能精确测定该账号是否真实存在
            // （不存在的账号永远不会出现第 N 次提示），也能据此判断字典规模。
            // 达到阈值时说明账号已被锁定，此时提示锁定是可接受的。
            if ($failures >= self::MAX_LOGIN_FAILURES) {
                return ['ok' => false, 'message' => '密码连续错误 ' . $failures . ' 次，账号已锁定 15 分钟。'];
            }
            return ['ok' => false, 'message' => $genericFail];
        }

        // 登录成功
        $this->recordAttempt($username, $ip, true);
        Database::update('users', [
            'login_fail_count' => 0,
            'locked_until'     => null,
            'last_login_at'    => now(),
            'last_login_ip'    => $ip,
        ], 'id = :id', ['id' => (int) $user['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = (int) $user['id'];
        self::$instance            = null;

        Logger::operation('auth.login', 'user', (int) $user['id'], '登录后台成功');
        return ['ok' => true, 'message' => '登录成功。'];
    }

    public function logout(): void
    {
        if ($this->check()) {
            Logger::operation('auth.logout', 'user', $this->id(), '退出后台登录');
        }
        $this->forget();
    }

    private function forget(): void
    {
        unset($_SESSION['admin_user_id']);
        $this->user = null;
    }

    private function recordAttempt(string $username, string $ip, bool $success): void
    {
        try {
            Database::insert('login_attempts', [
                'username'   => mb_substr($username, 0, 50, 'UTF-8'),
                'ip'         => $ip,
                'success'    => $success ? 1 : 0,
                'user_agent' => Request::userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 记录失败不阻断登录流程
        }
    }
}
