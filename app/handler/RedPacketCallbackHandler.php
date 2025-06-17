<?php
declare(strict_types=1);

namespace app\handler;

use app\service\TelegramRedPacketService;
use app\model\User;
use app\controller\BaseTelegramController;

/**
 * 红包回调处理器 - 完整修复版本
 * 修复：使用基类的 safeAnswerCallbackQuery 方法，正确传递 callbackQueryId
 */
class RedPacketCallbackHandler extends BaseTelegramController
{
    private TelegramRedPacketService $redPacketService;
    private ?User $currentUser = null;
    private ?array $chatContext = null;
    private $controllerBridge = null;
    private ?string $currentCallbackQueryId = null; // 🔥 新增：存储当前回调查询ID
    
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
     * 设置控制器桥接引用
     */
    public function setControllerBridge($bridge): void
    {
        $this->controllerBridge = $bridge;
    }
    
    /**
     * 🔥 新增：设置回调查询ID
     */
    public function setCallbackQueryId(string $callbackQueryId): void
    {
        $this->currentCallbackQueryId = $callbackQueryId;
    }
    
    /**
     * 处理回调 - 统一入口（增加 callbackQueryId 参数）
     */
    public function handle(string $callbackData, int $chatId, string $debugFile, ?string $callbackQueryId = null): void
    {
        $this->log($debugFile, "🎯 RedPacketCallbackHandler 处理回调: {$callbackData}");
        
        // 🔥 修复：设置回调查询ID
        if ($callbackQueryId) {
            $this->setCallbackQueryId($callbackQueryId);
        }
        
        try {
            // 处理抢红包回调
            if (strpos($callbackData, 'grab_redpacket_') === 0) {
                $this->handleGrabRedPacket($callbackData, $chatId, $debugFile);
                return;
            }
            
            // 处理红包详情回调
            if (strpos($callbackData, 'redpacket_detail_') === 0) {
                $this->handleRedPacketDetail($callbackData, $chatId, $debugFile);
                return;
            }
            
            // 处理刷新红包回调
            if (strpos($callbackData, 'refresh_redpacket_') === 0) {
                $this->handleRefreshRedPacket($callbackData, $chatId, $debugFile);
                return;
            }
            
            // 处理常规回调
            switch ($callbackData) {
                case 'redpacket':
                    $this->handleRedPacketMenu($chatId, $debugFile);
                    break;
                    
                case 'send_red_packet':
                    $this->handleSendRedPacket($chatId, $debugFile);
                    break;
                    
                case 'red_packet_history':
                    $this->handleRedPacketHistory($chatId, $debugFile);
                    break;
                    
                case 'confirm_send_redpacket':
                    $this->handleConfirmSendRedPacket($chatId, $debugFile);
                    break;
                    
                case 'cancel_send_redpacket':
                    $this->handleCancelSendRedPacket($chatId, $debugFile);
                    break;
                    
                default:
                    $this->handleUnknownCallback($callbackData, $chatId, $debugFile);
                    break;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 回调处理异常: " . $e->getMessage());
            // 尝试回应用户
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "❌ 操作失败，请重试", $debugFile);
            }
        }
    }
    
    /**
     * 处理抢红包回调 - 修复版本
     */
    private function handleGrabRedPacket(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            $packetId = str_replace('grab_redpacket_', '', $callbackData);
            $this->log($debugFile, "🎁 处理抢红包: {$packetId}");
            
            // 🔥 修复：使用基类的方法立即回应用户操作
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "正在处理中...", $debugFile);
            }
            
            // 通过桥接方法抢红包
            if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeGrabRedPacket')) {
                $this->controllerBridge->bridgeGrabRedPacket($packetId, $chatId, $debugFile);
            } else {
                $this->log($debugFile, "❌ 控制器桥接不可用");
                $this->bridgeSendMessage($chatId, "❌ 系统异常，请稍后重试", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 抢红包处理异常: " . $e->getMessage());
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "❌ 操作失败，请重试", $debugFile);
            }
            $this->bridgeSendMessage($chatId, "❌ 系统异常，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 处理红包详情回调
     */
    private function handleRedPacketDetail(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            $packetId = str_replace('redpacket_detail_', '', $callbackData);
            $this->log($debugFile, "📊 显示红包详情: {$packetId}");
            
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "加载详情中...", $debugFile);
            }
            
            $redPacket = $this->redPacketService->getRedPacketDetail($packetId);
            if ($redPacket) {
                $this->sendRedPacketDetailMessage($chatId, $redPacket, $debugFile);
            } else {
                $this->bridgeSendMessage($chatId, "❌ 红包不存在或已过期", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 红包详情处理异常: " . $e->getMessage());
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "❌ 加载失败", $debugFile);
            }
        }
    }
    
    /**
     * 处理刷新红包回调
     */
    private function handleRefreshRedPacket(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            $packetId = str_replace('refresh_redpacket_', '', $callbackData);
            $this->log($debugFile, "🔄 刷新红包请求: {$packetId}");
            
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "刷新中...", $debugFile);
            }
            $this->bridgeSendMessage($chatId, "🔄 红包状态刷新请求已提交，请稍等片刻...", $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 刷新红包异常: " . $e->getMessage());
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "❌ 刷新失败", $debugFile);
            }
        }
    }
    
    /**
     * 处理红包菜单回调
     */
    private function handleRedPacketMenu(int $chatId, string $debugFile): void
    {
        try {
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "", $debugFile);
            }
            
            if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowRedPacketMenu')) {
                $this->controllerBridge->bridgeShowRedPacketMenu($chatId, $debugFile);
            } else {
                $this->bridgeSendMessage($chatId, "❌ 菜单加载失败", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 红包菜单处理异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理发送红包回调
     */
    private function handleSendRedPacket(int $chatId, string $debugFile): void
    {
        try {
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "", $debugFile);
            }
            
            if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowSendRedPacketGuide')) {
                $this->controllerBridge->bridgeShowSendRedPacketGuide($chatId, $debugFile);
            } else {
                $this->bridgeSendMessage($chatId, "❌ 指南加载失败", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送红包指南处理异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理红包历史回调
     */
    private function handleRedPacketHistory(int $chatId, string $debugFile): void
    {
        try {
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "", $debugFile);
            }
            
            if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowRedPacketHistory')) {
                $this->controllerBridge->bridgeShowRedPacketHistory($chatId, $debugFile);
            } else {
                $this->bridgeSendMessage($chatId, "❌ 历史记录加载失败", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 红包历史处理异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理确认发送红包回调
     */
    private function handleConfirmSendRedPacket(int $chatId, string $debugFile): void
    {
        try {
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "确认中...", $debugFile);
            }
            
            // 这里应该调用红包发送逻辑
            $this->log($debugFile, "✅ 确认发送红包");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 确认发送红包异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理取消发送红包回调
     */
    private function handleCancelSendRedPacket(int $chatId, string $debugFile): void
    {
        try {
            if ($this->currentCallbackQueryId) {
                $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "已取消", $debugFile);
            }
            $this->clearUserState($chatId);
            $this->bridgeSendMessage($chatId, "❌ 红包发送已取消", $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 取消发送红包异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理未知回调
     */
    private function handleUnknownCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "❌ 未知红包回调: {$callbackData}");
        if ($this->currentCallbackQueryId) {
            $this->safeAnswerCallbackQuery($this->currentCallbackQueryId, "未知操作", $debugFile);
        }
    }
    
    // =================== 专用消息构建方法 ===================
    
    /**
     * 发送红包详情消息
     */
    private function sendRedPacketDetailMessage(int $chatId, array $redPacket, string $debugFile): void
    {
        try {
            $message = $this->buildRedPacketDetailMessage($redPacket);
            $keyboard = $this->buildRedPacketDetailKeyboard($redPacket['packet_id']);
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送红包详情消息异常: " . $e->getMessage());
            $this->bridgeSendMessage($chatId, "❌ 红包详情加载失败", $debugFile);
        }
    }
    
    /**
     * 构建红包详情消息
     */
    private function buildRedPacketDetailMessage(array $redPacket): string
    {
        $status = $redPacket['status'] ?? 'active';
        $statusText = $status === 'completed' ? '已领完' : '进行中';
        $senderName = $redPacket['sender_name'] ?? '未知用户';
        
        $message = "🧧 *红包详情*\n\n" .
                   "💰 总金额：{$redPacket['total_amount']} USDT\n" .
                   "📦 总个数：{$redPacket['total_count']} 个\n" .
                   "🎯 状态：{$statusText}\n" .
                   "📅 创建时间：" . date('Y-m-d H:i:s', strtotime($redPacket['created_at'])) . "\n" .
                   "👤 发送者：{$senderName}\n" .
                   "🎊 红包标题：{$redPacket['title']}\n\n" .
                   "📊 *抢包情况*\n" .
                   "已领取：{$redPacket['grabbed_count']}/{$redPacket['total_count']} 个\n" .
                   "已领金额：{$redPacket['grabbed_amount']} USDT\n" .
                   "剩余金额：" . ($redPacket['total_amount'] - $redPacket['grabbed_amount']) . " USDT";
        
        // 如果有抢包记录，显示前几名
        if (!empty($redPacket['grab_records'])) {
            $message .= "\n\n🏆 *抢包排行*\n";
            foreach (array_slice($redPacket['grab_records'], 0, 5) as $index => $record) {
                $userName = $record['user_name'] ?? '匿名用户';
                $amount = $record['amount'];
                $order = $index + 1;
                $medal = $order === 1 ? '🥇' : ($order === 2 ? '🥈' : ($order === 3 ? '🥉' : '🏅'));
                $time = isset($record['created_at']) ? date('H:i', strtotime($record['created_at'])) : '';
                $best = isset($record['is_best']) && $record['is_best'] ? ' 👑' : '';
                $message .= "{$medal} {$userName}: {$amount} USDT{$best} ({$time})\n";
            }
            
            if (count($redPacket['grab_records']) > 5) {
                $remaining = count($redPacket['grab_records']) - 5;
                $message .= "... 还有 {$remaining} 条记录\n";
            }
        }
        
        return $message;
    }
    
    /**
     * 构建红包详情键盘
     */
    private function buildRedPacketDetailKeyboard(string $packetId): array
    {
        return [
            [
                ['text' => '🎁 抢红包', 'callback_data' => "grab_redpacket_{$packetId}"],
                ['text' => '🔄 刷新', 'callback_data' => "refresh_redpacket_{$packetId}"]
            ],
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
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