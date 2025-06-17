<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;

/**
 * 内容控制器 - 处理各种内容展示
 */
class ContentController extends BaseTelegramController
{
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "ContentController 处理命令: {$command}");
        $this->sendMessage($chatId, "📰 内容功能正在开发中...", $debugFile);
    }
    
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "ContentController 处理回调: {$callbackData}");
        
        switch ($callbackData) {
            case 'win_culture':
                $text = "🎁 *包赢文化*\n\n" .
                        "🏆 盛邦娱乐城致力于为用户提供最佳的游戏体验\n\n" .
                        "💰 *我们的承诺*：\n" .
                        "• 公平公正的游戏环境\n" .
                        "• 快速安全的资金流转\n" .
                        "• 24小时贴心客服\n" .
                        "• 丰厚的奖励活动\n\n" .
                        "🎮 加入我们，体验真正的包赢文化！\n\n" .
                        "💡 更多精彩内容敬请期待...";
                break;
                
            case 'daily_news':
                $text = "🤝 *每日吃瓜*\n\n" .
                        "📅 " . date('Y-m-d') . " 今日看点\n\n" .
                        "🔥 *热门话题*：\n" .
                        "• 新用户注册送豪礼\n" .
                        "• USDT充值优惠活动\n" .
                        "• 每日签到领红包\n\n" .
                        "📰 *行业动态*：\n" .
                        "• 数字货币市场稳中有升\n" .
                        "• 在线娱乐行业蓬勃发展\n\n" .
                        "💡 更多资讯正在整理中...";
                break;
                
            case 'today_headlines':
                $text = "📢 *今日头条*\n\n" .
                        "🚀 *重大公告*\n" .
                        "盛邦娱乐城全新升级，带来更好体验！\n\n" .
                        "💎 *活动预告*：\n" .
                        "• 充值满额送豪礼\n" .
                        "• 邀请好友双重奖励\n" .
                        "• VIP专属特权开放\n\n" .
                        "⚡ *系统优化*：\n" .
                        "• 充值速度大幅提升\n" .
                        "• 游戏流畅度优化\n" .
                        "• 客服响应更及时\n\n" .
                        "📺 头条内容持续更新中...";
                break;
                
            default:
                $text = "📰 内容功能正在开发中...\n\n敬请期待更多精彩内容！";
                break;
        }
        
        $keyboard = [
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
    }
}