<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 充值记录模型
 */
class Recharge extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'common_pay_recharge';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'money' => 'float',
        'admin_uid' => 'integer',
        'user_id' => 'integer',
        'status' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'order_number', 'create_time', 'user_id'];
    
    /**
     * 状态常量
     */
    public const STATUS_PENDING = 0;      // 待审核
    public const STATUS_SUCCESS = 1;      // 成功
    public const STATUS_FAILED = 2;       // 失败
    public const STATUS_CANCELLED = 3;    // 已取消
    
    /**
     * 支付方式常量
     */
    public const METHOD_USDT = 'usdt';
    public const METHOD_HUIWANG = 'huiwang';
    
    /**
     * 验证方式常量
     */
    public const VERIFY_ORDER_NUMBER = 'order_number';
    public const VERIFY_IMAGE = 'image';
    public const VERIFY_BOTH = 'both';
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'order_number' => 'required|unique:common_pay_recharge',
            'money' => 'required|float|min:0.01',
            'user_id' => 'required|integer',
            'payment_method' => 'required|in:usdt,huiwang',
            'status' => 'in:0,1,2,3',
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
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 支付方式获取器
     */
    public function getPaymentMethodTextAttr($value, $data)
    {
        $methods = [
            self::METHOD_USDT => 'USDT充值',
            self::METHOD_HUIWANG => '汇旺充值',
        ];
        return $methods[$data['payment_method']] ?? '未知';
    }
    
    /**
     * 格式化金额
     */
    public function getFormattedMoneyAttr($value, $data)
    {
        return number_format($data['money'] ?? 0, 2) . ' USDT';
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
        
        $seconds = $data['success_time'] - $data['create_time'];
        
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
     * 创建充值订单
     */
    public static function createOrder(array $data): Recharge
    {
        $order = new static();
        
        // 生成订单号
        if (empty($data['order_number'])) {
            $data['order_number'] = $order->generateOrderNumber();
        }
        
        // 设置默认值
        $data = array_merge([
            'status' => self::STATUS_PENDING,
            'verify_method' => self::VERIFY_ORDER_NUMBER,
            'user_ip' => request()->ip(),
        ], $data);
        
        $order->save($data);
        
        // 记录创建日志
        trace([
            'action' => 'recharge_created',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'amount' => $order->money,
            'method' => $order->payment_method,
            'timestamp' => time(),
        ], 'payment');
        
        return $order;
    }
    
    /**
     * 审核通过 - 修复时间设置
     */
    public function approve(int $adminId, string $remarks = ''): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_SUCCESS;
        $this->success_time = date('Y-m-d H:i:s'); // 修复：使用字符串格式
        $this->admin_uid = $adminId;
        $this->admin_remarks = $remarks;
        
        $result = $this->save();
        
        if ($result) {
            // 给用户加余额
            $user = $this->user;
            if ($user) {
                $user->updateBalance($this->money, 'add', "充值到账 - 订单号{$this->order_number}");
            }
            
            // 发送通知
            $this->sendSuccessNotification();
            
            // 记录审核日志
            trace([
                'action' => 'recharge_approved',
                'order_id' => $this->id,
                'order_number' => $this->order_number,
                'admin_id' => $adminId,
                'amount' => $this->money,
                'remarks' => $remarks,
                'timestamp' => time(),
            ], 'payment');
            
            // 清除相关缓存
            $this->clearCache();
        }
        
        return $result;
    }
    
    /**
     * 审核拒绝 - 修复时间设置
     */
    public function reject(int $adminId, string $reason = ''): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_FAILED;
        $this->success_time = date('Y-m-d H:i:s'); // 修复：使用字符串格式
        $this->admin_uid = $adminId;
        $this->admin_remarks = $reason;
        
        $result = $this->save();
        
        if ($result) {
            // 发送失败通知
            $this->sendFailedNotification($reason);
            
            // 记录审核日志
            trace([
                'action' => 'recharge_rejected',
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
            // 记录取消日志
            trace([
                'action' => 'recharge_cancelled',
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
     * 根据订单号查找
     */
    public static function findByOrderNumber(string $orderNumber): ?Recharge
    {
        return static::where('order_number', strtoupper($orderNumber))->find();
    }
    
    /**
     * 获取用户充值统计
     */
    public static function getUserStats(int $userId): array
    {
        $query = static::where('user_id', $userId);
        
        return [
            'total_count' => $query->count(),
            'success_count' => $query->where('status', self::STATUS_SUCCESS)->count(),
            'pending_count' => $query->where('status', self::STATUS_PENDING)->count(),
            'total_amount' => $query->where('status', self::STATUS_SUCCESS)->sum('money'),
            'first_recharge_time' => $query->where('status', self::STATUS_SUCCESS)->min('success_time'),
            'last_recharge_time' => $query->where('status', self::STATUS_SUCCESS)->max('success_time'),
        ];
    }
    
    /**
     * 获取每日统计
     */
    public static function getDailyStats(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $startTime = strtotime($date . ' 00:00:00');
        $endTime = strtotime($date . ' 23:59:59');
        
        $query = static::where('create_time', '>=', $startTime)
                      ->where('create_time', '<=', $endTime);
        
        return [
            'total_count' => $query->count(),
            'success_count' => $query->where('status', self::STATUS_SUCCESS)->count(),
            'pending_count' => $query->where('status', self::STATUS_PENDING)->count(),
            'total_amount' => $query->where('status', self::STATUS_SUCCESS)->sum('money'),
            'usdt_amount' => $query->where('payment_method', self::METHOD_USDT)
                                  ->where('status', self::STATUS_SUCCESS)->sum('money'),
            'huiwang_amount' => $query->where('payment_method', self::METHOD_HUIWANG)
                                     ->where('status', self::STATUS_SUCCESS)->sum('money'),
        ];
    }
    
    /**
     * 发送成功通知
     */
    private function sendSuccessNotification(): void
    {
        // 这里应该调用通知服务发送消息
        // 暂时记录到日志
        trace([
            'event' => 'recharge_success_notification',
            'user_id' => $this->user_id,
            'order_number' => $this->order_number,
            'amount' => $this->money,
            'timestamp' => time(),
        ], 'notification');
    }
    
    /**
     * 发送失败通知
     */
    private function sendFailedNotification(string $reason): void
    {
        // 这里应该调用通知服务发送消息
        // 暂时记录到日志
        trace([
            'event' => 'recharge_failed_notification',
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
        return SecurityHelper::generateOrderNumber('R', 16);
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
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '充值ID',
            'order_number' => '订单号',
            'create_time' => '充值时间',
            'success_time' => '到账时间',
            'money' => '充值金额',
            'admin_uid' => '审核管理员ID',
            'user_id' => '用户ID',
            'user_ip' => '用户IP',
            'payment_method' => '支付方式',
            'transaction_id' => '交易单号',
            'payment_proof' => '支付凭证',
            'verify_method' => '验证方式',
            'admin_remarks' => '管理员备注',
            'status' => '状态',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '充值记录表';
    }
}