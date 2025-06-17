<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 邀请奖励配置模型
 */
class InvitationReward extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'invitation_rewards';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'level' => 'integer',
        'reward_value' => 'float',
        'min_deposit' => 'float',
        'max_reward' => 'float',
        'is_active' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'created_at'];
    
    /**
     * 邀请层级常量
     */
    public const LEVEL_FIRST = 1;        // 一级邀请
    public const LEVEL_SECOND = 2;       // 二级邀请
    public const LEVEL_THIRD = 3;        // 三级邀请
    
    /**
     * 奖励类型常量
     */
    public const REWARD_TYPE_FIXED = 'fixed';      // 固定金额
    public const REWARD_TYPE_PERCENT = 'percent';  // 百分比
    
    /**
     * 状态常量
     */
    public const STATUS_INACTIVE = 0;    // 禁用
    public const STATUS_ACTIVE = 1;      // 启用
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'level' => 'required|in:1,2,3',
            'reward_type' => 'required|in:fixed,percent',
            'reward_value' => 'required|float|min:0',
            'min_deposit' => 'float|min:0',
            'max_reward' => 'float|min:0',
            'is_active' => 'in:0,1',
        ];
    }
    
    /**
     * 奖励数值修改器
     */
    public function setRewardValueAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 最低充值要求修改器
     */
    public function setMinDepositAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 最大奖励金额修改器
     */
    public function setMaxRewardAttr($value)
    {
        return $value ? round((float)$value, 2) : null;
    }
    
    /**
     * 创建时间修改器
     */
    public function setCreatedAtAttr($value)
    {
        if (is_string($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 更新时间修改器
     */
    public function setUpdatedAtAttr($value)
    {
        if (is_string($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 邀请层级获取器
     */
    public function getLevelTextAttr($value, $data)
    {
        $levels = [
            self::LEVEL_FIRST => '一级邀请',
            self::LEVEL_SECOND => '二级邀请',
            self::LEVEL_THIRD => '三级邀请',
        ];
        return $levels[$data['level']] ?? '未知层级';
    }
    
    /**
     * 奖励类型获取器
     */
    public function getRewardTypeTextAttr($value, $data)
    {
        $types = [
            self::REWARD_TYPE_FIXED => '固定金额',
            self::REWARD_TYPE_PERCENT => '按比例',
        ];
        return $types[$data['reward_type']] ?? '未知类型';
    }
    
    /**
     * 是否启用获取器
     */
    public function getIsActiveTextAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 ? '启用' : '禁用';
    }
    
    /**
     * 启用状态颜色获取器
     */
    public function getIsActiveColorAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 ? 'success' : 'danger';
    }
    
    /**
     * 是否启用
     */
    public function getActiveAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1;
    }
    
    /**
     * 奖励描述获取器
     */
    public function getRewardDescAttr($value, $data)
    {
        $rewardType = $data['reward_type'] ?? '';
        $rewardValue = $data['reward_value'] ?? 0;
        
        if ($rewardType === self::REWARD_TYPE_FIXED) {
            return number_format($rewardValue, 2) . ' USDT';
        } elseif ($rewardType === self::REWARD_TYPE_PERCENT) {
            return $rewardValue . '%';
        } else {
            return '未配置';
        }
    }
    
    /**
     * 最低充值要求格式化
     */
    public function getFormattedMinDepositAttr($value, $data)
    {
        $minDeposit = $data['min_deposit'] ?? 0;
        return $minDeposit > 0 ? number_format($minDeposit, 2) . ' USDT' : '无要求';
    }
    
    /**
     * 最大奖励格式化
     */
    public function getFormattedMaxRewardAttr($value, $data)
    {
        $maxReward = $data['max_reward'] ?? null;
        return $maxReward ? number_format($maxReward, 2) . ' USDT' : '无限制';
    }
    
    /**
     * 完整配置描述
     */
    public function getConfigDescAttr($value, $data)
    {
        $desc = $this->level_text . ': ' . $this->reward_desc;
        
        if ($this->min_deposit > 0) {
            $desc .= ', 最低充值: ' . $this->formatted_min_deposit;
        }
        
        if ($this->max_reward > 0) {
            $desc .= ', 最高奖励: ' . $this->formatted_max_reward;
        }
        
        return $desc;
    }
    
    /**
     * 创建奖励配置
     */
    public static function createReward(array $data): InvitationReward
    {
        $reward = new static();
        
        // 设置默认值
        $data = array_merge([
            'is_active' => self::STATUS_ACTIVE,
            'min_deposit' => 0.00,
            'max_reward' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $data);
        
        $reward->save($data);
        
        // 清除缓存
        $reward->clearRewardCache();
        
        // 记录创建日志
        trace([
            'action' => 'invitation_reward_created',
            'reward_id' => $reward->id,
            'level' => $reward->level,
            'reward_type' => $reward->reward_type,
            'reward_value' => $reward->reward_value,
            'timestamp' => date('Y-m-d H:i:s'),
        ], 'invitation_reward');
        
        return $reward;
    }
    
    /**
     * 更新奖励配置
     */
    public function updateReward(array $data): bool
    {
        $updateFields = [
            'reward_type', 'reward_value', 'min_deposit', 
            'max_reward', 'is_active'
        ];
        
        $oldData = $this->toArray();
        
        foreach ($updateFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->$field = $data[$field];
            }
        }
        
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            // 清除缓存
            $this->clearRewardCache();
            
            // 记录更新日志
            trace([
                'action' => 'invitation_reward_updated',
                'reward_id' => $this->id,
                'level' => $this->level,
                'old_data' => $oldData,
                'new_data' => array_intersect_key($data, array_flip($updateFields)),
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'invitation_reward');
        }
        
        return $result;
    }
    
    /**
     * 启用奖励配置
     */
    public function enable(): bool
    {
        if ($this->is_active === self::STATUS_ACTIVE) {
            return true;
        }
        
        $this->is_active = self::STATUS_ACTIVE;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearRewardCache();
            
            trace([
                'action' => 'invitation_reward_enabled',
                'reward_id' => $this->id,
                'level' => $this->level,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'invitation_reward');
        }
        
        return $result;
    }
    
    /**
     * 禁用奖励配置
     */
    public function disable(): bool
    {
        if ($this->is_active === self::STATUS_INACTIVE) {
            return true;
        }
        
        $this->is_active = self::STATUS_INACTIVE;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearRewardCache();
            
            trace([
                'action' => 'invitation_reward_disabled',
                'reward_id' => $this->id,
                'level' => $this->level,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'invitation_reward');
        }
        
        return $result;
    }
    
    /**
     * 计算奖励金额
     */
    public function calculateReward(float $depositAmount): float
    {
        // 检查是否启用
        if (!$this->active) {
            return 0.00;
        }
        
        // 检查最低充值要求
        if ($this->min_deposit > 0 && $depositAmount < $this->min_deposit) {
            return 0.00;
        }
        
        // 计算奖励金额
        if ($this->reward_type === self::REWARD_TYPE_FIXED) {
            $rewardAmount = $this->reward_value;
        } else {
            // 按比例计算
            $rewardAmount = $depositAmount * ($this->reward_value / 100);
        }
        
        // 限制最大奖励
        if ($this->max_reward > 0) {
            $rewardAmount = min($rewardAmount, $this->max_reward);
        }
        
        return round($rewardAmount, 2);
    }
    
    /**
     * 验证配置有效性
     */
    public function validateConfig(): array
    {
        $errors = [];
        
        if ($this->reward_value <= 0) {
            $errors[] = '奖励数值必须大于0';
        }
        
        if ($this->reward_type === self::REWARD_TYPE_PERCENT && $this->reward_value > 100) {
            $errors[] = '百分比奖励不能超过100%';
        }
        
        if ($this->max_reward > 0 && $this->reward_type === self::REWARD_TYPE_FIXED && $this->reward_value > $this->max_reward) {
            $errors[] = '固定奖励金额不能超过最大奖励限制';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * 获取指定层级的奖励配置
     */
    public static function getByLevel(int $level): ?InvitationReward
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('invitation', 'reward_config', $level);
        $cachedConfig = CacheHelper::get($cacheKey);
        
        if ($cachedConfig !== null) {
            $reward = new static();
            $reward->data($cachedConfig);
            return $reward;
        }
        
        // 从数据库获取
        $reward = static::where('level', $level)
                       ->where('is_active', self::STATUS_ACTIVE)
                       ->find();
        
        if ($reward) {
            // 更新缓存
            CacheHelper::set($cacheKey, $reward->toArray(), 3600);
        }
        
        return $reward;
    }
    
    /**
     * 获取所有活跃的奖励配置
     */
    public static function getActiveRewards(): array
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('invitation', 'reward_configs', 'active');
        $cachedConfigs = CacheHelper::get($cacheKey);
        
        if ($cachedConfigs !== null) {
            return $cachedConfigs;
        }
        
        // 从数据库获取
        $rewards = static::where('is_active', self::STATUS_ACTIVE)
                        ->order('level ASC')
                        ->select()
                        ->toArray();
        
        // 更新缓存
        CacheHelper::set($cacheKey, $rewards, 3600);
        
        return $rewards;
    }
    
    /**
     * 获取所有奖励配置
     */
    public static function getAllRewards(): array
    {
        return static::order('level ASC')->select()->toArray();
    }
    
    /**
     * 批量更新状态
     */
    public static function batchUpdateStatus(array $rewardIds, int $status): int
    {
        if (empty($rewardIds) || !in_array($status, [self::STATUS_INACTIVE, self::STATUS_ACTIVE])) {
            return 0;
        }
        
        $count = static::whereIn('id', $rewardIds)->update([
            'is_active' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        if ($count > 0) {
            static::clearAllRewardCache();
        }
        
        return $count;
    }
    
    /**
     * 获取奖励统计
     */
    public static function getRewardStats(): array
    {
        return [
            'total_configs' => static::count(),
            'active_configs' => static::where('is_active', self::STATUS_ACTIVE)->count(),
            'inactive_configs' => static::where('is_active', self::STATUS_INACTIVE)->count(),
            'fixed_rewards' => static::where('reward_type', self::REWARD_TYPE_FIXED)->count(),
            'percent_rewards' => static::where('reward_type', self::REWARD_TYPE_PERCENT)->count(),
            'avg_fixed_reward' => static::where('reward_type', self::REWARD_TYPE_FIXED)
                                       ->where('is_active', self::STATUS_ACTIVE)
                                       ->avg('reward_value'),
            'avg_percent_reward' => static::where('reward_type', self::REWARD_TYPE_PERCENT)
                                         ->where('is_active', self::STATUS_ACTIVE)
                                         ->avg('reward_value'),
        ];
    }
    
    /**
     * 获取奖励计算示例
     */
    public function getCalculationExample(array $depositAmounts = [100, 500, 1000]): array
    {
        $examples = [];
        
        foreach ($depositAmounts as $amount) {
            $reward = $this->calculateReward($amount);
            $examples[] = [
                'deposit_amount' => $amount,
                'reward_amount' => $reward,
                'formatted_deposit' => number_format($amount, 2) . ' USDT',
                'formatted_reward' => number_format($reward, 2) . ' USDT',
            ];
        }
        
        return $examples;
    }
    
    /**
     * 复制配置到其他层级
     */
    public function copyToLevel(int $targetLevel): ?InvitationReward
    {
        if ($targetLevel === $this->level) {
            return null;
        }
        
        // 检查目标层级是否已存在配置
        $existingConfig = static::where('level', $targetLevel)->find();
        if ($existingConfig) {
            return null;
        }
        
        $data = $this->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['level'] = $targetLevel;
        
        return static::createReward($data);
    }
    
    /**
     * 重置为默认配置
     */
    public static function resetToDefault(): bool
    {
        try {
            // 删除现有配置
            static::where('1=1')->delete();
            
            // 创建默认配置
            $defaultConfigs = [
                [
                    'level' => self::LEVEL_FIRST,
                    'reward_type' => self::REWARD_TYPE_FIXED,
                    'reward_value' => 10.00,
                    'min_deposit' => 50.00,
                    'max_reward' => null,
                ],
                [
                    'level' => self::LEVEL_SECOND,
                    'reward_type' => self::REWARD_TYPE_PERCENT,
                    'reward_value' => 5.00,
                    'min_deposit' => 100.00,
                    'max_reward' => 50.00,
                ],
                [
                    'level' => self::LEVEL_THIRD,
                    'reward_type' => self::REWARD_TYPE_PERCENT,
                    'reward_value' => 2.00,
                    'min_deposit' => 200.00,
                    'max_reward' => 20.00,
                ],
            ];
            
            foreach ($defaultConfigs as $config) {
                static::createReward($config);
            }
            
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 清除奖励缓存
     */
    public function clearRewardCache(): void
    {
        $cacheKeys = [
            CacheHelper::key('invitation', 'reward_config', $this->level),
            CacheHelper::key('invitation', 'reward_configs', 'active'),
            CacheHelper::key('invitation', 'reward_configs', 'all'),
        ];
        
        foreach ($cacheKeys as $key) {
            CacheHelper::delete($key);
        }
    }
    
    /**
     * 清除所有奖励缓存
     */
    public static function clearAllRewardCache(): void
    {
        $cacheKeys = [
            CacheHelper::key('invitation', 'reward_configs', 'active'),
            CacheHelper::key('invitation', 'reward_configs', 'all'),
        ];
        
        foreach ($cacheKeys as $key) {
            CacheHelper::delete($key);
        }
        
        // 清除各层级缓存
        for ($level = 1; $level <= 3; $level++) {
            $levelKey = CacheHelper::key('invitation', 'reward_config', $level);
            CacheHelper::delete($levelKey);
        }
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_INACTIVE => '禁用',
            self::STATUS_ACTIVE => '启用',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '配置ID',
            'level' => '邀请层级',
            'reward_type' => '奖励类型',
            'reward_value' => '奖励数值',
            'min_deposit' => '被邀请人最低充值要求',
            'max_reward' => '最大奖励金额',
            'is_active' => '是否启用',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '邀请奖励配置表';
    }
}