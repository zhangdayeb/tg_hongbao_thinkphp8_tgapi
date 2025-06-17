<?php
declare(strict_types=1);

namespace app\handler;

use app\controller\BaseTelegramController;
use app\model\User;

/**
 * 红包消息发送器 - 修复方法签名版本
 * 职责：统一消息发送接口，主要负责私聊通知，不负责群内红包消息发送
 */
class RedPacketMessageSender extends BaseTelegramController
{
    private ?User $currentUser = null;
    private int $maxRetries = 3; // 最大重试次数
    private int $retryDelay = 1; // 重试延迟（秒）
    
    public function __construct()
    {
        parent::__construct();
    }
    
    /**
     * 设置当前用户
     */
    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
    
    /**
     * 发送简单文本消息
     */
    public function send(int $chatId, string $message, string $debugFile): ?array
    {
        return $this->sendWithRetry($chatId, $message, null, $debugFile);
    }
    
    /**
     * 发送带键盘的消息
     */
    public function sendWithKeyboard(int $chatId, string $message, array $keyboard, string $debugFile): ?array
    {
        return $this->sendWithRetry($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 编辑消息 - 修复方法签名以匹配父类
     */
    public function editMessage(int $chatId, int $messageId, string $text, ?array $keyboard = null, string $debugFile = 'telegram_debug'): bool
    {
        return $this->editWithRetry($chatId, $messageId, $text, $keyboard, $debugFile);
    }
    
    /**
     * 发送抢红包成功通知到私聊
     */
    public function sendGrabSuccessNotification(array $grabResult, string $debugFile): void
    {
        if (!$this->currentUser) {
            $this->log($debugFile, "❌ 用户对象未设置，无法发送通知");
            return;
        }
        
        try {
            $amount = $grabResult['amount'] ?? 0;
            $grabOrder = $grabResult['grab_order'] ?? 0;
            $isCompleted = $grabResult['is_completed'] ?? false;
            $isBestLuck = $grabResult['is_best_luck'] ?? false;
            
            // 刷新用户余额
            $this->currentUser->refresh();
            
            $message = "🎉 *恭喜抢到红包！*\n\n" .
                      "💰 金额：`{$amount} USDT`\n" .
                      "🏆 第 {$grabOrder} 个抢到\n";
            
            if ($isBestLuck) {
                $message .= "👑 *手气最佳！*\n";
            }
            
            $message .= "💎 当前余额：`{$this->currentUser->money_balance} USDT`\n\n";
            
            if ($isCompleted) {
                $message .= "🎊 红包已被抢完！\n";
            }
            
            $message .= "💡 红包金额已自动加入您的余额";
            
            $keyboard = [
                [
                    ['text' => '💰 查看余额', 'callback_data' => 'profile'],
                    ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet']
                ],
                [
                    ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
            
            // 发送到用户私聊
            $this->sendWithKeyboard($this->currentUser->user_id, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送抢红包成功通知失败: " . $e->getMessage());
        }
    }
    
    /**
     * 发送错误通知
     */
    public function sendErrorNotification(int $chatId, string $errorMessage, string $debugFile): void
    {
        $message = "❌ {$errorMessage}";
        $this->send($chatId, $message, $debugFile);
    }
    
    /**
     * 发送操作成功通知
     */
    public function sendSuccessNotification(int $chatId, string $successMessage, string $debugFile): void
    {
        $message = "✅ {$successMessage}";
        $this->send($chatId, $message, $debugFile);
    }
    
    /**
     * 发送临时消息（会自动删除）
     */
    public function sendTemporaryMessage(int $chatId, string $message, string $debugFile, int $deleteAfter = 5): ?array
    {
        $result = $this->send($chatId, $message, $debugFile);
        
        if ($result && isset($result['message_id'])) {
            // 设置定时删除
            $this->scheduleMessageDeletion($chatId, $result['message_id'], $deleteAfter, $debugFile);
        }
        
        return $result;
    }
    
    /**
     * 发送等待状态消息
     */
    public function sendWaitingMessage(int $chatId, string $operation, string $debugFile): ?array
    {
        $message = "⏳ {$operation}处理中，请稍候...";
        return $this->send($chatId, $message, $debugFile);
    }
    
    /**
     * 发送操作完成提醒
     */
    public function sendOperationCompleteMessage(int $chatId, string $operation, string $details, string $debugFile): ?array
    {
        $message = "✅ {$operation}完成！\n\n{$details}";
        return $this->send($chatId, $message, $debugFile);
    }
    
    /**
     * 发送用户状态提醒
     */
    public function sendUserStatusMessage(int $chatId, string $status, string $instructions, string $debugFile): ?array
    {
        $message = "📝 *{$status}*\n\n{$instructions}";
        return $this->send($chatId, $message, $debugFile);
    }
    
    /**
     * 批量发送私聊通知
     */
    public function sendBulkPrivateNotifications(array $notifications, string $debugFile): array
    {
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($notifications as $index => $notification) {
            $userId = $notification['user_id'];
            $message = $notification['message'];
            $keyboard = $notification['keyboard'] ?? null;
            
            try {
                $this->log($debugFile, "📤 批量通知 " . ($index + 1) . "/" . count($notifications) . " to UserID: {$userId}");
                
                if ($keyboard) {
                    $result = $this->sendWithKeyboard($userId, $message, $keyboard, $debugFile);
                } else {
                    $result = $this->send($userId, $message, $debugFile);
                }
                
                if ($result) {
                    $successCount++;
                    $results[$index] = ['success' => true, 'result' => $result];
                } else {
                    $failCount++;
                    $results[$index] = ['success' => false, 'error' => '发送失败'];
                }
                
                // 防止发送过快，添加小延迟
                if ($index < count($notifications) - 1) {
                    usleep(300000); // 300ms延迟
                }
                
            } catch (\Exception $e) {
                $failCount++;
                $results[$index] = ['success' => false, 'error' => $e->getMessage()];
                $this->log($debugFile, "❌ 批量通知发送失败: " . $e->getMessage());
            }
        }
        
        $this->log($debugFile, "📊 批量通知完成 - 成功: {$successCount}, 失败: {$failCount}");
        
        return [
            'total' => count($notifications),
            'success' => $successCount,
            'failed' => $failCount,
            'results' => $results
        ];
    }
    
    /**
     * 发送分页消息
     */
    public function sendPaginatedMessage(int $chatId, array $items, int $page, int $perPage, string $title, string $callbackPrefix, string $debugFile): ?array
    {
        $total = count($items);
        $totalPages = ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));
        
        $offset = ($page - 1) * $perPage;
        $pageItems = array_slice($items, $offset, $perPage);
        
        $message = "*{$title}*\n\n";
        
        foreach ($pageItems as $index => $item) {
            $itemNumber = $offset + $index + 1;
            $message .= "{$itemNumber}. {$item}\n";
        }
        
        $message .= "\n📄 第 {$page}/{$totalPages} 页 (共 {$total} 条)";
        
        // 构建分页键盘
        $keyboard = [];
        
        if ($totalPages > 1) {
            $paginationRow = [];
            
            if ($page > 1) {
                $paginationRow[] = ['text' => '⬅️ 上一页', 'callback_data' => "{$callbackPrefix}_page_" . ($page - 1)];
            }
            
            $paginationRow[] = ['text' => "{$page}/{$totalPages}", 'callback_data' => 'noop'];
            
            if ($page < $totalPages) {
                $paginationRow[] = ['text' => '下一页 ➡️', 'callback_data' => "{$callbackPrefix}_page_" . ($page + 1)];
            }
            
            $keyboard[] = $paginationRow;
        }
        
        // 添加返回按钮
        $keyboard[] = [
            ['text' => '🔙 返回', 'callback_data' => 'back']
        ];
        
        return $this->sendWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    // =================== 内部方法（重试机制）===================
    
    /**
     * 带重试机制的消息发送
     */
    private function sendWithRetry(int $chatId, string $message, ?array $keyboard, string $debugFile): ?array
    {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                $attempt++;
                $this->log($debugFile, "📤 尝试发送消息 (第{$attempt}次): ChatID={$chatId}");
                
                if ($keyboard) {
                    $result = $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
                } else {
                    $result = $this->sendMessage($chatId, $message, $debugFile);
                }
                
                if ($result) {
                    $this->log($debugFile, "✅ 消息发送成功");
                    return $result;
                }
                
                throw new \Exception("发送失败：无返回结果");
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $this->log($debugFile, "❌ 第{$attempt}次发送失败: {$lastError}");
                
                if ($attempt < $this->maxRetries) {
                    $this->log($debugFile, "⏳ {$this->retryDelay}秒后重试...");
                    sleep($this->retryDelay);
                    
                    // 递增重试延迟，避免频繁重试
                    $this->retryDelay = min($this->retryDelay * 2, 10);
                } else {
                    $this->log($debugFile, "❌ 达到最大重试次数，发送失败");
                }
            }
        }
        
        // 记录最终失败
        $this->log($debugFile, "❌ 消息发送最终失败: {$lastError}");
        
        // 重置重试延迟
        $this->retryDelay = 1;
        
        return null;
    }
    
    /**
     * 带重试机制的消息编辑
     */
    private function editWithRetry(int $chatId, int $messageId, string $text, ?array $keyboard, string $debugFile): bool
    {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                $attempt++;
                $this->log($debugFile, "✏️ 尝试编辑消息 (第{$attempt}次): ChatID={$chatId}, MessageID={$messageId}");
                
                $result = $this->updateMessage($chatId, $messageId, $text, $keyboard, $debugFile);
                
                if ($result) {
                    $this->log($debugFile, "✅ 消息编辑成功");
                    return true;
                }
                
                throw new \Exception("编辑失败：无返回结果");
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $this->log($debugFile, "❌ 第{$attempt}次编辑失败: {$lastError}");
                
                if ($attempt < $this->maxRetries) {
                    $this->log($debugFile, "⏳ {$this->retryDelay}秒后重试...");
                    sleep($this->retryDelay);
                } else {
                    $this->log($debugFile, "❌ 达到最大重试次数，编辑失败");
                }
            }
        }
        
        // 记录最终失败
        $this->log($debugFile, "❌ 消息编辑最终失败: {$lastError}");
        return false;
    }
    
    /**
     * 安排消息删除
     */
    private function scheduleMessageDeletion(int $chatId, int $messageId, int $deleteAfter, string $debugFile): void
    {
        try {
            $this->log($debugFile, "⏰ 安排删除消息: ChatID={$chatId}, MessageID={$messageId}, 延迟={$deleteAfter}秒");
            
            // 这里可以使用队列、定时任务或其他方式实现延迟删除
            // 示例：使用think-queue或其他队列系统
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 安排删除消息失败: " . $e->getMessage());
        }
    }
    
    // =================== 配置方法 ===================
    
    /**
     * 设置最大重试次数
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = max(1, $maxRetries);
    }
    
    /**
     * 设置重试延迟
     */
    public function setRetryDelay(int $delay): void
    {
        $this->retryDelay = max(0, $delay);
    }
    
    /**
     * 获取发送器状态信息
     */
    public function getStatus(): array
    {
        return [
            'max_retries' => $this->maxRetries,
            'retry_delay' => $this->retryDelay,
            'user_set' => $this->currentUser !== null,
            'user_id' => $this->currentUser ? $this->currentUser->id : null,
            'user_tg_id' => $this->currentUser ? $this->currentUser->user_id : null
        ];
    }
    
    /**
     * 重置发送器状态
     */
    public function reset(): void
    {
        $this->retryDelay = 1;
        $this->log('system', "🔄 MessageSender 状态已重置");
    }
}