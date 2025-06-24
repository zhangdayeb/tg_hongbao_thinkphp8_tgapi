<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 提现记录模型
 */
class Withdraw extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'common_pay_withdraw';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'money' => 'float',
        'money_balance' => 'float',
        'money_fee' => 'float',
        'money_actual' => 'float',
        'user_id' => 'integer',
        'admin_uid' => 'integer',
        'status' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'order_number', 'create_time', 'user_id', 'money', 'withdraw_address'];
    
    /**
     * 状态常量
     */
    public const STATUS_PENDING = 0;      // 待审核
    public const STATUS_SUCCESS = 1;      // 成功
    public const STATUS_FAILED = 2;       // 失败
    public const STATUS_CANCELLED = 3;    // 已取消
    public const STATUS_PROCESSING = 4;   // 处理中
    
    /**
     * 网络类型常量
     */
    public const NETWORK_TRC20 = 'TRC20';
    public const NETWORK_ERC20 = 'ERC20';
    public const NETWORK_OMNI = 'OMNI';
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'order_number' => 'required|unique:common_pay_withdraw',
            'money' => 'required|float|min:0.01',
            'user_id' => 'required|integer',
            'withdraw_address' => 'required|usdtAddress',
            'status' => 'in:0,1,2,3,4',
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
     * 手续费修改器
     */
    public function setMoneyFeeAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 实际到账金额修改器
     */
    public function setMoneyActualAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 订单号修改器
     */
    public function setOrderNumberAttr($value)
    {
        return strtoupper(trim($value));
    }
    
    /**
     * 状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_PENDING => '待审核',
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_PROCESSING => '处理中',
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 状态颜色获取器
     */
    public function getStatusColorAttr($value, $data)
    {
        $colors = [
            self::STATUS_PENDING => 'warning',
            self::STATUS_SUCCESS => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_PROCESSING => 'info',
        ];
        return $colors[$data['status']] ?? 'secondary';
    }
    
    /**
     * 格式化提现金额
     */
    public function getFormattedMoneyAttr($value, $data)
    {
        return number_format($data['money'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化手续费
     */
    public function getFormattedFeeAttr($value, $data)
    {
        return number_format($data['money_fee'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化实际到账金额
     */
    public function getFormattedActualAttr($value, $data)
    {
        return number_format($data['money_actual'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 地址掩码获取器
     */
    public function getAddressMaskedAttr($value, $data)
    {
        return SecurityHelper::maskSensitiveData($data['withdraw_address'] ?? '', 'usdt_address');
    }
    
    /**
     * 是否可以取消
     */
    public function getCanCancelAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_PENDING;
    }
    
    /**
     * 是否待审核
     */
    public function getIsPendingAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_PENDING;
    }
    
    /**
     * 是否成功
     */
    public function getIsSuccessAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_SUCCESS;
    }
    
    /**
     * 是否失败
     */
    public function getIsFailedAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_FAILED;
    }
    
    /**
     * 处理时长获取器
     */
    public function getProcessingTimeAttr($value, $data)
    {
        if (empty($data['success_time']) || empty($data['create_time'])) {
            return null;
        }
        
        $successTime = strtotime($data['success_time']);
        $createTime = strtotime($data['create_time']);
        $seconds = $successTime - $createTime;
        
        if ($seconds < 60) {
            return $seconds . '秒';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . '分钟';
        } else {
            return round($seconds / 3600, 1) . '小时';
        }
    }
    
    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * 关联审核管理员
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_uid');
    }
    
    /**
     * 创建提现订单
     */
    public static function createOrder(array $data): Withdraw
    {
        $order = new static();
        
        // 生成订单号
        if (empty($data['order_number'])) {
            $data['order_number'] = $order->generateOrderNumber();
        }
        
        // 计算手续费和实际到账金额
        $money = (float)$data['money'];
        $fee = $order->calculateFee($money);
        $actual = $money - $fee;
        
        // 设置默认值 - 修复：使用 datetime 格式
        $data = array_merge([
            'status' => self::STATUS_PENDING,
            'money_fee' => $fee,
            'money_actual' => $actual,
            'withdraw_network' => self::NETWORK_TRC20,
            'user_ip' => request()->ip(),
            'create_time' => date('Y-m-d H:i:s'), // 修复：使用 datetime 格式
        ], $data);
        
        $order->save($data);
        
        // 冻结用户余额
        $user = User::find($data['user_id']);
        if ($user) {
            $user->updateBalance($money, 'subtract', "提现申请 - 订单号{$order->order_number}");
        }
        
        // 记录创建日志
        trace([
            'action' => 'withdraw_created',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'amount' => $order->money,
            'fee' => $order->money_fee,
            'actual' => $order->money_actual,
            'address' => $order->withdraw_address,
            'timestamp' => time(),
        ], 'payment');
        
        return $order;
    }
    
    /**
     * 审核通过
     */
    public function approve(int $adminId, string $remarks = ''): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_PROCESSING;
        $this->admin_uid = $adminId;
        $this->msg = $remarks;
        
        $result = $this->save();
        
        if ($result) {
            // 这里应该调用区块链转账接口
            // 暂时直接设置为成功
            $this->processTransfer();
            
            // 记录审核日志
            trace([
                'action' => 'withdraw_approved',
                'order_id' => $this->id,
                'order_number' => $this->order_number,
                'admin_id' => $adminId,
                'amount' => $this->money,
                'remarks' => $remarks,
                'timestamp' => time(),
            ], 'payment');
        }
        
        return $result;
    }
    
    /**
     * 审核拒绝 - 修复：使用 datetime 格式
     */
    public function reject(int $adminId, string $reason = ''): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_FAILED;
        $this->success_time = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
        $this->admin_uid = $adminId;
        $this->msg = $reason;
        
        $result = $this->save();
        
        if ($result) {
            // 退还用户余额
            $user = $this->user;
            if ($user) {
                $user->updateBalance($this->money, 'add', "提现失败退款 - 订单号{$this->order_number}");
            }
            
            // 发送失败通知
            $this->sendFailedNotification($reason);
            
            // 记录审核日志
            trace([
                'action' => 'withdraw_rejected',
                'order_id' => $this->id,
                'order_number' => $this->order_number,
                'admin_id' => $adminId,
                'reason' => $reason,
                'timestamp' => time(),
            ], 'payment');
            
            // 清除相关缓存
            $this->clearCache();
        }
        
        return $result;
    }
    
    /**
     * 取消订单
     */
    public function cancel(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_CANCELLED;
        $result = $this->save();
        
        if ($result) {
            // 退还用户余额
            $user = $this->user;
            if ($user) {
                $user->updateBalance($this->money, 'add', "提现取消退款 - 订单号{$this->order_number}");
            }
            
            // 记录取消日志
            trace([
                'action' => 'withdraw_cancelled',
                'order_id' => $this->id,
                'order_number' => $this->order_number,
                'timestamp' => time(),
            ], 'payment');
            
            // 清除相关缓存
            $this->clearCache();
        }
        
        return $result;
    }
    
    /**
     * 处理转账 - 修复：使用 datetime 格式
     */
    public function processTransfer(): bool
    {
        if ($this->status !== self::STATUS_PROCESSING) {
            return false;
        }
        
        // 这里应该调用实际的区块链转账接口
        // 暂时模拟转账成功
        $transactionHash = $this->simulateTransfer();
        
        $this->status = self::STATUS_SUCCESS;
        $this->success_time = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
        $this->transaction_hash = $transactionHash;
        
        $result = $this->save();
        
        if ($result) {
            // 发送成功通知
            $this->sendSuccessNotification();
            
            // 记录转账日志
            trace([
                'action' => 'withdraw_completed',
                'order_id' => $this->id,
                'order_number' => $this->order_number,
                'transaction_hash' => $transactionHash,
                'timestamp' => time(),
            ], 'payment');
            
            // 清除相关缓存
            $this->clearCache();
        }
        
        return $result;
    }
    
    /**
     * 根据订单号查找
     */
    public static function findByOrderNumber(string $orderNumber): ?Withdraw
    {
        return static::where('order_number', strtoupper($orderNumber))->find();
    }
    
    /**
     * 获取用户提现统计
     */
    public static function getUserStats(int $userId): array
    {
        $query = static::where('user_id', $userId);
        
        return [
            'total_count' => $query->count(),
            'success_count' => $query->where('status', self::STATUS_SUCCESS)->count(),
            'pending_count' => $query->where('status', self::STATUS_PENDING)->count(),
            'total_amount' => $query->where('status', self::STATUS_SUCCESS)->sum('money'),
            'total_fee' => $query->where('status', self::STATUS_SUCCESS)->sum('money_fee'),
            'first_withdraw_time' => $query->where('status', self::STATUS_SUCCESS)->min('success_time'),
            'last_withdraw_time' => $query->where('status', self::STATUS_SUCCESS)->max('success_time'),
        ];
    }
    
    /**
     * 获取每日统计 - 修复：使用 datetime 格式比较
     */
    public static function getDailyStats(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $startTime = $date . ' 00:00:00';
        $endTime = $date . ' 23:59:59';
        
        $query = static::where('create_time', '>=', $startTime)
                      ->where('create_time', '<=', $endTime);
        
        return [
            'total_count' => $query->count(),
            'success_count' => $query->where('status', self::STATUS_SUCCESS)->count(),
            'pending_count' => $query->where('status', self::STATUS_PENDING)->count(),
            'total_amount' => $query->where('status', self::STATUS_SUCCESS)->sum('money'),
            'total_fee' => $query->where('status', self::STATUS_SUCCESS)->sum('money_fee'),
            'avg_amount' => $query->where('status', self::STATUS_SUCCESS)->avg('money'),
        ];
    }
    
    /**
     * 计算手续费
     */
    private function calculateFee(float $amount): float
    {
        $config = config('payment.withdraw.methods.usdt');
        
        $feeRate = $config['fee_rate'] ?? 0.01;  // 1%
        $feeFixed = $config['fee_fixed'] ?? 2.00; // 固定2 USDT
        $feeMin = $config['fee_min'] ?? 2.00;    // 最小2 USDT
        $feeMax = $config['fee_max'] ?? 100.00;  // 最大100 USDT
        
        // 计算费率费用
        $rateFee = $amount * $feeRate;
        
        // 总费用 = 固定费用 + 费率费用
        $totalFee = $feeFixed + $rateFee;
        
        // 限制在最小和最大范围内
        $totalFee = max($feeMin, min($feeMax, $totalFee));
        
        return round($totalFee, 2);
    }
    
    /**
     * 模拟转账
     */
    private function simulateTransfer(): string
    {
        // 生成模拟的交易哈希
        return '0x' . SecurityHelper::generateRandomString(64, '0123456789abcdef');
    }
    
    /**
     * 发送成功通知
     */
    private function sendSuccessNotification(): void
    {
        // 这里应该调用通知服务发送消息
        trace([
            'event' => 'withdraw_success_notification',
            'user_id' => $this->user_id,
            'order_number' => $this->order_number,
            'amount' => $this->money,
            'actual_amount' => $this->money_actual,
            'transaction_hash' => $this->transaction_hash,
            'timestamp' => time(),
        ], 'notification');
    }
    
    /**
     * 发送失败通知
     */
    private function sendFailedNotification(string $reason): void
    {
        // 这里应该调用通知服务发送消息
        trace([
            'event' => 'withdraw_failed_notification',
            'user_id' => $this->user_id,
            'order_number' => $this->order_number,
            'reason' => $reason,
            'timestamp' => time(),
        ], 'notification');
    }
    
    /**
     * 生成订单号
     */
    private function generateOrderNumber(): string
    {
        return SecurityHelper::generateOrderNumber('W', 16);
    }
    
    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        // 清除支付统计缓存
        $paymentCache = CacheHelper::payment();
        $orderCache = $paymentCache->order($this->order_number, null);
        
        // 清除用户余额缓存
        if ($this->user_id) {
            $userCache = CacheHelper::user($this->user_id);
            $userCache->balance(null);
        }
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_PENDING => '待审核',
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_PROCESSING => '处理中',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '提现ID',
            'order_number' => '订单号',
            'create_time' => '提现时间',
            'success_time' => '到账时间',
            'money' => '提现金额',
            'money_balance' => '用户余额',
            'money_fee' => '手续费',
            'money_actual' => '实际到账金额',
            'msg' => '备注信息',
            'user_id' => '用户ID',
            'user_ip' => '用户IP',
            'admin_uid' => '审核管理员ID',
            'status' => '状态',
            'withdraw_address' => 'USDT提现地址',
            'withdraw_network' => '网络类型',
            'transaction_hash' => '交易哈希',
            'verification_code' => '验证码',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '提现记录表';
    }
}