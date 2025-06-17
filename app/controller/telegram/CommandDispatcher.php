<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\UserService;
use app\service\TelegramService;
use app\model\User;
use app\controller\telegram\GeneralController;
use app\controller\telegram\PaymentController;
use app\controller\telegram\WithdrawController;
use app\controller\telegram\InviteController;
use app\controller\telegram\GameController;
use app\controller\telegram\ServiceController;
use app\controller\telegram\ProfileController;
use app\controller\telegram\RedPacketController;
use think\facade\Log;

/**
 * Telegram 命令调度器 - 修复红包系统版本
 * 负责分发所有 Telegram 命令和回调到对应的控制器
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
    
    // =================== 主要处理方法 ===================
    
    /**
     * 处理命令
     */
    public function handleCommand(array $update, string $debugFile): void
    {
        try {
            $message = $update['message'] ?? [];
            $text = trim($message['text'] ?? '');
            $chatId = (int)($message['chat']['id'] ?? 0);
            
            if (empty($text) || $chatId === 0) {
                $this->log($debugFile, "❌ 命令数据不完整");
                return;
            }
            
            $this->log($debugFile, "收到命令: ChatID={$chatId}, 内容={$text}");
            
            // 确保用户存在
            $user = $this->ensureUserExists($update, $debugFile);
            if (!$user) {
                $this->log($debugFile, "❌ 用户处理失败");
                return;
            }
            
            // 分发命令
            $this->dispatchCommand($text, $chatId, $user, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理命令", $debugFile);
        }
    }
    
    /**
     * 处理回调查询
     */
    public function handleCallbackQuery(array $update, string $debugFile): void
    {
        try {
            $callbackQuery = $update['callback_query'] ?? [];
            $callbackData = trim($callbackQuery['data'] ?? '');
            $chatId = (int)($callbackQuery['message']['chat']['id'] ?? 0);
            $queryId = $callbackQuery['id'] ?? '';
            
            $this->log($debugFile, "收到回调: ChatID={$chatId}, 数据={$callbackData}");
            
            // 回调响应 - 使用父类的方法
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
    
    /**
     * 处理普通文本消息
     */
    public function handleTextMessage(array $update, string $debugFile): void
    {
        try {
            $message = $update['message'] ?? [];
            $text = trim($message['text'] ?? '');
            $chatId = (int)($message['chat']['id'] ?? 0);
            
            if (empty($text) || $chatId === 0) {
                $this->log($debugFile, "❌ 文本消息数据不完整");
                return;
            }
            
            $this->log($debugFile, "收到文本: ChatID={$chatId}, 内容={$text}");
            
            // 确保用户存在
            $user = $this->ensureUserExists($update, $debugFile);
            if (!$user) {
                $this->log($debugFile, "❌ 用户处理失败");
                return;
            }
            
            // 分发文本输入
            $this->dispatchTextInput($chatId, $text, $user, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理文本消息", $debugFile);
        }
    }
    
    // =================== 分发逻辑（修复版本） ===================
    
    /**
     * 分发命令
     */
    private function dispatchCommand(string $text, int $chatId, User $user, string $debugFile): void
    {
        $command = $this->parseCommand($text);
        $controllerClass = $this->getCommandController($command);
        
        $this->log($debugFile, "命令分发: {$command} -> " . ($controllerClass ?: 'null'));
        
        if ($controllerClass) {
            $this->forwardToController($controllerClass, 'handle', [
                $command, $chatId, $debugFile, $text
            ], $user, $debugFile);
        } else {
            $this->sendMessage($chatId, "❓ 未知命令，请使用 /help 查看帮助", $debugFile);
        }
    }
    
    /**
     * 分发回调
     */
    private function dispatchCallback(string $callbackData, int $chatId, User $user, string $debugFile): void
    {
        $controllerClass = $this->getCallbackController($callbackData);
        
        $this->log($debugFile, "回调分发: {$callbackData} -> " . ($controllerClass ?: 'null'));
        
        if ($controllerClass) {
            $this->forwardToController($controllerClass, 'handleCallback', [
                $callbackData, $chatId, $debugFile
            ], $user, $debugFile);
        } else {
            $this->sendMessage($chatId, "❌ 操作无效", $debugFile);
        }
    }
    
    /**
     * 🔧 修复：分发文本输入
     */
    private function dispatchTextInput(int $chatId, string $text, User $user, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $currentState = $userState['state'] ?? 'idle';
        
        $this->log($debugFile, "文本输入: 状态={$currentState}, 内容={$text}");
        
        // 🔧 修复1：红包命令优先处理（不受用户状态影响）
        if ($this->isRedPacketCommand($text)) {
            $this->log($debugFile, "✅ 检测到红包命令，优先处理");
            $this->forwardToController(RedPacketController::class, 'handle', [
                $this->parseCommand($text), $chatId, $debugFile, $text
            ], $user, $debugFile);
            return;
        }
        
        // 🔧 修复2：系统命令优先处理
        if ($this->isSystemCommand($text)) {
            $command = $this->parseCommand($text);
            $this->log($debugFile, "✅ 检测到系统命令: {$command}，优先处理");
            $this->dispatchCommand($text, $chatId, $user, $debugFile);
            return;
        }
        
        // 🔧 修复3：处理用户状态相关输入
        $controllerClass = $this->getStateController($currentState);
        
        if ($controllerClass) {
            $this->log($debugFile, "✅ 状态处理: {$currentState} -> {$controllerClass}");
            $this->forwardToController($controllerClass, 'handleTextInput', [
                $chatId, $text, $debugFile
            ], $user, $debugFile);
        } else {
            // 🔧 修复4：空闲状态的智能处理
            $this->handleIdleStateInput($chatId, $text, $user, $debugFile);
        }
    }
    
    // =================== 控制器映射（修复版本） ===================
    
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
            // 🔧 修复：完善红包命令映射
            'redpacket' => RedPacketController::class,
            'red' => RedPacketController::class,
            'hongbao' => RedPacketController::class,
            'hb' => RedPacketController::class,
        ];
        
        return $map[$command] ?? null;
    }
    
    /**
     * 🔧 修复：获取回调对应的控制器
     */
    private function getCallbackController(string $callbackData): ?string
    {
        // 红包相关回调 - 精确匹配
        $redPacketPatterns = [
            '/^(redpacket|grab_redpacket|refresh_redpacket|send_red_packet|confirm_send_redpacket|cancel_send_redpacket|red_packet_history|redpacket_detail)/',
            '/^(red_|hb_|hongbao_)/',
        ];
        
        foreach ($redPacketPatterns as $pattern) {
            if (preg_match($pattern, $callbackData)) {
                return RedPacketController::class;
            }
        }
        
        // 充值相关回调
        $rechargePatterns = [
            '/^(recharge|quick_amount|confirm_amount|copy_|transfer_complete|manual_amount|reenter_amount)/',
        ];
        
        foreach ($rechargePatterns as $pattern) {
            if (preg_match($pattern, $callbackData)) {
                return PaymentController::class;
            }
        }
        
        // 提现相关回调
        $withdrawPatterns = [
            '/^(withdraw|start_withdraw|set_withdraw_password|bind_usdt_address|confirm_withdraw)/',
        ];
        
        foreach ($withdrawPatterns as $pattern) {
            if (preg_match($pattern, $callbackData)) {
                return WithdrawController::class;
            }
        }
        
        // 邀请相关回调
        if (str_starts_with($callbackData, 'invite')) {
            return InviteController::class;
        }
        
        // 游戏相关回调
        if (str_starts_with($callbackData, 'game')) {
            return GameController::class;
        }
        
        // 通用回调
        $generalCallbacks = ['back_to_main', 'main_menu', 'help', 'balance', 'profile'];
        if (in_array($callbackData, $generalCallbacks)) {
            return GeneralController::class;
        }
        
        return null;
    }
    
    /**
     * 🔧 修复：完善状态控制器映射
     */
    private function getStateController(string $state): ?string
    {
        $stateMap = [
            // 充值相关状态
            'waiting_recharge_amount' => PaymentController::class,
            'waiting_payment' => PaymentController::class,
            'waiting_transfer_proof' => PaymentController::class,
            
            // 提现相关状态
            'setting_withdraw_password' => WithdrawController::class,
            'confirming_withdraw_password' => WithdrawController::class,
            'binding_usdt_address' => WithdrawController::class,
            'waiting_withdraw_amount' => WithdrawController::class,
            'confirming_withdraw' => WithdrawController::class,
            
            // 🔧 修复：红包相关状态
            'sending_redpacket' => RedPacketController::class,
            'waiting_redpacket_amount' => RedPacketController::class,
            'waiting_redpacket_count' => RedPacketController::class,
            'waiting_redpacket_title' => RedPacketController::class,
            'confirming_redpacket' => RedPacketController::class,
            
            // 其他状态
            'idle' => null,
        ];
        
        return $stateMap[$state] ?? null;
    }
    
    // =================== 辅助方法（新增和修复） ===================
    
    /**
     * 🆕 检查是否是系统命令
     */
    private function isSystemCommand(string $text): bool
    {
        $systemCommands = ['/start', '/help', '/menu', '/balance', '/profile'];
        $command = $this->parseCommand($text);
        
        foreach ($systemCommands as $sysCmd) {
            if (strtolower($command) === substr($sysCmd, 1)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 🔧 修复：增强红包命令识别
     */
    private function isRedPacketCommand(string $text): bool
    {
        $redPacketCommands = ['/red', '/hongbao', '/hb', 'red', 'hongbao', 'hb'];
        $text = trim($text);
        
        // 检查完整命令格式
        foreach ($redPacketCommands as $cmd) {
            if (stripos($text, $cmd) === 0) {
                // 进一步验证是否是有效的红包命令格式
                if ($this->validateRedPacketCommandFormat($text)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * 🆕 验证红包命令格式
     */
    private function validateRedPacketCommandFormat(string $text): bool
    {
        // 基础命令检查
        $basicPattern = '/^\/?(red|hongbao|hb)(\s|$)/i';
        if (!preg_match($basicPattern, $text)) {
            return false;
        }
        
        // 如果只是命令本身（没有参数），也认为是有效的红包命令
        $parts = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) === 1) {
            return true; // 只有命令，显示红包菜单
        }
        
        // 如果有参数，验证基本格式
        if (count($parts) >= 3) {
            $amount = $parts[1] ?? '';
            $count = $parts[2] ?? '';
            
            // 验证金额格式（支持小数）
            if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
                return false;
            }
            
            // 验证个数格式（必须是整数）
            if (!preg_match('/^\d+$/', $count)) {
                return false;
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * 🆕 处理空闲状态输入
     */
    private function handleIdleStateInput(int $chatId, string $text, User $user, string $debugFile): void
    {
        // 智能识别用户意图
        $text = trim($text);
        
        // 数字输入 - 可能是金额
        if (is_numeric($text)) {
            $this->log($debugFile, "检测到数字输入，可能是充值金额");
            $this->forwardToController(PaymentController::class, 'handleTextInput', [
                $chatId, $text, $debugFile
            ], $user, $debugFile);
            return;
        }
        
        // 包含USDT地址的输入
        if (preg_match('/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$|^0x[a-fA-F0-9]{40}$|^T[A-Za-z1-9]{33}$/', $text)) {
            $this->log($debugFile, "检测到USDT地址输入");
            $this->forwardToController(WithdrawController::class, 'handleTextInput', [
                $chatId, $text, $debugFile
            ], $user, $debugFile);
            return;
        }
        
        // 默认处理 - 显示帮助
        $this->forwardToController(GeneralController::class, 'handleTextInput', [
            $chatId, $text, $debugFile
        ], $user, $debugFile);
    }
    
    // =================== 用户管理 ===================
    
    /**
     * 确保用户存在
     */
    private function ensureUserExists(array $update, string $debugFile): ?User
    {
        try {
            $telegramUserData = $this->extractTelegramUserData($update);
            if (!$telegramUserData) {
                $this->log($debugFile, "❌ 无法提取Telegram用户数据");
                return null;
            }
            
            return $this->userService->createOrUpdateFromTelegram($telegramUserData, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "确保用户存在", $debugFile);
            return null;
        }
    }
    
    /**
     * 转发到控制器
     */
    private function forwardToController(string $controllerClass, string $method, array $params, User $user, string $debugFile): void
    {
        try {
            if (!class_exists($controllerClass)) {
                throw new \Exception("控制器类不存在: {$controllerClass}");
            }
            
            $controller = new $controllerClass();
            
            // 设置用户（如果控制器支持）
            if (method_exists($controller, 'setUser')) {
                $controller->setUser($user);
            }
            
            // 调用方法
            if (method_exists($controller, $method)) {
                call_user_func_array([$controller, $method], $params);
            } else {
                throw new \Exception("方法不存在: {$controllerClass}::{$method}");
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 控制器转发失败: " . $e->getMessage());
            $this->handleException($e, "控制器转发", $debugFile);
        }
    }
    
    // =================== 工具方法 ===================
    
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
     * 获取用户服务实例
     */
    protected function getUserService(): UserService
    {
        return $this->userService;
    }
}