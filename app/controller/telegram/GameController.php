<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;

/**
 * 游戏控制器
 */
class GameController extends BaseTelegramController
{
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "GameController 处理命令: {$command}");
        $this->showGameCenter($chatId, $debugFile);
    }
    
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "GameController 处理回调: {$callbackData}");
        $this->showGameCenter($chatId, $debugFile);
    }
    
    private function showGameCenter(int $chatId, string $debugFile): void
    {
        $text = "🎮 *游戏中心*\n\n" .
                "🔥 *热门游戏*：\n" .
                "• 🃏 真人百家乐\n" .
                "• 🎰 电子老虎机\n" .
                "• ⚽ 体育竞猜\n" .
                "• 🐟 捕鱼达人\n" .
                "• 🎲 骰宝游戏\n\n" .
                "💎 *游戏特色*：\n" .
                "• 公平公正，结果透明\n" .
                "• 实时结算，秒速到账\n" .
                "• 多种玩法，精彩刺激\n\n" .
                "🌟 点击下方按钮立即开始游戏！";
        
        $keyboard = [
            [
                ['text' => '🎰 立即游戏', 'url' => config('telegram.links.game_url', 'https://game.tgapi.oyim.top')]
            ],
            [
                ['text' => '👥 游戏群组', 'url' => config('telegram.links.game_group_url', 'https://t.me/+IN8NfevhJuI4NTJl')]
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
    }
}