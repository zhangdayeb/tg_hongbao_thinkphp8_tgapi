<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 邀请记录模型
 */
class UserInvitation extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'user_invitations';
    
    /**
     * 自动时间戳
     */
    protected $autoWriteTimestamp = true;
    
    /**
     * 时间字段取值为时间戳
     */
    protected $dateFormat = false;
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'inviter_id' => 'integer',
        'invitee_id' => 'integer',
        'reward_amount' => 'float',
        'reward_status' => 'integer',
        'reward_time' => 'string',      // ✅ 修正：对应datetime字段
        'first_deposit_amount' => 'float',
        'completed_at' => 'string',     // ✅ 修正：对应datetime字段
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'inviter_id', 'invitation_code', 'create_time'];
    
    /**
     * 奖励状态常量
     */
    public const REWARD_PENDING = 0;      // 待发放
    public const REWARD_GRANTED = 1;      // 已发放
    public const REWARD_CANCELLED = 2;    // 已取消
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'inviter_id' => 'required|integer',
            'invitation_code' => 'required|unique:user_invitations',
            'reward_amount' => 'float|min:0',
            'reward_status' => 'in:0,1,2',
        ];
    }
    
    /**
     * 数据验证
     */
    public function validateData(): bool
    {
        $rules = $this->getValidationRules();
        if (empty($rules)) {
            return true;
        }
        
        $validator = new ValidatorHelper($this->data, $rules);
        return $validator->validate();
    }
    
    /**
     * 奖励金额修改器
     */
    public function setRewardAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 首次充值金额修改器
     */
    public function setFirstDepositAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 邀请码修改器
     */
    public function setInvitationCodeAttr($value)
    {
        return strtoupper(trim($value));
    }
    
    /**
     * 奖励状态获取器
     */
    public function getRewardStatusTextAttr($value, $data)
    {
        $statuses = [
            self::REWARD_PENDING => '待发放',
            self::REWARD_GRANTED => '已发放',
            self::REWARD_CANCELLED => '已取消',
        ];
        return $statuses[$data['reward_status']] ?? '未知';
    }
    
    /**
     * 奖励状态颜色获取器
     */
    public function getRewardStatusColorAttr($value, $data)
    {
        $colors = [
            self::REWARD_PENDING => 'warning',
            self::REWARD_GRANTED => 'success',
            self::REWARD_CANCELLED => 'danger',
        ];
        return $colors[$data['reward_status']] ?? 'secondary';
    }
    
    /**
     * 格式化奖励金额
     */
    public function getFormattedRewardAttr($value, $data)
    {
        return number_format($data['reward_amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化首次充值金额
     */
    public function getFormattedDepositAttr($value, $data)
    {
        return number_format($data['first_deposit_amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 是否已完成注册
     */
    public function getIsCompletedAttr($value, $data)
    {
        return !empty($data['invitee_id']) && !empty($data['completed_at']);
    }
    
    /**
     * 是否已获得奖励
     */
    public function getHasRewardAttr($value, $data)
    {
        return ($data['reward_status'] ?? 0) === self::REWARD_GRANTED;
    }
    
    /**
     * 邀请完成时间格式化
     */
    public function getCompletedTimeAttr($value, $data)
    {
        $completedAt = $data['completed_at'] ?? 0;
        return $completedAt > 0 ? date('Y-m-d H:i:s', $completedAt) : '';
    }
    
    /**
     * 奖励发放时间格式化
     */
    public function getRewardTimeAttr($value, $data)
    {
        $rewardTime = $data['reward_time'] ?? 0;
        return $rewardTime > 0 ? date('Y-m-d H:i:s', $rewardTime) : '';
    }
    
    /**
     * 关联邀请人
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }
    
    /**
     * 关联被邀请人
     */
    public function invitee()
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }
    
    /**
     * 创建邀请记录
     */
    public static function createInvitation(int $inviterId, string $inviteeTgId = ''): UserInvitation
    {
        $invitation = new static();
        
        // 生成邀请码
        $invitationCode = $invitation->generateInvitationCode();
        
        $data = [
            'inviter_id' => $inviterId,
            'invitation_code' => $invitationCode,
            'invitee_tg_id' => $inviteeTgId,
            'reward_amount' => 0.00,
            'reward_status' => self::REWARD_PENDING,
            'first_deposit_amount' => 0.00,
        ];
        
        $invitation->save($data);
        
        // 记录创建日志
        trace([
            'action' => 'invitation_created',
            'invitation_id' => $invitation->id,
            'inviter_id' => $inviterId,
            'invitation_code' => $invitationCode,
            'invitee_tg_id' => $inviteeTgId,
            'timestamp' => time(),
        ], 'invitation');
        
        return $invitation;
    }
    
    /**
     * 完成邀请（被邀请人注册完成）
     */
    public function complete(int $inviteeId): bool
    {
        if ($this->is_completed) {
            return false;
        }
        
        $this->invitee_id = $inviteeId;
        $this->completed_at = time();
        
        $result = $this->save();
        
        if ($result) {
            // 计算奖励金额
            $this->calculateReward();
            
            // 记录完成日志
            trace([
                'action' => 'invitation_completed',
                'invitation_id' => $this->id,
                'inviter_id' => $this->inviter_id,
                'invitee_id' => $inviteeId,
                'timestamp' => time(),
            ], 'invitation');
            
            // 清除相关缓存
            $this->clearInvitationCache();
        }
        
        return $result;
    }
    
    /**
     * 设置首次充值金额
     */
    public function setFirstDeposit(float $amount): bool
    {
        if ($this->first_deposit_amount > 0) {
            return false; // 已经设置过首次充值
        }
        
        $this->first_deposit_amount = $amount;
        $result = $this->save();
        
        if ($result) {
            // 重新计算奖励
            $this->calculateReward();
            
            // 记录首次充值日志
            trace([
                'action' => 'invitation_first_deposit',
                'invitation_id' => $this->id,
                'inviter_id' => $this->inviter_id,
                'invitee_id' => $this->invitee_id,
                'deposit_amount' => $amount,
                'timestamp' => time(),
            ], 'invitation');
            
            // 清除相关缓存
            $this->clearInvitationCache();
        }
        
        return $result;
    }
    
    /**
     * 发放奖励
     */
    public function grantReward(): bool
    {
        if ($this->reward_status !== self::REWARD_PENDING || $this->reward_amount <= 0) {
            return false;
        }
        
        $this->reward_status = self::REWARD_GRANTED;
        $this->reward_time = time();
        
        $result = $this->save();
        
        if ($result) {
            // 给邀请人发放奖励
            $inviter = $this->inviter;
            if ($inviter) {
                $inviter->updateBalance(
                    $this->reward_amount, 
                    'add', 
                    "邀请奖励 - {$this->invitation_code}"
                );
            }
            
            // 记录奖励发放日志
            trace([
                'action' => 'invitation_reward_granted',
                'invitation_id' => $this->id,
                'inviter_id' => $this->inviter_id,
                'reward_amount' => $this->reward_amount,
                'timestamp' => time(),
            ], 'invitation');
            
            // 清除相关缓存
            $this->clearInvitationCache();
        }
        
        return $result;
    }
    
    /**
     * 取消奖励
     */
    public function cancelReward(string $reason = ''): bool
    {
        if ($this->reward_status === self::REWARD_GRANTED) {
            return false; // 已发放的奖励不能取消
        }
        
        $this->reward_status = self::REWARD_CANCELLED;
        $result = $this->save();
        
        if ($result) {
            // 记录取消日志
            trace([
                'action' => 'invitation_reward_cancelled',
                'invitation_id' => $this->id,
                'inviter_id' => $this->inviter_id,
                'reason' => $reason,
                'timestamp' => time(),
            ], 'invitation');
            
            // 清除相关缓存
            $this->clearInvitationCache();
        }
        
        return $result;
    }
    
    /**
     * 根据邀请码查找
     */
    public static function findByCode(string $invitationCode): ?UserInvitation
    {
        $cacheKey = CacheHelper::key('invitation', 'code', strtoupper($invitationCode));
        
        return CacheHelper::remember($cacheKey, function() use ($invitationCode) {
            return static::where('invitation_code', strtoupper($invitationCode))->find();
        }, 3600); // 缓存1小时
    }
    
    /**
     * 获取邀请人统计
     */
    public static function getInviterStats(int $inviterId): array
    {
        $cacheKey = CacheHelper::key('invitation', 'stats', $inviterId);
        
        return CacheHelper::remember($cacheKey, function() use ($inviterId) {
            $query = static::where('inviter_id', $inviterId);
            
            return [
                'total_invitations' => $query->count(),
                'completed_invitations' => $query->where('invitee_id', '>', 0)->count(),
                'pending_invitations' => $query->where('invitee_id', 0)->count(),
                'total_rewards' => $query->where('reward_status', self::REWARD_GRANTED)->sum('reward_amount'),
                'pending_rewards' => $query->where('reward_status', self::REWARD_PENDING)->sum('reward_amount'),
                'total_deposits' => $query->sum('first_deposit_amount'),
            ];
        }, 1800); // 缓存30分钟
    }
    
    /**
     * 获取每日邀请统计
     */
    public static function getDailyStats(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $cacheKey = CacheHelper::key('invitation', 'daily', $date);
        
        return CacheHelper::remember($cacheKey, function() use ($date) {
            $startTime = strtotime($date . ' 00:00:00');
            $endTime = strtotime($date . ' 23:59:59');
            
            $createdQuery = static::where('create_time', '>=', $startTime)
                                 ->where('create_time', '<=', $endTime);
            
            $completedQuery = static::where('completed_at', '>=', $startTime)
                                   ->where('completed_at', '<=', $endTime);
            
            return [
                'created_count' => $createdQuery->count(),
                'completed_count' => $completedQuery->count(),
                'completion_rate' => $createdQuery->count() > 0 
                    ? round(($completedQuery->count() / $createdQuery->count()) * 100, 1) 
                    : 0,
                'total_rewards' => $completedQuery->where('reward_status', self::REWARD_GRANTED)->sum('reward_amount'),
                'avg_reward' => $completedQuery->where('reward_status', self::REWARD_GRANTED)->avg('reward_amount'),
            ];
        }, 7200); // 缓存2小时
    }
    
    /**
     * 计算奖励金额
     */
    private function calculateReward(): void
    {
        if (!$this->is_completed) {
            return;
        }
        
        // 暂时使用硬编码的奖励逻辑，实际使用时应该从配置表中读取
        $defaultReward = 10.00; // 默认奖励金额
        $minDeposit = 50.00;    // 最低充值要求
        
        // 检查最低充值要求
        if ($this->first_deposit_amount < $minDeposit) {
            return;
        }
        
        $this->reward_amount = $defaultReward;
        $this->save();
    }
    
    /**
     * 生成邀请码
     */
    private function generateInvitationCode(): string
    {
        do {
            $code = SecurityHelper::generateInviteCode(12);
        } while (static::where('invitation_code', $code)->find());
        
        return $code;
    }
    
    /**
     * 清除邀请相关缓存
     */
    private function clearInvitationCache(): void
    {
        // 清除邀请人统计缓存
        if ($this->inviter_id) {
            $statsKey = CacheHelper::key('invitation', 'stats', $this->inviter_id);
            CacheHelper::delete($statsKey);
        }
        
        // 清除邀请码缓存
        if ($this->invitation_code) {
            $codeKey = CacheHelper::key('invitation', 'code', $this->invitation_code);
            CacheHelper::delete($codeKey);
        }
        
        // 清除今日统计缓存
        $todayKey = CacheHelper::key('invitation', 'daily', date('Y-m-d'));
        CacheHelper::delete($todayKey);
    }
    
    /**
     * 数据过滤（保存前自动调用）
     */
    protected function filterData(): void
    {
        // 确保 $this->data 是数组
        if (!is_array($this->data)) {
            return;
        }
        
        foreach ($this->data as $key => $value) {
            if (is_string($value)) {
                $this->data[$key] = SecurityHelper::filterInput($value);
            }
        }
    }
    
    /**
     * 保存前的数据处理（重写父类方法，兼容 ThinkPHP 8）
     */
    public function save($data = [], $where = [], bool $refresh = false): bool
    {
        // 保存前进行数据过滤
        $this->filterData();
        
        return parent::save($data, $where, $refresh);
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '邀请记录ID',
            'inviter_id' => '邀请人ID',
            'invitee_id' => '被邀请人ID',
            'invitation_code' => '邀请码',
            'invitee_tg_id' => '被邀请人TG_ID',
            'reward_amount' => '奖励金额',
            'reward_status' => '奖励状态',
            'reward_time' => '奖励发放时间',
            'first_deposit_amount' => '首次充值金额',
            'create_time' => '邀请时间',
            'completed_at' => '完成注册时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '邀请记录表';
    }
}