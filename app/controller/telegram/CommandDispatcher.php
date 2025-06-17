<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\UserService;
use app\model\User;

/**
 * Telegram命令分发器 - 优化版
 * 与RedPacketController完全匹配，减少冗余代码
 */
class CommandDispatcher extends BaseTelegramController
{
    private UserService $userService;
    
    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }
    
    // 控制器映射表
    private array $controllerMap = [
        'start' => GeneralController::class,
        'help' => GeneralController::class,
        'menu' => GeneralController::class,
        'profile' => ProfileController::class,
        'recharge' => PaymentController::class,
        'withdraw' => WithdrawController::class,
        'invite' => InviteController::class,
        'game' => GameController::class,
        'service' => ServiceController::class,
        'redpacket' => RedPacketController::class,
        'red' => RedPacketController::class,
        'hongbao' => RedPacketController::class,
        'hb' => RedPacketController::class,
    ];
    
    // 回调映射表
    private array $callbackMap = [
        'back_to_main' => GeneralController::class,
        'check_balance' => GeneralController::class,
        'help' => GeneralController::class,
        'menu' => GeneralController::class,
        'balance' => GeneralController::class,
        'profile' => ProfileController::class,
        'bind_game_id' => ProfileController::class,
        'start_bind_game_id' => ProfileController::class,
        'cancel_bind_game_id' => ProfileController::class,
        'view_current_game_id' => ProfileController::class,
        'recharge' => PaymentController::class,
        'recharge_usdt' => PaymentController::class,
        'recharge_huiwang' => PaymentController::class,
        'confirm_amount' => PaymentController::class,
        'copy_address' => PaymentController::class,
        'copy_account' => PaymentController::class,
        'transfer_complete' => PaymentController::class,
        'manual_amount' => PaymentController::class,
        'reenter_amount' => PaymentController::class,
        'withdraw' => WithdrawController::class,
        'start_withdraw' => WithdrawController::class,
        'set_withdraw_password' => WithdrawController::class,
        'bind_usdt_address' => WithdrawController::class,
        'confirm_withdraw' => WithdrawController::class,
        'withdraw_history' => WithdrawController::class,
        'modify_address' => WithdrawController::class,
        'invite' => InviteController::class,
        'invite_stats' => InviteController::class,
        'invite_rewards' => InviteController::class,
        'copy_invite_link' => InviteController::class,
        'redpacket' => RedPacketController::class,
        'send_red_packet' => RedPacketController::class,
        'red_packet_history' => RedPacketController::class,
        'confirm_send_redpacket' => RedPacketController::class,
        'cancel_send_redpacket' => RedPacketController::class,
        'game' => GameController::class,
        'service' => ServiceController::class,
    ];
    
    /**
     * 处理文本消息
     */
    public function handleMessage(array $update, string $debugFile): void
    {
        try {
            $message = $update['message'];
            $chatId = intval($message['chat']['id']);
            $text = $message['text'] ?? '';
            
            $chatContext = $this->extractChatContext($message);
            $this->log($debugFile, "收到消息 - ChatID: {$chatId}, Type: {$chatContext['chat_type']}, 内容: {$text}");
            
            if (strpos($text, '/') === 0) {
                // 命令处理
                $invitationCode = $this->extractInvitationCode($text);
                $user = $this->ensureUserExists($update, $debugFile, $invitationCode);
                if (!$user) return;
                
                $this->dispatchCommand($text, $chatId, $user, $chatContext, $debugFile);
            } else {
                // 普通文本处理
                $user = $this->ensureUserExists($update, $debugFile);
                if (!$user) return;
                
                $this->dispatchTextInput($chatId, $text, $user, $chatContext, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理文本消息", $debugFile);
        }
    }
    
    /**
     * 处理回调查询
     */
    public function handleCallback(array $update, string $debugFile): void
    {
        try {
            $callbackQuery = $update['callback_query'];
            $chatId = intval($callbackQuery['message']['chat']['id']);
            $callbackData = $callbackQuery['data'] ?? '';
            $queryId = $callbackQuery['id'] ?? '';
            
            $chatContext = $this->extractChatContext($callbackQuery['message']);
            $this->log($debugFile, "收到回调 - ChatID: {$chatId}, 数据: {$callbackData}");
            
            // 安全回调响应
            $this->safeAnswerCallbackQuery($queryId, null, $debugFile);
            
            // 防重复处理
            if ($this->isDuplicateCallback($queryId, $debugFile)) {
                return;
            }
            
            $user = $this->ensureUserExists($update, $debugFile);
            if (!$user) return;
            
            $this->dispatchCallback($callbackData, $chatId, $user, $chatContext, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理回调查询", $debugFile);
        }
    }
    
    /**
     * 🆕 处理机器人状态变化（成为管理员等）
     */
    public function handleMyChatMember(array $update, string $debugFile): void
    {
        try {
            $myChatMember = $update['my_chat_member'] ?? [];
            if (empty($myChatMember)) {
                $this->log($debugFile, "❌ my_chat_member 数据为空");
                return;
            }
            
            $this->log($debugFile, "🤖 收到机器人状态变化通知");
            
            // 委托给 TelegramService 处理
            $telegramService = new \app\service\TelegramService();
            $telegramService->handleMyChatMemberUpdate($myChatMember, $debugFile);
            
            $this->log($debugFile, "✅ 机器人状态变化处理完成");
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理机器人状态变化", $debugFile);
        }
    }
    
    /**
     * 🆕 处理群成员变化（新成员加入、成员离开等）
     */
    public function handleChatMember(array $update, string $debugFile): void
    {
        try {
            $chatMember = $update['chat_member'] ?? [];
            if (empty($chatMember)) {
                $this->log($debugFile, "❌ chat_member 数据为空");
                return;
            }
            
            $this->log($debugFile, "👥 收到群成员变化通知");
            
            // 可以在这里添加群成员变化的处理逻辑
            // 比如记录成员变化、发送欢迎消息等
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理群成员变化", $debugFile);
        }
    }
    
    /**
     * 提取聊天上下文信息
     */
    private function extractChatContext(array $message): array
    {
        $chat = $message['chat'] ?? [];
        return [
            'chat_id' => $chat['id'] ?? 0,
            'chat_type' => $chat['type'] ?? 'private',
            'chat_title' => $chat['title'] ?? '',
            'chat_username' => $chat['username'] ?? '',
        ];
    }
    
    /**
     * 从命令中提取邀请码
     */
    private function extractInvitationCode(string $text): ?string
    {
        $parts = explode(' ', trim($text));
        if (count($parts) >= 2 && strtolower(substr($parts[0], 1)) === 'start') {
            $invitationCode = trim($parts[1]);
            if (!empty($invitationCode) && preg_match('/^[A-Z0-9]{6,20}$/i', $invitationCode)) {
                return strtoupper($invitationCode);
            }
        }
        return null;
    }
    
    /**
     * 确保用户存在
     */
    private function ensureUserExists(array $update, string $debugFile, ?string $invitationCode = null): ?User
    {
        try {
            $telegramData = $this->extractTelegramUserData($update);
            if (!$telegramData) {
                $this->log($debugFile, "❌ 无法提取Telegram用户信息");
                return null;
            }
            
            $inviteCodeParam = $invitationCode ?: '';
            $user = $this->userService->findOrCreateUser($telegramData, $inviteCodeParam);
            
            if ($user) {
                $this->log($debugFile, "✅ 用户处理成功 - ID: {$user->id}");
                return $user;
            } else {
                $this->log($debugFile, "❌ 用户处理失败");
                return null;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 用户处理异常: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 从update中提取Telegram用户数据
     */
    private function extractTelegramUserData(array $update): ?array
    {
        $from = $update['message']['from'] ?? $update['callback_query']['from'] ?? $update['inline_query']['from'] ?? null;
        
        if (!$from || empty($from['id'])) {
            return null;
        }
        
        return [
            'id' => (string)$from['id'],
            'username' => $from['username'] ?? '',
            'first_name' => $from['first_name'] ?? '',
            'last_name' => $from['last_name'] ?? '',
            'language_code' => $from['language_code'] ?? 'zh',
            'is_bot' => $from['is_bot'] ?? false
        ];
    }
    
    /**
     * 分发命令
     */
    private function dispatchCommand(string $text, int $chatId, User $user, array $chatContext, string $debugFile): void
    {
        $command = $this->parseCommand($text);
        
        // 红包命令聊天类型限制
        if ($this->isRedPacketCommand($command) && !$this->validateRedPacketCommandPermission($chatContext, $debugFile)) {
            $this->handlePrivateRedPacketCommand($chatId, $command, $debugFile);
            return;
        }
        
        $controllerClass = $this->controllerMap[$command] ?? null;
        
        if ($controllerClass && class_exists($controllerClass)) {
            try {
                $controller = new $controllerClass();
                
                // 设置用户和聊天上下文
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                if (method_exists($controller, 'setChatContext')) {
                    $controller->setChatContext($chatContext);
                }
                
                // 红包控制器特殊处理：传递完整消息
                if ($this->isRedPacketCommand($command)) {
                    $controller->handle($command, $chatId, $debugFile, $text);
                } else {
                    $controller->handle($command, $chatId, $debugFile);
                }
                
            } catch (\Exception $e) {
                $this->handleException($e, "命令处理: {$command}", $debugFile);
                $this->sendMessage($chatId, "❌ 命令处理失败，请稍后重试", $debugFile);
            }
        } else {
            $this->handleUnknownCommand($command, $chatId, $chatContext, $debugFile);
        }
    }
    
    /**
     * 分发回调处理
     */
    private function dispatchCallback(string $callbackData, int $chatId, User $user, array $chatContext, string $debugFile): void
    {
        // 特殊格式回调优先处理
        $specialHandlers = [
            'grab_redpacket_' => RedPacketController::class,
            'redpacket_detail_' => RedPacketController::class,
            'refresh_redpacket_' => RedPacketController::class,
            'quick_amount_' => PaymentController::class,
            'confirm_game_id_' => ProfileController::class,
        ];
        
        foreach ($specialHandlers as $prefix => $controllerClass) {
            if (str_starts_with($callbackData, $prefix)) {
                $this->createAndExecuteController($controllerClass, $user, $chatContext, function($controller) use ($callbackData, $chatId, $debugFile, $prefix) {
                    if ($prefix === 'confirm_game_id_' && method_exists($controller, 'handleGameIdConfirmation')) {
                        $controller->handleGameIdConfirmation($callbackData, $chatId, $debugFile);
                    } else {
                        $controller->handleCallback($callbackData, $chatId, $debugFile);
                    }
                });
                return;
            }
        }
        
        // 常规回调映射处理
        $controllerClass = $this->callbackMap[$callbackData] ?? null;
        if ($controllerClass && class_exists($controllerClass)) {
            $this->createAndExecuteController($controllerClass, $user, $chatContext, function($controller) use ($callbackData, $chatId, $debugFile) {
                $controller->handleCallback($callbackData, $chatId, $debugFile);
            });
        } else {
            $this->handleUnknownCallback($callbackData, $chatId, $chatContext, $debugFile);
        }
    }
    
    /**
     * 创建并执行控制器（减少重复代码）
     */
    private function createAndExecuteController(string $controllerClass, User $user, array $chatContext, callable $callback): void
    {
        try {
            $controller = new $controllerClass();
            
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            if (method_exists($controller, 'setChatContext')) {
                $controller->setChatContext($chatContext);
            }
            
            $callback($controller);
            
        } catch (\Exception $e) {
            $this->handleException($e, "控制器执行: {$controllerClass}", 'telegram_debug.log');
        }
    }
    
    /**
     * 分发文本输入
     */
    private function dispatchTextInput(int $chatId, string $text, User $user, array $chatContext, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $currentState = $userState['state'] ?? 'idle';
        
        // 状态映射
        $stateControllerMap = [
            // 充值相关状态
            'entering_amount' => PaymentController::class,
            'entering_order_id' => PaymentController::class,
            'waiting_payment' => PaymentController::class,
            'confirming_amount' => PaymentController::class,
            'waiting_recharge_amount' => PaymentController::class,
            'waiting_recharge_proof' => PaymentController::class,
            
            // 提现相关状态
            'waiting_withdraw_amount' => WithdrawController::class,
            'waiting_withdraw_address' => WithdrawController::class,
            'waiting_withdraw_password' => WithdrawController::class,
            'withdraw_setting_password' => WithdrawController::class,
            'withdraw_binding_address' => WithdrawController::class,
            'withdraw_entering_amount' => WithdrawController::class,
            'withdraw_entering_password' => WithdrawController::class,
            'withdraw_modifying_address' => WithdrawController::class,
            
            // 红包相关状态
            'waiting_redpacket_amount' => RedPacketController::class,
            'waiting_redpacket_count' => RedPacketController::class,
            'waiting_redpacket_title' => RedPacketController::class,
            'waiting_red_packet_command' => RedPacketController::class,
            'waiting_red_packet_amount' => RedPacketController::class,
            'waiting_red_packet_count' => RedPacketController::class,
            'waiting_red_packet_title' => RedPacketController::class,
            'confirming_red_packet' => RedPacketController::class,
            
            // 游戏ID相关状态
            'waiting_game_id_input' => ProfileController::class,
            'waiting_game_id_confirm' => ProfileController::class,
        ];
        
        $controllerClass = $stateControllerMap[$currentState] ?? null;
        
        if ($controllerClass) {
            $this->createAndExecuteController($controllerClass, $user, $chatContext, function($controller) use ($chatId, $text, $debugFile) {
                if (method_exists($controller, 'handleTextInput')) {
                    $controller->handleTextInput($chatId, $text, $debugFile);
                }
            });
        } else {
            // 空闲状态：检查是否是红包命令
            if ($this->isRedPacketCommand($text)) {
                if (!$this->validateRedPacketCommandPermission($chatContext, $debugFile)) {
                    $this->handlePrivateRedPacketCommand($chatId, $text, $debugFile);
                    return;
                }
                
                $this->createAndExecuteController(RedPacketController::class, $user, $chatContext, function($controller) use ($text, $chatId, $debugFile) {
                    $command = $this->parseCommand($text);
                    $controller->handle($command, $chatId, $debugFile, $text);
                });
            } else {
                $this->handleIdleInput($chatId, $text, $chatContext, $debugFile);
            }
        }
    }
    
    /**
     * 检查是否是红包命令
     */
    private function isRedPacketCommand($input): bool
    {
        $text = is_string($input) ? $input : '';
        $commands = ['red', 'hongbao', 'hb'];
        $commandsWithSlash = ['/red', '/hongbao', '/hb'];
        
        $trimmedText = trim($text);
        
        // 检查不带斜杠的命令
        if (in_array(strtolower($trimmedText), $commands)) {
            return true;
        }
        
        // 检查带斜杠的命令
        foreach ($commandsWithSlash as $command) {
            if (stripos($trimmedText, $command) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 验证红包命令权限
     */
    private function validateRedPacketCommandPermission(array $chatContext, string $debugFile): bool
    {
        $chatType = $chatContext['chat_type'] ?? 'private';
        $config = config('redpacket.command_restrictions', []);
        
        // 私聊限制检查
        if ($chatType === 'private' && !($config['allow_in_private'] ?? false)) {
            return false;
        }
        
        // 群组权限检查
        if (in_array($chatType, ['group', 'supergroup']) && !($config['allow_in_groups'] ?? true)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 处理私聊红包命令尝试
     */
    private function handlePrivateRedPacketCommand(int $chatId, string $command, string $debugFile): void
    {
        $message = "❌ *无法在私聊中发送红包*\n\n" .
                  "🧧 *红包功能说明：*\n" .
                  "• 红包命令只能在群组中使用\n" .
                  "• 发送的红包仅在当前群组有效\n" .
                  "• 请在群组中发送 `/red 100 10` 命令\n\n" .
                  "💡 *私聊可用功能：*\n" .
                  "• 查看红包记录和统计\n" .
                  "• 设置红包偏好\n" .
                  "• 查看账户余额";
        
        $keyboard = [
            [['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']],
            [
                ['text' => '💰 查看余额', 'callback_data' => 'check_balance'],
                ['text' => '🏠 主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理空闲状态的输入
     */
    private function handleIdleInput(int $chatId, string $text, array $chatContext, string $debugFile): void
    {
        $chatType = $chatContext['chat_type'] ?? 'private';
        
        $message = "❓ *需要帮助吗？*\n\n请使用下方菜单或发送命令：\n• /start - 返回主菜单\n• /help - 查看帮助\n";
        
        if ($chatType === 'private') {
            $message .= "• /redpacket - 红包菜单 🧧\n\n💡 红包发送需要在群组中使用";
            $keyboard = [
                [
                    ['text' => '🧧 红包菜单', 'callback_data' => 'redpacket'],
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
        } else {
            $message .= "• /red 100 10 - 发红包 🧧\n\n💡 如需充值、提现、发红包等操作，请使用菜单按钮";
            $keyboard = [
                [
                    ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
        }
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理未知命令
     */
    private function handleUnknownCommand(string $command, int $chatId, array $chatContext, string $debugFile): void
    {
        $chatType = $chatContext['chat_type'] ?? 'private';
        
        $text = "❓ *未知命令*\n\n请使用以下有效命令：\n• /start - 主菜单\n• /help - 帮助信息\n• /profile - 个人中心\n• /withdraw - 提现功能\n• /recharge - 充值功能\n";
        
        if ($chatType === 'private') {
            $text .= "• /redpacket - 红包菜单 🧧\n\n💡 红包发送命令需要在群组中使用";
            $keyboard = [
                [
                    ['text' => '🧧 红包菜单', 'callback_data' => 'redpacket'],
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
        } else {
            $text .= "• /red 100 10 - 发红包 🧧\n\n💡 建议使用菜单按钮操作";
            $keyboard = [
                [
                    ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
        }
        
        $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
    }
    
    /**
     * 处理未知回调
     */
    private function handleUnknownCallback(string $callbackData, int $chatId, array $chatContext, string $debugFile): void
    {
        $chatType = $chatContext['chat_type'] ?? 'private';
        $text = "❌ *操作无效*\n\n请使用菜单重新操作";
        
        if ($chatType === 'private') {
            $keyboard = [
                [
                    ['text' => '🧧 红包菜单', 'callback_data' => 'redpacket'],
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
        } else {
            $keyboard = [
                [
                    ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
        }
        
        $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
    }
    
    /**
     * 解析命令
     */
    private function parseCommand(string $text): string
    {
        $text = trim($text);
        if (strpos($text, '/') !== 0) {
            return '';
        }
        
        $parts = explode(' ', $text);
        $command = substr($parts[0], 1);
        
        // 处理带@bot_name的命令
        if (strpos($command, '@') !== false) {
            $command = explode('@', $command)[0];
        }
        
        return strtolower($command);
    }
    
    /**
     * 获取用户服务实例
     */
    protected function getUserService(): UserService
    {
        return $this->userService;
    }
    
    /**
     * 处理内联查询
     */
    public function handleInlineQuery(array $update, string $debugFile): void
    {
        $this->log($debugFile, "收到内联查询");
    }
    
    /**
     * 处理未知消息类型
     */
    public function handleUnknown(array $update, string $debugFile): void
    {
        try {
            // 检查是否包含机器人状态变化
            if (isset($update['my_chat_member'])) {
                $this->handleMyChatMember($update, $debugFile);
                return;
            }
            
            // 检查是否包含群成员变化
            if (isset($update['chat_member'])) {
                $this->handleChatMember($update, $debugFile);
                return;
            }
            
            // 其他未知类型
            $this->log($debugFile, "收到未知类型消息: " . json_encode(array_keys($update)));
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理未知消息类型", $debugFile);
        }
    }
}