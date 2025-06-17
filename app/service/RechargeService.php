<?php
// 文件位置: app/service/RechargeService.php
// 充值服务 - 精简实用版，与PaymentController和数据库模型完美联动

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\Recharge;
use app\model\DepositMethod;
use app\model\DepositAccount;
use think\facade\Log;
use think\facade\Cache;
use think\exception\ValidateException;

class RechargeService
{
    // 支付状态常量
    const STATUS_PENDING = 0;    // 待审核
    const STATUS_SUCCESS = 1;    // 成功
    const STATUS_FAILED = 2;     // 失败
    const STATUS_CANCELLED = 3;  // 已取消
    
    // 支付方式常量
    const METHOD_HUIWANG = 'huiwang';
    const METHOD_USDT = 'usdt';
    
    
    // =================== 核心业务方法 ===================
    
    /**
     * 获取充值方式列表（合并版：支持Telegram和普通模式）
     */
    public function getDepositMethods(bool $forTelegram = false): array
    {
        try {
            $cacheKey = $forTelegram ? 'telegram_deposit_methods' : 'deposit_methods';
            $cachedData = Cache::get($cacheKey);
            
            if ($cachedData) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $cachedData
                ];
            }
            
            // 获取启用的充值方式
            $methods = DepositMethod::getEnabledMethods();
            
            if ($forTelegram) {
                // Telegram模式：返回增强格式
                $telegramMethods = [];
                foreach ($methods as $method) {
                    $availableAccounts = count(DepositAccount::getAvailableAccounts($method['method_code']));
                    
                    $telegramMethods[] = [
                        'method_code' => $method['method_code'],
                        'method_name' => $method['method_name'],
                        'icon' => $this->getMethodIcon($method['method_code']),
                        'description' => $method['method_desc'] ?? '',
                        'min_amount' => $method['min_amount'],
                        'max_amount' => $method['max_amount'],
                        'amount_range' => $this->formatAmountRange($method['min_amount'], $method['max_amount']),
                        'fee_info' => $this->getMethodFeeInfo($method['method_code']),
                        'arrive_time' => $method['processing_time'] ?? '实时到账',
                        'network_type' => $this->getNetworkType($method['method_code']),
                        'is_available' => $availableAccounts > 0,
                        'quick_amounts' => $this->getQuickAmounts($method['method_code'])['data'] ?? [],
                        'sort_order' => $method['sort_order'] ?? 0
                    ];
                }
                
                // 按可用性和排序排列
                usort($telegramMethods, function($a, $b) {
                    if ($a['is_available'] !== $b['is_available']) {
                        return $b['is_available'] - $a['is_available'];
                    }
                    return $a['sort_order'] - $b['sort_order'];
                });
                
                Cache::set($cacheKey, $telegramMethods, 300); // 缓存5分钟
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $telegramMethods
                ];
            } else {
                // 普通模式：返回基础格式（向后兼容）
                Cache::set($cacheKey, $methods, 600); // 缓存10分钟
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $methods
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('获取充值方式失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '获取充值方式失败',
                'data' => []
            ];
        }
    }
    
    /**
     * 获取支付方式配置信息
     */
    public function getPaymentConfig(string $method): array
    {
        try {
            $cacheKey = "payment_config_{$method}";
            $cachedData = Cache::get($cacheKey);
            
            if ($cachedData) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $cachedData
                ];
            }
            
            // 获取方式基础信息
            $methodInfo = DepositMethod::findByCode($method);
            if (!$methodInfo || !$methodInfo->enabled) {
                throw new ValidateException('充值方式不可用');
            }
            
            // 构建配置数据
            $config = [
                'method_code' => $method,
                'method_name' => $methodInfo->method_name,
                'display_name' => $this->getMethodDisplayName($method),
                'description' => $methodInfo->method_desc ?? '',
                'icon' => $this->getMethodIcon($method),
                
                // 金额限制
                'min_amount' => $methodInfo->min_amount,
                'max_amount' => $methodInfo->max_amount,
                'min_amount_display' => $this->getMinAmountDisplay($method, $methodInfo->min_amount),
                'max_amount_display' => $this->getMaxAmountDisplay($method, $methodInfo->max_amount),
                'amount_range' => $this->formatAmountRange($methodInfo->min_amount, $methodInfo->max_amount),
                
                // 费用信息
                'fee_info' => $this->getMethodFeeInfo($method),
                'fee_rate' => $this->getMethodFeeRate($method),
                
                // 处理信息
                'network_type' => $this->getNetworkType($method),
                'arrive_time' => $methodInfo->processing_time ?? $this->getArriveTime($method),
                'processing_steps' => $this->getProcessingSteps($method),
                
                // 可用性
                'is_available' => count(DepositAccount::getAvailableAccounts($method)) > 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            Cache::set($cacheKey, $config, 300); // 缓存5分钟
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $config
            ];
            
        } catch (\Exception $e) {
            Log::error('获取支付配置失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * 获取收款账户（合并版：支持最优账户选择） - 增强汇旺支付支持
     */
    public function getDepositAccounts(string $method, bool $optimal = false): array
    {
        try {
            // 获取可用账户
            $availableAccounts = DepositAccount::getAvailableAccounts($method);
            
            if (empty($availableAccounts)) {
                throw new ValidateException('暂无可用的收款账户');
            }
            
            if ($optimal) {
                // 返回最优账户（整合generatePaymentAddress功能）
                $optimalAccount = $this->selectOptimalAccount($availableAccounts);
                
                if (!$optimalAccount) {
                    throw new ValidateException('没有可用的收款账户');
                }
                
                // 更新使用记录
                $optimalAccount->recordUsage();
                
                // 格式化返回数据
                $accountData = [
                    'id' => $optimalAccount->id,
                    'method_code' => $optimalAccount->method_code,
                    'account_name' => $optimalAccount->account_name,
                    'account_type' => $optimalAccount->account_type,
                    'display_info' => $optimalAccount->display_info,
                    'qr_code_url' => $optimalAccount->qr_code_url,
                    'network_type' => $optimalAccount->network_type ?? $this->getNetworkType($method),
                ];
                
                // 根据账户类型返回支付信息
                if ($optimalAccount->account_type === 'crypto' || $method === self::METHOD_USDT) {
                    // USDT/加密货币账户
                    $accountData['payment_address'] = $optimalAccount->wallet_address;
                    $accountData['copy_text'] = $optimalAccount->wallet_address;
                } elseif ($optimalAccount->account_type === 'bank' || $method === self::METHOD_HUIWANG) {
                    // 银行账户（汇旺支付）
                    $accountData['account_number'] = $optimalAccount->account_number;
                    $accountData['bank_name'] = $optimalAccount->bank_name;
                    $accountData['copy_text'] = $optimalAccount->account_number;
                    
                    // 确保汇旺支付账户包含必要的银行信息
                    if (empty($accountData['account_number']) || empty($accountData['bank_name'])) {
                        Log::warning('汇旺支付账户信息不完整', [
                            'account_id' => $optimalAccount->id,
                            'account_number' => $optimalAccount->account_number,
                            'bank_name' => $optimalAccount->bank_name
                        ]);
                        throw new ValidateException('收款账户信息不完整');
                    }
                }
                
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $accountData
                ];
            } else {
                // 返回所有可用账户（向后兼容）
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $availableAccounts
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('获取收款账户失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * 验证充值金额
     */
    public function validateAmount(string $method, float $amount, int $userId = 0): array
    {
        try {
            // 获取方式配置
            $methodInfo = DepositMethod::findByCode($method);
            if (!$methodInfo || !$methodInfo->enabled) {
                return [
                    'valid' => false,
                    'errors' => ['充值方式不可用']
                ];
            }
            
            $errors = [];
            
            // 基础验证
            if ($amount <= 0) {
                $errors[] = '充值金额必须大于0';
            }
            
            // 格式验证（小数位数）
            if (!$this->isValidAmountFormat($amount)) {
                $errors[] = '金额格式错误，最多支持2位小数';
            }
            
            // 范围验证
            if ($methodInfo->min_amount > 0 && $amount < $methodInfo->min_amount) {
                $errors[] = "充值金额不能少于 " . number_format($methodInfo->min_amount, 2) . " USDT";
            }
            
            if ($methodInfo->max_amount > 0 && $amount > $methodInfo->max_amount) {
                $errors[] = "充值金额不能超过 " . number_format($methodInfo->max_amount, 2) . " USDT";
            }
            
            // 用户状态验证
            if ($userId > 0) {
                $user = User::find($userId);
                if (!$user) {
                    $errors[] = '用户不存在';
                } elseif ($user->status !== User::STATUS_NORMAL) {
                    $errors[] = '用户状态异常，无法充值';
                }
            }
            
            // 计算费用信息
            $feeInfo = $this->calculateFee($method, $amount);
            
            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'fee_info' => $feeInfo,
                'suggestions' => empty($errors) ? [] : $this->getAmountSuggestions($method, $amount)
            ];
            
        } catch (\Exception $e) {
            Log::error('验证充值金额失败: ' . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['系统验证失败，请稍后重试']
            ];
        }
    }
    
    /**
     * 获取快捷金额选项
     */
    public function getQuickAmounts(string $method): array
    {
        try {
            $cacheKey = "quick_amounts_{$method}";
            $cachedAmounts = Cache::get($cacheKey);
            
            if ($cachedAmounts) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $cachedAmounts
                ];
            }
            
            $methodInfo = DepositMethod::findByCode($method);
            if (!$methodInfo) {
                return [
                    'code' => 404,
                    'msg' => '充值方式不存在',
                    'data' => []
                ];
            }
            
            $minAmount = $methodInfo->min_amount;
            $maxAmount = $methodInfo->max_amount;
            
            // 基础快捷金额
            $baseAmounts = [];
            if ($method === self::METHOD_USDT) {
                $baseAmounts = [20, 50, 100, 200, 500, 1000, 2000, 5000];
            } else {
                $baseAmounts = [100, 200, 500, 1000, 2000, 5000, 10000];
            }
            
            // 过滤在限额范围内的金额
            $validAmounts = array_filter($baseAmounts, function($amount) use ($minAmount, $maxAmount) {
                return $amount >= $minAmount && $amount <= $maxAmount;
            });
            
            // 格式化输出
            $quickAmounts = [];
            foreach ($validAmounts as $amount) {
                $quickAmounts[] = [
                    'amount' => $amount,
                    'display' => number_format($amount) . ' USDT',
                    'callback_data' => "quick_amount_{$amount}",
                ];
            }
            
            // 如果没有合适的快捷金额，生成动态金额
            if (empty($quickAmounts)) {
                $quickAmounts = $this->generateDynamicAmounts($minAmount, $maxAmount);
            }
            
            Cache::set($cacheKey, $quickAmounts, 600); // 缓存10分钟
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $quickAmounts
            ];
            
        } catch (\Exception $e) {
            Log::error('生成快捷金额失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '获取快捷金额失败',
                'data' => []
            ];
        }
    }
    
    /**
     * 创建充值订单（保持原有方法，略作优化）
     */
    public function createRechargeOrder(int $userId, float $amount, string $method): array
    {
        try {
            // 实时验证金额
            $validation = $this->validateAmount($method, $amount, $userId);
            if (!$validation['valid']) {
                throw new ValidateException(implode(', ', $validation['errors']));
            }
            
            // 检查用户状态
            $user = User::find($userId);
            if (!$user || $user->status != User::STATUS_NORMAL) {
                throw new ValidateException('用户状态异常');
            }
            
            // 生成订单号
            $orderNo = $this->generateOrderNo('R');
            
            // 创建充值订单
            $orderData = [
                'order_number' => $orderNo,
                'user_id' => $userId,
                'money' => $amount,
                'payment_method' => $method,
                'user_ip' => request()->ip(),
                'status' => self::STATUS_PENDING,
                'create_time' => time()
            ];
            
            $order = Recharge::create($orderData);
            
            // 获取最优收款账户
            $accountResult = $this->getDepositAccounts($method, true);
            $account = $accountResult['code'] === 200 ? $accountResult['data'] : null;
            
            Log::info('创建充值订单', [
                'user_id' => $userId,
                'order_no' => $orderNo,
                'amount' => $amount,
                'method' => $method
            ]);
            
            return [
                'code' => 200,
                'msg' => '订单创建成功',
                'data' => [
                    'order_no' => $orderNo,
                    'amount' => $amount,
                    'method' => $method,
                    'account' => $account
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('创建充值订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 提交支付凭证（保持原有方法）
     */
    public function submitPaymentProof(string $orderNo, array $proofData): array
    {
        try {
            $order = Recharge::findByOrderNumber($orderNo);
            if (!$order) {
                throw new ValidateException('订单不存在');
            }
            
            if ($order->status != self::STATUS_PENDING) {
                throw new ValidateException('订单状态异常');
            }
            
            // 更新订单凭证信息
            $updateData = [];
            if (isset($proofData['transaction_id'])) {
                $updateData['transaction_id'] = $proofData['transaction_id'];
            }
            if (isset($proofData['payment_proof'])) {
                $updateData['payment_proof'] = $proofData['payment_proof'];
            }
            if (isset($proofData['tg_message_id'])) {
                $updateData['tg_message_id'] = $proofData['tg_message_id'];
            }
            
            $order->save($updateData);
            

            // 直接删除广播相关代码
            $user = User::find($order->user_id);
            if ($user) {
                Log::info('充值凭证提交成功', [
                    'order_no' => $orderNo,
                    'user_id' => $order->user_id,
                    'amount' => $order->money,
                    'method' => $order->payment_method
                ]);
            }
            
            Log::info('提交支付凭证', [
                'order_no' => $orderNo,
                'user_id' => $order->user_id
            ]);
            
            return [
                'code' => 200,
                'msg' => '凭证提交成功，请等待审核',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('提交支付凭证失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 查询方法 ===================
    
    /**
     * 获取充值订单列表（保持原有方法）
     */
    public function getRechargeOrders(int $userId, array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $limit = min($params['limit'] ?? 20, 100);
            $status = $params['status'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = [['user_id', '=', $userId]];
            if ($status !== '') {
                $where[] = ['status', '=', $status];
            }
            
            $orders = Recharge::where($where)
                                ->order('create_time', 'desc')
                                ->limit($offset, $limit)
                                ->select();
            
            $total = Recharge::where($where)->count();
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $orders,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取充值订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取充值订单详情（保持原有方法）
     */
    public function getRechargeDetail(int $userId, string $orderNo): array
    {
        try {
            $order = Recharge::where('order_number', $orderNo)
                                ->where('user_id', $userId)
                                ->find();
            
            if (!$order) {
                throw new ValidateException('订单不存在');
            }
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $order
            ];
            
        } catch (\Exception $e) {
            Log::error('获取充值订单详情失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 检查订单状态（简化版状态检查）
     */
    public function checkOrderStatus(string $orderNo): array
    {
        try {
            $order = Recharge::findByOrderNumber($orderNo);
            if (!$order) {
                return [
                    'code' => 404,
                    'msg' => '订单不存在',
                    'data' => null
                ];
            }
            
            $statusData = [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'status_text' => $order->status_text,
                'amount' => $order->formatted_money,
                'method' => $order->payment_method_text,
                'created_at' => date('Y-m-d H:i:s', $order->create_time),
                'is_pending' => $order->is_pending,
                'is_success' => $order->is_success,
                'can_cancel' => $order->is_pending && $this->canCancelOrder($order),
            ];
            
            // 如果有处理时间，添加处理时长
            if ($order->processing_time) {
                $statusData['processing_time'] = $order->processing_time;
            }
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $statusData
            ];
            
        } catch (\Exception $e) {
            Log::error('检查订单状态失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '获取状态失败',
                'data' => null
            ];
        }
    }
    
    // =================== 私有工具方法 ===================
    
    /**
     * 获取方式图标
     */
    private function getMethodIcon(string $method): string
    {
        $icons = [
            self::METHOD_USDT => '₿',
            self::METHOD_HUIWANG => '⚡',
        ];
        return $icons[$method] ?? '💰';
    }
    
    /**
     * 获取方式显示名称
     */
    private function getMethodDisplayName(string $method): string
    {
        $names = [
            self::METHOD_USDT => 'USDT(TRC-20)',
            self::METHOD_HUIWANG => '汇旺支付'
        ];
        return $names[$method] ?? strtoupper($method);
    }
    
    /**
     * 获取最小金额显示
     */
    private function getMinAmountDisplay(string $method, float $minAmount): string
    {
        return number_format($minAmount, 0) . ' USDT';
    }
    
    /**
     * 获取最大金额显示
     */
    private function getMaxAmountDisplay(string $method, float $maxAmount): string
    {
        if ($maxAmount <= 0) {
            return '无限制';
        }
        return number_format($maxAmount, 0) . ' USDT';
    }
    
    /**
     * 格式化金额范围
     */
    private function formatAmountRange(float $min, float $max): string
    {
        if ($min > 0 && $max > 0) {
            return number_format($min, 0) . ' - ' . number_format($max, 0) . ' USDT';
        } elseif ($min > 0) {
            return '≥ ' . number_format($min, 0) . ' USDT';
        } elseif ($max > 0) {
            return '≤ ' . number_format($max, 0) . ' USDT';
        }
        return '无限制';
    }
    
    /**
     * 获取方式手续费信息
     */
    private function getMethodFeeInfo(string $method): string
    {
        $feeRate = $this->getMethodFeeRate($method);
        if ($feeRate == 0) {
            return '免费';
        } else {
            return sprintf('%.1f%%', $feeRate * 100);
        }
    }
    
    /**
     * 获取方式手续费率
     */
    private function getMethodFeeRate(string $method): float
    {
        $feeMap = [
            self::METHOD_USDT => 0.0,
            self::METHOD_HUIWANG => 0.0,
        ];
        return $feeMap[$method] ?? 0.0;
    }
    
    /**
     * 获取网络类型
     */
    private function getNetworkType(string $method): string
    {
        $networks = [
            self::METHOD_USDT => 'TRC-20',
            self::METHOD_HUIWANG => '银行转账'
        ];
        return $networks[$method] ?? 'Unknown';
    }
    
    /**
     * 获取预计到账时间
     */
    private function getArriveTime(string $method): string
    {
        $times = [
            self::METHOD_USDT => '1-3分钟',
            self::METHOD_HUIWANG => '5-10分钟'
        ];
        return $times[$method] ?? '实时到账';
    }
    
    /**
     * 获取处理步骤
     */
    private function getProcessingSteps(string $method): array
    {
        if ($method === self::METHOD_USDT) {
            return [
                '1. 复制钱包地址',
                '2. 打开钱包APP转账',
                '3. 点击"转账完成"',
                '4. 等待系统确认'
            ];
        } else {
            return [
                '1. 复制银行账号信息',
                '2. 通过银行APP转账',
                '3. 点击"转账完成"',
                '4. 输入银行订单号',
                '5. 等待人工审核'
            ];
        }
    }
    
    /**
     * 选择最优账户（简化版智能选择） - 增强汇旺支付验证
     */
    private function selectOptimalAccount(array $accounts): ?DepositAccount
    {
        if (empty($accounts)) {
            return null;
        }
        
        // 简单策略：使用次数最少的账户，优先选择信息完整的账户
        $optimalAccount = null;
        $minUsageCount = PHP_INT_MAX;
        
        foreach ($accounts as $account) {
            if (!$account->isAvailable()) {
                continue;
            }
            
            // 对汇旺支付进行额外验证
            if ($account->method_code === self::METHOD_HUIWANG) {
                // 确保汇旺支付账户有必要的银行信息
                if (empty($account->account_number) || empty($account->bank_name) || empty($account->account_name)) {
                    Log::warning('跳过信息不完整的汇旺支付账户', [
                        'account_id' => $account->id,
                        'account_number' => $account->account_number,
                        'bank_name' => $account->bank_name,
                        'account_name' => $account->account_name
                    ]);
                    continue;
                }
            }
            
            if ($account->usage_count < $minUsageCount) {
                $minUsageCount = $account->usage_count;
                $optimalAccount = $account;
            }
        }
        
        return $optimalAccount ?: $accounts[0]; // 如果没找到，返回第一个
    }
    
    /**
     * 验证金额格式
     */
    private function isValidAmountFormat(float $amount): bool
    {
        // 检查小数位数是否超过2位
        $str = (string)$amount;
        $decimalPos = strpos($str, '.');
        if ($decimalPos !== false) {
            $decimalPlaces = strlen(substr($str, $decimalPos + 1));
            return $decimalPlaces <= 2;
        }
        return true;
    }
    
    /**
     * 计算手续费
     */
    private function calculateFee(string $method, float $amount): array
    {
        $feeRate = $this->getMethodFeeRate($method);
        $fee = $amount * $feeRate;
        $actualAmount = $amount - $fee;
        
        return [
            'original_amount' => $amount,
            'fee_rate' => $feeRate,
            'fee_amount' => round($fee, 2),
            'actual_amount' => round($actualAmount, 2),
            'formatted_fee' => number_format($fee, 2) . ' USDT',
            'formatted_actual' => number_format($actualAmount, 2) . ' USDT'
        ];
    }
    
    /**
     * 获取金额建议
     */
    private function getAmountSuggestions(string $method, float $amount): array
    {
        $methodInfo = DepositMethod::findByCode($method);
        if (!$methodInfo) {
            return [];
        }
        
        $suggestions = [];
        
        if ($amount < $methodInfo->min_amount) {
            $suggestions[] = "建议充值金额：" . number_format($methodInfo->min_amount, 0) . " USDT";
        }
        
        if ($amount > $methodInfo->max_amount && $methodInfo->max_amount > 0) {
            $suggestions[] = "建议充值金额：" . number_format($methodInfo->max_amount, 0) . " USDT";
        }
        
        return $suggestions;
    }
    
    /**
     * 生成动态金额（当基础快捷金额不适用时）
     */
    private function generateDynamicAmounts(float $min, float $max): array
    {
        $amounts = [];
        $count = 6; // 生成6个金额选项
        
        if ($max > $min) {
            $step = ($max - $min) / ($count - 1);
            for ($i = 0; $i < $count; $i++) {
                $amount = round($min + ($step * $i));
                $amounts[] = [
                    'amount' => $amount,
                    'display' => number_format($amount) . ' USDT',
                    'callback_data' => "quick_amount_{$amount}"
                ];
            }
        } else {
            // 如果范围太小，只生成最小金额
            $amounts[] = [
                'amount' => $min,
                'display' => number_format($min) . ' USDT',
                'callback_data' => "quick_amount_{$min}"
            ];
        }
        
        return $amounts;
    }
    
    /**
     * 检查是否可以取消订单
     */
    private function canCancelOrder($order): bool
    {
        // 超过30分钟且未提交凭证的订单可以取消
        $elapsed = time() - $order->create_time;
        return $elapsed > 1800 && empty($order->transaction_id);
    }
    
    /**
     * 生成订单号
     */
    private function generateOrderNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . sprintf('%06d', mt_rand(0, 999999));
    }
}