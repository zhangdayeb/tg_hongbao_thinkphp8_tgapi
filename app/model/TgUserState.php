<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * Telegram用户状态模型
 */
class TgUserState extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'telegram_user_states';
    
    /**
     * 类型转换 - 修复：expires_at 改为 datetime
     */
    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'expires_at' => 'datetime',  // 修复：改为 datetime 类型
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'tg_user_id', 'created_at'];
    
    /**
     * JSON字段
     */
    protected $json = ['state_data'];
    
    /**
     * 用户状态常量
     */
    public const STATE_IDLE = 'idle';                          // 空闲状态
    public const STATE_RECHARGE_METHOD = 'recharge_method';    // 选择充值方式
    public const STATE_RECHARGE_AMOUNT = 'recharge_amount';    // 输入充值金额
    public const STATE_RECHARGE_PROOF = 'recharge_proof';      // 上传充值凭证
    public const STATE_WITHDRAW_AMOUNT = 'withdraw_amount';    // 输入提现金额
    public const STATE_WITHDRAW_ADDRESS = 'withdraw_address';  // 输入提现地址
    public const STATE_WITHDRAW_PASSWORD = 'withdraw_password'; // 输入提现密码
    public const STATE_SET_WITHDRAW_PWD = 'set_withdraw_pwd';  // 设置提现密码
    public const STATE_SEND_REDPACKET = 'send_redpacket';      // 发送红包
    public const STATE_REDPACKET_AMOUNT = 'redpacket_amount';  // 红包金额
    public const STATE_REDPACKET_COUNT = 'redpacket_count';    // 红包个数
    public const STATE_BIND_PHONE = 'bind_phone';              // 绑定手机号
    public const STATE_VERIFY_PHONE = 'verify_phone';          // 验证手机号
    public const STATE_SET_PASSWORD = 'set_password';          // 设置密码
    public const STATE_REGISTRATION = 'registration';          // 注册流程
    
    /**
     * 默认状态过期时间（秒）
     */
    public const DEFAULT_EXPIRE_TIME = 1800; // 30分钟
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'user_id' => 'required|integer',
            'tg_user_id' => 'required',
            'current_state' => 'required|maxLength:50',
            'expires_at' => 'date',
        ];
    }
    
    /**
     * 状态数据修改器
     */
    public function setStateDataAttr($value)
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }
    
    /**
     * 状态数据获取器
     */
    public function getStateDataAttr($value)
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
    
    /**
     * 过期时间修改器 - 修复：统一使用 datetime 格式
     */
    public function setExpiresAtAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        // 如果是时间戳，转换为datetime格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果是字符串，检查格式
        if (is_string($value)) {
            // 如果已经是正确格式
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return $value;
            }
            
            // 尝试解析其他格式
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        return null;
    }
    
    /**
     * 状态文本获取器
     */
    public function getStateTextAttr($value, $data)
    {
        $states = [
            self::STATE_IDLE => '空闲',
            self::STATE_RECHARGE_METHOD => '选择充值方式',
            self::STATE_RECHARGE_AMOUNT => '输入充值金额',
            self::STATE_RECHARGE_PROOF => '上传充值凭证',
            self::STATE_WITHDRAW_AMOUNT => '输入提现金额',
            self::STATE_WITHDRAW_ADDRESS => '输入提现地址',
            self::STATE_WITHDRAW_PASSWORD => '输入提现密码',
            self::STATE_SET_WITHDRAW_PWD => '设置提现密码',
            self::STATE_SEND_REDPACKET => '发送红包',
            self::STATE_REDPACKET_AMOUNT => '输入红包金额',
            self::STATE_REDPACKET_COUNT => '输入红包个数',
            self::STATE_BIND_PHONE => '绑定手机号',
            self::STATE_VERIFY_PHONE => '验证手机号',
            self::STATE_SET_PASSWORD => '设置密码',
            self::STATE_REGISTRATION => '注册流程',
        ];
        return $states[$data['current_state']] ?? '未知状态';
    }
    
    /**
     * 是否已过期 - 修复：使用 datetime 字符串比较
     */
    public function getIsExpiredAttr($value, $data)
    {
        $expiresAt = $data['expires_at'] ?? null;
        
        if (empty($expiresAt)) {
            return false;
        }
        
        // 使用 datetime 字符串直接比较
        $currentTime = date('Y-m-d H:i:s');
        return $expiresAt < $currentTime;
    }
    
    /**
     * 剩余时间（秒）- 修复：统一时间处理
     */
    public function getRemainingTimeAttr($value, $data)
    {
        $expiresAt = $data['expires_at'] ?? null;
        
        if (empty($expiresAt)) {
            return 0;
        }
        
        // 转换为时间戳计算
        $expiresTimestamp = strtotime($expiresAt);
        $currentTimestamp = strtotime(date('Y-m-d H:i:s'));
        
        return max(0, $expiresTimestamp - $currentTimestamp);
    }
    
    /**
     * 剩余时间文本
     */
    public function getRemainingTimeTextAttr($value, $data)
    {
        $remainingTime = $this->remaining_time;
        
        if ($remainingTime <= 0) {
            return '已过期';
        }
        
        if ($remainingTime < 60) {
            return $remainingTime . '秒';
        } elseif ($remainingTime < 3600) {
            return round($remainingTime / 60) . '分钟';
        } else {
            return round($remainingTime / 3600, 1) . '小时';
        }
    }
    
    /**
     * 过期时间格式化
     */
    public function getExpiresAtTextAttr($value, $data)
    {
        $expiresAt = $data['expires_at'] ?? '';
        return $expiresAt ?: '';
    }
    
    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * 设置用户状态 - 修复：使用 datetime 格式
     */
    public static function setState(string $tgUserId, string $state, array $data = [], int $expireTime = null): TgUserState
    {
        $expireTime = $expireTime ?? (time() + self::DEFAULT_EXPIRE_TIME);
        
        // 查找或创建状态记录
        $userState = static::where('tg_user_id', $tgUserId)->find();
        
        if (!$userState) {
            $userState = new static();
            $userState->tg_user_id = $tgUserId;
            
            // 尝试获取用户ID
            $user = User::findByTgId($tgUserId);
            if ($user) {
                $userState->user_id = $user->id;
            }
        }
        
        $userState->current_state = $state;
        $userState->state_data = $data;
        $userState->expires_at = date('Y-m-d H:i:s', $expireTime); // 修复：使用 datetime 格式
        $userState->updated_at = date('Y-m-d H:i:s');
        
        $userState->save();
        
        // 更新缓存
        $userState->updateStateCache();
        
        // 记录状态变更日志
        trace([
            'action' => 'user_state_set',
            'tg_user_id' => $tgUserId,
            'state' => $state,
            'data' => $data,
            'expires_at' => $expireTime,
            'timestamp' => time(),
        ], 'telegram_state');
        
        return $userState;
    }
    
    /**
     * 获取用户状态
     */
    public static function getState(string $tgUserId): ?TgUserState
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('telegram', 'user_state', $tgUserId);
        $cachedState = CacheHelper::get($cacheKey);
        
        if ($cachedState !== null) {
            // 检查是否过期
            $currentTime = date('Y-m-d H:i:s');
            if (!empty($cachedState['expires_at']) && $cachedState['expires_at'] < $currentTime) {
                static::clearState($tgUserId);
                return null;
            }
            
            // 从缓存数据创建模型实例
            $userState = new static();
            $userState->data($cachedState);
            return $userState;
        }
        
        // 从数据库获取
        $userState = static::where('tg_user_id', $tgUserId)->find();
        
        if (!$userState) {
            return null;
        }
        
        // 检查是否过期
        if ($userState->is_expired) {
            $userState->delete();
            return null;
        }
        
        // 更新缓存
        $userState->updateStateCache();
        
        return $userState;
    }
    
    /**
     * 清除用户状态
     */
    public static function clearState(string $tgUserId): bool
    {
        // 删除数据库记录
        $deleted = static::where('tg_user_id', $tgUserId)->delete();
        
        // 清除缓存
        $cacheKey = CacheHelper::key('telegram', 'user_state', $tgUserId);
        CacheHelper::delete($cacheKey);
        
        // 记录清除日志
        trace([
            'action' => 'user_state_cleared',
            'tg_user_id' => $tgUserId,
            'timestamp' => time(),
        ], 'telegram_state');
        
        return $deleted > 0;
    }
    
    /**
     * 更新状态数据
     */
    public function updateStateData(array $data, bool $merge = true): bool
    {
        if ($merge && is_array($this->state_data)) {
            $this->state_data = array_merge($this->state_data, $data);
        } else {
            $this->state_data = $data;
        }
        
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->updateStateCache();
        }
        
        return $result;
    }
    
    /**
     * 延长状态过期时间 - 修复：使用 datetime 格式
     */
    public function extendExpireTime(int $seconds): bool
    {
        $newExpireTime = time() + $seconds;
        $this->expires_at = date('Y-m-d H:i:s', $newExpireTime); // 修复：使用 datetime 格式
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->updateStateCache();
        }
        
        return $result;
    }
    
    /**
     * 检查状态是否匹配
     */
    public function isState(string $state): bool
    {
        return $this->current_state === $state && !$this->is_expired;
    }
    
    /**
     * 获取状态数据中的指定键值
     */
    public function getStateValue(string $key, $default = null)
    {
        $stateData = $this->state_data;
        return is_array($stateData) ? ($stateData[$key] ?? $default) : $default;
    }
    
    /**
     * 设置状态数据中的指定键值
     */
    public function setStateValue(string $key, $value): bool
    {
        $stateData = is_array($this->state_data) ? $this->state_data : [];
        $stateData[$key] = $value;
        
        return $this->updateStateData($stateData, false);
    }
    
    /**
     * 批量清理过期状态 - 修复：使用 datetime 比较
     */
    public static function cleanupExpired(): int
    {
        $currentTime = date('Y-m-d H:i:s');
        
        $count = static::where('expires_at', '<', $currentTime)
                      ->where('expires_at', 'is not null')
                      ->delete();
        
        // 记录清理日志
        if ($count > 0) {
            trace([
                'action' => 'expired_states_cleaned',
                'count' => $count,
                'timestamp' => time(),
            ], 'telegram_state');
        }
        
        return $count;
    }
    
    /**
     * 获取用户状态统计
     */
    public static function getStateStats(): array
    {
        $currentTime = date('Y-m-d H:i:s');
        
        $totalStates = static::count();
        $activeStates = static::where('expires_at', '>', $currentTime)->count();
        $expiredStates = static::where('expires_at', '<=', $currentTime)
                              ->where('expires_at', 'is not null')
                              ->count();
        
        // 按状态类型统计
        $stateTypes = static::field('current_state, COUNT(*) as count')
                           ->where('expires_at', '>', $currentTime)
                           ->group('current_state')
                           ->select()
                           ->toArray();
        
        return [
            'total_states' => $totalStates,
            'active_states' => $activeStates,
            'expired_states' => $expiredStates,
            'state_types' => $stateTypes,
        ];
    }
    
    /**
     * 获取指定状态的用户列表
     */
    public static function getUsersByState(string $state): array
    {
        $currentTime = date('Y-m-d H:i:s');
        
        return static::where('current_state', $state)
                    ->where('expires_at', '>', $currentTime)
                    ->select()
                    ->toArray();
    }
    
    /**
     * 批量设置用户状态为空闲
     */
    public static function setUsersIdle(array $tgUserIds): int
    {
        if (empty($tgUserIds)) {
            return 0;
        }
        
        return static::whereIn('tg_user_id', $tgUserIds)->delete();
    }
    
    /**
     * 更新状态缓存
     */
    private function updateStateCache(): void
    {
        $cacheKey = CacheHelper::key('telegram', 'user_state', $this->tg_user_id);
        $cacheData = $this->toArray();
        
        // 计算缓存过期时间
        $remainingTime = $this->remaining_time;
        $expireTime = max(60, $remainingTime); // 至少缓存1分钟
        
        CacheHelper::set($cacheKey, $cacheData, $expireTime);
    }
    
    /**
     * 清除状态缓存
     */
    public function clearStateCache(): void
    {
        $cacheKey = CacheHelper::key('telegram', 'user_state', $this->tg_user_id);
        CacheHelper::delete($cacheKey);
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATE_IDLE => '空闲',
            self::STATE_RECHARGE_METHOD => '选择充值方式',
            self::STATE_RECHARGE_AMOUNT => '输入充值金额',
            self::STATE_WITHDRAW_AMOUNT => '输入提现金额',
            self::STATE_SEND_REDPACKET => '发送红包',
            self::STATE_REGISTRATION => '注册流程',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '状态记录ID',
            'user_id' => '用户ID',
            'tg_user_id' => 'Telegram用户ID',
            'current_state' => '当前状态',
            'state_data' => '状态数据JSON',
            'expires_at' => '过期时间',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return 'Telegram用户状态表';
    }
}