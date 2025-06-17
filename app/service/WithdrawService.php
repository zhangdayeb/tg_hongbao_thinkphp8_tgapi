<?php
// 文件位置: app/service/WithdrawService.php
// 提现前端服务 - 处理用户提现相关的所有交互

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\Withdraw;
use app\model\MoneyLog;
use think\facade\Db;
use think\facade\Log;
use think\exception\ValidateException;

class WithdrawService
{
    // 提现状态常量
    const STATUS_PENDING = 0;    // 待审核
    const STATUS_SUCCESS = 1;    // 成功
    const STATUS_FAILED = 2;     // 失败
    const STATUS_CANCELLED = 3;  // 已取消
    
    // 手续费配置
    const WITHDRAW_FEE_RATE = 0.01; // 提现手续费率 1%
    const MIN_WITHDRAW_AMOUNT = 10; // 最小提现金额
    const MAX_WITHDRAW_AMOUNT = 10000; // 最大提现金额
    
    private TelegramService $telegramService;
    private TelegramBroadcastService $telegramBroadcastService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->telegramBroadcastService = new TelegramBroadcastService();
    }
    
    // =================== 1. 提现密码管理 ===================
    
    /**
     * 设置提现密码
     */
    public function setWithdrawPassword(int $userId, string $password): array
    {
        try {
            $user = is_numeric($userId) ? User::find($userId) : User::where('tg_id', (string)$userId)->find();
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            if ($user->withdraw_password_set == 1) {
                throw new ValidateException('提现密码已设置，请使用修改功能');
            }
            
            // 验证密码格式
            if (strlen($password) < 6) {
                throw new ValidateException('提现密码长度不能少于6位');
            }
            
            // 加密密码
            $hashedPassword = base64_encode($password);
            
            $user->save([
                'withdraw_pwd' => $hashedPassword,
                'withdraw_password_set' => 1
            ]);
            
            // 记录日志
            Log::info('设置提现密码', ['user_id' => $userId]);
            
            return [
                'code' => 200,
                'msg' => '提现密码设置成功',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('设置提现密码失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 验证提现密码
     */
    public function verifyWithdrawPassword(int $userId, string $password): array
    {
        try {
            $user = is_numeric($userId) ? User::find($userId) : User::where('tg_id', (string)$userId)->find();
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            if ($user->withdraw_password_set == 0) {
                throw new ValidateException('未设置提现密码');
            }
            
            $hashedPassword = base64_encode($password);
            if ($user->withdraw_pwd !== $hashedPassword) {
                throw new ValidateException('提现密码错误');
            }
            
            return [
                'code' => 200,
                'msg' => '密码验证成功',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('验证提现密码失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 修改提现密码
     */
    public function changeWithdrawPassword(int $userId, string $oldPwd, string $newPwd): array
    {
        try {
            // 验证旧密码
            $this->verifyWithdrawPassword($userId, $oldPwd);
            
            // 验证新密码格式
            if (strlen($newPwd) < 6) {
                throw new ValidateException('新密码长度不能少于6位');
            }
            
            // 更新密码
            $user = is_numeric($userId) ? User::find($userId) : User::where('tg_id', (string)$userId)->find();
            $hashedPassword = base64_encode($newPwd);
            
            $user->save(['withdraw_pwd' => $hashedPassword]);
            
            // 记录日志
            Log::info('修改提现密码', ['user_id' => $userId]);
            
            return [
                'code' => 200,
                'msg' => '提现密码修改成功',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('修改提现密码失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 2. USDT地址管理 ===================
    
    /**
     * 绑定USDT地址
     */
    public function bindUsdtAddress(int $userId, string $address): array
    {
        try {
            $user = is_numeric($userId) ? User::find($userId) : User::where('tg_id', (string)$userId)->find();
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            if (!empty($user->usdt_address)) {
                throw new ValidateException('USDT地址已绑定，请使用修改功能');
            }
            
            // 验证USDT地址格式
            if (!$this->validateUsdtAddress($address)) {
                throw new ValidateException('USDT地址格式不正确');
            }
            
            $user->save(['usdt_address' => $address]);
            
            // 记录日志
            Log::info('绑定USDT地址', [
                'user_id' => $userId,
                'address' => $address
            ]);
            
            return [
                'code' => 200,
                'msg' => 'USDT地址绑定成功',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('绑定USDT地址失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 更新USDT地址
     */
    public function updateUsdtAddress(int $userId, string $address): array
    {
        try {
            $user = is_numeric($userId) ? User::find($userId) : User::where('tg_id', (string)$userId)->find();
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            // 验证USDT地址格式
            if (!$this->validateUsdtAddress($address)) {
                throw new ValidateException('USDT地址格式不正确');
            }
            
            $user->save(['usdt_address' => $address]);
            
            // 记录日志
            Log::info('更新USDT地址', [
                'user_id' => $userId,
                'address' => $address
            ]);
            
            return [
                'code' => 200,
                'msg' => 'USDT地址更新成功',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('更新USDT地址失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 验证USDT地址格式
     */
    public function validateUsdtAddress(string $address): bool
    {
        // TRON USDT地址验证（TRC20）
        if (preg_match('/^T[A-Za-z1-9]{33}$/', $address)) {
            return true;
        }
        
        // 其他USDT地址格式可以在这里添加
        
        return false;
    }
    
    // =================== 3. 提现功能 ===================
    
    /**
     * 检查提现前置条件
     */
    public function checkWithdrawConditions(int $userId): array
    {
        try {
            $user = is_numeric($userId) ? User::find($userId) : User::where('tg_id', (string)$userId)->find();
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            $conditions = [
                'withdraw_password_set' => $user->withdraw_password_set == 1,
                'usdt_address_bound' => !empty($user->usdt_address),
                'sufficient_balance' => $user->money_balance >= self::MIN_WITHDRAW_AMOUNT
            ];
            
            $allMet = array_reduce($conditions, function($carry, $item) {
                return $carry && $item;
            }, true);
            
            return [
                'code' => 200,
                'msg' => '条件检查完成',
                'data' => [
                    'all_conditions_met' => $allMet,
                    'conditions' => $conditions,
                    'user_balance' => $user->money_balance,
                    'min_withdraw' => self::MIN_WITHDRAW_AMOUNT,
                    'max_withdraw' => self::MAX_WITHDRAW_AMOUNT,
                    'fee_rate' => self::WITHDRAW_FEE_RATE
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('检查提现条件失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 创建提现订单 - 移除所有通知
     */
    public function createWithdrawOrder(int $userId, float $amount, string $withdrawPwd): array
    {
        try {
            // 验证提现密码
            $this->verifyWithdrawPassword($userId, $withdrawPwd);
            
            // 检查用户状态和余额
            $user = User::find($userId);
            if (!$user || $user->status != 1) {
                throw new ValidateException('用户状态异常');
            }
            
            // 检查提现条件
            $conditions = $this->checkWithdrawConditions($userId);
            if (!$conditions['data']['all_conditions_met']) {
                throw new ValidateException('提现条件不满足');
            }
            
            // 验证提现金额
            if ($amount < self::MIN_WITHDRAW_AMOUNT || $amount > self::MAX_WITHDRAW_AMOUNT) {
                throw new ValidateException('提现金额必须在' . self::MIN_WITHDRAW_AMOUNT . '-' . self::MAX_WITHDRAW_AMOUNT . '之间');
            }
            
            // 计算手续费
            $fee = $amount * self::WITHDRAW_FEE_RATE;
            $totalAmount = $amount + $fee;
            
            if ($user->money_balance < $totalAmount) {
                throw new ValidateException('余额不足，需要' . $totalAmount . '元（含手续费' . $fee . '元）');
            }
            
            Db::startTrans();
            
            // 生成提现订单号
            $orderNo = $this->generateOrderNo('W');
            
            // 创建提现订单
            $orderData = [
                'order_number' => $orderNo,
                'user_id' => $userId,
                'money' => $amount,
                'money_balance' => $user->money_balance,
                'money_fee' => $fee,
                'money_actual' => $amount,
                'withdraw_address' => $user->usdt_address,
                'withdraw_network' => 'TRC20',
                'user_ip' => request()->ip(),
                'status' => self::STATUS_PENDING,
                'create_time' => date('Y-m-d H:i:s')
            ];
            
            $order = Withdraw::create($orderData);
            
            // 冻结用户余额
            $oldBalance = $user->money_balance;
            $newBalance = $oldBalance - $totalAmount;
            
            $user->save(['money_balance' => $newBalance]);
            
            // 记录资金流水
            $this->createMoneyLog($userId, 2, 201, $oldBalance, $newBalance, $totalAmount, $order->id, "提现申请 - 订单号{$orderNo}（含手续费{$fee}元）");
            
            // 🔥 删除所有通知相关代码
            /*
            // 发送个人提现申请通知
            $this->telegramService->sendWithdrawApplyNotify($userId, [...]);
            
            // 提现申请群组广播
            $this->telegramBroadcastService->broadcastWithdrawApply([...]);
            */
            
            Db::commit();
            
            // 记录日志
            Log::info('创建提现订单', [
                'user_id' => $userId,
                'order_no' => $orderNo,
                'amount' => $amount,
                'fee' => $fee
            ]);
            
            return [
                'code' => 200,
                'msg' => '提现申请提交成功',
                'data' => [
                    'order_no' => $orderNo,
                    'amount' => $amount,
                    'fee' => $fee,
                    'actual_amount' => $amount
                ]
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('创建提现订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取提现订单列表
     */
    public function getWithdrawOrders(int $userId, array $params = []): array
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
            
            $orders = Withdraw::where($where)
                                 ->order('create_time', 'desc')
                                 ->limit($offset, $limit)
                                 ->select();
            
            $total = Withdraw::where($where)->count();
            
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
            Log::error('获取提现订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取提现订单详情
     */
    public function getWithdrawDetail(int $userId, string $orderNo): array
    {
        try {
            $order = Withdraw::where('order_number', $orderNo)
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
            Log::error('获取提现订单详情失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 生成订单号
     */
    private function generateOrderNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . sprintf('%06d', mt_rand(0, 999999));
    }
    
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