<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 资金流水模型
 */
class MoneyLog extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'common_pay_money_log';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'type' => 'integer',
        'status' => 'integer',
        'money_before' => 'float',
        'money_end' => 'float',
        'money' => 'float',
        'uid' => 'integer',
        'source_id' => 'integer',
        'market_uid' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'create_time', 'uid', 'type', 'status', 'money_before', 'money_end', 'money'];
    
    /**
     * 流水类型常量
     */
    public const TYPE_INCOME = 1;          // 收入
    public const TYPE_EXPENSE = 2;         // 支出
    public const TYPE_ADMIN_ADJUST = 3;    // 后台调整
    public const TYPE_REFUND = 4;          // 退款
    
    /**
     * 流水状态常量
     */
    public const STATUS_RECHARGE = 101;         // 充值
    public const STATUS_WITHDRAW = 201;         // 提现
    public const STATUS_POINTS = 301;           // 积分
    public const STATUS_PACKAGE_REWARD = 401;  // 套餐分销奖励
    public const STATUS_RECHARGE_REWARD = 403; // 充值分销奖励
    public const STATUS_GAME = 501;             // 游戏
    public const STATUS_AGENT_REBATE = 601;    // 代理返利
    public const STATUS_REDPACKET_SEND = 701;  // 发红包
    public const STATUS_REDPACKET_RECEIVE = 702; // 收红包
    public const STATUS_INVITE_REWARD = 801;   // 邀请奖励
    public const STATUS_ADMIN_ADD = 901;       // 后台加款
    public const STATUS_ADMIN_SUBTRACT = 902;  // 后台扣款
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'type' => 'required|in:1,2,3,4',
            'status' => 'required|integer',
            'money_before' => 'required|float|min:0',
            'money_end' => 'required|float|min:0',
            'money' => 'required|float|min:0',
            'uid' => 'required|integer',
        ];
    }
    
    /**
     * 金额修改器
     */
    public function setMoneyAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 变化前金额修改器
     */
    public function setMoneyBeforeAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 变化后金额修改器
     */
    public function setMoneyEndAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 流水类型获取器
     */
    public function getTypeTextAttr($value, $data)
    {
        $types = [
            self::TYPE_INCOME => '收入',
            self::TYPE_EXPENSE => '支出',
            self::TYPE_ADMIN_ADJUST => '后台调整',
            self::TYPE_REFUND => '退款',
        ];
        return $types[$data['type']] ?? '未知';
    }
    
    /**
     * 流水状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_RECHARGE => '充值',
            self::STATUS_WITHDRAW => '提现',
            self::STATUS_POINTS => '积分',
            self::STATUS_PACKAGE_REWARD => '套餐分销奖励',
            self::STATUS_RECHARGE_REWARD => '充值分销奖励',
            self::STATUS_GAME => '游戏',
            self::STATUS_AGENT_REBATE => '代理返利',
            self::STATUS_REDPACKET_SEND => '发红包',
            self::STATUS_REDPACKET_RECEIVE => '收红包',
            self::STATUS_INVITE_REWARD => '邀请奖励',
            self::STATUS_ADMIN_ADD => '后台加款',
            self::STATUS_ADMIN_SUBTRACT => '后台扣款',
        ];
        return $statuses[$data['status']] ?? '其他';
    }
    
    /**
     * 流水方向获取器
     */
    public function getDirectionAttr($value, $data)
    {
        return ($data['type'] ?? 0) === self::TYPE_INCOME ? '+' : '-';
    }
    
    /**
     * 流水方向文本获取器
     */
    public function getDirectionTextAttr($value, $data)
    {
        return ($data['type'] ?? 0) === self::TYPE_INCOME ? '收入' : '支出';
    }
    
    /**
     * 格式化金额
     */
    public function getFormattedMoneyAttr($value, $data)
    {
        $direction = ($data['type'] ?? 0) === self::TYPE_INCOME ? '+' : '-';
        return $direction . number_format($data['money'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化变化前金额
     */
    public function getFormattedBeforeAttr($value, $data)
    {
        return number_format($data['money_before'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化变化后金额
     */
    public function getFormattedEndAttr($value, $data)
    {
        return number_format($data['money_end'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }
    
    /**
     * 关联业务员
     */
    public function marketer()
    {
        return $this->belongsTo(User::class, 'market_uid');
    }
    
    /**
     * 创建流水记录 - 修复：使用 datetime 格式
     */
    public static function createLog(array $data): MoneyLog
    {
        $log = new static();
        
        // 设置默认值 - 修复：使用 datetime 格式
        $data = array_merge([
            'create_time' => date('Y-m-d H:i:s'), // 修复：使用 datetime 格式
            'source_id' => 0,
            'market_uid' => 0,
        ], $data);
        
        // 验证余额变化的合理性
        if (isset($data['money_before'], $data['money_end'], $data['money'], $data['type'])) {
            $expectedEnd = $data['type'] === self::TYPE_INCOME 
                ? $data['money_before'] + $data['money']
                : $data['money_before'] - $data['money'];
            
            if (abs($expectedEnd - $data['money_end']) > 0.01) {
                throw new \Exception('余额变化不符合逻辑');
            }
        }
        
        $log->save($data);
        
        // 记录到系统日志
        trace([
            'action' => 'money_log_created',
            'log_id' => $log->id,
            'user_id' => $log->uid,
            'type' => $log->type,
            'status' => $log->status,
            'amount' => $log->money,
            'before' => $log->money_before,
            'after' => $log->money_end,
            'mark' => $log->mark,
            'timestamp' => time(),
        ], 'money_flow');
        
        return $log;
    }
    
    /**
     * 添加发红包流水记录 - 新增方法
     */
    public static function addRedPacketSendLog(int $userId, float $amount, string $packetId, float $beforeBalance): MoneyLog
    {
        return self::createLog([
            'uid' => $userId,
            'type' => self::TYPE_EXPENSE,
            'status' => self::STATUS_REDPACKET_SEND,
            'money_before' => $beforeBalance,
            'money_end' => $beforeBalance - $amount,
            'money' => $amount,
            'source_id' => 0, // 可以存储红包记录ID
            'mark' => "发红包 - {$packetId}",
        ]);
    }
    
    /**
     * 添加收红包流水记录 - 新增方法
     */
    public static function addRedPacketReceiveLog(int $userId, float $amount, string $packetId, float $beforeBalance): MoneyLog
    {
        return self::createLog([
            'uid' => $userId,
            'type' => self::TYPE_INCOME,
            'status' => self::STATUS_REDPACKET_RECEIVE,
            'money_before' => $beforeBalance,
            'money_end' => $beforeBalance + $amount,
            'money' => $amount,
            'source_id' => 0, // 可以存储红包记录ID
            'mark' => "收红包 - {$packetId}",
        ]);
    }
    
    /**
     * 获取用户红包流水统计 - 新增方法
     */
    public static function getRedPacketStats(int $userId, string $startDate = null, string $endDate = null): array
    {
        $query = static::where('uid', $userId)
                      ->whereIn('status', [self::STATUS_REDPACKET_SEND, self::STATUS_REDPACKET_RECEIVE]);
        
        if ($startDate) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }
        
        $sendAmount = $query->where('status', self::STATUS_REDPACKET_SEND)->sum('money');
        $receiveAmount = $query->where('status', self::STATUS_REDPACKET_RECEIVE)->sum('money');
        $sendCount = $query->where('status', self::STATUS_REDPACKET_SEND)->count();
        $receiveCount = $query->where('status', self::STATUS_REDPACKET_RECEIVE)->count();
        
        return [
            'send_amount' => $sendAmount ?: 0,
            'receive_amount' => $receiveAmount ?: 0,
            'send_count' => $sendCount,
            'receive_count' => $receiveCount,
            'net_amount' => ($receiveAmount ?: 0) - ($sendAmount ?: 0),
        ];
    }
    
    /**
     * 获取用户流水统计 - 修复：使用 datetime 格式比较
     */
    public static function getUserStats(int $userId, string $startDate = null, string $endDate = null): array
    {
        $query = static::where('uid', $userId);
        
        if ($startDate) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }
        
        $totalIncome = $query->where('type', self::TYPE_INCOME)->sum('money');
        $totalExpense = $query->where('type', self::TYPE_EXPENSE)->sum('money');
        
        return [
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_amount' => $totalIncome - $totalExpense,
            'total_count' => $query->count(),
            'income_count' => $query->where('type', self::TYPE_INCOME)->count(),
            'expense_count' => $query->where('type', self::TYPE_EXPENSE)->count(),
            'recharge_amount' => $query->where('status', self::STATUS_RECHARGE)->sum('money'),
            'withdraw_amount' => $query->where('status', self::STATUS_WITHDRAW)->sum('money'),
            'game_amount' => $query->where('status', self::STATUS_GAME)->sum('money'),
            'redpacket_send' => $query->where('status', self::STATUS_REDPACKET_SEND)->sum('money'),
            'redpacket_receive' => $query->where('status', self::STATUS_REDPACKET_RECEIVE)->sum('money'),
        ];
    }
    
    /**
     * 获取流水分类统计 - 修复：使用 datetime 格式比较
     */
    public static function getCategoryStats(int $userId, int $days = 30): array
    {
        $startTime = date('Y-m-d 00:00:00', time() - ($days * 86400));
        
        $query = static::where('uid', $userId)
                      ->where('create_time', '>=', $startTime);
        
        $stats = [];
        $statuses = [
            self::STATUS_RECHARGE => '充值',
            self::STATUS_WITHDRAW => '提现',
            self::STATUS_GAME => '游戏',
            self::STATUS_REDPACKET_SEND => '发红包',
            self::STATUS_REDPACKET_RECEIVE => '收红包',
            self::STATUS_INVITE_REWARD => '邀请奖励',
        ];
        
        foreach ($statuses as $status => $name) {
            $amount = $query->where('status', $status)->sum('money');
            $count = $query->where('status', $status)->count();
            
            $stats[] = [
                'status' => $status,
                'name' => $name,
                'amount' => $amount,
                'count' => $count,
                'formatted_amount' => number_format($amount, 2) . ' USDT',
            ];
        }
        
        return $stats;
    }
    
    /**
     * 获取每日流水统计 - 修复：使用 datetime 格式
     */
    public static function getDailyStats(int $userId, int $days = 7): array
    {
        $stats = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $startTime = $date . ' 00:00:00';
            $endTime = $date . ' 23:59:59';
            
            $query = static::where('uid', $userId)
                          ->where('create_time', '>=', $startTime)
                          ->where('create_time', '<=', $endTime);
            
            $income = $query->where('type', self::TYPE_INCOME)->sum('money');
            $expense = $query->where('type', self::TYPE_EXPENSE)->sum('money');
            
            $stats[$date] = [
                'date' => $date,
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
                'count' => $query->count(),
            ];
        }
        
        return array_reverse($stats, true);
    }
    
    /**
     * 验证余额一致性
     */
    public static function validateBalance(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) {
            return ['valid' => false, 'message' => '用户不存在'];
        }
        
        // 计算根据流水记录得出的余额
        $lastLog = static::where('uid', $userId)->order('id', 'desc')->find();
        
        if (!$lastLog) {
            // 没有流水记录，余额应该为0
            $calculatedBalance = 0.00;
        } else {
            $calculatedBalance = $lastLog->money_end;
        }
        
        $actualBalance = $user->money_balance;
        $difference = abs($calculatedBalance - $actualBalance);
        
        return [
            'valid' => $difference < 0.01, // 允许0.01的误差
            'actual_balance' => $actualBalance,
            'calculated_balance' => $calculatedBalance,
            'difference' => $difference,
            'message' => $difference < 0.01 ? '余额一致' : '余额不一致',
        ];
    }
    
    /**
     * 修复余额不一致
     */
    public static function fixBalance(int $userId, string $reason = '余额修复'): bool
    {
        $validation = static::validateBalance($userId);
        
        if ($validation['valid']) {
            return true; // 余额已经一致
        }
        
        $user = User::find($userId);
        $correctBalance = $validation['calculated_balance'];
        $oldBalance = $user->money_balance;
        
        // 创建调整记录
        static::createLog([
            'uid' => $userId,
            'type' => self::TYPE_ADMIN_ADJUST,
            'status' => $correctBalance > $oldBalance ? self::STATUS_ADMIN_ADD : self::STATUS_ADMIN_SUBTRACT,
            'money_before' => $oldBalance,
            'money_end' => $correctBalance,
            'money' => abs($correctBalance - $oldBalance),
            'mark' => $reason . '（系统自动修复）',
        ]);
        
        // 更新用户余额
        $user->money_balance = $correctBalance;
        return $user->save();
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '流水ID',
            'create_time' => '创建时间',
            'type' => '流水类型',
            'status' => '详细状态',
            'money_before' => '变化前金额',
            'money_end' => '变化后金额',
            'money' => '变化金额',
            'uid' => '用户ID',
            'source_id' => '源头ID',
            'market_uid' => '业务员ID',
            'mark' => '备注',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '资金流水表';
    }
}