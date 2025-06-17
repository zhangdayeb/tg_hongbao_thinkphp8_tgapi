<?php
declare(strict_types=1);

namespace app\trait;

use app\model\RedPacket;

/**
 * 红包消息处理相关功能 Trait - 完整版本
 * 职责：构建各种红包相关的消息内容和键盘布局
 */
trait RedPacketMessageTrait
{
    /**
     * 构建红包主菜单消息
     */
    protected function buildRedPacketMenuMessage(): string
    {
        $stats = $this->getUserRedPacketStats();
        $chatType = $this->getChatType($this->chatContext['chat_id'] ?? 0);
        
        $message = "🧧 *红包功能*\n\n" .
                  "📊 *我的红包统计*\n" .
                  "├ 发送红包：{$stats['sent_count']} 个\n" .
                  "├ 发送金额：{$stats['sent_amount']} USDT\n" .
                  "├ 抢到红包：{$stats['received_count']} 个\n" .
                  "├ 抢到金额：{$stats['received_amount']} USDT\n" .
                  "└ 手气最佳：{$stats['best_luck_count']} 次\n\n" .
                  "💰 当前余额：`{$this->currentUser->money_balance} USDT`\n\n";
        
        // 根据聊天类型显示不同的提示
        if ($chatType === 'private') {
            $message .= "💡 *使用说明：*\n" .
                       "• 红包发送需要在群组中进行\n" .
                       "• 可在此查看红包记录和统计\n\n";
        } else {
            $message .= "🎯 当前群组可以发送红包\n\n";
        }
        
        $message .= "🎯 选择操作：";
        
        return $message;
    }
    
    /**
     * 构建红包主菜单键盘
     */
    protected function buildRedPacketMenuKeyboard(int $chatId): array
    {
        $chatType = $this->getChatType($chatId);
        $keyboard = [];
        
        // 根据聊天类型显示不同的按钮
        if ($chatType === 'private') {
            $keyboard[] = [
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ];
        } else {
            $keyboard[] = [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ];
        }
        
        $keyboard[] = [
            ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
        ];
        
        return $keyboard;
    }
    
    /**
     * 构建发红包指南消息
     */
    protected function buildSendRedPacketGuideMessage(): string
    {
        $balance = $this->currentUser->money_balance;
        $config = config('redpacket.basic', []);
        $minAmount = $config['min_amount'] ?? 1.00;
        $maxAmount = $config['max_amount'] ?? 10000.00;
        $minCount = $config['min_count'] ?? 1;
        $maxCount = $config['max_count'] ?? 100;
        $chatType = $this->getChatType($this->chatContext['chat_id'] ?? 0);
        
        $message = "🧧 *发红包指南*\n\n" .
                  "💰 当前余额：`{$balance} USDT`\n";
        
        // 根据聊天类型显示不同提示
        if ($chatType !== 'private') {
            $message .= "🎯 发送范围：仅当前群组\n";
        }
        
        $message .= "\n📝 *命令格式：*\n" .
                   "`/red <金额> <个数> [标题]`\n\n" .
                   "🌰 *使用示例：*\n" .
                   "• `/red 100 10` - 100USDT分10个\n" .
                   "• `/red 50 5 恭喜发财` - 带标题\n" .
                   "• `/hongbao 20 3 新年快乐`\n\n" .
                   "⚠️ *限制说明：*\n" .
                   "• 金额范围：{$minAmount} - {$maxAmount} USDT\n" .
                   "• 红包个数：{$minCount} - {$maxCount} 个\n" .
                   "• 仅限群组内发送\n\n" .
                   "💡 发送后群友可点击按钮抢红包";
        
        return $message;
    }
    
    /**
     * 构建发红包指南键盘
     */
    protected function buildSendRedPacketGuideKeyboard(): array
    {
        return [
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
    }
    
    /**
     * 构建红包消息（群组中显示的红包）
     */
    protected function buildRedPacketMessage(array $redPacket): string
    {
        $title = $redPacket['title'] ?? '恭喜发财，大吉大利';
        $status = $redPacket['status'] ?? 'active';
        $senderName = $redPacket['sender_name'] ?? '未知用户';
        
        $message = "🧧 *{$title}*\n\n";
        
        if ($status === 'completed') {
            $message .= "🎉 *红包已领完*\n";
        } else {
            $message .= "💰 总金额：{$redPacket['total_amount']} USDT\n";
            $message .= "📦 总个数：{$redPacket['total_count']} 个\n";
        }
        
        $message .= "📊 已领取：{$redPacket['grabbed_count']}/{$redPacket['total_count']}\n";
        $message .= "👤 发送者：{$senderName}\n\n";
        
        if ($status !== 'completed') {
            $message .= "💡 点击下方按钮抢红包";
        } else {
            $message .= "🎊 感谢参与！";
        }
        
        return $message;
    }
    
    /**
     * 构建红包键盘（群组中显示的红包按钮）
     */
    protected function buildRedPacketKeyboard(array $redPacket): array
    {
        $packetId = $redPacket['packet_id'];
        $status = $redPacket['status'] ?? 'active';
        
        if ($status === 'completed') {
            return [
                [
                    ['text' => '📊 详情', 'callback_data' => "redpacket_detail_{$packetId}"],
                    ['text' => '🔄 刷新', 'callback_data' => "refresh_redpacket_{$packetId}"]
                ]
            ];
        } else {
            return [
                [
                    ['text' => '🎁 抢红包', 'callback_data' => "grab_redpacket_{$packetId}"],
                    ['text' => '📊 详情', 'callback_data' => "redpacket_detail_{$packetId}"]
                ],
                [
                    ['text' => '🔄 刷新', 'callback_data' => "refresh_redpacket_{$packetId}"]
                ]
            ];
        }
    }
    
    /**
     * 构建红包历史消息
     */
    protected function buildRedPacketHistoryMessage(array $history): string
    {
        $sent = $history['sent'] ?? [];
        $received = $history['received'] ?? [];
        
        $message = "📊 *红包历史记录*\n\n";
        
        // 发送记录
        if (!empty($sent)) {
            $message .= "🧧 *发送记录* (最近5条)\n";
            foreach (array_slice($sent, 0, 5) as $item) {
                $status = $item['status'] === 'completed' ? '已领完' : '进行中';
                $time = date('m-d H:i', strtotime($item['created_at']));
                $message .= "• {$item['total_amount']} USDT ({$item['total_count']}个) - {$status} ({$time})\n";
            }
            
            if (count($sent) > 5) {
                $remaining = count($sent) - 5;
                $message .= "... 还有 {$remaining} 条记录\n";
            }
            $message .= "\n";
        }
        
        // 接收记录
        if (!empty($received)) {
            $message .= "🎁 *抢包记录* (最近5条)\n";
            foreach (array_slice($received, 0, 5) as $item) {
                $time = date('m-d H:i', strtotime($item['created_at']));
                $best = isset($item['is_best']) && $item['is_best'] ? '👑' : '';
                $message .= "• {$item['amount']} USDT {$best} - {$time}\n";
            }
            
            if (count($received) > 5) {
                $remaining = count($received) - 5;
                $message .= "... 还有 {$remaining} 条记录\n";
            }
        }
        
        if (empty($sent) && empty($received)) {
            $message .= "暂无红包记录\n\n💡 快去发个红包或抢个红包吧！";
        }
        
        return $message;
    }
    
    /**
     * 构建红包历史键盘
     */
    protected function buildRedPacketHistoryKeyboard(): array
    {
        return [
            [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '🔄 刷新', 'callback_data' => 'red_packet_history']
            ],
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
    }
    
    /**
     * 构建抢红包成功消息
     */
    protected function buildGrabSuccessMessage(array $result): string
    {
        $amount = $result['amount'] ?? 0;
        $grabOrder = $result['grab_order'] ?? 0;
        $isCompleted = $result['is_completed'] ?? false;
        $isBestLuck = $result['is_best_luck'] ?? false;
        
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
        
        return $message;
    }
    
    /**
     * 构建抢红包成功键盘
     */
    protected function buildGrabSuccessKeyboard(): array
    {
        return [
            [
                ['text' => '💰 查看余额', 'callback_data' => 'profile'],
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
    }
    
    /**
     * 构建红包详情消息（基于数组数据）
     */
    protected function buildRedPacketDetailMessage(array $redPacket): string
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
            foreach (array_slice($redPacket['grab_records'], 0, 8) as $index => $record) {
                $userName = $record['user_name'] ?? '匿名用户';
                $amount = $record['amount'];
                $order = $index + 1;
                $medal = $order === 1 ? '🥇' : ($order === 2 ? '🥈' : ($order === 3 ? '🥉' : '🏅'));
                $time = isset($record['created_at']) ? date('H:i', strtotime($record['created_at'])) : '';
                $best = isset($record['is_best']) && $record['is_best'] ? ' 👑' : '';
                $message .= "{$medal} {$userName}: {$amount} USDT{$best} ({$time})\n";
            }
            
            if (count($redPacket['grab_records']) > 8) {
                $remaining = count($redPacket['grab_records']) - 8;
                $message .= "... 还有 {$remaining} 条记录\n";
            }
        }
        
        return $message;
    }
    
    /**
     * 构建红包详情键盘
     */
    protected function buildRedPacketDetailKeyboard(string $packetId): array
    {
        return [
            [
                ['text' => '🔄 刷新', 'callback_data' => "refresh_redpacket_{$packetId}"],
                ['text' => '🎁 抢红包', 'callback_data' => "grab_redpacket_{$packetId}"]
            ],
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
    }
    
    /**
     * 构建红包确认消息
     */
    protected function buildRedPacketConfirmMessage(array $redPacketData): string
    {
        $amount = $redPacketData['amount'];
        $count = $redPacketData['count'];
        $title = $redPacketData['title'];
        $currentBalance = $this->currentUser->money_balance;
        $afterBalance = $currentBalance - $amount;
        
        return "🧧 *确认发送红包*\n\n" .
               "💰 红包金额：{$amount} USDT\n" .
               "📦 红包个数：{$count} 个\n" .
               "🎯 红包标题：{$title}\n" .
               "💵 平均金额：" . round($amount / $count, 2) . " USDT\n\n" .
               "💼 当前余额：{$currentBalance} USDT\n" .
               "💸 发送后余额：{$afterBalance} USDT\n\n" .
               "❓ 确认发送此红包吗？";
    }
    
    /**
     * 构建红包确认键盘
     */
    protected function buildRedPacketConfirmKeyboard(): array
    {
        return [
            [
                ['text' => '✅ 确认发送', 'callback_data' => 'confirm_send_redpacket'],
                ['text' => '❌ 取消', 'callback_data' => 'cancel_send_redpacket']
            ],
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
    }
    
    /**
     * 获取用户红包统计
     */
    protected function getUserRedPacketStats(): array
    {
        if (!$this->currentUser) {
            return [
                'sent_count' => 0,
                'sent_amount' => 0,
                'received_count' => 0,
                'received_amount' => 0,
                'best_luck_count' => 0,
            ];
        }
        
        return RedPacket::getUserStats($this->currentUser->id);
    }
}