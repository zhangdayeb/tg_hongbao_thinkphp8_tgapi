<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 管理员模型
 */
class Admin extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'common_admin';
    
    /**
     * 隐藏字段
     */
    protected $hidden = ['pwd', 'delete_time'];
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'role' => 'integer',
        'market_level' => 'integer',
        'status' => 'integer',
        'token_version' => 'integer',
        'login_count' => 'integer',
        'create_time' => 'datetime',
        'last_login_time' => 'datetime',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'create_time'];
    
    /**
     * 角色常量
     */
    public const ROLE_SUPER_ADMIN = 1;    // 超级管理员
    public const ROLE_ADMIN = 2;          // 普通管理员
    public const ROLE_SERVICE = 3;        // 客服管理员
    public const ROLE_FINANCE = 4;        // 财务管理员
    public const ROLE_OPERATOR = 5;       // 运营管理员
    
    /**
     * 状态常量
     */
    public const STATUS_DISABLED = 0;     // 禁用
    public const STATUS_NORMAL = 1;       // 正常
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'user_name' => 'required|alphaNum|minLength:3|maxLength:20',
            'pwd' => 'required|minLength:6',
            'role' => 'required|in:1,2,3,4,5',
            'phone' => 'phone:CN',
            'status' => 'in:0,1',
        ];
    }
    
    /**
     * 密码修改器
     */
    public function setPwdAttr($value)
    {
        return $value ? base64_encode($value) : '';
    }
    
    /**
     * 密码获取器
     */
    public function getPwdAttr($value)
    {
        return $value ? base64_decode($value) : '';
    }
    
    /**
     * 创建时间修改器
     */
    public function setCreateTimeAttr($value)
    {
        if (is_string($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 最后登录时间修改器
     */
    public function setLastLoginTimeAttr($value)
    {
        if (is_string($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 角色获取器
     */
    public function getRoleTextAttr($value, $data)
    {
        $roles = [
            self::ROLE_SUPER_ADMIN => '超级管理员',
            self::ROLE_ADMIN => '普通管理员',
            self::ROLE_SERVICE => '客服管理员',
            self::ROLE_FINANCE => '财务管理员',
            self::ROLE_OPERATOR => '运营管理员',
        ];
        return $roles[$data['role']] ?? '未知';
    }
    
    /**
     * 权限获取器
     */
    public function getPermissionsAttr($value, $data)
    {
        return $this->getRolePermissions($data['role'] ?? 0);
    }
    
    /**
     * 是否为超级管理员
     */
    public function getIsSuperAdminAttr($value, $data)
    {
        return ($data['role'] ?? 0) === self::ROLE_SUPER_ADMIN;
    }
    
    /**
     * 最后登录时间获取器
     */
    public function getLastLoginTimeTextAttr($value, $data)
    {
        $lastLoginTime = $data['last_login_time'] ?? null;
        return $lastLoginTime ? $lastLoginTime : '从未登录';
    }
    
    /**
     * 手机号掩码获取器
     */
    public function getPhoneMaskedAttr($value, $data)
    {
        return SecurityHelper::maskSensitiveData($data['phone'] ?? '', 'phone');
    }
    
    /**
     * 根据用户名查找管理员
     */
    public static function findByUsername(string $username): ?Admin
    {
        return static::where('user_name', $username)->find();
    }
    
    /**
     * 创建管理员
     */
    public static function createAdmin(array $data): Admin
    {
        $admin = new static();
        
        // 生成邀请码
        if (empty($data['invitation_code'])) {
            $data['invitation_code'] = $admin->generateInviteCode();
        }
        
        // 设置默认值
        $data = array_merge([
            'role' => self::ROLE_ADMIN,
            'status' => self::STATUS_NORMAL,
            'market_level' => 1,
            'token_version' => 1,
            'login_count' => 0,
            'create_time' => date('Y-m-d H:i:s'),
        ], $data);
        
        $admin->save($data);
        
        // 清除相关缓存
        $admin->clearAdminCache();
        
        return $admin;
    }
    
    /**
     * 验证密码
     */
    public function verifyPassword(string $password): bool
    {
        return $this->pwd === $password;
    }
    
    /**
     * 更改密码
     */
    public function changePassword(string $newPassword): bool
    {
        $this->pwd = $newPassword;
        $this->token_version = ($this->token_version ?? 1) + 1; // 使所有现有token失效
        
        $result = $this->save();
        
        if ($result) {
            $this->clearAdminCache();
        }
        
        return $result;
    }
    
    /**
     * 记录登录
     */
    public function recordLogin(string $ip, string $userAgent): bool
    {
        $this->last_login_time = date('Y-m-d H:i:s');
        $this->last_login_ip = $ip;
        $this->login_count = ($this->login_count ?? 0) + 1;
        
        $result = $this->save();
        
        if ($result) {
            // 记录登录日志
            trace([
                'admin_id' => $this->id,
                'admin_name' => $this->user_name,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'login_time' => date('Y-m-d H:i:s'),
            ], 'admin_login');
            
            $this->clearAdminCache();
        }
        
        return $result;
    }
    
    /**
     * 禁用管理员
     */
    public function disable(string $reason = ''): bool
    {
        $this->status = self::STATUS_DISABLED;
        $result = $this->save();
        
        if ($result) {
            $this->clearAdminCache();
            
            // 记录操作日志
            trace([
                'action' => 'admin_disable',
                'admin_id' => $this->id,
                'admin_name' => $this->user_name,
                'reason' => $reason,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'admin_operation');
        }
        
        return $result;
    }
    
    /**
     * 启用管理员
     */
    public function enable(): bool
    {
        $this->status = self::STATUS_NORMAL;
        $result = $this->save();
        
        if ($result) {
            $this->clearAdminCache();
            
            // 记录操作日志
            trace([
                'action' => 'admin_enable',
                'admin_id' => $this->id,
                'admin_name' => $this->user_name,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'admin_operation');
        }
        
        return $result;
    }
    
    /**
     * 重置Token版本（强制重新登录）
     */
    public function resetTokenVersion(): bool
    {
        $this->token_version = ($this->token_version ?? 1) + 1;
        $result = $this->save();
        
        if ($result) {
            $this->clearAdminCache();
        }
        
        return $result;
    }
    
    /**
     * 检查权限
     */
    public function hasPermission(string $permission): bool
    {
        // 超级管理员拥有所有权限
        if ($this->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }
        
        $permissions = $this->getRolePermissions($this->role);
        return in_array($permission, $permissions);
    }
    
    /**
     * 获取角色权限
     */
    public function getRolePermissions(int $role): array
    {
        $permissions = [
            self::ROLE_SUPER_ADMIN => ['*'], // 所有权限
            
            self::ROLE_ADMIN => [
                'dashboard.view',
                'users.view', 'users.edit', 'users.freeze',
                'payment.view', 'payment.approve',
                'redpackets.view', 'redpackets.manage',
                'messages.view', 'messages.send',
                'statistics.view',
            ],
            
            self::ROLE_SERVICE => [
                'dashboard.view',
                'users.view',
                'messages.view', 'messages.send', 'messages.broadcast',
                'redpackets.view',
            ],
            
            self::ROLE_FINANCE => [
                'dashboard.view',
                'users.view',
                'payment.view', 'payment.approve', 'payment.manage',
                'statistics.view', 'statistics.finance',
            ],
            
            self::ROLE_OPERATOR => [
                'dashboard.view',
                'users.view', 'users.edit',
                'messages.view', 'messages.send', 'messages.broadcast',
                'redpackets.view', 'redpackets.manage',
                'games.view', 'games.manage',
                'statistics.view',
            ],
        ];
        
        return $permissions[$role] ?? [];
    }
    
    /**
     * 获取管理员统计信息
     */
    public function getStats(): array
    {
        // 根据角色返回不同的统计信息
        $stats = [
            'login_count' => $this->login_count ?? 0,
            'last_login_time' => $this->last_login_time,
            'role_text' => $this->role_text,
        ];
        
        // 添加角色特定的统计
        switch ($this->role) {
            case self::ROLE_FINANCE:
                // 财务相关统计
                $stats['pending_recharge'] = 0; // 这里需要根据实际业务调用相关模型
                $stats['pending_withdraw'] = 0;
                break;
                
            case self::ROLE_SERVICE:
                // 客服相关统计
                $stats['today_messages'] = 0; // 今日发送消息数
                $todayStart = date('Y-m-d 00:00:00');
                $stats['active_users'] = User::where('last_activity_at', '>', $todayStart)->count();
                break;
        }
        
        return $stats;
    }
    
    /**
     * 获取操作日志
     */
    public function getOperationLogs(int $limit = 50): array
    {
        // 这里应该从日志文件或日志表中获取
        // 暂时返回空数组，实际应用中需要实现具体的日志查询逻辑
        return [];
    }
    
    /**
     * 获取登录日志
     */
    public function getLoginLogs(int $limit = 20): array
    {
        // 这里应该从日志文件或日志表中获取
        // 暂时返回空数组，实际应用中需要实现具体的日志查询逻辑
        return [];
    }
    
    /**
     * 生成邀请码
     */
    private function generateInviteCode(): string
    {
        do {
            $code = 'ADMIN' . SecurityHelper::generateRandomString(12, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        } while (static::where('invitation_code', $code)->find());
        
        return $code;
    }
    
    /**
     * 清除管理员缓存
     */
    public function clearAdminCache(): void
    {
        // 清除管理员信息缓存
        $infoKey = CacheHelper::key('admin', 'info', $this->id);
        CacheHelper::delete($infoKey);
        
        // 清除会话缓存
        $sessionKey = CacheHelper::key('admin', 'session', $this->id);
        CacheHelper::delete($sessionKey);
        
        // 清除权限缓存
        $permissionKey = CacheHelper::key('admin', 'permissions', $this->id);
        CacheHelper::delete($permissionKey);
    }
    
    /**
     * 获取隐藏字段
     */
    protected function getHiddenFields(): array
    {
        return array_merge(parent::getHiddenFields(), [
            'pwd', 'token_version'
        ]);
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_NORMAL => '正常',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '管理员ID',
            'user_name' => '管理员账号',
            'pwd' => '密码',
            'role' => '角色',
            'market_level' => '市场部级别',
            'remarks' => '备注',
            'phone' => '手机号码',
            'invitation_code' => '邀请码',
            'status' => '状态',
            'create_time' => '创建时间',
            'last_login_time' => '最后登录时间',
            'last_login_ip' => '最后登录IP',
            'login_count' => '登录次数',
            'token_version' => 'Token版本',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '后台管理员表';
    }
}