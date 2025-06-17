<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\UserService;
use app\service\TelegramService;
use app\model\User;

/**
 * Telegram命令分发器 - 极简版：纯路由分发，业务逻辑交给对应控制器
 */
class CommandDispatcher extends BaseTelegramController
{
    private UserService $userService;
    private TelegramService $telegramService;
    
    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
        $this->telegramService = new TelegramService();
    }
    
    // =================== 🆕 新增：my_chat_member事件路由 ===================
    
    /**
     * 🆕 处理机器人状态变化事件（纯路由）
     */
    public function handleMyChatMember(array $update, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🤖 机器人状态变化事件 -> TelegramService");
            $this->telegramService->handleMyChatMemberUpdate($update['my_chat_member'], $debugFile);
        } catch (\Exception $e) {
            $this->handleException($e, "机器人状态变化", $debugFile);
        }
    }
    
    // =================== 核心分发方法 ===================
    
    /**
     * 处理文本消息
     */
    public function handleMessage(array $update, string $debugFile): void
    {
        try {
            $message = $update['message'];
            $chatId = intval($message['chat']['id']);
            $text = $message['text'] ?? '';
            
            $this->log($debugFile, "收到消息: ChatID={$chatId}, 内容={$text}");
            
            // 确保用户存在
            $user = $this->ensureUserExists($update, $debugFile);
            if (!$user) {
                $this->log($debugFile, "❌ 用户处理失败");
                return;
            }
            
            // 分发处理
            if (strpos($text, '/') === 0) {
                $this->dispatchCommand($text, $chatId, $user, $debugFile);
            } else {
                $this->dispatchTextInput($chatId, $text, $user, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理消息", $debugFile);
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
            
            $this->log($debugFile, "收到回调: ChatID={$chatId}, 数据={$callbackData}");
            
            // 回调响应
            $this->safeAnswerCallbackQuery($queryId, null, $debugFile);
            
            // 确保用户存在
            $user = $this->ensureUserExists($update, $debugFile);
            if (!$user) {
                $this->log($debugFile, "❌ 用户处理失败");
                return;
            }
            
            // 分发回调
            $this->dispatchCallback($callbackData, $chatId, $user, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理回调", $debugFile);
        }
    }
    
    // =================== 分发逻辑 ===================
    
    /**
     * 分发命令（简化版）
     */
    private function dispatchCommand(string $text, int $chatId, User $user, string $debugFile): void
    {
        $command = $this->parseCommand($text);
        $controllerClass = $this->getCommandController($command);
        
        $this->log($debugFile, "命令分发: {$command} -> {$controllerClass}");
        
        if ($controllerClass) {
            $this->forwardToController($controllerClass, 'handle', [
                $command, $chatId, $debugFile, $text
            ], $user, $debugFile);
        } else {
            $this->sendMessage($chatId, "❓ 未知命令，请使用 /help 查看帮助", $debugFile);
        }
    }
    
    /**
     * 分发回调（简化版）
     */
    private function dispatchCallback(string $callbackData, int $chatId, User $user, string $debugFile): void
    {
        $controllerClass = $this->getCallbackController($callbackData);
        
        $this->log($debugFile, "回调分发: {$callbackData} -> {$controllerClass}");
        
        if ($controllerClass) {
            $this->forwardToController($controllerClass, 'handleCallback', [
                $callbackData, $chatId, $debugFile
            ], $user, $debugFile);
        } else {
            $this->sendMessage($chatId, "❌ 操作无效", $debugFile);
        }
    }
    
    /**
     * 分发文本输入（简化版）
     */
    private function dispatchTextInput(int $chatId, string $text, User $user, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $currentState = $userState['state'] ?? 'idle';
        
        $this->log($debugFile, "文本输入: 状态={$currentState}, 内容={$text}");
        
        $controllerClass = $this->getStateController($currentState);
        
        if ($controllerClass) {
            $this->forwardToController($controllerClass, 'handleTextInput', [
                $chatId, $text, $debugFile
            ], $user, $debugFile);
        } else {
            // 空闲状态，检查是否是红包命令
            if ($this->isRedPacketCommand($text)) {
                $this->forwardToController(RedPacketController::class, 'handle', [
                    $this->parseCommand($text), $chatId, $debugFile, $text
                ], $user, $debugFile);
            } else {
                $this->sendMessage($chatId, "❓ 需要帮助？请使用 /help", $debugFile);
            }
        }
    }
    
    // =================== 控制器映射（简化） ===================
    
    /**
     * 获取命令对应的控制器
     */
    private function getCommandController(string $command): ?string
    {
        $map = [
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
        
        return $map[$command] ?? null;
    }
    
    /**
     * 获取回调对应的控制器（模式匹配）
     */
    private function getCallbackController(string $callbackData): ?string
    {
        // 红包相关回调
        if (str_starts_with($callbackData, 'redpacket') || 
            str_starts_with($callbackData, 'grab_redpacket') || 
            str_starts_with($callbackData, 'refresh_redpacket') ||
            str_starts_with($callbackData, 'send_red_packet') ||
            str_starts_with($callbackData, 'confirm_send_redpacket') ||
            str_starts_with($callbackData, 'cancel_send_redpacket') ||
            str_starts_with($callbackData, 'red_packet_history')) {
            return RedPacketController::class;
        }
        
        // 充值相关回调
        if (str_starts_with($callbackData, 'recharge') || 
            str_starts_with($callbackData, 'quick_amount') ||
            str_starts_with($callbackData, 'confirm_amount') ||
            str_starts_with($callbackData, 'copy_') ||
            str_starts_with($callbackData, 'transfer_complete') ||
            str_starts_with($callbackData, 'manual_amount') ||
            str_starts_with($callbackData, 'reenter_amount')) {
            return PaymentController::class;
        }
        
        // 提现相关回调
        if (str_starts_with($callbackData, 'withdraw') ||
            str_starts_with($callbackData, 'start_withdraw') ||
            str_starts_with($callbackData, 'set_withdraw_password') ||
            str_starts_with($callbackData, 'bind_usdt_address') ||
            str_starts_with($callbackData, 'confirm_withdraw') ||
            str_starts_with($callbackData, 'cancel_withdraw') ||
            str_starts_with($callbackData, 'modify_address')) {
            return WithdrawController::class;
        }
        
        // 个人中心相关回调
        if (str_starts_with($callbackData, 'profile') ||
            str_starts_with($callbackData, 'bind_game_id') ||
            str_starts_with($callbackData, 'start_bind_game_id') ||
            str_starts_with($callbackData, 'cancel_bind_game_id') ||
            str_starts_with($callbackData, 'view_current_game_id') ||
            str_starts_with($callbackData, 'confirm_game_id')) {
            return ProfileController::class;
        }
        
        // 邀请相关回调
        if (str_starts_with($callbackData, 'invite')) {
            return InviteController::class;
        }
        
        // 游戏相关回调
        if (str_starts_with($callbackData, 'game')) {
            return GameController::class;
        }
        
        // 客服相关回调
        if (str_starts_with($callbackData, 'service')) {
            return ServiceController::class;
        }
        
        // 通用回调 - 默认返回GeneralController
        return GeneralController::class;
    }
    
    /**
     * 获取状态对应的控制器
     */
    private function getStateController(string $state): ?string
    {
        // 充值相关状态
        if (str_contains($state, 'recharge') || str_contains($state, 'amount') || str_contains($state, 'payment')) {
            return PaymentController::class;
        }
        
        // 提现相关状态
        if (str_contains($state, 'withdraw')) {
            return WithdrawController::class;
        }
        
        // 红包相关状态
        if (str_contains($state, 'red_packet') || str_contains($state, 'redpacket')) {
            return RedPacketController::class;
        }
        
        // 游戏ID相关状态
        if (str_contains($state, 'game_id')) {
            return ProfileController::class;
        }
        
        return null; // 空闲状态
    }
    
    // =================== 工具方法 ===================
    
    /**
     * 统一转发到控制器（消除重复代码）
     */
    private function forwardToController(string $controllerClass, string $method, array $params, User $user, string $debugFile): void
    {
        try {
            if (!class_exists($controllerClass)) {
                $this->log($debugFile, "❌ 控制器不存在: {$controllerClass}");
                return;
            }
            
            $controller = new $controllerClass();
            
            // 传递用户对象
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            
            // 调用方法
            if (method_exists($controller, $method)) {
                call_user_func_array([$controller, $method], $params);
                $this->log($debugFile, "✅ 控制器调用成功: {$controllerClass}::{$method}");
            } else {
                $this->log($debugFile, "❌ 方法不存在: {$controllerClass}::{$method}");
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 控制器调用失败: " . $e->getMessage());
            $this->sendMessage($params[1] ?? 0, "❌ 操作失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 确保用户存在（简化版）
     */
    private function ensureUserExists(array $update, string $debugFile): ?User
    {
        try {
            $telegramData = $this->extractTelegramUserData($update);
            if (!$telegramData) {
                return null;
            }
            
            // 解析邀请码（仅限start命令）
            $invitationCode = '';
            if (isset($update['message']['text'])) {
                $invitationCode = $this->extractInvitationCode($update['message']['text']) ?? '';
            }
            
            return $this->userService->findOrCreateUser($telegramData, $invitationCode);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 用户处理异常: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 提取Telegram用户数据
     */
    private function extractTelegramUserData(array $update): ?array
    {
        $from = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;
        
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
     * 从命令中提取邀请码
     */
    private function extractInvitationCode(string $text): ?string
    {
        $parts = explode(' ', trim($text));
        if (count($parts) >= 2 && strtolower(substr($parts[0], 1)) === 'start') {
            $code = trim($parts[1]);
            if (preg_match('/^[A-Z0-9]{6,20}$/i', $code)) {
                return strtoupper($code);
            }
        }
        return null;
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
        
        // 处理@bot_name
        if (strpos($command, '@') !== false) {
            $command = explode('@', $command)[0];
        }
        
        return strtolower($command);
    }
    
    /**
     * 检查是否是红包命令
     */
    private function isRedPacketCommand(string $text): bool
    {
        $redPacketCommands = ['/red', '/hongbao', '/hb', 'red', 'hongbao', 'hb'];
        $text = trim($text);
        
        foreach ($redPacketCommands as $cmd) {
            if (stripos($text, $cmd) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取用户服务实例
     */
    protected function getUserService(): UserService
    {
        return $this->userService;
    }
    
    /**
     * 处理内联查询（预留）
     */
    public function handleInlineQuery(array $update, string $debugFile): void
    {
        $this->log($debugFile, "收到内联查询");
        // 如需要可以实现
    }
    
    /**
     * 处理未知消息类型（预留）
     */
    public function handleUnknown(array $update, string $debugFile): void
    {
        $this->log($debugFile, "收到未知类型消息");
        // 如需要可以实现
    }
}