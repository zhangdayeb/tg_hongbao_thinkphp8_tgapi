<?php
declare(strict_types=1);

namespace app\trait;

use app\model\TgCrowdList;

/**
 * 红包验证相关功能 Trait（约100行）
 * 职责：验证聊天类型权限、群组权限、用户权限等
 */
trait RedPacketValidationTrait
{
    /**
     * 验证聊天类型权限
     */
    protected function validateChatTypePermission(int $chatId, string $command, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        $config = config('redpacket.command_restrictions', []);
        
        $this->log($debugFile, "聊天类型验证 - ChatID: {$chatId}, Type: {$chatType}, Command: {$command}");
        
        // 私聊限制检查
        if ($chatType === 'private' && !($config['allow_in_private'] ?? false)) {
            $this->handlePrivateRedPacketAttempt($chatId, $command, $debugFile);
            return false;
        }
        
        // 群组权限检查
        if (in_array($chatType, ['group', 'supergroup']) && !($config['allow_in_groups'] ?? true)) {
            $this->sendMessage($chatId, "❌ 群组红包功能已禁用", $debugFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证群组红包权限
     */
    protected function validateGroupRedPacketPermission(int $chatId, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        
        // 私聊直接拒绝
        if ($chatType === 'private') {
            $this->handlePrivateRedPacketAttempt($chatId, 'red_packet_operation', $debugFile);
            return false;
        }
        
        // 群组权限检查
        if (in_array($chatType, ['group', 'supergroup'])) {
            return $this->validateGroupPermission($chatId, $debugFile);
        }
        
        return false;
    }
    
    /**
     * 验证群组权限（机器人管理员等）
     */
    protected function validateGroupPermission(int $chatId, string $debugFile): bool
    {
        try {
            $config = config('redpacket.command_restrictions', []);
            
            // 检查是否需要机器人管理员权限
            if ($config['require_bot_admin'] ?? true) {
                $group = TgCrowdList::where('crowd_id', (string)$chatId)
                                   ->where('is_active', 1)
                                   ->where('broadcast_enabled', 1)
                                   ->where('bot_status', 'administrator')
                                   ->where('del', 0)
                                   ->find();
                
                if (!$group) {
                    $this->log($debugFile, "❌ 群组权限验证失败 - ChatID: {$chatId}");
                    $this->sendGroupPermissionError($chatId, $debugFile);
                    return false;
                }
            }
            
            $this->log($debugFile, "✅ 群组权限验证通过 - ChatID: {$chatId}");
            return true;
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 群组权限验证异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 权限验证失败，请稍后重试", $debugFile);
            return false;
        }
    }
    
    /**
     * 验证群组操作权限（抢红包等）
     */
    protected function validateGroupOperation(int $chatId, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        
        // 群组操作允许在群组和私聊中进行（查看红包详情等）
        if (in_array($chatType, ['group', 'supergroup', 'private'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取聊天类型
     */
    protected function getChatType(int $chatId): string
    {
        // 优先使用设置的聊天上下文
        if ($this->chatContext && isset($this->chatContext['chat_type'])) {
            return $this->chatContext['chat_type'];
        }
        
        // 根据 chatId 判断类型
        if ($chatId > 0) {
            return 'private';
        } else {
            // 负数ID通常是群组，具体类型需要从数据库查询
            $group = TgCrowdList::where('crowd_id', (string)$chatId)->find();
            return $group ? 'group' : 'supergroup'; // 简化处理
        }
    }
    
    /**
     * 处理私聊红包尝试
     */
    protected function handlePrivateRedPacketAttempt(int $chatId, string $command, string $debugFile): void
    {
        $this->log($debugFile, "🚫 私聊红包尝试被拒绝 - Command: {$command}");
        
        $message = "❌ *无法在私聊中发送红包*\n\n" .
                  "🧧 *红包功能说明：*\n" .
                  "• 红包命令只能在群组中使用\n" .
                  "• 发送的红包仅在当前群组有效\n" .
                  "• 请在群组中发送 `/red 100 10` 命令\n\n" .
                  "💡 *可用功能：*\n" .
                  "• 查看红包记录\n" .
                  "• 查看红包统计\n" .
                  "• 设置红包偏好";
        
        $keyboard = [
            [
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 发送群组权限错误消息
     */
    protected function sendGroupPermissionError(int $chatId, string $debugFile): void
    {
        $message = "❌ *当前群组无法使用红包功能*\n\n" .
                  "🔍 *可能的原因：*\n" .
                  "• 机器人不是群组管理员\n" .
                  "• 群组未启用红包功能\n" .
                  "• 群组状态异常\n\n" .
                  "💡 *解决方法：*\n" .
                  "• 请联系群组管理员\n" .
                  "• 确保机器人具有管理员权限\n" .
                  "• 检查群组设置";
        
        $keyboard = [
            [
                ['text' => '🔄 重试', 'callback_data' => 'redpacket']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
}