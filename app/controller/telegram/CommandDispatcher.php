<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\UserService;
use app\model\User;

/**
 * Telegram命令分发器 - 🔥 增强版：支持聊天类型限制和红包功能
 * 明确各控制器的职责分工，避免重复路由
 */
class CommandDispatcher extends BaseTelegramController
{
    private UserService $userService;
    
    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }
    
    // 控制器映射表 - 🔥 增强红包命令支持
    private array $controllerMap = [
        // 通用功能 - GeneralController
        'start' => GeneralController::class,
        'help' => GeneralController::class,
        'menu' => GeneralController::class,
        
        // 个人中心 - ProfileController
        'profile' => ProfileController::class,
        
        // 功能模块各自处理
        'recharge' => PaymentController::class,
        'withdraw' => WithdrawController::class,
        'invite' => InviteController::class,
        'game' => GameController::class,
        'service' => ServiceController::class,
        
        // 🔥 红包功能 - RedPacketController
        'redpacket' => RedPacketController::class,       // 红包主菜单
        'red' => RedPacketController::class,             // 发红包命令
        'hongbao' => RedPacketController::class,         // 红包命令（中文）
        'hb' => RedPacketController::class,              // 红包简写命令
    ];
    
    // 回调映射表 - 🔥 增强红包回调支持
    private array $callbackMap = [
        // 通用回调 - GeneralController
        'back_to_main' => GeneralController::class,
        'back' => GeneralController::class,
        'check_balance' => GeneralController::class,
        'game_history' => GeneralController::class,
        'security_settings' => GeneralController::class,
        'binding_info' => GeneralController::class,
        'win_culture' => GeneralController::class,
        'daily_news' => GeneralController::class,
        'today_headlines' => GeneralController::class,
        
        // 个人中心回调 - ProfileController（增强版）
        'profile' => ProfileController::class,
        'bind_game_id' => ProfileController::class,              // 绑定游戏ID主菜单
        'start_bind_game_id' => ProfileController::class,        // 开始绑定流程
        'cancel_bind_game_id' => ProfileController::class,       // 取消绑定
        'view_current_game_id' => ProfileController::class,      // 查看当前游戏ID
        
        // 充值相关回调 - PaymentController
        'recharge' => PaymentController::class,
        'recharge_usdt' => PaymentController::class,
        'recharge_huiwang' => PaymentController::class,
        'confirm_amount' => PaymentController::class,
        'copy_address' => PaymentController::class,
        'copy_account' => PaymentController::class,              // 🔧 新增：复制银行账号
        'transfer_complete' => PaymentController::class,
        'cancel_recharge' => PaymentController::class,
        'confirm_recharge' => PaymentController::class,
        'retry_verify' => PaymentController::class,
        'manual_amount' => PaymentController::class,             // 🔧 新增：手动输入金额
        'reenter_amount' => PaymentController::class,            // 🔧 新增：重新输入金额
        
        // 提现相关回调 - WithdrawController
        'withdraw' => WithdrawController::class,
        'start_withdraw' => WithdrawController::class,
        'set_withdraw_password' => WithdrawController::class,
        'bind_usdt_address' => WithdrawController::class,
        'confirm_withdraw' => WithdrawController::class,
        'cancel_withdraw' => WithdrawController::class,
        'withdraw_history' => WithdrawController::class,
        'modify_address' => WithdrawController::class,
        
        // 邀请相关回调 - InviteController
        'invite' => InviteController::class,
        'invite_stats' => InviteController::class,
        'invite_rewards' => InviteController::class,
        
        // 🔥 红包相关回调 - RedPacketController
        'redpacket' => RedPacketController::class,               // 红包主菜单
        'send_red_packet' => RedPacketController::class,         // 发红包
        'red_packet_history' => RedPacketController::class,      // 红包记录
        'confirm_send_redpacket' => RedPacketController::class,  // 确认发送红包
        'cancel_send_redpacket' => RedPacketController::class,   // 取消发送红包
        
        // 游戏相关回调 - GameController
        'game' => GameController::class,
        
        // 客服相关回调 - ServiceController
        'service' => ServiceController::class,
    ];
    
    /**
     * 处理文本消息 - 🔥 增强聊天上下文传递
     */
    public function handleMessage(array $update, string $debugFile): void
    {
        try {
            $message = $update['message'];
            $chatId = intval($message['chat']['id']);
            $text = $message['text'] ?? '';
            $messageId = $message['message_id'] ?? 0;
            
            // 🔥 提取聊天上下文信息
            $chatContext = $this->extractChatContext($message);
            
            $this->log($debugFile, "收到消息 - ChatID: {$chatId}, Type: {$chatContext['chat_type']}, 内容: {$text}");
            
            // 检查是否是命令
            if (strpos($text, '/') === 0) {
                $this->log($debugFile, "识别为命令消息");
                
                // 🆕 解析邀请码（在用户处理之前）
                $invitationCode = $this->extractInvitationCode($text);
                if ($invitationCode) {
                    $this->log($debugFile, "🎯 检测到邀请码: {$invitationCode}");
                }
                
                // ✅ 方案B：统一用户处理（传递邀请码）
                $user = $this->ensureUserExists($update, $debugFile, $invitationCode);
                if (!$user) {
                    $this->log($debugFile, "❌ 用户处理失败，终止消息处理");
                    return;
                }
                
                // 🔥 传递聊天上下文到命令处理
                $this->dispatchCommand($text, $chatId, $user, $chatContext, $debugFile);
            } else {
                $this->log($debugFile, "识别为普通文本消息");
                
                // 普通文本消息不涉及邀请码
                $user = $this->ensureUserExists($update, $debugFile);
                if (!$user) {
                    $this->log($debugFile, "❌ 用户处理失败，终止消息处理");
                    return;
                }
                
                // 🔥 传递聊天上下文到文本处理
                $this->dispatchTextInput($chatId, $text, $user, $chatContext, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理文本消息", $debugFile);
        }
    }
    
    /**
     * 处理回调查询 - 🔥 增强聊天上下文传递
     */
    public function handleCallback(array $update, string $debugFile): void
    {
        try {
            $callbackQuery = $update['callback_query'];
            $chatId = intval($callbackQuery['message']['chat']['id']);
            $callbackData = $callbackQuery['data'] ?? '';
            $queryId = $callbackQuery['id'] ?? '';
            
            // 🔥 提取聊天上下文信息
            $chatContext = $this->extractChatContext($callbackQuery['message']);
            
            $this->log($debugFile, "收到回调 - ChatID: {$chatId}, Type: {$chatContext['chat_type']}, 数据: {$callbackData}");
            
            // 安全回调响应
            $this->safeAnswerCallbackQuery($queryId, null, $debugFile);
            
            // 防重复处理
            if ($this->isDuplicateCallback($queryId, $debugFile)) {
                $this->log($debugFile, "⚠️ 重复回调查询，已忽略");
                return;
            }
            
            // ✅ 方案B：统一用户处理（回调不涉及邀请码）
            $user = $this->ensureUserExists($update, $debugFile);
            if (!$user) {
                $this->log($debugFile, "❌ 用户处理失败，终止回调处理");
                return;
            }
            
            $this->log($debugFile, "开始分发回调: {$callbackData}");
            
            // 🔥 传递聊天上下文到回调处理
            $this->dispatchCallback($callbackData, $chatId, $user, $chatContext, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理回调查询", $debugFile);
        }
    }
    
    // =================== 🔥 新增：聊天上下文提取 ===================
    
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
     * 🆕 从命令中提取邀请码
     */
    private function extractInvitationCode(string $text): ?string
    {
        $text = trim($text);
        $parts = explode(' ', $text);
        
        // 检查是否是 /start 命令且有参数
        if (count($parts) >= 2 && strtolower(substr($parts[0], 1)) === 'start') {
            $invitationCode = trim($parts[1]);
            
            // 简单验证邀请码格式（字母数字组合，长度适当）
            if (!empty($invitationCode) && preg_match('/^[A-Z0-9]{6,20}$/i', $invitationCode)) {
                return strtoupper($invitationCode);
            }
        }
        
        return null;
    }
    
    /**
     * ✅ 方案B核心方法：统一用户处理（支持邀请码）
     * 确保用户存在，不存在则自动创建（最小化创建）
     */
    private function ensureUserExists(array $update, string $debugFile, ?string $invitationCode = null): ?User
    {
        try {
            // 提取 Telegram 用户信息
            $telegramData = $this->extractTelegramUserData($update);
            if (!$telegramData) {
                $this->log($debugFile, "❌ 无法提取Telegram用户信息");
                return null;
            }
            
            $tgUserId = $telegramData['id'];
            $this->log($debugFile, "🔍 处理Telegram用户: {$tgUserId}");
            
            if ($invitationCode) {
                $this->log($debugFile, "🎯 携带邀请码: {$invitationCode}");
            }
            
            // 调用 UserService 进行用户查找/创建（传递邀请码）
            $user = $this->userService->findOrCreateUser($telegramData, $invitationCode ?? '');
            
            if ($user) {
                $this->log($debugFile, "✅ 用户处理成功 - ID: {$user->id}, TG_ID: {$tgUserId}, 用户名: {$user->user_name}");
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
     * 从 update 中提取 Telegram 用户数据
     */
    private function extractTelegramUserData(array $update): ?array
    {
        $from = null;
        
        // 从不同类型的 update 中提取用户信息
        if (isset($update['message']['from'])) {
            $from = $update['message']['from'];
        } elseif (isset($update['callback_query']['from'])) {
            $from = $update['callback_query']['from'];
        } elseif (isset($update['inline_query']['from'])) {
            $from = $update['inline_query']['from'];
        }
        
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
     * 🔥 最终修复：分发命令（解决红包原始消息为空问题）
     */
    private function dispatchCommand(string $text, int $chatId, User $user, array $chatContext, string $debugFile): void
    {
        $command = $this->parseCommand($text);
        $this->log($debugFile, "分发命令: {$command} (用户ID: {$user->id}, 聊天类型: {$chatContext['chat_type']})");
        
        // 🔥 预检查：红包命令的聊天类型限制
        if ($this->isRedPacketCommand($command) && !$this->validateRedPacketCommandPermission($chatContext, $debugFile)) {
            $this->handlePrivateRedPacketCommand($chatId, $command, $debugFile);
            return;
        }
        
        $controllerClass = $this->controllerMap[$command] ?? null;
        
        if ($controllerClass && class_exists($controllerClass)) {
            try {
                $controller = new $controllerClass();
                
                // 传递用户对象到控制器（如果控制器支持）
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                
                // 🔥 传递聊天上下文到控制器（如果支持）
                if (method_exists($controller, 'setChatContext')) {
                    $controller->setChatContext($chatContext);
                }
                
                // 🔥 最终修复：红包控制器特殊处理
                if ($this->isRedPacketCommand($command)) {
                    // 🚨 重要：添加详细日志确认消息传递
                    $this->log($debugFile, "🧧 即将调用红包控制器");
                    $this->log($debugFile, "🧧 命令: {$command}");
                    $this->log($debugFile, "🧧 完整消息: {$text}");
                    $this->log($debugFile, "🧧 调用参数: handle('{$command}', {$chatId}, '{$debugFile}', '{$text}')");
                    
                    if (method_exists($controller, 'handle')) {
                        // 确保参数顺序正确
                        $controller->handle($command, $chatId, $debugFile, $text);
                        $this->log($debugFile, "🧧 RedPacketController::handle 调用完成");
                    } else {
                        $this->log($debugFile, "❌ RedPacketController 没有 handle 方法");
                        $this->sendMessage($chatId, "❌ 红包功能暂时不可用", $debugFile);
                    }
                } else {
                    // 其他控制器使用标准调用
                    if (method_exists($controller, 'handle')) {
                        $controller->handle($command, $chatId, $debugFile);
                    } else {
                        $this->log($debugFile, "❌ 控制器 {$controllerClass} 没有 handle 方法");
                        $this->sendMessage($chatId, "❌ 功能暂时不可用", $debugFile);
                    }
                }
                
                $this->log($debugFile, "✅ 命令处理完成: {$command} -> {$controllerClass}");
            } catch (\Exception $e) {
                $this->handleException($e, "命令处理: {$command}", $debugFile);
                $this->sendMessage($chatId, "❌ 命令处理失败，请稍后重试", $debugFile);
            }
        } else {
            $this->handleUnknownCommand($command, $chatId, $chatContext, $debugFile);
        }
    }

   
    /**
     * 分发回调处理（🔥 增强版本，传递聊天上下文和红包特殊处理）
     */
    private function dispatchCallback(string $callbackData, int $chatId, User $user, array $chatContext, string $debugFile): void
    {
        // 🔥 优先检查红包特殊格式的回调
        if (strpos($callbackData, 'grab_redpacket_') === 0) {
            $this->log($debugFile, "→ 抢红包回调转发到RedPacketController: {$callbackData}");
            $controller = new RedPacketController();
            
            // 传递用户对象和聊天上下文
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            if (method_exists($controller, 'setChatContext')) {
                $controller->setChatContext($chatContext);
            }
            
            $controller->handleCallback($callbackData, $chatId, $debugFile);
            return;
        }
        
        // 🔥 红包详情回调
        if (strpos($callbackData, 'redpacket_detail_') === 0) {
            $this->log($debugFile, "→ 红包详情回调转发到RedPacketController: {$callbackData}");
            $controller = new RedPacketController();
            
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            if (method_exists($controller, 'setChatContext')) {
                $controller->setChatContext($chatContext);
            }
            
            $controller->handleCallback($callbackData, $chatId, $debugFile);
            return;
        }
        
        // 🔥 刷新红包回调
        if (strpos($callbackData, 'refresh_redpacket_') === 0) {
            $this->log($debugFile, "→ 刷新红包回调转发到RedPacketController: {$callbackData}");
            $controller = new RedPacketController();
            
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            if (method_exists($controller, 'setChatContext')) {
                $controller->setChatContext($chatContext);
            }
            
            $controller->handleCallback($callbackData, $chatId, $debugFile);
            return;
        }
        
        // 优先检查特殊格式的回调（原有逻辑保留）
        if (strpos($callbackData, 'quick_amount_') === 0) {
            $this->log($debugFile, "→ 快捷金额选择转发到PaymentController: {$callbackData}");
            $controller = new PaymentController();
            
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            
            $controller->handleCallback($callbackData, $chatId, $debugFile);
            return;
        }
        
        // 🆔 处理游戏ID确认回调 confirm_game_id_xxx
        if (strpos($callbackData, 'confirm_game_id_') === 0) {
            $this->log($debugFile, "→ 游戏ID确认回调转发到ProfileController: {$callbackData}");
            $controller = new ProfileController();
            
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            
            // 调用特殊处理方法
            if (method_exists($controller, 'handleGameIdConfirmation')) {
                $controller->handleGameIdConfirmation($callbackData, $chatId, $debugFile);
            } else {
                $controller->handleCallback($callbackData, $chatId, $debugFile);
            }
            return;
        }
        
        // 常规回调映射处理
        $controllerClass = $this->callbackMap[$callbackData] ?? null;
        
        if ($controllerClass && class_exists($controllerClass)) {
            try {
                $controller = new $controllerClass();
                
                // 传递用户对象到控制器（如果控制器支持）
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                
                // 🔥 传递聊天上下文到控制器（如果支持）
                if (method_exists($controller, 'setChatContext')) {
                    $controller->setChatContext($chatContext);
                }
                
                $controller->handleCallback($callbackData, $chatId, $debugFile);
                $this->log($debugFile, "✅ 回调处理完成: {$callbackData} -> {$controllerClass}");
            } catch (\Exception $e) {
                $this->handleException($e, "回调处理: {$callbackData}", $debugFile);
                $this->sendMessage($chatId, "❌ 操作失败，请稍后重试", $debugFile);
            }
        } else {
            $this->handleUnknownCallback($callbackData, $chatId, $chatContext, $debugFile);
        }
    }
    
    /**
     * 🔥 修复：分发文本输入中的红包命令处理
     */
    private function dispatchTextInput(int $chatId, string $text, User $user, array $chatContext, string $debugFile): void
    {
        // 获取用户状态
        $userState = $this->getUserState($chatId);
        $currentState = $userState['state'] ?? 'idle';
        
        $this->log($debugFile, "用户状态: {$currentState}, 文本输入: {$text} (用户ID: {$user->id}, 聊天类型: {$chatContext['chat_type']})");
        
        // 根据用户状态分发到对应控制器
        switch ($currentState) {
            // 🔧 修复：充值相关状态映射
            case 'entering_amount':           // 输入充值金额
            case 'entering_order_id':         // 输入订单号
            case 'waiting_payment':           // 等待支付（可能的文本输入）
            case 'confirming_amount':         // 确认金额（可能的文本输入）
            case 'waiting_recharge_amount':   // 旧状态兼容
            case 'waiting_recharge_proof':    // 旧状态兼容
                $this->log($debugFile, "→ 充值流程文本输入转发到PaymentController");
                $controller = new PaymentController();
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                $controller->handleTextInput($chatId, $text, $debugFile);
                break;
                
            // 🔧 修复：提现相关状态映射 - 添加缺失的状态
            case 'waiting_withdraw_amount':
            case 'waiting_withdraw_address':
            case 'waiting_withdraw_password':
            case 'withdraw_setting_password':      // 🆕 添加：设置提现密码状态
            case 'withdraw_binding_address':       // 🆕 添加：绑定提现地址状态
            case 'withdraw_entering_amount':       // 🆕 添加：输入提现金额状态
            case 'withdraw_entering_password':     // 🆕 添加：输入提现密码状态
            case 'withdraw_modifying_address':     // 🆕 添加：修改提现地址状态
                $this->log($debugFile, "→ 提现流程文本输入转发到WithdrawController");
                $controller = new WithdrawController();
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                $controller->handleTextInput($chatId, $text, $debugFile);
                break;
                
            // 🔥 红包相关状态映射
            case 'waiting_red_packet_command':     // 🔥 新增：等待红包命令
            case 'waiting_red_packet_amount':      // 红包金额输入
            case 'waiting_red_packet_count':       // 红包个数输入
            case 'waiting_red_packet_title':       // 🔥 新增：等待红包标题
            case 'confirming_red_packet':          // 🔥 新增：确认红包信息
                $this->log($debugFile, "→ 红包流程文本输入转发到RedPacketController");
                $controller = new RedPacketController();
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                if (method_exists($controller, 'setChatContext')) {
                    $controller->setChatContext($chatContext);
                }
                $controller->handleTextInput($chatId, $text, $debugFile);
                break;
                
            // 🆔 新增：游戏ID相关的文本输入处理
            case 'waiting_game_id_input':
            case 'waiting_game_id_confirm':
                $this->log($debugFile, "→ 游戏ID流程文本输入转发到ProfileController");
                $controller = new ProfileController();
                if (method_exists($controller, 'setUser')) {
                    $controller->setUser($user);
                }
                if (method_exists($controller, 'handleTextInput')) {
                    $controller->handleTextInput($chatId, $text, $debugFile);
                } else {
                    $this->log($debugFile, "❌ ProfileController 没有 handleTextInput 方法");
                }
                break;
                
            default:
                // 🔥 空闲状态，优先检查是否是红包命令
                if ($this->isRedPacketCommand($text)) {
                    // 🔥 检查红包命令权限
                    if (!$this->validateRedPacketCommandPermission($chatContext, $debugFile)) {
                        $this->handlePrivateRedPacketCommand($chatId, $text, $debugFile);
                        return;
                    }
                    
                    $this->log($debugFile, "→ 检测到红包命令，转发到RedPacketController");
                    $controller = new RedPacketController();
                    if (method_exists($controller, 'setUser')) {
                        $controller->setUser($user);
                    }
                    if (method_exists($controller, 'setChatContext')) {
                        $controller->setChatContext($chatContext);
                    }
                    // 🔥 修复：使用正确的参数顺序调用 handle 方法
                    $command = $this->parseCommand($text);
                    if (method_exists($controller, 'handle')) {
                        $controller->handle($command, $chatId, $debugFile, $text); // 正确的参数顺序
                    } else {
                        $controller->handleTextInput($chatId, $text, $debugFile);
                    }
                } else {
                    // 其他情况显示帮助信息
                    $this->log($debugFile, "→ 空闲状态，显示帮助信息");
                    $this->handleIdleInput($chatId, $text, $chatContext, $debugFile);
                }
                break;
        }
    }
    
    // =================== 🔥 新增：红包权限验证方法 ===================
    
    /**
     * 🔥 修复：检查是否是红包命令
     */
    private function isRedPacketCommand($input): bool
    {
        $text = is_string($input) ? $input : '';
        
        // 🔥 修复：支持带斜杠和不带斜杠的命令检查
        $commands = ['red', 'hongbao', 'hb'];  // 不带斜杠的命令列表
        $commandsWithSlash = ['/red', '/hongbao', '/hb'];  // 带斜杠的命令列表
        
        $trimmedText = trim($text);
        
        // 检查不带斜杠的命令（从 parseCommand 传入）
        if (in_array(strtolower($trimmedText), $commands)) {
            return true;
        }
        
        // 检查带斜杠的完整命令（从原始文本传入）
        foreach ($commandsWithSlash as $command) {
            if (stripos($trimmedText, $command) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 🔥 验证红包命令权限
     */
    private function validateRedPacketCommandPermission(array $chatContext, string $debugFile): bool
    {
        $chatType = $chatContext['chat_type'] ?? 'private';
        $config = config('redpacket.command_restrictions', []);
        
        $this->log($debugFile, "红包命令权限验证 - 聊天类型: {$chatType}");
        
        // 私聊限制检查
        if ($chatType === 'private' && !($config['allow_in_private'] ?? false)) {
            $this->log($debugFile, "❌ 私聊红包命令被禁止");
            return false;
        }
        
        // 群组权限检查
        if (in_array($chatType, ['group', 'supergroup']) && !($config['allow_in_groups'] ?? true)) {
            $this->log($debugFile, "❌ 群组红包命令被禁止");
            return false;
        }
        
        $this->log($debugFile, "✅ 红包命令权限验证通过");
        return true;
    }
    
    /**
     * 🔥 处理私聊红包命令尝试
     */
    private function handlePrivateRedPacketCommand(int $chatId, string $command, string $debugFile): void
    {
        $this->log($debugFile, "🚫 私聊红包命令被拒绝: {$command}");
        
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
            [
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ],
            [
                ['text' => '💰 查看余额', 'callback_data' => 'check_balance'],
                ['text' => '🏠 主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 获取用户数据（供子控制器使用的便捷方法）
     */
    protected function getUserByTgId(string $tgUserId): ?User
    {
        return $this->userService->getUserByTgId($tgUserId);
    }
    
    /**
     * 处理空闲状态的输入 - 🔥 增强红包提示和聊天类型适配
     */
    private function handleIdleInput(int $chatId, string $text, array $chatContext, string $debugFile): void
    {
        $chatType = $chatContext['chat_type'] ?? 'private';
        
        $message = "❓ *需要帮助吗？*\n\n" .
                  "请使用下方菜单或发送命令：\n" .
                  "• /start - 返回主菜单\n" .
                  "• /help - 查看帮助\n";
        
        // 🔥 根据聊天类型显示不同的红包提示
        if ($chatType === 'private') {
            $message .= "• /redpacket - 红包菜单 🧧\n\n" .
                       "💡 红包发送需要在群组中使用";
        } else {
            $message .= "• /red 100 10 - 发红包 🧧\n\n" .
                       "💡 如需充值、提现、发红包等操作，请使用菜单按钮";
        }
        
        $keyboard = [];
        
        // 🔥 根据聊天类型显示不同的按钮
        if ($chatType === 'private') {
            $keyboard[] = [
                ['text' => '🧧 红包菜单', 'callback_data' => 'redpacket'],
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ];
        } else {
            $keyboard[] = [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ];
        }
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理未知命令 - 🔥 增强红包命令提示和聊天类型适配
     */
    private function handleUnknownCommand(string $command, int $chatId, array $chatContext, string $debugFile): void
    {
        $this->log($debugFile, "❌ 未知命令: {$command}");
        
        $chatType = $chatContext['chat_type'] ?? 'private';
        
        $text = "❓ *未知命令*\n\n" .
               "请使用以下有效命令：\n" .
               "• /start - 主菜单\n" .
               "• /help - 帮助信息\n" .
               "• /profile - 个人中心\n" .
               "• /withdraw - 提现功能\n" .
               "• /recharge - 充值功能\n";
        
        // 🔥 根据聊天类型显示不同的红包命令提示
        if ($chatType === 'private') {
            $text .= "• /redpacket - 红包菜单 🧧\n\n" .
                    "💡 红包发送命令需要在群组中使用";
        } else {
            $text .= "• /red 100 10 - 发红包 🧧\n\n" .
                    "💡 建议使用菜单按钮操作";
        }
        
        $keyboard = [];
        
        // 🔥 根据聊天类型显示不同的按钮
        if ($chatType === 'private') {
            $keyboard[] = [
                ['text' => '🧧 红包菜单', 'callback_data' => 'redpacket'],
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ];
        } else {
            $keyboard[] = [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ];
        }
        
        $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
    }
    
    /**
     * 处理未知回调 - 🔥 增强聊天类型适配
     */
    private function handleUnknownCallback(string $callbackData, int $chatId, array $chatContext, string $debugFile): void
    {
        $this->log($debugFile, "❌ 未知回调: {$callbackData}");
        
        $chatType = $chatContext['chat_type'] ?? 'private';
        
        $text = "❌ *操作无效*\n\n请使用菜单重新操作";
        $keyboard = [];
        
        // 🔥 根据聊天类型显示不同的按钮
        if ($chatType === 'private') {
            $keyboard[] = [
                ['text' => '🧧 红包菜单', 'callback_data' => 'redpacket'],
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ];
        } else {
            $keyboard[] = [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
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
        $command = substr($parts[0], 1); // 移除 '/'
        
        // 处理带@bot_name的命令
        if (strpos($command, '@') !== false) {
            $command = explode('@', $command)[0];
        }
        
        return strtolower($command);
    }
    
    /**
     * 获取用户服务实例（供子类使用）
     */
    protected function getUserService(): UserService
    {
        return $this->userService;
    }
    
    /**
     * 处理内联查询（预留接口）
     */
    public function handleInlineQuery(array $update, string $debugFile): void
    {
        try {
            $this->log($debugFile, "收到内联查询");
            // 内联查询处理逻辑（如果需要）
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理内联查询", $debugFile);
        }
    }
    
    /**
     * 处理未知消息类型（预留接口）
     */
    public function handleUnknown(array $update, string $debugFile): void
    {
        try {
            $this->log($debugFile, "收到未知类型消息");
            // 未知消息类型处理逻辑
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理未知消息", $debugFile);
        }
    }
}