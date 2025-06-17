<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;

/**
 * 用户状态管理服务
 * 负责管理用户在充值流程中的状态
 */
class UserStateService
{
    // 状态常量
    const STATE_NORMAL = 'normal';
    const STATE_SELECTING_PAYMENT = 'selecting_payment';
    const STATE_ENTERING_AMOUNT = 'entering_amount';
    const STATE_CONFIRMING_PAYMENT = 'confirming_payment';
    const STATE_WAITING_TRANSFER = 'waiting_transfer';
    const STATE_ENTERING_ORDER_ID = 'entering_order_id';
    const STATE_RECHARGE_COMPLETED = 'recharge_completed';
    
    // 缓存前缀
    private $cachePrefix = 'tg_user_state_';
    
    // 默认过期时间（秒）
    private $defaultTtl = 300; // 5分钟
    
    /**
     * 获取用户状态
     */
    public function getUserState(int $userId): array
    {
        $cacheKey = $this->cachePrefix . $userId;
        $stateData = Cache::get($cacheKey);
        
        if (!$stateData) {
            return [
                'state' => self::STATE_NORMAL,
                'data' => [],
                'created_at' => time(),
                'expire_at' => 0
            ];
        }
        
        // 检查是否过期
        if ($stateData['expire_at'] > 0 && time() > $stateData['expire_at']) {
            $this->clearUserState($userId);
            return [
                'state' => self::STATE_NORMAL,
                'data' => [],
                'created_at' => time(),
                'expire_at' => 0
            ];
        }
        
        return $stateData;
    }
    
    /**
     * 设置用户状态
     */
    public function setUserState(int $userId, string $state, array $data = [], int $ttl = null): bool
    {
        if ($ttl === null) {
            $ttl = $this->getStateTtl($state);
        }
        
        $stateData = [
            'state' => $state,
            'data' => $data,
            'created_at' => time(),
            'expire_at' => time() + $ttl
        ];
        
        $cacheKey = $this->cachePrefix . $userId;
        return Cache::set($cacheKey, $stateData, $ttl);
    }
    
    /**
     * 清除用户状态
     */
    public function clearUserState(int $userId): bool
    {
        $cacheKey = $this->cachePrefix . $userId;
        return Cache::delete($cacheKey);
    }
    
    /**
     * 检查用户是否在特定状态
     */
    public function isUserInState(int $userId, string $state): bool
    {
        $userState = $this->getUserState($userId);
        return $userState['state'] === $state;
    }
    
    /**
     * 检查用户是否在充值流程中
     */
    public function isUserInRechargeFlow(int $userId): bool
    {
        $userState = $this->getUserState($userId);
        $rechargeStates = [
            self::STATE_SELECTING_PAYMENT,
            self::STATE_ENTERING_AMOUNT,
            self::STATE_CONFIRMING_PAYMENT,
            self::STATE_WAITING_TRANSFER,
            self::STATE_ENTERING_ORDER_ID
        ];
        
        return in_array($userState['state'], $rechargeStates);
    }
    
    /**
     * 更新用户状态数据（保持状态不变）
     */
    public function updateUserStateData(int $userId, array $data): bool
    {
        $userState = $this->getUserState($userId);
        if ($userState['state'] === self::STATE_NORMAL) {
            return false;
        }
        
        $userState['data'] = array_merge($userState['data'], $data);
        $remainingTtl = $userState['expire_at'] - time();
        
        if ($remainingTtl <= 0) {
            return false;
        }
        
        return $this->setUserState($userId, $userState['state'], $userState['data'], $remainingTtl);
    }
    
    /**
     * 获取用户状态数据
     */
    public function getUserStateData(int $userId, string $key = null)
    {
        $userState = $this->getUserState($userId);
        
        if ($key === null) {
            return $userState['data'];
        }
        
        return $userState['data'][$key] ?? null;
    }
    
    /**
     * 获取状态剩余时间
     */
    public function getStateRemainingTime(int $userId): int
    {
        $userState = $this->getUserState($userId);
        
        if ($userState['expire_at'] === 0) {
            return 0;
        }
        
        $remaining = $userState['expire_at'] - time();
        return max(0, $remaining);
    }
    
    /**
     * 获取格式化的剩余时间
     */
    public function getFormattedRemainingTime(int $userId, string $format = 'precise'): string
    {
        $remainingSeconds = $this->getStateRemainingTime($userId);
        
        if ($remainingSeconds <= 0) {
            return '已超时';
        }
        
        if ($format === 'precise') {
            $minutes = floor($remainingSeconds / 60);
            $seconds = $remainingSeconds % 60;
            return "{$minutes}分{$seconds}秒";
        } else {
            $minutes = ceil($remainingSeconds / 60);
            return "{$minutes}分钟";
        }
    }
    
    /**
     * 处理状态过期
     */
    public function handleStateExpiry(int $userId): bool
    {
        $userState = $this->getUserState($userId);
        
        if ($userState['state'] === self::STATE_NORMAL) {
            return false;
        }
        
        if ($userState['expire_at'] > 0 && time() > $userState['expire_at']) {
            $this->clearUserState($userId);
            return true;
        }
        
        return false;
    }
    
    /**
     * 清理所有过期状态（定时任务可调用）
     */
    public function cleanExpiredStates(): int
    {
        // 这里简化实现，实际项目中可以遍历所有缓存键
        // 由于Cache接口限制，这里只返回清理数量的模拟值
        return 0;
    }
    
    /**
     * 获取状态的TTL
     */
    private function getStateTtl(string $state): int
    {
        $stateTtls = config('telegram.user_states.expire_times', []);
        
        $stateMap = [
            self::STATE_SELECTING_PAYMENT => 'SELECTING_PAYMENT',
            self::STATE_ENTERING_AMOUNT => 'ENTERING_AMOUNT',
            self::STATE_CONFIRMING_PAYMENT => 'CONFIRMING_PAYMENT',
            self::STATE_WAITING_TRANSFER => 'WAITING_TRANSFER',
            self::STATE_ENTERING_ORDER_ID => 'ENTERING_ORDER_ID',
            self::STATE_RECHARGE_COMPLETED => 'RECHARGE_COMPLETED'
        ];
        
        $configKey = $stateMap[$state] ?? null;
        if ($configKey && isset($stateTtls[$configKey])) {
            return $stateTtls[$configKey];
        }
        
        return $this->defaultTtl;
    }
    
    /**
     * 获取状态流转的下一个状态
     */
    public function getNextState(string $currentState): string
    {
        $stateFlow = [
            self::STATE_NORMAL => self::STATE_SELECTING_PAYMENT,
            self::STATE_SELECTING_PAYMENT => self::STATE_ENTERING_AMOUNT,
            self::STATE_ENTERING_AMOUNT => self::STATE_CONFIRMING_PAYMENT,
            self::STATE_CONFIRMING_PAYMENT => self::STATE_WAITING_TRANSFER,
            self::STATE_WAITING_TRANSFER => self::STATE_ENTERING_ORDER_ID,
            self::STATE_ENTERING_ORDER_ID => self::STATE_RECHARGE_COMPLETED,
            self::STATE_RECHARGE_COMPLETED => self::STATE_NORMAL
        ];
        
        return $stateFlow[$currentState] ?? self::STATE_NORMAL;
    }
    
    /**
     * 验证状态流转是否合法
     */
    public function isValidStateTransition(string $fromState, string $toState): bool
    {
        $validTransitions = [
            self::STATE_NORMAL => [self::STATE_SELECTING_PAYMENT],
            self::STATE_SELECTING_PAYMENT => [self::STATE_ENTERING_AMOUNT, self::STATE_NORMAL],
            self::STATE_ENTERING_AMOUNT => [self::STATE_CONFIRMING_PAYMENT, self::STATE_NORMAL],
            self::STATE_CONFIRMING_PAYMENT => [self::STATE_WAITING_TRANSFER, self::STATE_NORMAL],
            self::STATE_WAITING_TRANSFER => [self::STATE_ENTERING_ORDER_ID, self::STATE_NORMAL],
            self::STATE_ENTERING_ORDER_ID => [self::STATE_RECHARGE_COMPLETED, self::STATE_NORMAL],
            self::STATE_RECHARGE_COMPLETED => [self::STATE_NORMAL]
        ];
        
        return in_array($toState, $validTransitions[$fromState] ?? []);
    }
}