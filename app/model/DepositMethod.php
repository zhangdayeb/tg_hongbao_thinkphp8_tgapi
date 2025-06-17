<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 充值方式配置模型
 */
class DepositMethod extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'dianji_deposit_methods';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'is_enabled' => 'integer',
        'sort_order' => 'integer',
        'min_amount' => 'float',
        'max_amount' => 'float',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'method_code', 'created_at'];
    
    /**
     * 状态常量
     */
    public const STATUS_DISABLED = 0;    // 禁用
    public const STATUS_ENABLED = 1;     // 启用
    
    /**
     * 充值方式代码常量
     */
    public const METHOD_USDT = 'usdt';           // USDT充值
    public const METHOD_HUIWANG = 'huiwang';     // 汇旺充值
    public const METHOD_ALIPAY = 'alipay';       // 支付宝
    public const METHOD_WECHAT = 'wechat';       // 微信支付
    public const METHOD_BANK = 'bank';           // 银行转账
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'method_code' => 'required|unique:dianji_deposit_methods|maxLength:50',
            'method_name' => 'required|maxLength:100',
            'min_amount' => 'float|min:0',
            'max_amount' => 'float|min:0',
            'sort_order' => 'integer|min:0',
            'is_enabled' => 'in:0,1',
        ];
    }
    
    /**
     * 方式代码修改器
     */
    public function setMethodCodeAttr($value)
    {
        return strtolower(trim($value));
    }
    
    /**
     * 方式名称修改器
     */
    public function setMethodNameAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 最小金额修改器
     */
    public function setMinAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 最大金额修改器
     */
    public function setMaxAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 启用状态获取器
     */
    public function getIsEnabledTextAttr($value, $data)
    {
        return ($data['is_enabled'] ?? 0) === 1 ? '启用' : '禁用';
    }
    
    /**
     * 启用状态颜色获取器
     */
    public function getIsEnabledColorAttr($value, $data)
    {
        return ($data['is_enabled'] ?? 0) === 1 ? 'success' : 'danger';
    }
    
    /**
     * 是否启用
     */
    public function getEnabledAttr($value, $data)
    {
        return ($data['is_enabled'] ?? 0) === 1;
    }
    
    /**
     * 金额范围获取器
     */
    public function getAmountRangeAttr($value, $data)
    {
        $min = $data['min_amount'] ?? 0;
        $max = $data['max_amount'] ?? 0;
        
        if ($min > 0 && $max > 0) {
            return number_format($min, 2) . ' - ' . number_format($max, 2) . ' USDT';
        } elseif ($min > 0) {
            return '≥ ' . number_format($min, 2) . ' USDT';
        } elseif ($max > 0) {
            return '≤ ' . number_format($max, 2) . ' USDT';
        } else {
            return '无限制';
        }
    }
    
    /**
     * 格式化最小金额
     */
    public function getFormattedMinAmountAttr($value, $data)
    {
        return number_format($data['min_amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化最大金额
     */
    public function getFormattedMaxAmountAttr($value, $data)
    {
        return number_format($data['max_amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 处理时间说明获取器
     */
    public function getProcessingTimeTextAttr($value, $data)
    {
        $time = $data['processing_time'] ?? '';
        return empty($time) ? '实时到账' : $time;
    }
    
    /**
     * 图标URL获取器
     */
    public function getIconUrlAttr($value, $data)
    {
        $icon = $data['icon'] ?? '';
        if (empty($icon)) {
            return $this->getDefaultIcon();
        }
        
        // 如果是完整URL，直接返回
        if (strpos($icon, 'http') === 0) {
            return $icon;
        }
        
        // 否则拼接为完整路径
        return config('app.domain') . '/static/images/payment/' . $icon;
    }
    
    /**
     * 方式标识获取器
     */
    public function getMethodTagAttr($value, $data)
    {
        $tags = [
            self::METHOD_USDT => ['text' => 'USDT', 'color' => 'success'],
            self::METHOD_HUIWANG => ['text' => '汇旺', 'color' => 'primary'],
            self::METHOD_ALIPAY => ['text' => '支付宝', 'color' => 'info'],
            self::METHOD_WECHAT => ['text' => '微信', 'color' => 'success'],
            self::METHOD_BANK => ['text' => '银行', 'color' => 'warning'],
        ];
        
        $code = $data['method_code'] ?? '';
        return $tags[$code] ?? ['text' => '其他', 'color' => 'secondary'];
    }
    
    /**
     * 关联充值账户
     */
    public function accounts()
    {
        return $this->hasMany(DepositAccount::class, 'method_code', 'method_code');
    }
    
    /**
     * 关联可用账户
     */
    public function activeAccounts()
    {
        return $this->hasMany(DepositAccount::class, 'method_code', 'method_code')
                    ->where('is_active', 1);
    }
    
    /**
     * 创建充值方式
     */
    public static function createMethod(array $data): DepositMethod
    {
        $method = new static();
        
        // 设置默认值
        $data = array_merge([
            'is_enabled' => self::STATUS_ENABLED,
            'sort_order' => 0,
            'min_amount' => 0.00,
            'max_amount' => 999999.99,
            'method_desc' => '',
            'icon' => '',
            'processing_time' => '实时到账',
        ], $data);
        
        $method->save($data);
        
        // 清除缓存
        $method->clearMethodCache();
        
        // 记录创建日志
        trace([
            'action' => 'deposit_method_created',
            'method_id' => $method->id,
            'method_code' => $method->method_code,
            'method_name' => $method->method_name,
            'timestamp' => time(),
        ], 'payment_method');
        
        return $method;
    }
    
    /**
     * 更新充值方式
     */
    public function updateMethod(array $data): bool
    {
        $updateFields = [
            'method_name', 'method_desc', 'icon', 'is_enabled', 
            'sort_order', 'min_amount', 'max_amount', 'processing_time'
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
            $this->clearMethodCache();
            
            // 记录更新日志
            trace([
                'action' => 'deposit_method_updated',
                'method_id' => $this->id,
                'method_code' => $this->method_code,
                'old_data' => $oldData,
                'new_data' => array_intersect_key($data, array_flip($updateFields)),
                'timestamp' => time(),
            ], 'payment_method');
        }
        
        return $result;
    }
    
    /**
     * 启用充值方式
     */
    public function enable(): bool
    {
        if ($this->is_enabled === self::STATUS_ENABLED) {
            return true;
        }
        
        $this->is_enabled = self::STATUS_ENABLED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearMethodCache();
            
            trace([
                'action' => 'deposit_method_enabled',
                'method_id' => $this->id,
                'method_code' => $this->method_code,
                'timestamp' => time(),
            ], 'payment_method');
        }
        
        return $result;
    }
    
    /**
     * 禁用充值方式
     */
    public function disable(): bool
    {
        if ($this->is_enabled === self::STATUS_DISABLED) {
            return true;
        }
        
        $this->is_enabled = self::STATUS_DISABLED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearMethodCache();
            
            trace([
                'action' => 'deposit_method_disabled',
                'method_id' => $this->id,
                'method_code' => $this->method_code,
                'timestamp' => time(),
            ], 'payment_method');
        }
        
        return $result;
    }
    
    /**
     * 验证充值金额
     */
    public function validateAmount(float $amount): array
    {
        $errors = [];
        
        if ($this->min_amount > 0 && $amount < $this->min_amount) {
            $errors[] = "充值金额不能少于 {$this->formatted_min_amount}";
        }
        
        if ($this->max_amount > 0 && $amount > $this->max_amount) {
            $errors[] = "充值金额不能超过 {$this->formatted_max_amount}";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * 获取可用账户
     */
    public function getAvailableAccount(): ?DepositAccount
    {
        return $this->activeAccounts()
                    ->order('usage_count ASC')
                    ->order('last_used_at ASC')
                    ->find();
    }
    
    /**
     * 根据方式代码查找
     */
    public static function findByCode(string $methodCode): ?DepositMethod
    {
        return static::where('method_code', strtolower($methodCode))->find();
    }
    
    /**
     * 获取启用的充值方式
     */
    public static function getEnabledMethods(): array
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('payment', 'deposit_methods', 'enabled');
        $cachedMethods = CacheHelper::get($cacheKey);
        
        if ($cachedMethods !== null) {
            return $cachedMethods;
        }
        
        // 从数据库获取
        $methods = static::where('is_enabled', self::STATUS_ENABLED)
                        ->order('sort_order ASC')
                        ->order('id ASC')
                        ->select()
                        ->toArray();
        
        // 更新缓存
        CacheHelper::set($cacheKey, $methods, 3600);
        
        return $methods;
    }
    
    /**
     * 获取所有充值方式
     */
    public static function getAllMethods(): array
    {
        return static::order('sort_order ASC')
                    ->order('id ASC')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 更新排序
     */
    public static function updateSort(array $sortData): bool
    {
        try {
            foreach ($sortData as $data) {
                if (isset($data['id']) && isset($data['sort_order'])) {
                    static::where('id', $data['id'])
                         ->update(['sort_order' => $data['sort_order']]);
                }
            }
            
            // 清除缓存
            static::clearAllMethodCache();
            
            return true;
            
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取充值方式统计
     */
    public static function getMethodStats(): array
    {
        return [
            'total_methods' => static::count(),
            'enabled_methods' => static::where('is_enabled', self::STATUS_ENABLED)->count(),
            'disabled_methods' => static::where('is_enabled', self::STATUS_DISABLED)->count(),
            'usdt_methods' => static::where('method_code', 'like', '%usdt%')->count(),
            'methods_with_accounts' => static::alias('m')
                                           ->join('dianji_deposit_accounts a', 'm.method_code = a.method_code')
                                           ->group('m.id')
                                           ->count(),
        ];
    }
    
    /**
     * 批量启用/禁用
     */
    public static function batchUpdateStatus(array $methodIds, int $status): int
    {
        if (empty($methodIds) || !in_array($status, [self::STATUS_DISABLED, self::STATUS_ENABLED])) {
            return 0;
        }
        
        $count = static::whereIn('id', $methodIds)->update([
            'is_enabled' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        if ($count > 0) {
            static::clearAllMethodCache();
        }
        
        return $count;
    }
    
    /**
     * 获取默认图标
     */
    private function getDefaultIcon(): string
    {
        $defaultIcons = [
            self::METHOD_USDT => 'usdt.png',
            self::METHOD_HUIWANG => 'huiwang.png',
            self::METHOD_ALIPAY => 'alipay.png',
            self::METHOD_WECHAT => 'wechat.png',
            self::METHOD_BANK => 'bank.png',
        ];
        
        $icon = $defaultIcons[$this->method_code] ?? 'default.png';
        return config('app.domain') . '/static/images/payment/' . $icon;
    }
    
    /**
     * 清除方式缓存
     */
    public function clearMethodCache(): void
    {
        $cacheKeys = [
            CacheHelper::key('payment', 'deposit_methods', 'enabled'),
            CacheHelper::key('payment', 'deposit_methods', 'all'),
            CacheHelper::key('payment', 'deposit_method', $this->method_code),
        ];
        
        foreach ($cacheKeys as $key) {
            CacheHelper::delete($key);
        }
    }
    
    /**
     * 清除所有方式缓存
     */
    public static function clearAllMethodCache(): void
    {
        $cacheKeys = [
            CacheHelper::key('payment', 'deposit_methods', 'enabled'),
            CacheHelper::key('payment', 'deposit_methods', 'all'),
        ];
        
        foreach ($cacheKeys as $key) {
            CacheHelper::delete($key);
        }
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用',
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
            'method_name' => '充值方式名称',
            'method_desc' => '充值方式描述',
            'icon' => '图标',
            'is_enabled' => '是否启用',
            'sort_order' => '排序顺序',
            'min_amount' => '最小充值金额',
            'max_amount' => '最大充值金额',
            'processing_time' => '处理时间说明',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '充值方式配置表';
    }
}