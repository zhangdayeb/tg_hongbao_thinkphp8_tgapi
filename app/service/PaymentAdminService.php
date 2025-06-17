<?php
// 文件位置: app/service/PaymentAdminService.php
// 支付管理服务 - 处理后端管理员审核和管理功能

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\PaymentOrder;
use app\model\WithdrawOrder;
use app\model\MoneyLog;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use think\exception\ValidateException;

class PaymentAdminService
{
    // 支付状态常量
    const STATUS_PENDING = 0;    // 待审核
    const STATUS_SUCCESS = 1;    // 成功
    const STATUS_FAILED = 2;     // 失败
    const STATUS_CANCELLED = 3;  // 已取消
    
    private TelegramService $telegramService;
    private TelegramBroadcastService $telegramBroadcastService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->telegramBroadcastService = new TelegramBroadcastService();
    }
    
    // =================== 1. 充值审核功能 ===================
    
    /**
     * 审核充值订单
     */
    public function processRecharge(string $orderNo, int $status, string $remark = ''): array
    {
        try {
            Db::startTrans();
            
            // 查找订单
            $order = PaymentOrder::where('order_number', $orderNo)->find();
            if (!$order) {
                throw new ValidateException('订单不存在');
            }
            
            if ($order->status != self::STATUS_PENDING) {
                throw new ValidateException('订单状态异常');
            }
            
            // 获取用户信息
            $user = User::find($order->user_id);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            if ($status == self::STATUS_SUCCESS) {
                // 充值成功
                $order->save([
                    'status' => self::STATUS_SUCCESS,
                    'success_time' => date('Y-m-d H:i:s'),
                    'admin_remarks' => $remark
                ]);
                
                // 更新用户余额
                $oldBalance = $user->money_balance;
                $newBalance = $oldBalance + $order->money;
                
                $user->save(['money_balance' => $newBalance]);
                
                // 记录资金流水
                $this->createMoneyLog($order->user_id, 1, 101, $oldBalance, $newBalance, $order->money, $order->id, "充值到账 - 订单号{$orderNo}");
                                
                $logMsg = "充值审核通过: {$orderNo}";
                
            } else {
                // 充值失败
                $order->save([
                    'status' => self::STATUS_FAILED,
                    'admin_remarks' => $remark
                ]);
                
                $logMsg = "充值审核拒绝: {$orderNo}";
            }
            
            Db::commit();
            
            // 记录日志
            Log::info($logMsg, [
                'order_no' => $orderNo,
                'user_id' => $order->user_id,
                'status' => $status,
                'remark' => $remark
            ]);
            
            return [
                'code' => 200,
                'msg' => '审核完成',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('审核充值订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 批量审核充值订单
     */
    public function batchProcessRecharge(array $orderNos, int $status, string $remark = ''): array
    {
        try {
            $successCount = 0;
            $failedCount = 0;
            $results = [];
            
            foreach ($orderNos as $orderNo) {
                try {
                    $this->processRecharge($orderNo, $status, $remark);
                    $successCount++;
                    $results[] = [
                        'order_no' => $orderNo,
                        'status' => 'success'
                    ];
                } catch (\Exception $e) {
                    $failedCount++;
                    $results[] = [
                        'order_no' => $orderNo,
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return [
                'code' => 200,
                'msg' => '批量审核完成',
                'data' => [
                    'total' => count($orderNos),
                    'success' => $successCount,
                    'failed' => $failedCount,
                    'details' => $results
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('批量审核充值订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取待审核充值订单列表
     */
    public function getPendingRechargeOrders(array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $limit = min($params['limit'] ?? 20, 100);
            $startTime = $params['start_time'] ?? '';
            $endTime = $params['end_time'] ?? '';
            $method = $params['method'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = [['status', '=', self::STATUS_PENDING]];
            
            if (!empty($startTime)) {
                $where[] = ['create_time', '>=', $startTime];
            }
            if (!empty($endTime)) {
                $where[] = ['create_time', '<=', $endTime];
            }
            if (!empty($method)) {
                $where[] = ['payment_method', '=', $method];
            }
            
            $orders = PaymentOrder::where($where)
                                ->with(['user'])
                                ->order('create_time', 'desc')
                                ->limit($offset, $limit)
                                ->select();
            
            $total = PaymentOrder::where($where)->count();
            
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
            Log::error('获取待审核充值订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 2. 提现审核功能 ===================
    
    /**
     * 审核提现订单
     */
    public function processWithdraw(string $orderNo, int $status, string $remark = ''): array
    {
        try {
            Db::startTrans();
            
            // 查找订单
            $order = WithdrawOrder::where('order_number', $orderNo)->find();
            if (!$order) {
                throw new ValidateException('提现订单不存在');
            }
            
            if ($order->status != self::STATUS_PENDING) {
                throw new ValidateException('订单状态异常');
            }
            
            // 获取用户信息
            $user = User::find($order->user_id);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            if ($status == self::STATUS_SUCCESS) {
                // 提现成功
                $order->save([
                    'status' => self::STATUS_SUCCESS,
                    'success_time' => date('Y-m-d H:i:s'),
                    'msg' => $remark
                ]);
                
                // 发送个人提现成功通知
                $this->telegramService->sendWithdrawSuccessNotify($order->user_id, [
                    'amount' => $order->money,
                    'actual_amount' => $order->money_actual,
                    'order_no' => $orderNo,
                    'time' => date('Y-m-d H:i:s')
                ]);
                
                // 🔥 提现成功群组广播
                $this->telegramBroadcastService->broadcastWithdrawSuccess([
                    'user' => $user,
                    'amount' => $order->money,
                    'order_no' => $orderNo,
                    'time' => date('Y-m-d H:i:s')
                ]);
                
                $logMsg = "提现审核通过: {$orderNo}";
                
            } else {
                // 提现失败，退还余额
                $order->save([
                    'status' => self::STATUS_FAILED,
                    'msg' => $remark
                ]);
                
                // 退还用户余额
                $refundAmount = $order->money + $order->money_fee;
                $oldBalance = $user->money_balance;
                $newBalance = $oldBalance + $refundAmount;
                
                $user->save(['money_balance' => $newBalance]);
                
                // 记录资金流水
                $this->createMoneyLog($order->user_id, 1, 401, $oldBalance, $newBalance, $refundAmount, $order->id, "提现失败退款 - 订单号{$orderNo}");
                
                // 发送提现失败通知
                $this->telegramService->sendWithdrawFailedNotify($order->user_id, [
                    'amount' => $order->money,
                    'order_no' => $orderNo,
                    'reason' => $remark,
                    'time' => date('Y-m-d H:i:s')
                ]);
                
                $logMsg = "提现审核拒绝: {$orderNo}，已退还余额";
            }
            
            Db::commit();
            
            // 记录日志
            Log::info($logMsg, [
                'order_no' => $orderNo,
                'user_id' => $order->user_id,
                'status' => $status,
                'remark' => $remark
            ]);
            
            return [
                'code' => 200,
                'msg' => '审核完成',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('审核提现订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 批量审核提现订单
     */
    public function batchProcessWithdraw(array $orderNos, int $status, string $remark = ''): array
    {
        try {
            $successCount = 0;
            $failedCount = 0;
            $results = [];
            
            foreach ($orderNos as $orderNo) {
                try {
                    $this->processWithdraw($orderNo, $status, $remark);
                    $successCount++;
                    $results[] = [
                        'order_no' => $orderNo,
                        'status' => 'success'
                    ];
                } catch (\Exception $e) {
                    $failedCount++;
                    $results[] = [
                        'order_no' => $orderNo,
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return [
                'code' => 200,
                'msg' => '批量审核完成',
                'data' => [
                    'total' => count($orderNos),
                    'success' => $successCount,
                    'failed' => $failedCount,
                    'details' => $results
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('批量审核提现订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取待审核提现订单列表
     */
    public function getPendingWithdrawOrders(array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $limit = min($params['limit'] ?? 20, 100);
            $startTime = $params['start_time'] ?? '';
            $endTime = $params['end_time'] ?? '';
            $minAmount = $params['min_amount'] ?? '';
            $maxAmount = $params['max_amount'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = [['status', '=', self::STATUS_PENDING]];
            
            if (!empty($startTime)) {
                $where[] = ['create_time', '>=', $startTime];
            }
            if (!empty($endTime)) {
                $where[] = ['create_time', '<=', $endTime];
            }
            if ($minAmount !== '') {
                $where[] = ['money', '>=', $minAmount];
            }
            if ($maxAmount !== '') {
                $where[] = ['money', '<=', $maxAmount];
            }
            
            $orders = WithdrawOrder::where($where)
                                 ->with(['user'])
                                 ->order('create_time', 'desc')
                                 ->limit($offset, $limit)
                                 ->select();
            
            $total = WithdrawOrder::where($where)->count();
            
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
            Log::error('获取待审核提现订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 3. 管理员查询统计功能 ===================
    
    /**
     * 获取支付统计概览
     */
    public function getPaymentOverview(array $params = []): array
    {
        try {
            $startTime = $params['start_time'] ?? date('Y-m-d', strtotime('-30 days'));
            $endTime = $params['end_time'] ?? date('Y-m-d');
            
            // 充值统计
            $rechargeStats = PaymentOrder::where('create_time', '>=', $startTime . ' 00:00:00')
                                       ->where('create_time', '<=', $endTime . ' 23:59:59')
                                       ->field('
                                           status,
                                           COUNT(*) as count,
                                           SUM(money) as total_amount
                                       ')
                                       ->group('status')
                                       ->select()
                                       ->toArray();
            
            // 提现统计
            $withdrawStats = WithdrawOrder::where('create_time', '>=', $startTime . ' 00:00:00')
                                        ->where('create_time', '<=', $endTime . ' 23:59:59')
                                        ->field('
                                            status,
                                            COUNT(*) as count,
                                            SUM(money) as total_amount,
                                            SUM(money_fee) as total_fee
                                        ')
                                        ->group('status')
                                        ->select()
                                        ->toArray();
            
            // 今日统计
            $today = date('Y-m-d');
            $todayRecharge = PaymentOrder::where('create_time', '>=', $today . ' 00:00:00')
                                       ->where('create_time', '<=', $today . ' 23:59:59')
                                       ->where('status', self::STATUS_SUCCESS)
                                       ->field('COUNT(*) as count, SUM(money) as amount')
                                       ->find();
            
            $todayWithdraw = WithdrawOrder::where('create_time', '>=', $today . ' 00:00:00')
                                        ->where('create_time', '<=', $today . ' 23:59:59')
                                        ->where('status', self::STATUS_SUCCESS)
                                        ->field('COUNT(*) as count, SUM(money) as amount')
                                        ->find();
            
            // 待审核数量
            $pendingRecharge = PaymentOrder::where('status', self::STATUS_PENDING)->count();
            $pendingWithdraw = WithdrawOrder::where('status', self::STATUS_PENDING)->count();
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'overview' => [
                        'pending_recharge' => $pendingRecharge,
                        'pending_withdraw' => $pendingWithdraw,
                        'today_recharge' => [
                            'count' => $todayRecharge->count ?? 0,
                            'amount' => $todayRecharge->amount ?? 0
                        ],
                        'today_withdraw' => [
                            'count' => $todayWithdraw->count ?? 0,
                            'amount' => $todayWithdraw->amount ?? 0
                        ]
                    ],
                    'recharge_stats' => $rechargeStats,
                    'withdraw_stats' => $withdrawStats,
                    'period' => [
                        'start_time' => $startTime,
                        'end_time' => $endTime
                    ]
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取支付统计概览失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取订单列表（管理员视图）
     */
    public function getOrdersForAdmin(string $type, array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $limit = min($params['limit'] ?? 20, 100);
            $status = $params['status'] ?? '';
            $startTime = $params['start_time'] ?? '';
            $endTime = $params['end_time'] ?? '';
            $userId = $params['user_id'] ?? '';
            $orderNo = $params['order_no'] ?? '';
            
            $offset = ($page - 1) * $limit;
            $where = [];
            
            // 状态筛选
            if ($status !== '') {
                $where[] = ['status', '=', $status];
            }
            
            // 时间范围
            if (!empty($startTime)) {
                $where[] = ['create_time', '>=', $startTime . ' 00:00:00'];
            }
            if (!empty($endTime)) {
                $where[] = ['create_time', '<=', $endTime . ' 23:59:59'];
            }
            
            // 用户ID
            if (!empty($userId)) {
                $where[] = ['user_id', '=', $userId];
            }
            
            // 订单号
            if (!empty($orderNo)) {
                $where[] = ['order_number', 'like', '%' . $orderNo . '%'];
            }
            
            if ($type === 'recharge') {
                $orders = PaymentOrder::where($where)
                                    ->with(['user'])
                                    ->order('create_time', 'desc')
                                    ->limit($offset, $limit)
                                    ->select();
                
                $total = PaymentOrder::where($where)->count();
            } else {
                $orders = WithdrawOrder::where($where)
                                     ->with(['user'])
                                     ->order('create_time', 'desc')
                                     ->limit($offset, $limit)
                                     ->select();
                
                $total = WithdrawOrder::where($where)->count();
            }
            
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
            Log::error('获取管理员订单列表失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取用户支付记录
     */
    public function getUserPaymentHistory(int $userId, array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $limit = min($params['limit'] ?? 20, 100);
            $type = $params['type'] ?? ''; // recharge | withdraw
            
            $offset = ($page - 1) * $limit;
            
            $data = [];
            
            if (empty($type) || $type === 'recharge') {
                $rechargeOrders = PaymentOrder::where('user_id', $userId)
                                            ->order('create_time', 'desc')
                                            ->limit($offset, $limit)
                                            ->select()
                                            ->toArray();
                
                foreach ($rechargeOrders as $order) {
                    $order['order_type'] = 'recharge';
                    $data[] = $order;
                }
            }
            
            if (empty($type) || $type === 'withdraw') {
                $withdrawOrders = WithdrawOrder::where('user_id', $userId)
                                              ->order('create_time', 'desc')
                                              ->limit($offset, $limit)
                                              ->select()
                                              ->toArray();
                
                foreach ($withdrawOrders as $order) {
                    $order['order_type'] = 'withdraw';
                    $data[] = $order;
                }
            }
            
            // 按时间排序
            usort($data, function($a, $b) {
                return strtotime($b['create_time']) - strtotime($a['create_time']);
            });
            
            // 分页处理
            $total = count($data);
            $data = array_slice($data, $offset, $limit);
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $data,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取用户支付记录失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 4. 系统管理功能 ===================
    
    /**
     * 更新支付配置
     */
    public function updatePaymentConfig(array $config): array
    {
        try {
            // 这里可以根据需要保存配置到数据库或配置文件
            Cache::set('payment_config', $config, 86400 * 30); // 缓存30天
            
            Log::info('更新支付配置', ['config' => $config]);
            
            return [
                'code' => 200,
                'msg' => '配置更新成功',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('更新支付配置失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取支付配置
     */
    public function getPaymentConfigForAdmin(): array
    {
        try {
            $defaultConfig = [
                'recharge' => [
                    'min_amount' => 10,
                    'max_amount' => 50000,
                    'enabled_methods' => ['usdt', 'huiwang']
                ],
                'withdraw' => [
                    'min_amount' => 10,
                    'max_amount' => 10000,
                    'fee_rate' => 0.01,
                    'processing_time' => '1-24小时',
                    'enabled' => true
                ],
                'telegram' => [
                    'broadcast_enabled' => true,
                    'notification_enabled' => true
                ]
            ];
            
            $config = Cache::get('payment_config', $defaultConfig);
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $config
            ];
            
        } catch (\Exception $e) {
            Log::error('获取支付配置失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 创建资金流水记录
     */
    private function createMoneyLog(int $userId, int $type, int $status, float $moneyBefore, float $moneyEnd, float $money, int $sourceId, string $mark): void
    {
        MoneyLog::create([
            'uid' => $userId,
            'type' => $type,
            'status' => $status,
            'money_before' => $moneyBefore,
            'money_end' => $moneyEnd,
            'money' => $money,
            'source_id' => $sourceId,
            'mark' => $mark,
            'create_time' => date('Y-m-d H:i:s')
        ]);
    }
}