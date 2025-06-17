<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;

/**
 * Telegram客服系统控制器
 * 处理: service 等客服相关命令
 */
class ServiceController extends BaseTelegramController
{
    /**
     * 处理客服系统命令
     */
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "ServiceController 处理命令: {$command}");
        
        switch ($command) {
            case 'service':
                $this->handleService($chatId, $debugFile);
                break;
                
            default:
                $this->log($debugFile, "❌ ServiceController 未知命令: {$command}");
                break;
        }
    }
    
    /**
     * 处理客服相关回调
     */
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "ServiceController 处理回调: {$callbackData}");
        
        switch ($callbackData) {
            case 'service':
                $this->handleService($chatId, $debugFile);
                break;
                
            default:
                $this->log($debugFile, "❌ ServiceController 未知回调: {$callbackData}");
                $this->sendMessage($chatId, "❌ 未知服务操作", $debugFile);
                break;
        }
    }
    
    /**
     * 处理 /service 命令
     */
    private function handleService(int $chatId, string $debugFile): void
    {
        $text = "👨‍💼 *客服中心*\n\n" .
                "🕐 *服务时间*\n" .
                "24小时在线客服\n\n" .
                "📞 *联系方式*\n" .
                "• 在线客服：`@customer_service`\n" .
                "• 财务客服：`@finance_service`\n" .
                "• 技术支持：`@tech_support`\n" .
                "• 客服电话：`+85212345678`\n\n" .
                "💬 *常见问题*\n" .
                "• 充值问题 - 联系财务客服\n" .
                "• 提现问题 - 联系财务客服\n" .
                "• 游戏问题 - 联系技术支持\n" .
                "• 账户问题 - 联系在线客服\n\n" .
                "📧 *邮箱支持*\n" .
                "`support@shengbang.com`\n\n" .
                "⚡ *快速响应*\n" .
                "• 在线客服：平均 2 分钟\n" .
                "• 邮件回复：平均 1 小时\n" .
                "• 电话客服：立即接通\n\n" .
                "🔥 *VIP专线*\n" .
                "VIP3及以上用户享受专属客服\n\n" .
                "💡 我们随时为您服务！";
        
        $keyboard = [
            [
                ['text' => '🎰唯一客服', 'url' => 'https://t.me/xg_soft_bot'],
                ['text' => '💰唯一财务', 'url' => 'https://t.me/xiaoxiaoxiaomama']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
    }
}