<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\model\UserInvitation;
use app\model\User;

/**
 * 邀请控制器 - 最终版本
 */
class InviteController extends BaseTelegramController
{
    protected ?User $user = null;
    
    /**
     * 设置当前用户
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }
    
    /**
     * 处理邀请命令
     */
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "InviteController 处理命令: {$command}");
        
        if ($command === 'invite') {
            $this->showInvitePage($chatId, $debugFile);
        } else {
            $this->sendMessage($chatId, "💎 未知的邀请命令", $debugFile);
        }
    }
    
    /**
     * 处理邀请相关回调
     */
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "InviteController 处理回调: {$callbackData}");
        
        switch ($callbackData) {
            case 'invite':
                $this->showInvitePage($chatId, $debugFile);
                break;
                
            case 'copy_invite_link':
                $this->copyInviteLink($chatId, $debugFile);
                break;
                
            default:
                $this->sendMessage($chatId, "💎 未知的邀请操作", $debugFile);
        }
    }
    
    /**
     * 显示邀请页面（一人一码，真实数据，无HTML标签）
     */
    private function showInvitePage(int $chatId, string $debugFile): void
    {
        if (!$this->user) {
            $this->sendMessage($chatId, "❌ 用户信息获取失败", $debugFile);
            return;
        }
        
        try {
            // 先查找用户是否已有邀请记录（一人一码逻辑）
            $invitation = UserInvitation::where('inviter_id', $this->user->id)->find();
            
            if (!$invitation) {
                // 第一次邀请，创建邀请记录
                $invitation = UserInvitation::createInvitation($this->user->id);
                $this->log($debugFile, "🆕 首次邀请，创建新邀请码: {$invitation->invitation_code}");
            } else {
                // 已有邀请记录，复用邀请码
                $this->log($debugFile, "🔄 复用现有邀请码: {$invitation->invitation_code}");
            }
            
            // 获取真实的邀请统计数据
            $stats = UserInvitation::getInviterStats($this->user->id);
            
            // 获取机器人用户名
            $botUsername = config('telegram.bot_username', '');
            
            if (empty($botUsername)) {
                $this->log($debugFile, "❌ 机器人用户名未配置");
                $this->sendMessage($chatId, "❌ 系统配置错误，请联系管理员", $debugFile);
                return;
            }
            
            // 生成邀请链接
            $inviteLink = "https://t.me/{$botUsername}?start={$invitation->invitation_code}";
            
            // 构建完整的邀请界面（使用真实数据，无HTML标签）
            $text = "🆔 邀请您的朋友，我有奖励返利！\n\n" .
                    "👥 直属人数: {$stats['completed_invitations']}\n" .
                    "👥 团队人数: {$stats['total_invitations']}\n" .
                    "🎁 未结佣金: " . number_format($stats['pending_rewards'], 2) . "\n" .
                    "🔗 邀请链接(👆点击复制):\n" .
                    "{$inviteLink}";
            
            $keyboard = [
                [
                    ['text' => '📋 复制链接', 'url' => $inviteLink]
                ],
                [
                    ['text' => '🔙 返回', 'callback_data' => 'back_to_main']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 邀请页面显示成功 - 邀请码: {$invitation->invitation_code}");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 邀请页面显示失败: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 邀请功能暂时不可用，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 复制邀请链接（备用方法，如果URL按钮不够用）
     */
    private function copyInviteLink(int $chatId, string $debugFile): void
    {
        // 这个方法可以用来处理额外的复制逻辑
        // 比如显示提示信息等
        $this->sendMessage($chatId, "📋 请长按上面的链接进行复制", $debugFile);
    }
}