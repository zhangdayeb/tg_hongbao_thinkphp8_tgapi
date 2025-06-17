<?php
declare(strict_types=1);

namespace app\handler;

use app\service\TelegramRedPacketService;
use app\model\User;
use app\controller\BaseTelegramController;

/**
 * 红包回调处理器 - 简化版本（移除群内发送逻辑）
 * 职责：专注回调业务逻辑，不负责群内消息发送
 */
class RedPacketCallbackHandler extends BaseTelegramController
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
     * 处理回调 - 统一入口
     */
    public function handle(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "🎯 RedPacketCallbackHandler 处理回调: {$callbackData}");
        
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
            
            // 处理刷新红包回调（注意：这个可能需要群内消息更新）
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
            // 对于回调异常，通常不显示错误消息，避免打扰用户
        }
    }
    
    /**
     * 处理抢红包回调 - 增加错误处理和用户反馈
     */
    private function handleGrabRedPacket(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            $packetId = str_replace('grab_redpacket_', '', $callbackData);
            $this->log($debugFile, "🎁 处理抢红包: {$packetId}");
            
            // 🔥 修复：立即回应用户操作
            $this->answerCallbackQuery("正在处理中...");
            
            // 通过桥接方法抢红包
            if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeGrabRedPacket')) {
                $this->controllerBridge->bridgeGrabRedPacket($packetId, $chatId, $debugFile);
            } else {
                $this->log($debugFile, "❌ 控制器桥接不可用");
                $this->bridgeSendMessage($chatId, "❌ 系统异常，请稍后重试", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 抢红包处理异常: " . $e->getMessage());
            $this->answerCallbackQuery("❌ 操作失败，请重试");
            $this->bridgeSendMessage($chatId, "❌ 系统异常，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 回应回调查询
     */
    private function answerCallbackQuery(string $text = '', bool $showAlert = false): void
    {
        try {
            $data = [
                'callback_query_id' => $this->callbackQueryId,
                'text' => $text,
                'show_alert' => $showAlert
            ];
            
            // 发送回应
            $this->sendApiRequest('answerCallbackQuery', $data);
            
        } catch (\Exception $e) {
            // 静默处理回应失败
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
            
            $redPacket = $this->redPacketService->getRedPacketDetail($packetId);
            if ($redPacket) {
                $this->sendRedPacketDetailMessage($chatId, $redPacket, $debugFile);
            } else {
                $this->bridgeSendMessage($chatId, "❌ 红包不存在或已过期", $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 红包详情处理异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理刷新红包回调（注意：这里不做群内消息更新）
     */
    private function handleRefreshRedPacket(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            $packetId = str_replace('refresh_redpacket_', '', $callbackData);
            $this->log($debugFile, "🔄 刷新红包请求: {$packetId}");
            
            // 由于不再负责群内消息更新，这里只给用户一个提示
            $this->bridgeSendMessage($chatId, "🔄 红包状态刷新请求已提交，请稍等片刻...", $debugFile);
            
            // 可以在这里触发一个信号给统一发送系统，让其更新群内消息
            // 比如：写入缓存、发送队列消息等
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 刷新红包异常: " . $e->getMessage());
        }
    }
    
    /**
     * 处理红包菜单回调
     */
    private function handleRedPacketMenu(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "📋 显示红包菜单");
        
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowRedPacketMenu')) {
            $this->controllerBridge->bridgeShowRedPacketMenu($chatId, $debugFile);
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 红包菜单暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理发红包回调
     */
    private function handleSendRedPacket(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "🧧 处理发红包请求");
        
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowSendRedPacketGuide')) {
            $this->controllerBridge->bridgeShowSendRedPacketGuide($chatId, $debugFile);
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 发红包功能暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理红包历史回调
     */
    private function handleRedPacketHistory(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "📊 显示红包历史");
        
        if ($this->controllerBridge && method_exists($this->controllerBridge, 'bridgeShowRedPacketHistory')) {
            $this->controllerBridge->bridgeShowRedPacketHistory($chatId, $debugFile);
        } else {
            $this->log($debugFile, "❌ 控制器桥接不可用");
            $this->bridgeSendMessage($chatId, "❌ 红包历史暂时不可用", $debugFile);
        }
    }
    
    /**
     * 处理确认发送红包回调
     */
    private function handleConfirmSendRedPacket(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "✅ 确认发送红包");
            
            // 获取用户状态中的红包数据
            $userState = $this->getUserState($chatId);
            $redPacketData = $userState['data']['redpacket_data'] ?? null;
            
            if ($redPacketData && $this->controllerBridge && method_exists($this->controllerBridge, 'bridgeCreateRedPacket')) {
                $success = $this->controllerBridge->bridgeCreateRedPacket($chatId, $redPacketData, $debugFile);
                if ($success) {
                    $this->clearUserState($chatId);
                }
            } else {
                $this->bridgeSendMessage($chatId, "❌ 红包数据丢失，请重新开始", $debugFile);
                $this->clearUserState($chatId);
            }
            
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
            $this->log($debugFile, "❌ 取消发送红包");
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
        // 对于未知回调，通常不发送消息，避免打扰用户
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
    
    // =================== 辅助方法 ===================
    
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