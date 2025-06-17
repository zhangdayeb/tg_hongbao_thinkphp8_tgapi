<?php
declare(strict_types=1);

namespace app\service;

use app\model\TgCrowdList;
use app\model\TgMessageLog;
use think\facade\Log;
use think\facade\Cache;

/**
 * Telegram通知发送服务
 * 适用于 ThinkPHP8 + PHP8.2
 */
class TelegramNotificationService
{
    private string $botToken;
    private string $apiUrl;
    private int $timeout;
    private int $maxRetries;
    
    public function __construct()
    {
        $this->botToken = config('telegram.bot_token', '');
        $this->apiUrl = 'https://api.telegram.org/bot' . $this->botToken . '/';
        $this->timeout = config('monitor_config.message_config.timeout', 30);
        $this->maxRetries = config('monitor_config.message_config.max_retries', 3);
        
        if (empty($this->botToken)) {
            throw new \Exception('Telegram Bot Token 未配置');
        }
    }
    
    /**
     * 发送到所有群组
     */
    public function sendToAllGroups(string $messageType, array $templateData, string $sourceType, int $sourceId = 0): array
    {
        $groups = $this->getAllBroadcastGroups();
        $results = [];
        
        Log::info("开始向 " . count($groups) . " 个群组发送 {$messageType} 通知");
        
        foreach ($groups as $group) {
            $result = $this->sendToGroup(
                $group['crowd_id'], 
                $messageType, 
                $templateData, 
                $sourceType, 
                $sourceId
            );
            
            $results[] = [
                'group_id' => $group['crowd_id'],
                'group_name' => $group['title'],
                'success' => $result['success'],
                'message' => $result['message']
            ];
            
            // 群组间发送延迟
            if (count($groups) > 1) {
                sleep(1);
            }
        }
        
        return $results;
    }
    
    /**
     * 发送到指定群组
     */
    public function sendToTargetGroup(string $chatId, string $messageType, array $templateData, string $sourceType, int $sourceId = 0): array
    {
        return $this->sendToGroup($chatId, $messageType, $templateData, $sourceType, $sourceId);
    }
    
    /**
     * 核心发送方法
     */
    private function sendToGroup(string $chatId, string $messageType, array $templateData, string $sourceType, int $sourceId): array
    {
        try {
            // 获取消息模板
            $template = config("notification_templates.{$messageType}");
            if (!$template) {
                throw new \Exception("消息模板 {$messageType} 不存在");
            }
            
            // 格式化消息内容
            $messageContent = $this->formatMessageContent($template, $templateData);
            
            // 根据模板类型发送消息
            $result = match($template['type']) {
                'photo' => $this->sendPhoto($chatId, $messageContent),
                'text_with_button' => $this->sendTextWithButton($chatId, $messageContent),
                default => $this->sendText($chatId, $messageContent)
            };
            
            // 记录发送日志
            $this->logMessage($chatId, $messageType, $messageContent, $result, $sourceType, $sourceId);
            
            return [
                'success' => $result['ok'] ?? false,
                'message' => $result['ok'] ? '发送成功' : ($result['description'] ?? '发送失败'),
                'telegram_response' => $result
            ];
            
        } catch (\Exception $e) {
            Log::error("发送消息到群组 {$chatId} 失败: " . $e->getMessage());
            
            // 记录失败日志
            $this->logMessage($chatId, $messageType, '', ['ok' => false, 'description' => $e->getMessage()], $sourceType, $sourceId);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'telegram_response' => null
            ];
        }
    }
    
    /**
     * 格式化消息内容
     */
    private function formatMessageContent(array $template, array $data): array
    {
        $messageTemplateService = new MessageTemplateService();
        return $messageTemplateService->formatTemplate($template, $data);
    }
    
    /**
     * 发送图片消息
     */
    private function sendPhoto(string $chatId, array $content): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $content['image_url'],
            'caption' => $content['caption'] ?? '',
            'parse_mode' => null // 不使用任何解析模式，避免特殊字符问题
        ];
        
        return $this->makeApiRequest('sendPhoto', $params);
    }
    
    /**
     * 发送带按钮的文本消息
     */
    private function sendTextWithButton(string $chatId, array $content): array
    {
        $replyMarkup = null;
        
        if (isset($content['button'])) {
            $replyMarkup = json_encode([
                'inline_keyboard' => [[
                    [
                        'text' => $content['button']['text'],
                        'callback_data' => $content['button']['callback_data']
                    ]
                ]]
            ]);
        }
        
        $params = [
            'chat_id' => $chatId,
            'text' => $content['text'],
            'parse_mode' => null, // 不使用任何解析模式
            'reply_markup' => $replyMarkup
        ];
        
        return $this->makeApiRequest('sendMessage', $params);
    }
    
    /**
     * 发送普通文本消息
     */
    private function sendText(string $chatId, array $content): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $content['text'] ?? $content['caption'] ?? '',
            'parse_mode' => null // 不使用任何解析模式
        ];
        
        return $this->makeApiRequest('sendMessage', $params);
    }
    
    /**
     * 执行 Telegram API 请求
     */
    private function makeApiRequest(string $method, array $params): array
    {
        $url = $this->apiUrl . $method;
        $retries = 0;
        
        while ($retries < $this->maxRetries) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $params,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'TelegramBot/1.0'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                if ($error) {
                    throw new \Exception("CURL错误: {$error}");
                }
                
                if ($httpCode !== 200) {
                    throw new \Exception("HTTP错误: {$httpCode}");
                }
                
                $result = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("JSON解析错误: " . json_last_error_msg());
                }
                
                return $result;
                
            } catch (\Exception $e) {
                $retries++;
                Log::warning("API请求失败 (重试 {$retries}/{$this->maxRetries}): " . $e->getMessage());
                
                if ($retries >= $this->maxRetries) {
                    return [
                        'ok' => false,
                        'description' => $e->getMessage()
                    ];
                }
                
                // 重试延迟
                sleep(pow(2, $retries)); // 指数退避
            }
        }
        
        return ['ok' => false, 'description' => '达到最大重试次数'];
    }
    
    /**
     * 获取所有可广播的群组
     */
    private function getAllBroadcastGroups(): array
    {
        $cacheKey = 'telegram_broadcast_groups';
        $groups = Cache::get($cacheKey);
        
        if ($groups === null) {
            $groups = TgCrowdList::where('del', 0)
                                ->where('is_active', 1)
                                ->where('broadcast_enabled', 1)
                                ->whereIn('bot_status', ['member', 'administrator'])
                                ->field('crowd_id,title,member_count')
                                ->order('member_count', 'desc')
                                ->select()
                                ->toArray();
            
            // 缓存5分钟
            Cache::set($cacheKey, $groups, 300);
        }
        
        Log::info("获取到 " . count($groups) . " 个可广播群组");
        return $groups;
    }
    
    /**
     * 记录消息发送日志
     */
    private function logMessage(string $chatId, string $messageType, mixed $content, array $result, string $sourceType, int $sourceId): void
    {
        try {
            $contentText = '';
            if (is_array($content)) {
                $contentText = $content['text'] ?? $content['caption'] ?? json_encode($content);
            } else {
                $contentText = (string)$content;
            }
            
            TgMessageLog::create([
                'message_type' => 'notification',
                'target_type' => 'group',
                'target_id' => $chatId,
                'content' => $contentText,
                'send_status' => ($result['ok'] ?? false) ? 1 : 2,
                'error_message' => $result['ok'] ? null : ($result['description'] ?? '未知错误'),
                'telegram_message_id' => $result['result']['message_id'] ?? null,
                'source_id' => $sourceId > 0 ? $sourceId : null,
                'source_type' => $sourceType,
                'sent_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            Log::error("记录消息日志失败: " . $e->getMessage());
        }
    }
    
    /**
     * 检查机器人是否在群组中
     */
    public function checkBotInGroup(string $chatId): bool
    {
        try {
            $result = $this->makeApiRequest('getChat', ['chat_id' => $chatId]);
            return $result['ok'] ?? false;
        } catch (\Exception $e) {
            Log::error("检查机器人状态失败: " . $e->getMessage());
            return false;
        }
    }
}