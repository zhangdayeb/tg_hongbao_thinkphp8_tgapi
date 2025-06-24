<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 充值账户信息模型
 */
class DepositAccount extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'dianji_deposit_accounts';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'is_active' => 'integer',
        'daily_limit' => 'float',
        'balance_limit' => 'float',
        'usage_count' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'method_code', 'created_at'];
    
    /**
     * 状态常量
     */
    public const STATUS_INACTIVE = 0;    // 禁用
    public const STATUS_ACTIVE = 1;      // 启用
    
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
            'method_code' => 'required|maxLength:50',
            'account_name' => 'required|maxLength:200',
            'daily_limit' => 'float|min:0',
            'balance_limit' => 'float|min:0',
            'is_active' => 'in:0,1',
        ];
    }
    
    /**
     * 账户名称修改器
     */
    public function setAccountNameAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 账户号码修改器
     */
    public function setAccountNumberAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 银行名称修改器
     */
    public function setBankNameAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 钱包地址修改器
     */
    public function setWalletAddressAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 日限额修改器
     */
    public function setDailyLimitAttr($value)
    {
        return $value ? round((float)$value, 2) : null;
    }
    
    /**
     * 余额限制修改器
     */
    public function setBalanceLimitAttr($value)
    {
        return $value ? round((float)$value, 2) : null;
    }
    
    /**
     * 最后使用时间修改器 - 修复datetime格式问题
     */
    public function setLastUsedAtAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        // 如果是时间戳（整数），转换为datetime格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果是字符串，检查格式
        if (is_string($value)) {
            // 如果已经是正确的datetime格式
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
     * 活跃状态获取器
     */
    public function getIsActiveTextAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 ? '启用' : '禁用';
    }
    
    /**
     * 活跃状态颜色获取器
     */
    public function getIsActiveColorAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 ? 'success' : 'danger';
    }
    
    /**
     * 是否活跃
     */
    public function getActiveAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1;
    }
    
    /**
     * 账户号码掩码获取器
     */
    public function getAccountNumberMaskedAttr($value, $data)
    {
        return SecurityHelper::maskSensitiveData($data['account_number'] ?? '', 'bank_card');
    }
    
    /**
     * 钱包地址掩码获取器
     */
    public function getWalletAddressMaskedAttr($value, $data)
    {
        return SecurityHelper::maskSensitiveData($data['wallet_address'] ?? '', 'wallet_address');
    }
    
    /**
     * 手机号掩码获取器
     */
    public function getPhoneNumberMaskedAttr($value, $data)
    {
        return SecurityHelper::maskSensitiveData($data['phone_number'] ?? '', 'phone');
    }
    
    /**
     * 账户类型获取器
     */
    public function getAccountTypeAttr($value, $data)
    {
        $methodCode = $data['method_code'] ?? '';
        
        if (strpos($methodCode, 'usdt') !== false || !empty($data['wallet_address'])) {
            return 'crypto';
        } elseif (!empty($data['account_number'])) {
            return 'bank';
        } elseif (!empty($data['phone_number'])) {
            return 'mobile';
        } else {
            return 'other';
        }
    }
    
    /**
     * 账户类型文本获取器
     */
    public function getAccountTypeTextAttr($value, $data)
    {
        $types = [
            'crypto' => '数字钱包',
            'bank' => '银行账户',
            'mobile' => '手机支付',
            'other' => '其他',
        ];
        
        return $types[$this->account_type] ?? '未知';
    }
    
    /**
     * 格式化日限额
     */
    public function getFormattedDailyLimitAttr($value, $data)
    {
        $limit = $data['daily_limit'] ?? null;
        return $limit ? number_format($limit, 2) . ' USDT' : '无限制';
    }
    
    /**
     * 格式化余额限制
     */
    public function getFormattedBalanceLimitAttr($value, $data)
    {
        $limit = $data['balance_limit'] ?? null;
        return $limit ? number_format($limit, 2) . ' USDT' : '无限制';
    }
    
    /**
     * 最后使用时间格式化
     */
    public function getLastUsedAtTextAttr($value, $data)
    {
        $lastUsed = $data['last_used_at'] ?? null;
        if (empty($lastUsed)) {
            return '从未使用';
        }
        
        $timestamp = is_numeric($lastUsed) ? $lastUsed : strtotime($lastUsed);
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * 使用频率获取器
     */
    public function getUsageFrequencyAttr($value, $data)
    {
        $count = $data['usage_count'] ?? 0;
        $createdAt = $data['created_at'] ?? '';
        
        if ($count <= 0 || empty($createdAt)) {
            return 0;
        }
        
        $createdTimestamp = is_numeric($createdAt) ? $createdAt : strtotime($createdAt);
        $days = max(1, ceil((time() - $createdTimestamp) / 86400));
        
        return round($count / $days, 2);
    }
    
    /**
     * 二维码URL获取器
     */
    public function getQrCodeUrlAttr($value, $data)
    {
        $qrCode = $data['qr_code_url'] ?? '';
        if (empty($qrCode)) {
            return '';
        }
        
        // 如果是完整URL，直接返回
        if (strpos($qrCode, 'http') === 0) {
            return $qrCode;
        }
        
        // 否则拼接为完整路径
        return config('app.domain') . '/uploads/qrcodes/' . $qrCode;
    }
    
    /**
     * 账户显示信息获取器
     */
    public function getDisplayInfoAttr($value, $data)
    {
        $methodCode = $data['method_code'] ?? '';
        $accountName = $data['account_name'] ?? '';
        
        if (strpos($methodCode, 'usdt') !== false) {
            $address = $data['wallet_address'] ?? '';
            return $accountName . ' (' . $this->wallet_address_masked . ')';
        } elseif (!empty($data['account_number'])) {
            $bank = $data['bank_name'] ?? '';
            return $accountName . ' (' . $bank . ' ' . $this->account_number_masked . ')';
        } elseif (!empty($data['phone_number'])) {
            return $accountName . ' (' . $this->phone_number_masked . ')';
        } else {
            return $accountName;
        }
    }
    
    /**
     * 关联充值方式
     */
    public function depositMethod()
    {
        return $this->belongsTo(DepositMethod::class, 'method_code', 'method_code');
    }
    
    /**
     * 关联充值记录
     */
    public function recharges()
    {
        return $this->hasMany(Recharge::class, 'payment_method', 'method_code');
    }
    
    /**
     * 创建充值账户
     */
    public static function createAccount(array $data): DepositAccount
    {
        $account = new static();
        
        // 设置默认值
        $data = array_merge([
            'is_active' => self::STATUS_ACTIVE,
            'daily_limit' => null,
            'balance_limit' => null,
            'usage_count' => 0,
            'account_number' => '',
            'bank_name' => '',
            'phone_number' => '',
            'wallet_address' => '',
            'network_type' => self::NETWORK_TRC20,
            'qr_code_url' => '',
            'remark' => '',
        ], $data);
        
        $account->save($data);
        
        // 清除缓存
        $account->clearAccountCache();
        
        // 记录创建日志
        trace([
            'action' => 'deposit_account_created',
            'account_id' => $account->id,
            'method_code' => $account->method_code,
            'account_name' => $account->account_name,
            'timestamp' => time(),
        ], 'payment_account');
        
        return $account;
    }
    
    /**
     * 更新账户信息
     */
    public function updateAccount(array $data): bool
    {
        $updateFields = [
            'account_name', 'account_number', 'bank_name', 'phone_number',
            'wallet_address', 'network_type', 'qr_code_url', 'is_active',
            'daily_limit', 'balance_limit', 'remark'
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
            $this->clearAccountCache();
            
            // 记录更新日志
            trace([
                'action' => 'deposit_account_updated',
                'account_id' => $this->id,
                'method_code' => $this->method_code,
                'old_data' => $oldData,
                'new_data' => array_intersect_key($data, array_flip($updateFields)),
                'timestamp' => time(),
            ], 'payment_account');
        }
        
        return $result;
    }
    
    /**
     * 启用账户
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
            $this->clearAccountCache();
            
            trace([
                'action' => 'deposit_account_enabled',
                'account_id' => $this->id,
                'method_code' => $this->method_code,
                'timestamp' => time(),
            ], 'payment_account');
        }
        
        return $result;
    }
    
    /**
     * 禁用账户
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
            $this->clearAccountCache();
            
            trace([
                'action' => 'deposit_account_disabled',
                'account_id' => $this->id,
                'method_code' => $this->method_code,
                'timestamp' => time(),
            ], 'payment_account');
        }
        
        return $result;
    }
    
    /**
     * 记录使用 - 修复时间戳问题
     */
    public function recordUsage(): bool
    {
        $this->usage_count += 1;
        $this->last_used_at = date('Y-m-d H:i:s'); // 修复：使用字符串格式而不是时间戳
        $this->updated_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
        
    /**
     * 检查是否可用
     */
    public function isAvailable(): bool
    {
        // 检查是否启用
        if (!$this->active) {
            return false;
        }
        
        // 检查日限额
        if ($this->daily_limit > 0) {
            $todayUsage = $this->getTodayUsage();
            if ($todayUsage >= $this->daily_limit) {
                return false;
            }
        }
        
        // 检查余额限制
        if ($this->balance_limit > 0) {
            $currentBalance = $this->getCurrentBalance();
            if ($currentBalance >= $this->balance_limit) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 获取今日使用量
     */
    public function getTodayUsage(): float
    {
        $today = date('Y-m-d');
        $startTime = $today . ' 00:00:00';
        $endTime = $today . ' 23:59:59';
        
        return (float)Recharge::where('payment_method', $this->method_code)
                             ->where('status', Recharge::STATUS_SUCCESS)
                             ->where('success_time', '>=', $startTime)
                             ->where('success_time', '<=', $endTime)
                             ->sum('money');
    }
    
    /**
     * 获取当前余额（模拟）
     */
    public function getCurrentBalance(): float
    {
        // 这里应该调用实际的余额查询接口
        // 暂时返回0
        return 0.00;
    }
    
    /**
     * 根据方式代码获取账户
     */
    public static function getByMethodCode(string $methodCode): array
    {
        return static::where('method_code', $methodCode)
                    ->where('is_active', self::STATUS_ACTIVE)
                    ->order('usage_count ASC')
                    ->order('last_used_at ASC')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取可用账户
     */
    public static function getAvailableAccounts(string $methodCode = ''): array
    {
        $query = static::where('is_active', self::STATUS_ACTIVE);
        
        if (!empty($methodCode)) {
            $query->where('method_code', $methodCode);
        }
        
        $accounts = $query->order('usage_count ASC')
                         ->order('last_used_at ASC')
                         ->select();
        
        // 过滤可用账户
        $availableAccounts = [];
        foreach ($accounts as $account) {
            if ($account->isAvailable()) {
                $availableAccounts[] = $account;
            }
        }
        
        return $availableAccounts;
    }
    
    /**
     * 获取账户统计
     */
    public static function getAccountStats(): array
    {
        return [
            'total_accounts' => static::count(),
            'active_accounts' => static::where('is_active', self::STATUS_ACTIVE)->count(),
            'inactive_accounts' => static::where('is_active', self::STATUS_INACTIVE)->count(),
            'usdt_accounts' => static::where('method_code', 'like', '%usdt%')->count(),
            'bank_accounts' => static::where('account_number', '<>', '')->count(),
            'mobile_accounts' => static::where('phone_number', '<>', '')->count(),
        ];
    }
    
    /**
     * 获取使用统计
     */
    public static function getUsageStats(int $days = 30): array
    {
        $startTime = time() - ($days * 86400);
        
        return [
            'total_usage' => static::sum('usage_count'),
            'avg_usage' => static::avg('usage_count'),
            'most_used' => static::order('usage_count DESC')->limit(1)->value('account_name') ?: '无',
            'recent_active' => static::where('last_used_at', '>=', $startTime)->count(),
        ];
    }
    
    /**
     * 批量启用/禁用
     */
    public static function batchUpdateStatus(array $accountIds, int $status): int
    {
        if (empty($accountIds) || !in_array($status, [self::STATUS_INACTIVE, self::STATUS_ACTIVE])) {
            return 0;
        }
        
        $count = static::whereIn('id', $accountIds)->update([
            'is_active' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        if ($count > 0) {
            static::clearAllAccountCache();
        }
        
        return $count;
    }
    
    /**
     * 重置使用计数
     */
    public function resetUsageCount(): bool
    {
        $this->usage_count = 0;
        $this->last_used_at = null;
        $this->updated_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
    
    /**
     * 批量重置使用计数
     */
    public static function batchResetUsage(array $accountIds = []): int
    {
        $query = static::query();
        
        if (!empty($accountIds)) {
            $query->whereIn('id', $accountIds);
        }
        
        return $query->update([
            'usage_count' => 0,
            'last_used_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    
    /**
     * 清除账户缓存
     */
    public function clearAccountCache(): void
    {
        $cacheKeys = [
            CacheHelper::key('payment', 'deposit_accounts', $this->method_code),
            CacheHelper::key('payment', 'deposit_account', $this->id),
        ];
        
        foreach ($cacheKeys as $key) {
            CacheHelper::delete($key);
        }
    }
    
    /**
     * 清除所有账户缓存
     */
    public static function clearAllAccountCache(): void
    {
        // 这里可以实现更精细的缓存清理逻辑
        $cachePattern = CacheHelper::key('payment', 'deposit_accounts', '*');
        CacheHelper::deletePattern($cachePattern);
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
            'id' => '主键ID',
            'method_code' => '充值方式代码',
            'account_name' => '账户名称/收款人姓名',
            'account_number' => '账户号码/银行卡号',
            'bank_name' => '银行名称',
            'phone_number' => '手机号码',
            'wallet_address' => '钱包地址',
            'network_type' => '网络类型',
            'qr_code_url' => '二维码图片URL',
            'is_active' => '是否激活',
            'daily_limit' => '日限额',
            'balance_limit' => '余额限制',
            'usage_count' => '使用次数',
            'last_used_at' => '最后使用时间',
            'remark' => '备注信息',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '充值账户信息表';
    }
}