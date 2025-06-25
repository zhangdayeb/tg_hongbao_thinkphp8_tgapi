<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\model\User;
use think\facade\Db;

/**
 * 群聊专用控制器 - 处理群聊中的机器人交互
 * 主要功能：群聊 /start 命令、使用帮助等
 */
class GroupController extends BaseTelegramController
{
    protected ?User $user = null;
    
    // 数据库配置缓存
    private static ?array $dbConfig = null;
    
    /**
     * 设置当前用户
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }
    
    /**
     * 处理群聊中的 /start 命令 - 引导用户开启私聊
     */
    public function handleStartCommand(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🚀 GroupController 处理群聊/start命令 - ChatID: {$chatId}");
            
            // 获取机器人用户名（从配置或缓存获取）
            $botUsername = $this->getBotUsername($debugFile);
            
            if (empty($botUsername)) {
                $this->log($debugFile, "❌ 无法获取机器人用户名，发送备用消息");
                $this->sendFallbackMessage($chatId, $debugFile);
                return;
            }
            
            // 生成私聊链接，带群组来源参数
            $privateLink = "https://t.me/{$botUsername}?start=group_" . abs($chatId);
            
            // 从数据库获取配置并构建消息
            $message = $this->buildWelcomeMessage($privateLink);
            $keyboard = $this->buildKeyboard($privateLink);
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 群聊配置化欢迎消息发送完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理群聊/start命令异常: " . $e->getMessage());
            $this->sendFallbackMessage($chatId, $debugFile);
        }
    }
    
    /**
     * 处理群聊相关回调
     */
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "GroupController 处理回调: {$callbackData}");
        
        try {
            switch ($callbackData) {
                case 'usage_help':
                    $this->sendUsageHelp($chatId, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "❌ GroupController 未知回调: {$callbackData}");
                    $this->sendFallbackMessage($chatId, $debugFile);
                    break;
            }
        } catch (\Exception $e) {
            $this->handleException($e, "处理群聊回调: {$callbackData}", $debugFile);
            $this->sendFallbackMessage($chatId, $debugFile);
        }
    }
    
    /**
     * 构建欢迎消息
     */
    private function buildWelcomeMessage(string $privateLink): string
    {
        $config = $this->getDbConfig();
        
        // 获取欢迎消息，如果没有配置则使用默认消息
        $message = $config['welcome'] ?? $this->getDefaultWelcomeMessage();
        
        // 处理占位符替换
        $message = $this->processTextConfig($message);
        
        // 如果消息中没有私聊引导内容，则添加
        if (strpos($message, '私聊') === false && strpos($message, '开启') === false) {
            $message .= "\n\n👆 *点击下方按钮开启私聊对话*";
        }
        
        return $message;
    }
    
    /**
     * 构建键盘
     */
    private function buildKeyboard(string $privateLink): array
    {
        $config = $this->getDbConfig();
        $keyboard = [];
        $excludeKeywords = ['个人中心', '邀请', '充值', '提现', '余额', '账户'];
        
        // 获取有效的配置按钮
        $validButtons = [];
        for ($i = 1; $i <= 6; $i++) {
            $nameKey = "button{$i}_name";
            $urlKey = "button{$i}_url";
            
            $buttonName = $config[$nameKey] ?? '';
            $buttonUrl = $config[$urlKey] ?? '';
            
            // 跳过空按钮
            if (empty($buttonName) || empty($buttonUrl)) {
                continue;
            }
            
            // 过滤不适合群聊的按钮
            $shouldExclude = false;
            foreach ($excludeKeywords as $keyword) {
                if (strpos($buttonName, $keyword) !== false) {
                    $shouldExclude = true;
                    break;
                }
            }
            
            if (!$shouldExclude) {
                // 处理URL中的占位符
                $processedUrl = $this->processTextConfig($buttonUrl);
                $validButtons[$i] = [
                    'name' => $buttonName,
                    'url' => $processedUrl
                ];
            }
        }
        
        // 第一行：button1（如果存在）
        if (isset($validButtons[1])) {
            $keyboard[] = [
                ['text' => $validButtons[1]['name'], 'url' => $validButtons[1]['url']]
            ];
        }
        
        // 第二行：button2（如果存在）
        if (isset($validButtons[2])) {
            $keyboard[] = [
                ['text' => $validButtons[2]['name'], 'url' => $validButtons[2]['url']]
            ];
        }
        
        // 第三行：开启机器人按钮（必须存在）
        $keyboard[] = [
            ['text' => '💬 开启机器人', 'url' => $privateLink]
        ];
        
        // 第四行：唯一客服 + 唯一财务（从配置文件读取）
        $serviceUrl = config('telegram.links.customer_service_url', '');
        $financeUrl = config('telegram.links.finance_service_url', '');
        
        $serviceRow = [];
        if (!empty($serviceUrl)) {
            $serviceRow[] = ['text' => '👨‍💼 唯一客服', 'url' => $serviceUrl];
        }
        if (!empty($financeUrl)) {
            $serviceRow[] = ['text' => '💰 唯一财务', 'url' => $financeUrl];
        }
        
        if (!empty($serviceRow)) {
            $keyboard[] = $serviceRow;
        }
        
        // 第五行：button3 + button4（如果存在）
        $row5 = [];
        if (isset($validButtons[3])) {
            $row5[] = ['text' => $validButtons[3]['name'], 'url' => $validButtons[3]['url']];
        }
        if (isset($validButtons[4])) {
            $row5[] = ['text' => $validButtons[4]['name'], 'url' => $validButtons[4]['url']];
        }
        if (!empty($row5)) {
            $keyboard[] = $row5;
        }
        
        // 第六行：button5 + button6（如果存在）
        $row6 = [];
        if (isset($validButtons[5])) {
            $row6[] = ['text' => $validButtons[5]['name'], 'url' => $validButtons[5]['url']];
        }
        if (isset($validButtons[6])) {
            $row6[] = ['text' => $validButtons[6]['name'], 'url' => $validButtons[6]['url']];
        }
        if (!empty($row6)) {
            $keyboard[] = $row6;
        }
        
        return $keyboard;
    }
    
    
    /**
     * 发送使用帮助
     */
    private function sendUsageHelp(int $chatId, string $debugFile): void
    {
        $message = "📖 *使用帮助*\n\n" .
                  "🎯 *群聊功能：*\n" .
                  "• 发送红包：`/red 金额 个数`\n" .
                  "• 示例：`/red 100 10`\n\n" .
                  "💡 *私聊功能：*\n" .
                  "• 充值提现\n" .
                  "• 个人中心\n" .
                  "• 邀请好友\n" .
                  "• 游戏记录\n\n" .
                  "🔗 *开启私聊：*\n" .
                  "点击上方「💬 开启机器人」按钮";
        
        $keyboard = [
            [
                ['text' => '🔙 返回', 'callback_data' => 'back_to_group_start']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 发送使用帮助完成");
    }
    
    /**
     * 获取机器人用户名
     */
    private function getBotUsername(string $debugFile): string
    {
        try {
            // 优先从配置文件获取
            $botUsername = config('telegram.bot_username', '');
            if (!empty($botUsername)) {
                $this->log($debugFile, "✅ 从配置获取到机器人用户名: {$botUsername}");
                return $botUsername;
            }
            
            // 从缓存获取
            $cacheKey = 'telegram_bot_username';
            $cachedUsername = cache($cacheKey);
            if (!empty($cachedUsername)) {
                $this->log($debugFile, "✅ 从缓存获取到机器人用户名: {$cachedUsername}");
                return $cachedUsername;
            }
            
            // 通过API获取并缓存
            $telegramService = new \app\service\TelegramService();
            $botInfo = $telegramService->getMe();
            
            if ($botInfo['code'] === 200 && !empty($botInfo['data']['username'])) {
                $username = $botInfo['data']['username'];
                // 缓存1小时
                cache($cacheKey, $username, 3600);
                $this->log($debugFile, "✅ 通过API获取并缓存机器人用户名: {$username}");
                return $username;
            }
            
            $this->log($debugFile, "❌ 无法获取机器人用户名");
            return '';
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取机器人用户名异常: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * 发送备用消息（当无法获取配置或机器人用户名时）
     */
    private function sendFallbackMessage(int $chatId, string $debugFile): void
    {
        try {
            $message = "👋 *欢迎使用机器人！*\n\n" .
                      "🔐 *所有功能需要在私聊中使用*\n\n" .
                      "📱 *如何开启私聊：*\n" .
                      "1️⃣ 点击机器人头像\n" .
                      "2️⃣ 选择\"发送消息\"\n" .
                      "3️⃣ 发送 /start 开始使用\n\n" .
                      "💡 *或者直接搜索机器人名称，发起私聊*";
            
            $keyboard = [
                [
                    ['text' => '❓ 使用帮助', 'callback_data' => 'usage_help']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 群聊备用消息发送完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送群聊备用消息异常: " . $e->getMessage());
        }
    }
    
    /**
     * 获取默认欢迎消息
     */
    private function getDefaultWelcomeMessage(): string
    {
        return "⭐ *欢迎使用机器人！* ⭐\n\n" .
               "🎰 *体验优质服务*\n" .
               "💎 *实体平台信誉*\n" .
               "🧧 *注册即可使用*\n" .
               "💰 *安全便捷充值*\n" .
               "🔥 *丰厚奖励等您*";
    }
    
    // ========================================
    // 数据库配置处理方法（参考 GeneralController）
    // ========================================
    
    /**
     * 获取数据库配置
     */
    private function getDbConfig(): array
    {
        if (self::$dbConfig === null) {
            try {
                $config = Db::name('tg_bot_config')->order('id', 'asc')->find();
                
                $this->log('debug.log', "查询群聊数据库配置: " . ($config ? '成功' : '失败'));
                if ($config) {
                    $this->log('debug.log', "群聊配置内容: " . json_encode($config, JSON_UNESCAPED_UNICODE));
                }
                
                if ($config) {
                    self::$dbConfig = $config;
                } else {
                    self::$dbConfig = [];
                }
            } catch (\Exception $e) {
                $this->log('debug.log', "群聊数据库查询异常: " . $e->getMessage());
                self::$dbConfig = [];
            }
        }
        return self::$dbConfig;
    }
    
    /**
     * 处理文本配置替换
     */
    private function processTextConfig(string $text): string
    {
        $config = $this->getDbConfig();
        if (empty($config)) {
            return $text;
        }
        
        // 替换占位符
        $text = str_replace('[welcome]', $config['welcome'] ?? '', $text);
        $text = str_replace('[button1_name]', $config['button1_name'] ?? '', $text);
        $text = str_replace('[button2_name]', $config['button2_name'] ?? '', $text);
        $text = str_replace('[button3_name]', $config['button3_name'] ?? '', $text);
        $text = str_replace('[button4_name]', $config['button4_name'] ?? '', $text);
        $text = str_replace('[button5_name]', $config['button5_name'] ?? '', $text);
        $text = str_replace('[button6_name]', $config['button6_name'] ?? '', $text);
        
        // 处理URL占位符（如果有用户信息）
        if ($this->user) {
            $text = str_replace('[button1_url]', ($config['button1_url'] ?? '') . 'login?user_id=' . $this->user->id, $text);
        } else {
            $text = str_replace('[button1_url]', $config['button1_url'] ?? '', $text);
        }
        
        $text = str_replace('[button2_url]', $config['button2_url'] ?? '', $text);
        $text = str_replace('[button3_url]', $config['button3_url'] ?? '', $text);
        $text = str_replace('[button4_url]', $config['button4_url'] ?? '', $text);
        $text = str_replace('[button5_url]', $config['button5_url'] ?? '', $text);
        $text = str_replace('[button6_url]', $config['button6_url'] ?? '', $text);
        
        // 处理换行标记
        $text = str_replace('[换行]', "\n", $text);
        
        return $text;
    }
}