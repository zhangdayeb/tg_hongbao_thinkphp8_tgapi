<?php
declare(strict_types=1);

namespace app\controller;

use think\facade\Cache;
use think\facade\Log;
use app\service\UserStateService;

/**
 * Telegram机器人基础控制器 - 重构版
 * 整合所有Telegram API调用和公共功能
 */
abstract class BaseTelegramController
{
    protected string $botToken;
    protected UserStateService $userStateService;
    
    public function __construct()
    {
        $this->botToken = config('telegram.bot_token', '');
        if (empty($this->botToken)) {
            throw new \Exception('Telegram Bot Token 未配置');
        }
        $this->userStateService = new UserStateService();
    }
    
    // =================== 日志系统 ===================
    
    /**
     * 统一日志记录方法
     */
    protected function log(string $file, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        // 写入文件日志
        $logDir = runtime_path() . 'telegram/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logDir . $file, $logMessage, FILE_APPEND | LOCK_EX);
        
        // 同时写入系统日志
        Log::info($message, ['module' => 'telegram']);
    }
    
    /**
     * 错误日志记录
     */
    protected function logError(string $file, string $message, \Exception $e = null): void
    {
        $errorMessage = $message;
        if ($e) {
            $errorMessage .= " - Exception: " . $e->getMessage();
            $errorMessage .= " - File: " . $e->getFile() . ":" . $e->getLine();
        }
        
        $this->log($file, "❌ " . $errorMessage);
        Log::error($errorMessage, ['module' => 'telegram']);
    }
    
    // =================== 防重复处理系统 ===================
    
    /**
     * 检查回调是否重复
     */
    protected function isDuplicateCallback(string $queryId, string $debugFile): bool
    {
        $cacheKey = "callback_processed_{$queryId}";
        if (Cache::has($cacheKey)) {
            $this->log($debugFile, "⚠️ 重复回调检测：{$queryId}");
            return true;
        }
        return false;
    }
    
    /**
     * 标记回调已处理
     */
    protected function markCallbackProcessed(string $queryId, string $debugFile): void
    {
        $cacheKey = "callback_processed_{$queryId}";
        Cache::set($cacheKey, true, 300); // 5分钟防重复
        $this->log($debugFile, "✅ 标记回调已处理：{$queryId}");
    }
    
    /**
     * 全局防重复检查
     */
    protected function checkGlobalDuplicate(int $chatId, string $action, int $seconds = 3): bool
    {
        $cacheKey = "global_action_{$chatId}_{$action}";
        if (Cache::has($cacheKey)) {
            return true;
        }
        Cache::set($cacheKey, true, $seconds);
        return false;
    }
    
    // =================== Telegram API调用系统 ===================
    
    /**
     * 安全响应回调查询
     */
    protected function safeAnswerCallbackQuery(string $queryId, string $text = null, string $debugFile = 'telegram_debug.log'): bool
    {
        // 检查是否已经响应过
        $cacheKey = "answered_callback_{$queryId}";
        if (Cache::has($cacheKey)) {
            $this->log($debugFile, "⚠️ 回调查询已响应：{$queryId}");
            return true;
        }
        
        try {
            $url = "https://api.telegram.org/bot" . $this->botToken . "/answerCallbackQuery";
            $data = ['callback_query_id' => $queryId];
            
            if ($text) {
                $data['text'] = $text;
                $data['show_alert'] = false;
            }
            
            $response = $this->makeRequest($url, $data);
            
            if ($response['ok'] ?? false) {
                // 标记已响应，缓存10分钟
                Cache::set($cacheKey, true, 600);
                $this->log($debugFile, "✅ 回调查询响应成功：{$queryId}");
                return true;
            } else {
                $this->log($debugFile, "❌ 回调查询响应失败：" . ($response['description'] ?? 'unknown error'));
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logError($debugFile, "回调查询响应异常", $e);
            return false;
        }
    }
    
    /**
     * 发送普通消息
     */
    protected function sendMessage(int $chatId, string $text, string $debugFile = 'telegram_debug.log'): bool
    {
        try {
            // 检查是否正在发送中
            if ($this->checkGlobalDuplicate($chatId, 'send_message', 2)) {
                $this->log($debugFile, "⚠️ 消息发送中，跳过重复发送");
                return false;
            }
            
            $url = "https://api.telegram.org/bot" . $this->botToken . "/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $text                
            ];
            
            $response = $this->makeRequest($url, $data);
            
            if ($response['ok'] ?? false) {
                $this->log($debugFile, "✅ 消息发送成功 - ChatID: {$chatId}");
                return true;
            } else {
                $this->log($debugFile, "❌ 消息发送失败 - " . ($response['description'] ?? 'unknown error'));
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logError($debugFile, "消息发送异常", $e);
            return false;
        }
    }
    
    /**
     * 发送带键盘的消息 - 增强调试版本
     */
    protected function sendMessageWithKeyboard(int $chatId, string $text, array $keyboard, string $debugFile = 'telegram_debug.log'): bool
    {
        try {
            // 检查是否正在发送中（保持原有逻辑）
            if ($this->checkGlobalDuplicate($chatId, 'send_keyboard', 2)) {
                $this->log($debugFile, "⚠️ 键盘消息发送中，跳过重复发送");
                return false;
            }
            
            $url = "https://api.telegram.org/bot" . $this->botToken . "/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ];
            
            // === 新增：详细的调试信息 ===
            $this->log($debugFile, "📤 准备发送键盘消息:");
            $this->log($debugFile, "  - URL: {$url}");
            $this->log($debugFile, "  - ChatID: {$chatId}");
            $this->log($debugFile, "  - 文本长度: " . strlen($text));
            $this->log($debugFile, "  - 文本内容: " . substr($text, 0, 100) . (strlen($text) > 100 ? '...' : ''));
            $this->log($debugFile, "  - 键盘JSON: " . $data['reply_markup']);
            $this->log($debugFile, "  - Bot Token 前6位: " . substr($this->botToken, 0, 6) . "***");
            
            $response = $this->makeRequest($url, $data);
            
            // === 新增：记录完整的API响应 ===
            $this->log($debugFile, "📥 Telegram API 完整响应: " . json_encode($response, JSON_UNESCAPED_UNICODE));
            
            if ($response['ok'] ?? false) {
                $this->log($debugFile, "✅ 键盘消息发送成功 - ChatID: {$chatId}");
                return true;
            } else {
                // === 增强：更详细的错误信息 ===
                $errorMsg = $response['description'] ?? 'unknown error';
                $errorCode = $response['error_code'] ?? 'unknown code';
                $parameters = $response['parameters'] ?? [];
                
                $this->log($debugFile, "❌ 键盘消息发送失败:");
                $this->log($debugFile, "  - 错误码: {$errorCode}");
                $this->log($debugFile, "  - 错误信息: {$errorMsg}");
                if (!empty($parameters)) {
                    $this->log($debugFile, "  - 额外参数: " . json_encode($parameters));
                }
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logError($debugFile, "键盘消息发送异常", $e);
            return false;
        }
    }
    
    /**
     * 编辑消息
     */
    protected function editMessage(int $chatId, int $messageId, string $text, array $keyboard = null, string $debugFile = 'telegram_debug.log'): bool
    {
        try {
            $url = "https://api.telegram.org/bot" . $this->botToken . "/editMessageText";
            $data = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text                
            ];
            
            if ($keyboard) {
                $data['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
            }
            
            $response = $this->makeRequest($url, $data);
            
            if ($response['ok'] ?? false) {
                $this->log($debugFile, "✅ 消息编辑成功 - ChatID: {$chatId}, MessageID: {$messageId}");
                return true;
            } else {
                $this->log($debugFile, "❌ 消息编辑失败 - " . ($response['description'] ?? 'unknown error'));
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logError($debugFile, "消息编辑异常", $e);
            return false;
        }
    }
    
    /**
     * 删除消息
     */
    protected function deleteMessage(int $chatId, int $messageId, string $debugFile = 'telegram_debug.log'): bool
    {
        try {
            $url = "https://api.telegram.org/bot" . $this->botToken . "/deleteMessage";
            $data = [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ];
            
            $response = $this->makeRequest($url, $data);
            
            if ($response['ok'] ?? false) {
                $this->log($debugFile, "✅ 消息删除成功 - ChatID: {$chatId}, MessageID: {$messageId}");
                return true;
            } else {
                $this->log($debugFile, "❌ 消息删除失败 - " . ($response['description'] ?? 'unknown error'));
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logError($debugFile, "消息删除异常", $e);
            return false;
        }
    }
    
    /**
     * 发送图片
     */
    protected function sendPhoto(int $chatId, string $photoUrl, string $caption = '', string $debugFile = 'telegram_debug.log'): bool
    {
        try {
            $url = "https://api.telegram.org/bot" . $this->botToken . "/sendPhoto";
            $data = [
                'chat_id' => $chatId,
                'photo' => $photoUrl,
                'caption' => $caption                
            ];
            
            $response = $this->makeRequest($url, $data);
            
            if ($response['ok'] ?? false) {
                $this->log($debugFile, "✅ 图片发送成功 - ChatID: {$chatId}");
                return true;
            } else {
                $this->log($debugFile, "❌ 图片发送失败 - " . ($response['description'] ?? 'unknown error'));
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logError($debugFile, "图片发送异常", $e);
            return false;
        }
    }
    
    // =================== HTTP请求系统 ===================
    
    /**
     * 执行HTTP请求到Telegram API - 增强调试版本
     */
    protected function makeRequest(string $url, array $data): array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: TelegramBot/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // === 新增：记录详细的CURL信息 ===
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("CURL Error: " . $error);
        }
        
        if ($httpCode !== 200) {
            // === 增强：更详细的HTTP错误信息 ===
            $responseText = is_string($response) ? $response : 'No response body';
            $errorDetails = "HTTP Error: {$httpCode}";
            $errorDetails .= " - Response Body: " . substr($responseText, 0, 500);
            $errorDetails .= " - Request URL: " . $curlInfo['url'];
            $errorDetails .= " - Total Time: " . $curlInfo['total_time'] . "s";
            $errorDetails .= " - Connect Time: " . $curlInfo['connect_time'] . "s";
            
            throw new \Exception($errorDetails);
        }
        
        $result = json_decode($response, true);
        if ($result === null) {
            $jsonError = json_last_error_msg();
            throw new \Exception("Invalid JSON response: " . $jsonError . " - Raw response: " . substr($response, 0, 200));
        }
        
        // 保持原有返回格式
        $result['http_code'] = $httpCode;
        return $result;
    }
    
    // =================== 用户状态管理 ===================
    
    /**
     * 获取用户状态
     */
    protected function getUserState(int $chatId): array
    {
        return $this->userStateService->getUserState($chatId);
    }
    
    /**
     * 设置用户状态
     */
    protected function setUserState(int $chatId, string $state, array $data = []): bool
    {
        return $this->userStateService->setUserState($chatId, $state, $data);
    }
    
    /**
     * 清除用户状态
     */
    protected function clearUserState(int $chatId): bool
    {
        return $this->userStateService->clearUserState($chatId);
    }
    
    // =================== 异常处理系统 ===================
    
    /**
     * 统一异常处理
     */
    protected function handleException(\Exception $e, string $context, string $debugFile = 'telegram_debug.log'): void
    {
        $this->logError($debugFile, "异常处理 - {$context}", $e);
        
        // 如果是生产环境，可以发送告警通知
        if (config('app.debug') === false) {
            $this->sendAlert($context, $e);
        }
    }
    
    /**
     * 发送告警通知（可扩展）
     */
    protected function sendAlert(string $context, \Exception $e): void
    {
        // 这里可以实现告警通知逻辑
        // 比如发送邮件、企业微信、钉钉等
        Log::critical("Telegram Bot Exception", [
            'context' => $context,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    
    // =================== 配置管理 ===================
    
    /**
     * 获取Bot配置
     */
    protected function getBotConfig(string $key = null, $default = null)
    {
        $config = config('telegram', []);
        
        if ($key === null) {
            return $config;
        }
        
        return $config[$key] ?? $default;
    }
    
    protected function getStatusIcon(string $status): string
    {
        $icons = [
            'pending' => '⏳',
            'success' => '✅', 
            'failed' => '❌',
            'cancelled' => '🚫'
        ];
        return $icons[$status] ?? '❓';
    }
    /**
     * 检查Bot配置是否有效
     */
    protected function validateBotConfig(): bool
    {
        $requiredConfigs = ['bot_token'];
        
        foreach ($requiredConfigs as $config) {
            if (empty($this->getBotConfig($config))) {
                throw new \Exception("Telegram配置缺失: {$config}");
            }
        }
        
        return true;
    }
}