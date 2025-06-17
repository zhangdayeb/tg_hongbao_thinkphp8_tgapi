<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\TelegramUserState;
use app\service\TelegramService;
use app\service\PaymentService;
use app\service\UserService;
use app\utils\TelegramKeyboard;
use app\utils\TelegramMessage;
use think\facade\Cache;
use think\facade\Log;
use think\exception\ValidateException;

/**
 * Telegram机器人交互逻辑服务
 */
class TelegramBotService
{
    private TelegramService $telegramService;
    private PaymentService $paymentService;
    private UserService $userService;
    
    // 用户状态常量
    const STATE_IDLE = 'idle';
    const STATE_RECHARGE_METHOD = 'recharge_method';
    const STATE_RECHARGE_AMOUNT = 'recharge_amount';
    const STATE_WITHDRAW_AMOUNT = 'withdraw_amount';
    const STATE_WITHDRAW_PASSWORD = 'withdraw_password';
    const STATE_BIND_VERIFICATION = 'bind_verification';
    const STATE_REDPACKET_TYPE = 'redpacket_type';
    const STATE_REDPACKET_AMOUNT = 'redpacket_amount';
    const STATE_REDPACKET_COUNT = 'redpacket_count';
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->paymentService = new PaymentService();
        $this->userService = new UserService();
    }
    
    /**
     * 处理开始命令
     */
    public function handleStartCommand(int $chatId, array $from, string $startParam = ''): array
    {
        try {
            $tgUserId = (string)$from['id'];
            
            // 检查用户是否存在
            $user = $this->findOrCreateUser($from);
            
            // 清除用户状态
            $this->clearUserState($tgUserId);
            
            // 生成欢迎消息
            $welcomeMessage = TelegramMessage::welcome(
                $from['username'] ?? '',
                $from['first_name'] ?? ''
            );
            
            // 生成主菜单键盘
            $keyboard = TelegramKeyboard::mainMenu();
            
            return $this->telegramService->sendMessage($chatId, $welcomeMessage, [
                'reply_markup' => $keyboard
            ]);
            
        } catch (\Exception $e) {
            Log::error('处理开始命令失败', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            return $this->sendErrorMessage($chatId, '系统繁忙，请稍后再试');
        }
    }
    
    /**
     * 处理帮助命令
     */
    public function handleHelpCommand(int $chatId): array
    {
        $helpMessage = "🤖 *机器人功能说明*\n\n" .
                      "💰 *财务功能*\n" .
                      "• 查看余额 - 实时显示账户余额\n" .
                      "• 充值 - 支持USDT和汇旺充值\n" .
                      "• 提现 - USDT提现到钱包\n\n" .
                      "🧧 *红包功能*\n" .
                      "• 发红包 - 在群组发送红包\n" .
                      "• 抢红包 - 参与群组红包活动\n\n" .
                      "👥 *社交功能*\n" .
                      "• 邀请好友 - 获得邀请奖励\n" .
                      "• 联系客服 - 获得人工帮助\n\n" .
                      "使用下方菜单或发送相应命令即可开始使用。";
        
        $keyboard = TelegramKeyboard::backToMain();
        
        return $this->telegramService->sendMessage($chatId, $helpMessage, [
            'reply_markup' => $keyboard
        ]);
    }
    
    /**
     * 处理个人中心
     */
    public function handleProfileFlow(int $chatId, string $tgUserId): array
    {
        try {
            $user = $this->getUserByTgId($tgUserId);
            if (!$user) {
                return $this->sendErrorMessage($chatId, '用户不存在，请先注册');
            }
            
            // 获取用户统计信息
            $userStats = $this->getUserStats($user->id);
            
            $profileMessage = TelegramMessage::walletInfo([
                'money_balance' => $user->money_balance,
                'total_recharge' => $userStats['total_recharge'],
                'total_withdraw' => $userStats['total_withdraw']
            ]);
            
            $keyboard = TelegramKeyboard::profileMenu();
            
            return $this->telegramService->sendMessage($chatId, $profileMessage, [
                'reply_markup' => $keyboard
            ]);
            
        } catch (\Exception $e) {
            Log::error('处理个人中心失败', [
                'chat_id' => $chatId,
                'tg_user_id' => $tgUserId,
                'error' => $e->getMessage()
            ]);
            
            return $this->sendErrorMessage($chatId, '获取个人信息失败');
        }
    }
    
    /**
     * 处理充值流程
     */
    public function handleRechargeFlow(int $chatId, string $tgUserId, ?string $callbackData = null): array
    {
        try {
            $user = $this->getUserByTgId($tgUserId);
            if (!$user) {
                return $this->sendErrorMessage($chatId, '用户不存在，请先注册');
            }
            
            $currentState = $this->getUserState($tgUserId);
            
            switch ($currentState['state']) {
                case self::STATE_IDLE:
                    // 显示充值方式选择
                    return $this->showRechargeMethodSelection($chatId, $tgUserId);
                    
                case self::STATE_RECHARGE_METHOD:
                    // 处理充值方式选择
                    if ($callbackData) {
                        return $this->handleRechargeMethodSelection($chatId, $tgUserId, $callbackData);
                    }
                    break;
                    
                case self::STATE_RECHARGE_AMOUNT:
                    // 处理充值金额输入
                    return $this->handleRechargeAmountInput($chatId, $tgUserId, $callbackData);
            }
            
            return $this->sendErrorMessage($chatId, '充值流程异常，请重新开始');
            
        } catch (\Exception $e) {
            Log::error('处理充值流程失败', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            return $this->sendErrorMessage($chatId, '充值流程处理失败');
        }
    }
    
    /**
     * 处理提现流程
     */
    public function handleWithdrawFlow(int $chatId, string $tgUserId, ?string $input = null): array
    {
        try {
            $user = $this->getUserByTgId($tgUserId);
            if (!$user) {
                return $this->sendErrorMessage($chatId, '用户不存在，请先注册');
            }
            
            // 检查提现前置条件
            $conditions = $this->paymentService->checkWithdrawConditions($user->id);
            if (!$conditions['data']['all_conditions_met']) {
                return $this->showWithdrawConditions($chatId, $conditions['data']);
            }
            
            $currentState = $this->getUserState($tgUserId);
            
            switch ($currentState['state']) {
                case self::STATE_IDLE:
                    // 显示提现金额输入
                    return $this->showWithdrawAmountInput($chatId, $user);
                    
                case self::STATE_WITHDRAW_AMOUNT:
                    // 处理提现金额输入
                    return $this->handleWithdrawAmountInput($chatId, $tgUserId, $input);
                    
                case self::STATE_WITHDRAW_PASSWORD:
                    // 处理提现密码输入
                    return $this->handleWithdrawPasswordInput($chatId, $tgUserId, $input);
            }
            
            return $this->sendErrorMessage($chatId, '提现流程异常，请重新开始');
            
        } catch (\Exception $e) {
            Log::error('处理提现流程失败', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            return $this->sendErrorMessage($chatId, '提现流程处理失败');
        }
    }
    
    /**
     * 处理红包发送流程
     */
    public function handleRedPacketFlow(int $chatId, string $tgUserId, ?string $input = null): array
    {
        try {
            $user = $this->getUserByTgId($tgUserId);
            if (!$user) {
                return $this->sendErrorMessage($chatId, '用户不存在，请先注册');
            }
            
            $currentState = $this->getUserState($tgUserId);
            
            switch ($currentState['state']) {
                case self::STATE_IDLE:
                    // 显示红包类型选择
                    return $this->showRedPacketTypeSelection($chatId, $tgUserId);
                    
                case self::STATE_REDPACKET_TYPE:
                    // 处理红包类型选择
                    return $this->handleRedPacketTypeSelection($chatId, $tgUserId, $input);
                    
                case self::STATE_REDPACKET_AMOUNT:
                    // 处理红包金额输入
                    return $this->handleRedPacketAmountInput($chatId, $tgUserId, $input);
                    
                case self::STATE_REDPACKET_COUNT:
                    // 处理红包个数输入
                    return $this->handleRedPacketCountInput($chatId, $tgUserId, $input);
            }
            
            return $this->sendErrorMessage($chatId, '红包流程异常，请重新开始');
            
        } catch (\Exception $e) {
            Log::error('处理红包流程失败', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            return $this->sendErrorMessage($chatId, '红包流程处理失败');
        }
    }
    
    /**
     * 用户状态管理 - 获取用户状态
     */
    public function getUserState(string $tgUserId): array
    {
        $cacheKey = "telegram_user_state:{$tgUserId}";
        $state = Cache::get($cacheKey);
        
        if (!$state) {
            return [
                'state' => self::STATE_IDLE,
                'data' => [],
                'expires_at' => time() + 1800
            ];
        }
        
        return $state;
    }
    
    /**
     * 用户状态管理 - 设置用户状态
     */
    public function setUserState(string $tgUserId, string $state, array $data = [], int $expireTime = 1800): bool
    {
        $cacheKey = "telegram_user_state:{$tgUserId}";
        $stateData = [
            'state' => $state,
            'data' => $data,
            'expires_at' => time() + $expireTime
        ];
        
        return Cache::set($cacheKey, $stateData, $expireTime);
    }
    
    /**
     * 用户状态管理 - 清除用户状态
     */
    public function clearUserState(string $tgUserId): bool
    {
        $cacheKey = "telegram_user_state:{$tgUserId}";
        return Cache::delete($cacheKey);
    }
    
    /**
     * 查找或创建用户
     */
    private function findOrCreateUser(array $from): User
    {
        $tgUserId = (string)$from['id'];
        
        // 先根据TG ID查找
        $user = User::where('tg_id', $tgUserId)->find();
        
        if (!$user) {
            // 自动创建用户
            $userData = [
                'tg_id' => $tgUserId,
                'tg_username' => $from['username'] ?? '',
                'tg_first_name' => $from['first_name'] ?? '',
                'tg_last_name' => $from['last_name'] ?? '',
                'language_code' => $from['language_code'] ?? 'zh',
                'user_name' => $this->generateUsername($tgUserId),
                'auto_created' => 1,
                'telegram_bind_time' => date('Y-m-d H:i:s'),
                'create_time' => date('Y-m-d H:i:s'),
                'registration_step' => 1
            ];
            
            $user = User::create($userData);
            
            Log::info('自动创建Telegram用户', [
                'user_id' => $user->id,
                'tg_id' => $tgUserId,
                'username' => $userData['user_name']
            ]);
        }
        
        // 更新最后活动时间
        $user->updateLastActivity();
        
        return $user;
    }
    
    /**
     * 根据TG ID获取用户
     */
    private function getUserByTgId(string $tgUserId): ?User
    {
        return User::where('tg_id', $tgUserId)->find();
    }
    
    /**
     * 生成用户名
     */
    private function generateUsername(string $tgUserId): string
    {
        $prefix = 'TG';
        $suffix = substr($tgUserId, -6);
        return $prefix . $suffix;
    }
    
    /**
     * 获取用户统计信息
     */
    private function getUserStats(int $userId): array
    {
        return [
            'total_recharge' => 0, // 这里应该调用相关服务获取统计
            'total_withdraw' => 0,
            'today_recharge' => 0,
            'today_withdraw' => 0
        ];
    }
    
    /**
     * 显示充值方式选择
     */
    private function showRechargeMethodSelection(int $chatId, string $tgUserId): array
    {
        $this->setUserState($tgUserId, self::STATE_RECHARGE_METHOD);
        
        $message = "💳 *选择充值方式*\n\n" .
                  "请选择您要使用的充值方式：\n\n" .
                  "💎 *USDT充值*\n" .
                  "• 最小金额：10 USDT\n" .
                  "• 最大金额：100,000 USDT\n" .
                  "• 手续费：免费\n" .
                  "• 到账时间：实时\n\n" .
                  "⚡ *汇旺充值*\n" .
                  "• 最小金额：10 USDT\n" .
                  "• 最大金额：20,000 USDT\n" .
                  "• 手续费：免费\n" .
                  "• 到账时间：30分钟-2小时";
        
        $keyboard = TelegramKeyboard::paymentMethods();
        
        return $this->telegramService->sendMessage($chatId, $message, [
            'reply_markup' => $keyboard
        ]);
    }
    
    /**
     * 处理充值方式选择
     */
    private function handleRechargeMethodSelection(int $chatId, string $tgUserId, string $method): array
    {
        $validMethods = ['usdt', 'huiwang'];
        
        if (!in_array($method, $validMethods)) {
            return $this->sendErrorMessage($chatId, '无效的充值方式');
        }
        
        // 更新状态
        $this->setUserState($tgUserId, self::STATE_RECHARGE_AMOUNT, [
            'method' => $method
        ]);
        
        $methodName = $method === 'usdt' ? 'USDT' : '汇旺';
        $message = "💰 *输入充值金额*\n\n" .
                  "充值方式：{$methodName}\n\n" .
                  "请输入您要充值的金额（最小10 USDT）：";
        
        $keyboard = TelegramKeyboard::backButton();
        
        return $this->telegramService->sendMessage($chatId, $message, [
            'reply_markup' => $keyboard
        ]);
    }
    
    /**
     * 处理充值金额输入
     */
    private function handleRechargeAmountInput(int $chatId, string $tgUserId, ?string $amount): array
    {
        if (!is_numeric($amount) || floatval($amount) < 10) {
            return $this->sendErrorMessage($chatId, '请输入有效的充值金额（最小10 USDT）');
        }
        
        $state = $this->getUserState($tgUserId);
        $method = $state['data']['method'] ?? '';
        
        try {
            $user = $this->getUserByTgId($tgUserId);
            
            // 创建充值订单
            $result = $this->paymentService->createRechargeOrder($user->id, [
                'amount' => floatval($amount),
                'method' => $method
            ]);
            
            if ($result['code'] === 200) {
                $this->clearUserState($tgUserId);
                
                $orderInfo = $result['data'];
                $message = TelegramMessage::rechargeInfo($orderInfo);
                
                $keyboard = TelegramKeyboard::mainMenu();
                
                return $this->telegramService->sendMessage($chatId, $message, [
                    'reply_markup' => $keyboard
                ]);
            } else {
                return $this->sendErrorMessage($chatId, $result['msg']);
            }
            
        } catch (\Exception $e) {
            Log::error('创建充值订单失败', [
                'tg_user_id' => $tgUserId,
                'amount' => $amount,
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            
            return $this->sendErrorMessage($chatId, '创建充值订单失败，请稍后再试');
        }
    }
    
    /**
     * 显示提现条件
     */
    private function showWithdrawConditions(int $chatId, array $conditions): array
    {
        $message = "⚠️ *提现条件检查*\n\n";
        
        if (!$conditions['withdraw_password_set']) {
            $message .= "❌ 未设置提现密码\n";
        } else {
            $message .= "✅ 提现密码已设置\n";
        }
        
        if (!$conditions['usdt_address_bound']) {
            $message .= "❌ 未绑定USDT地址\n";
        } else {
            $message .= "✅ USDT地址已绑定\n";
        }
        
        if (!$conditions['sufficient_balance']) {
            $message .= "❌ 余额不足（最小提现20 USDT）\n";
        } else {
            $message .= "✅ 余额充足\n";
        }
        
        $message .= "\n请先完成以上设置后再进行提现。";
        
        $keyboard = TelegramKeyboard::settingsMenu();
        
        return $this->telegramService->sendMessage($chatId, $message, [
            'reply_markup' => $keyboard
        ]);
    }
    
    /**
     * 显示提现金额输入
     */
    private function showWithdrawAmountInput(int $chatId, User $user): array
    {
        $this->setUserState($user->tg_id, self::STATE_WITHDRAW_AMOUNT);
        
        $message = "💸 *提现申请*\n\n" .
                  "当前余额：{$user->money_balance} USDT\n\n" .
                  "提现说明：\n" .
                  "• 最小金额：20 USDT\n" .
                  "• 手续费：1% + 2 USDT\n" .
                  "• 处理时间：1-24小时\n\n" .
                  "请输入提现金额：";
        
        $keyboard = TelegramKeyboard::backButton();
        
        return $this->telegramService->sendMessage($chatId, $message, [
            'reply_markup' => $keyboard
        ]);
    }
    
    /**
     * 显示红包类型选择
     */
    private function showRedPacketTypeSelection(int $chatId, string $tgUserId): array
    {
        $this->setUserState($tgUserId, self::STATE_REDPACKET_TYPE);
        
        $message = "🧧 *发送红包*\n\n" .
                  "请选择红包类型：\n\n" .
                  "🎲 *拼手气红包*\n" .
                  "金额随机分配，拼人品\n\n" .
                  "📦 *普通红包*\n" .
                  "金额平均分配，人人有份";
        
        $keyboard = TelegramKeyboard::redPacketTypes();
        
        return $this->telegramService->sendMessage($chatId, $message, [
            'reply_markup' => $keyboard
        ]);
    }
    
    /**
     * 发送错误消息
     */
    private function sendErrorMessage(int $chatId, string $message): array
    {
        return $this->telegramService->sendMessage($chatId, "❌ {$message}");
    }
}