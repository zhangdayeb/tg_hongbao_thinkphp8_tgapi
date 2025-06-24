<?php
declare(strict_types=1);

namespace app\handler;

use app\service\TelegramRedPacketService;
use app\model\User;
use app\controller\BaseTelegramController;

/**
 * 红包命令处理器 - 简化版本（移除群内发送逻辑）
 * 职责：专注命令解析和业务逻辑到数据库写入完成
 */
class RedPacketCommandHandler extends BaseTelegramController
{
    private TelegramRedPacketService $redPacketService;
    private ?User $currentUser = null;
    private ?array $chatContext = null;
    private $controllerBridge = null; // 控制器桥接引用
    
    public function __construct(TelegramRedPacketService $redPacketService)
    {
        parent::__construct();
        $this->redPacketService = $redPacketService;
    }
    
    /**
     * 设置当前用户
     */
    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
    
    /**
     * 设置聊天上下文
     */
    public function setChatContext(array $chatContext): void
    {
        $this->chatContext = $chatContext;
    }
    
    /**
     * 设置控制器桥接引用（避免循环依赖）
     */
    public function setControllerBridge($bridge): void
    {
        $this->controllerBridge = $bridge;
    }
    
    /**
     * 处理命令 - 统一入口
     */
    public function handle(string $command, int $chatId, string $debugFile, ?string $fullMessage = null): void
    {
        $this->log($debugFile, "🎯 RedPacketCommandHandler 处理命令: {$command}");
        $this->log($debugFile, "完整消息: " . ($fullMessage ?? 'null'));
        
        try {
            switch ($command) {
                case 'redpacket':
                    $this->handleRedPacketMenu($chatId, $debugFile);
                    break;
                    
                case 'red':
                case 'hongbao': 
                case 'hb':
                    $this->handleRedPacketSendCommand($chatId, $debugFile, $fullMessage);
                    break;
                    
                default:
                    $this->handleUnknownCommand($command, $chatId, $debugFile);
                    break;
            }
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 命令处理异常: " . $e->getMessage());
            $this->bridgeSendMessage($chatId, "❌ 命令处理失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 处理红包菜单命令
     */
    private function handleRedPacketMenu(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "📋 显示红包菜单");
        
        // 通过桥接方法调用控制器
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowRedPacketMenu')) {
            $this->controllerBridge->bridgeShowRedPacketMenu($chatId, $debugFile);
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 红包菜单暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理红包发送命令
     */
    private function handleRedPacketSendCommand(int $chatId, string $debugFile, ?string $fullMessage = null): void
    {
        $this->log($debugFile, "🧧 处理红包发送命令");
        
        if ($this->hasCompleteRedPacketParams($fullMessage, $debugFile)) {
            $this->log($debugFile, "✅ 检测到完整红包参数，直接创建红包");
            $this->handleCompleteRedPacketCommand($chatId, $fullMessage, $debugFile);
        } else {
            $this->log($debugFile, "📋 参数不完整，显示发红包指南");
            $this->showSendRedPacketGuide($chatId, $debugFile);
        }
    }
    
    /**
     * 检查是否有完整的红包参数
     */
    private function hasCompleteRedPacketParams(?string $message, string $debugFile): bool
    {
        if (empty($message)) {
            $this->log($debugFile, "原始消息为空");
            return false;
        }
        
        $pattern = '/^\/?(red|hb|hongbao)\s+(\d+(?:\.\d+)?)\s+(\d+)(?:\s+(.+))?/i';
        $hasParams = preg_match($pattern, trim($message), $matches);
        
        $this->log($debugFile, "参数检查 - 原始消息: '{$message}', 匹配结果: " . ($hasParams ? '是' : '否'));
        
        if ($hasParams) {
            $this->log($debugFile, "解析到参数 - 金额: {$matches[2]}, 个数: {$matches[3]}, 标题: " . ($matches[4] ?? '默认'));
        }
        
        return $hasParams > 0;
    }
    
    /**
     * 处理完整的红包命令
     */
    private function handleCompleteRedPacketCommand(int $chatId, string $message, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🎯 开始处理完整红包命令");
            
            $chatContext = [
                'chat_id' => $chatId,
                'chat_type' => $this->getChatType($chatId),
                'message_id' => 0,
            ];
            
            $parsed = $this->redPacketService->parseRedPacketCommand($message, $chatContext);
            
            if ($parsed && !isset($parsed['error'])) {
                $this->log($debugFile, "✅ 命令解析成功");
                
                // 验证用户权限
                $permission = $this->redPacketService->validateUserRedPacketPermission($this->currentUser, $parsed['amount']);
                if (!$permission['valid']) {
                    $this->bridgeSendMessage($chatId, "❌ " . $permission['message'], $debugFile);
                    return;
                }
                
                // 通过桥接方法创建红包（仅数据库操作）
                if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeCreateRedPacket')) {
                    $this->controllerBridge->bridgeCreateRedPacket($chatId, $parsed, $debugFile);
                } else {
                    $this->log($debugFile, "❌ 控制器桥接不可用");
                    $this->bridgeSendMessage($chatId, "❌ 红包功能暂时不可用", $debugFile);
                }
            } else {
                $this->log($debugFile, "❌ 命令解析失败");
                $errorMsg = $parsed['message'] ?? '命令格式错误';
                $this->bridgeSendMessage($chatId, "❌ " . $errorMsg, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 完整红包命令处理异常: " . $e->getMessage());
            $this->bridgeSendMessage($chatId, "❌ 红包创建失败：" . $e->getMessage(), $debugFile);
        }
    }
    
    /**
     * 显示发红包指南
     */
    private function showSendRedPacketGuide(int $chatId, string $debugFile): void
    {
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowSendRedPacketGuide')) {
            $this->controllerBridge->bridgeShowSendRedPacketGuide($chatId, $debugFile);
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 红包指南暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理红包命令（文本输入状态）
     */
    public function handleRedPacketCommand(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "🎯 处理红包命令文本: {$text}");
        
        // 解析红包命令
        $chatContext = $this->chatContext ?? ['chat_id' => $chatId];
        $parsed = $this->redPacketService->parseRedPacketCommand($text, $chatContext);
        
        if (!$parsed || isset($parsed['error'])) {
            $errorMsg = $parsed['message'] ?? '命令格式错误，请使用：/red <金额> <个数> [标题]';
            $this->bridgeSendMessage($chatId, "❌ " . $errorMsg, $debugFile);
            return;
        }
        
        // 验证用户权限
        $permission = $this->redPacketService->validateUserRedPacketPermission($this->currentUser, $parsed['amount']);
        if (!$permission['valid']) {
            $this->bridgeSendMessage($chatId, "❌ " . $permission['message'], $debugFile);
            return;
        }
        
        // 通过桥接方法创建红包（仅数据库操作）
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeCreateRedPacket')) {
            $success = $this->controllerBridge->bridgeCreateRedPacket($chatId, $parsed, $debugFile);
            if ($success) {
                // 清除用户状态
                $this->clearUserState($chatId);
            }
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 红包功能暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理红包金额输入
     */
    public function handleRedPacketAmount(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "💰 处理红包金额: {$text}");
        
        // 验证金额格式
        if (!is_numeric($text) || floatval($text) <= 0) {
            $this->bridgeSendMessage($chatId, "❌ 请输入有效的金额（大于0的数字）", $debugFile);
            return;
        }
        
        $amount = floatval($text);
        
        // 验证金额权限
        $permission = $this->redPacketService->validateUserRedPacketPermission($this->currentUser, $amount);
        if (!$permission['valid']) {
            $this->bridgeSendMessage($chatId, "❌ " . $permission['message'], $debugFile);
            return;
        }
        
        // 保存金额并转入下一状态
        $userState = $this->getUserState($chatId);
        $userState['data']['amount'] = $amount;
        $this->setUserState($chatId, 'waiting_red_packet_count', $userState['data']);
        
        $this->bridgeSendMessage($chatId, "✅ 金额已设置为 {$amount} USDT\n\n💡 请输入红包个数（1-100）：", $debugFile);
    }
    
    /**
     * 处理红包个数输入
     */
    public function handleRedPacketCount(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "📦 处理红包个数: {$text}");
        
        // 验证个数格式
        if (!ctype_digit($text) || intval($text) <= 0) {
            $this->bridgeSendMessage($chatId, "❌ 请输入有效的红包个数（大于0的整数）", $debugFile);
            return;
        }
        
        $count = intval($text);
        $config = config('redpacket.basic', []);
        $maxCount = $config['max_count'] ?? 100;
        
        if ($count > $maxCount) {
            $this->bridgeSendMessage($chatId, "❌ 红包个数不能超过 {$maxCount} 个", $debugFile);
            return;
        }
        
        // 保存个数并转入下一状态
        $userState = $this->getUserState($chatId);
        $userState['data']['count'] = $count;
        $this->setUserState($chatId, 'waiting_red_packet_title', $userState['data']);
        
        $this->bridgeSendMessage($chatId, "✅ 红包个数已设置为 {$count} 个\n\n💡 请输入红包标题（可选，直接回复'发送'跳过）：", $debugFile);
    }
    
    /**
     * 处理红包标题输入
     */
    public function handleRedPacketTitle(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "🏷️ 处理红包标题: {$text}");
        
        $userState = $this->getUserState($chatId);
        $amount = $userState['data']['amount'] ?? 0;
        $count = $userState['data']['count'] ?? 0;
        
        // 处理标题
        $title = '恭喜发财，大吉大利'; // 默认标题
        if (trim($text) !== '发送' && !empty(trim($text))) {
            $title = trim($text);
            
            // 限制标题长度
            if (mb_strlen($title) > 50) {
                $this->bridgeSendMessage($chatId, "❌ 红包标题不能超过50个字符", $debugFile);
                return;
            }
        }
        
        // 构建红包数据并创建（仅数据库操作）
        $parsed = [
            'amount' => $amount,
            'count' => $count,
            'title' => $title
        ];
        
        // 通过桥接方法创建红包
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeCreateRedPacket')) {
            $success = $this->controllerBridge->bridgeCreateRedPacket($chatId, $parsed, $debugFile);
            if ($success) {
                // 清除用户状态
                $this->clearUserState($chatId);
            }
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 红包功能暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理红包确认
     */
    public function handleRedPacketConfirmation(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "✅ 处理红包确认: {$text}");
        
        $userState = $this->getUserState($chatId);
        $redPacketData = $userState['data'] ?? [];
        
        if (strtolower(trim($text)) === 'y' || trim($text) === '是' || trim($text) === '确认') {
            // 确认发送（仅数据库操作）
            if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeCreateRedPacket')) {
                $success = $this->controllerBridge->bridgeCreateRedPacket($chatId, $redPacketData, $debugFile);
                if ($success) {
                    $this->clearUserState($chatId);
                }
            }
        } else {
            // 取消发送
            $this->clearUserState($chatId);
            $this->bridgeSendMessage($chatId, "❌ 红包发送已取消", $debugFile);
        }
    }
    
    /**
     * 处理未知命令
     */
    private function handleUnknownCommand(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "❌ 未知红包命令: {$command}");
        $this->bridgeSendMessage($chatId, "❓ 未知的红包命令，请使用 /red 命令发送红包", $debugFile);
    }
    
    // =================== 辅助方法 ===================
    
    /**
     * 获取聊天类型
     */
    private function getChatType(int $chatId): string
    {
        if ($this->chatContext && isset($this->chatContext['chat_type'])) {
            return $this->chatContext['chat_type'];
        }
        
        return $chatId > 0 ? 'private' : 'group';
    }
    
    /**
     * 桥接发送消息方法
     */
    private function bridgeSendMessage(int $chatId, string $message, string $debugFile): void
    {
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeSendMessage')) {
            $this->controllerBridge->bridgeSendMessage($chatId, $message, $debugFile);
        } else {
            // 兜底方案：直接发送
            $this->sendMessage($chatId, $message, $debugFile);
        }
    }
}