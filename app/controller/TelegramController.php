<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\Response;
use app\controller\telegram\CommandDispatcher;

/**
 * Telegram控制器 - 简化版（纯接收器）
 * 只负责webhook接收、数据验证，完全交给CommandDispatcher处理
 */
class TelegramController extends BaseTelegramController
{
    /**
     * 测试接口
     */
    public function test(): Response
    {
        try {
            // 验证配置
            $this->validateBotConfig();
            
            return json([
                'status' => 'success',
                'message' => 'Telegram Controller - Pure Receiver (重构版)',
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => 'v6.0-refactored',
                'description' => '纯接收器，只做webhook接收和数据验证',
                'bot_token_configured' => !empty($this->botToken),
                'bot_token_length' => strlen($this->botToken),
                'config_valid' => true
            ]);
            
        } catch (\Exception $e) {
            return json([
                'status' => 'error',
                'message' => 'Configuration Error',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ], 500);
        }
    }
    
    /**
     * Webhook入口 - 纯接收器实现
     * 只负责：接收 -> 验证 -> 转发
     */
    public function webhook(Request $request): Response
    {
        $debugFile = 'telegram_debug.log';
        $time = date('Y-m-d H:i:s');
        
        try {
            // 开始处理日志
            $this->log($debugFile, "=== {$time} === TelegramController 开始接收 ===");
            
            // 1. 基础数据获取和验证
            $rawData = $request->getContent();
            $this->log($debugFile, "接收数据长度: " . strlen($rawData));
            
            if (empty($rawData)) {
                $this->log($debugFile, "❌ 空数据");
                return $this->buildErrorResponse('Bad Request: No Data', 400);
            }
            
            // 2. JSON解析验证
            $data = json_decode($rawData, true);
            if (!$data) {
                $this->log($debugFile, "❌ JSON解析失败");
                return $this->buildErrorResponse('Bad Request: Invalid JSON', 400);
            }
            
            $this->log($debugFile, "✅ JSON解析成功");
            
            // 3. 数据结构基础验证
            if (!$this->validateUpdateStructure($data, $debugFile)) {
                return $this->buildErrorResponse('Bad Request: Invalid Structure', 400);
            }
            
            // 4. 转发给CommandDispatcher处理
            $this->log($debugFile, "🔄 转交给CommandDispatcher处理");
            $this->handOffToDispatcher($data, $debugFile);
            
            // 5. 处理完成
            $this->log($debugFile, "✅ TelegramController 处理完成\n=== 结束 ===");
            return response('OK', 200);
            
        } catch (\Exception $e) {
            // 统一异常处理
            $this->handleException($e, 'Webhook处理', $debugFile);
            return response('OK', 200); // 避免Telegram重发
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 验证更新数据结构
     */
    private function validateUpdateStructure(array $data, string $debugFile): bool
    {
        // 检查是否包含必要字段
        if (!isset($data['update_id'])) {
            $this->log($debugFile, "❌ 缺少update_id字段");
            return false;
        }
        
        // 检查是否包含消息或回调查询
        if (!isset($data['message']) && !isset($data['callback_query']) && !isset($data['inline_query'])) {
            $this->log($debugFile, "❌ 不包含有效的消息类型");
            return false;
        }
        
        $this->log($debugFile, "✅ 数据结构验证通过");
        return true;
    }
    
    /**
     * 转发给CommandDispatcher
     */
    private function handOffToDispatcher(array $data, string $debugFile): void
    {
        try {
            $dispatcher = new CommandDispatcher();
            
            // 根据消息类型转发
            if (isset($data['message'])) {
                $this->log($debugFile, "→ 转发普通消息给CommandDispatcher");
                $dispatcher->handleMessage($data, $debugFile);
                
            } elseif (isset($data['callback_query'])) {
                $this->log($debugFile, "→ 转发回调查询给CommandDispatcher");
                $dispatcher->handleCallback($data, $debugFile);
                
            } elseif (isset($data['inline_query'])) {
                $this->log($debugFile, "→ 转发内联查询给CommandDispatcher");
                $dispatcher->handleInlineQuery($data, $debugFile);
                
            } else {
                $this->log($debugFile, "→ 未知消息类型，转发给CommandDispatcher默认处理");
                $dispatcher->handleUnknown($data, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, 'CommandDispatcher处理', $debugFile);
            throw $e; // 重新抛出异常，让上层处理
        }
    }
    
    /**
     * 构建错误响应
     */
    private function buildErrorResponse(string $message, int $code): Response
    {
        return response($message, $code);
    }
    
    // =================== 健康检查相关 ===================
    
    /**
     * 健康检查接口
     */
    public function health(): Response
    {
        try {
            // 检查Bot Token配置
            $tokenValid = !empty($this->botToken);
            
            // 检查目录权限
            $logDir = runtime_path() . 'telegram/';
            $logDirWritable = is_writable($logDir) || mkdir($logDir, 0755, true);
            
            // 检查缓存系统
            $cacheWorking = true;
            try {
                $testKey = 'health_check_' . time();
                \think\facade\Cache::set($testKey, 'test', 10);
                $cacheWorking = \think\facade\Cache::get($testKey) === 'test';
                \think\facade\Cache::delete($testKey);
            } catch (\Exception $e) {
                $cacheWorking = false;
            }
            
            $allHealthy = $tokenValid && $logDirWritable && $cacheWorking;
            
            return json([
                'status' => $allHealthy ? 'healthy' : 'unhealthy',
                'timestamp' => date('Y-m-d H:i:s'),
                'checks' => [
                    'bot_token' => $tokenValid,
                    'log_directory' => $logDirWritable,
                    'cache_system' => $cacheWorking
                ],
                'version' => 'v6.0-refactored'
            ], $allHealthy ? 200 : 503);
            
        } catch (\Exception $e) {
            return json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ], 500);
        }
    }
    
    /**
     * 获取webhook信息
     */
    public function webhookInfo(): Response
    {
        try {
            $url = "https://api.telegram.org/bot" . $this->botToken . "/getWebhookInfo";
            $response = $this->makeRequest($url, []);
            
            return json([
                'status' => 'success',
                'webhook_info' => $response['result'] ?? null,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            $this->handleException($e, 'Webhook信息查询');
            
            return json([
                'status' => 'error',
                'message' => 'Failed to get webhook info',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ], 500);
        }
    }
    
    /**
     * 设置webhook
     */
    public function setWebhook(Request $request): Response
    {
        try {
            $webhookUrl = $request->param('url');
            if (empty($webhookUrl)) {
                return json(['status' => 'error', 'message' => 'URL参数不能为空'], 400);
            }
            
            $url = "https://api.telegram.org/bot" . $this->botToken . "/setWebhook";
            $data = ['url' => $webhookUrl];
            
            $response = $this->makeRequest($url, $data);
            
            return json([
                'status' => $response['ok'] ? 'success' : 'error',
                'result' => $response['result'] ?? null,
                'description' => $response['description'] ?? null,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            $this->handleException($e, 'Webhook设置');
            
            return json([
                'status' => 'error',
                'message' => 'Failed to set webhook',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ], 500);
        }
    }
}