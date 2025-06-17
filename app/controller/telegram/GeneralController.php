<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\common\helper\TemplateHelper;
use app\model\UserInvitation;
use app\model\User;

/**
 * 通用功能控制器 - 只处理通用功能（主菜单、帮助等） + 邀请码欢迎消息
 */
class GeneralController extends BaseTelegramController
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
     * 处理命令 - 支持邀请码的 /start 命令
     */
    public function handle(string $command, int $chatId, string $debugFile, string $inviteCode = ''): void
    {
        $this->log($debugFile, "GeneralController 处理命令: {$command}, 邀请码: " . ($inviteCode ?: '无'));
        
        switch ($command) {
            case '/start':
            case 'start':
                $this->sendMainMenu($chatId, $debugFile, $inviteCode);
                break;
                
            case '/help':
            case 'help':
                $this->sendHelp($chatId, $debugFile);
                break;
                
            default:
                $this->sendMainMenu($chatId, $debugFile);
                break;
        }
    }
    
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "GeneralController 处理回调: {$callbackData}");
        
        try {
            switch ($callbackData) {
                case 'back_to_main':
                    $this->sendMainMenu($chatId, $debugFile);
                    break;
                    
                case 'check_balance':
                    $this->showBalance($chatId, $debugFile);
                    break;
                    
                case 'game_history':
                    $this->showGameHistory($chatId, $debugFile);
                    break;
                    
                case 'security_settings':
                    $this->showSecuritySettings($chatId, $debugFile);
                    break;
                    
                case 'binding_info':
                    $this->showBindingInfo($chatId, $debugFile);
                    break;
                    
                case 'win_culture':
                case 'daily_news':
                case 'today_headlines':
                    $this->showUnderDevelopment($chatId, $callbackData, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "❌ GeneralController 未知回调: {$callbackData}");
                    $this->handleUnknownCallback($callbackData, $chatId, $debugFile);
                    break;
            }
        } catch (\Exception $e) {
            $this->handleException($e, "处理通用回调: {$callbackData}", $debugFile);
            $errorMsg = TemplateHelper::getError('general', 'processing_error');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 发送主菜单 - 支持邀请码欢迎消息
     */
    private function sendMainMenu(int $chatId, string $debugFile, string $inviteCode = ''): void
    {
        // 清除用户状态
        $this->clearUserState($chatId);
        
        // 🎯 处理邀请码欢迎消息
        $message = $this->buildWelcomeMessage($inviteCode, $debugFile);
        
        // 获取主菜单键盘
        $keyboard = TemplateHelper::getKeyboard('general', 'main_menu');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 发送主菜单完成" . ($inviteCode ? " (包含邀请码欢迎消息)" : ""));
    }
    
    /**
     * 🎯 构建欢迎消息 - 根据是否有邀请码显示不同内容
     */
    private function buildWelcomeMessage(string $inviteCode, string $debugFile): string
    {
        // 基础欢迎消息
        $baseMessage = TemplateHelper::getMessage('general', 'welcome');
        
        // 如果没有邀请码，返回基础消息
        if (empty($inviteCode)) {
            return $baseMessage;
        }
        
        // 有邀请码，尝试获取邀请人信息
        try {
            $invitation = UserInvitation::findByCode($inviteCode);
            
            if (!$invitation) {
                $this->log($debugFile, "⚠️ 邀请码不存在: {$inviteCode}");
                return $baseMessage . "\n\n❌ 邀请码无效，请检查后重试";
            }
            
            if ($invitation->is_completed) {
                $this->log($debugFile, "⚠️ 邀请码已被使用: {$inviteCode}");
                return $baseMessage . "\n\n❌ 该邀请码已被使用";
            }
            
            // 获取邀请人信息
            $inviter = User::find($invitation->inviter_id);
            if (!$inviter) {
                $this->log($debugFile, "⚠️ 邀请人不存在: {$invitation->inviter_id}");
                return $baseMessage . "\n\n❌ 邀请人信息异常";
            }
            
            // 构建邀请欢迎消息
            $inviterDisplayName = $this->getInviterDisplayName($inviter);
            
            $inviteWelcome = "🎉 *特别欢迎！*\n\n" .
                           "您是通过朋友 **{$inviterDisplayName}** 的邀请加入我们的！\n\n" .
                           "🎁 邀请奖励：\n" .
                           "• 您和邀请人都将获得丰厚奖励\n" .
                           "• 首次充值可享受额外优惠\n" .
                           "• 更多专属福利等您发现\n\n";
            
            $this->log($debugFile, "✅ 构建邀请欢迎消息成功 - 邀请人: {$inviterDisplayName}");
            
            return $inviteWelcome . $baseMessage;
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理邀请码欢迎消息失败: " . $e->getMessage());
            return $baseMessage . "\n\n❌ 邀请信息处理异常，请稍后重试";
        }
    }
    
    /**
     * 🎯 获取邀请人显示名称
     */
    private function getInviterDisplayName(User $inviter): string
    {
        // 优先级：TG用户名 > TG全名 > 系统用户名
        if (!empty($inviter->tg_username)) {
            return "@{$inviter->tg_username}";
        }
        
        $fullName = trim(($inviter->tg_first_name ?? '') . ' ' . ($inviter->tg_last_name ?? ''));
        if (!empty($fullName)) {
            return $fullName;
        }
        
        return $inviter->user_name ?? "用户{$inviter->id}";
    }
    
    /**
     * 发送帮助信息 - 使用模板系统
     */
    private function sendHelp(int $chatId, string $debugFile): void
    {
        // 获取帮助消息模板
        $message = TemplateHelper::getMessage('general', 'help');
        
        // 获取帮助键盘
        $keyboard = TemplateHelper::getKeyboard('general', 'help');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 发送帮助信息完成");
    }
    
    /**
     * 显示余额信息
     */
    private function showBalance(int $chatId, string $debugFile): void
    {
        $user = $this->user ?? $this->getMockUser($chatId);
        
        $message = "💰 *余额查询*\n\n" .
                  "💎 *当前余额*: " . number_format($user->money_balance ?? 0, 2) . " USDT\n" .
                  "📈 *今日盈亏*: +0.00 USDT\n" .
                  "📊 *本周盈亏*: +0.00 USDT\n" .
                  "📅 *更新时间*: " . date('Y-m-d H:i:s') . "\n\n" .
                  "💡 余额实时更新，如有疑问请联系客服";
        
        $keyboard = TemplateHelper::getKeyboard('general', 'back_only');
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 显示余额信息完成");
    }
    
    /**
     * 显示游戏记录
     */
    private function showGameHistory(int $chatId, string $debugFile): void
    {
        $message = "🎮 *游戏记录*\n\n" .
                  "📝 *最近记录*:\n" .
                  "• 暂无游戏记录\n\n" .
                  "💡 开始游戏后这里将显示您的游戏记录";
        
        $keyboard = TemplateHelper::getKeyboard('general', 'back_only');
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 显示游戏记录完成");
    }
    
    /**
     * 显示安全设置
     */
    private function showSecuritySettings(int $chatId, string $debugFile): void
    {
        $message = "🔒 *安全设置*\n\n" .
                  "🛡️ *账户安全*:\n" .
                  "• 登录密码: 已设置\n" .
                  "• 支付密码: 未设置\n" .
                  "• 双重验证: 未开启\n\n" .
                  "📱 *绑定信息*:\n" .
                  "• 手机号码: 未绑定\n" .
                  "• 邮箱地址: 未绑定\n\n" .
                  "💡 建议完善安全设置，保护账户安全";
        
        $keyboard = TemplateHelper::getKeyboard('general', 'back_only');
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 显示安全设置完成");
    }
    
    /**
     * 显示绑定信息
     */
    private function showBindingInfo(int $chatId, string $debugFile): void
    {
        $user = $this->user;
        $bindTime = $user ? ($user->telegram_bind_time ?? date('Y-m-d')) : date('Y-m-d');
        
        $message = "🔗 *绑定信息*\n\n" .
                  "📱 *Telegram*: 已绑定\n" .
                  "• 用户ID: {$chatId}\n" .
                  "• 绑定时间: {$bindTime}\n\n" .
                  "📞 *手机号码*: 未绑定\n" .
                  "📧 *邮箱地址*: 未绑定\n\n" .
                  "💡 绑定手机和邮箱可提高账户安全性";
        
        $keyboard = TemplateHelper::getKeyboard('general', 'back_only');
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 显示绑定信息完成");
    }
    
    /**
     * 显示功能开发中
     */
    private function showUnderDevelopment(int $chatId, string $callbackData, string $debugFile): void
    {
        $featureNames = [
            'win_culture' => '包赢文化',
            'daily_news' => '每日吃瓜', 
            'today_headlines' => '今日头条'
        ];
        
        $featureName = $featureNames[$callbackData] ?? '该功能';
        
        $message = "🚧 *功能开发中*\n\n" .
                  "{$featureName} 正在紧急开发中，敬请期待！\n\n" .
                  "如有紧急需求，请联系客服处理。";
        
        $keyboard = TemplateHelper::getKeyboard('general', 'back_only');
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 显示功能开发中: {$featureName}");
    }
    
    /**
     * 处理未知回调
     */
    private function handleUnknownCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $message = "❓ *未知操作*\n\n" .
                  "抱歉，未识别的操作指令。\n\n" .
                  "请使用下方菜单进行操作。";
        
        $keyboard = TemplateHelper::getKeyboard('general', 'back_only');
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 获取模拟用户数据（兼容旧版本）
     */
    private function getMockUser(int $chatId): object
    {
        return (object)[
            'id' => $chatId,
            'money_balance' => 0.00,
            'status' => 1,
        ];
    }
}