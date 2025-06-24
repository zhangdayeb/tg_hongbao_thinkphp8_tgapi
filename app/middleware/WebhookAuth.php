<?php
declare(strict_types=1);

namespace app\middleware;

use app\common\ResponseHelper;
use app\common\CacheHelper;
use app\common\SecurityHelper;
use think\Request;
use think\Response;

/**
 * Webhook认证中间件
 * 
 * Bot Token验证、IP白名单检查、请求签名验证、安全防护
 */
class WebhookAuth
{
    /**
     * Telegram官方IP段
     */
    private array $telegramIpRanges = [
        '149.154.160.0/20',
        '91.108.4.0/22',
        '91.108.56.0/22',
        '109.239.140.0/24',
        '149.154.164.0/22',
        '149.154.168.0/22',
        '149.154.172.0/22',
    ];
    
    /**
     * 处理请求
     *
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        // IP白名单检查
        if (!$this->checkIpWhitelist($request)) {
            $this->logSecurityEvent($request, 'ip_not_allowed');
            return response('Forbidden', 403);
        }
        
        // Webhook路径验证
        if (!$this->validateWebhookPath($request)) {
            $this->logSecurityEvent($request, 'invalid_webhook_path');
            return response('Not Found', 404);
        }
        
        // 请求频率限制
        if (!$this->checkRateLimit($request)) {
            $this->logSecurityEvent($request, 'rate_limit_exceeded');
            return response('Too Many Requests', 429);
        }
        
        // 请求大小检查
        if (!$this->checkRequestSize($request)) {
            $this->logSecurityEvent($request, 'request_too_large');
            return response('Request Entity Too Large', 413);
        }
        
        // Content-Type检查
        if (!$this->checkContentType($request)) {
            $this->logSecurityEvent($request, 'invalid_content_type');
            return response('Unsupported Media Type', 415);
        }
        
        // Secret Token验证（如果配置了）
        if (!$this->verifySecretToken($request)) {
            $this->logSecurityEvent($request, 'invalid_secret_token');
            return response('Unauthorized', 401);
        }
        
        // 请求体验证
        if (!$this->validateRequestBody($request)) {
            $this->logSecurityEvent($request, 'invalid_request_body');
            return response('Bad Request', 400);
        }
        
        // 重放攻击防护
        if (!$this->checkReplayAttack($request)) {
            $this->logSecurityEvent($request, 'replay_attack');
            return response('Bad Request', 400);
        }
        
        // 记录成功的webhook请求
        $this->logWebhookRequest($request);
        
        return $next($request);
    }
    
    /**
     * 检查IP白名单
     *
     * @param Request $request
     * @return bool
     */
    private function checkIpWhitelist(Request $request): bool
    {
        $clientIp = $this->getClientIp($request);
        
        // 开发模式下允许本地IP
        if (config('app.debug') && in_array($clientIp, ['127.0.0.1', '::1', 'localhost'])) {
            return true;
        }
        
        // 检查自定义IP白名单
        $customWhitelist = config('telegram.allowed_ips', []);
        if (!empty($customWhitelist)) {
            return SecurityHelper::validateIp($clientIp, $customWhitelist);
        }
        
        // 检查Telegram官方IP段
        return SecurityHelper::validateIp($clientIp, $this->telegramIpRanges);
    }
    
    /**
     * 获取客户端真实IP
     *
     * @param Request $request
     * @return string
     */
    private function getClientIp(Request $request): string
    {
        // 尝试从各种头部获取真实IP
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // 负载均衡器
            'HTTP_X_REAL_IP',           // Nginx
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            $ip = $request->server($header);
            if (!empty($ip)) {
                // 处理多个IP的情况（取第一个）
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // 验证IP格式
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $request->ip();
    }
    
    /**
     * 验证Webhook路径
     *
     * @param Request $request
     * @return bool
     */
    private function validateWebhookPath(Request $request): bool
    {
        $path = $request->pathinfo();
        $allowedPaths = [
            'webhook/telegram',
        ];
        
        foreach ($allowedPaths as $allowedPath) {
            if (strpos($path, $allowedPath) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查请求频率限制
     *
     * @param Request $request
     * @return bool
     */
    private function checkRateLimit(Request $request): bool
    {
        $clientIp = $this->getClientIp($request);
        
        // IP级别限制：每分钟100个请求
        if (CacheHelper::rateLimitCheck('webhook_ip_' . $clientIp, 'webhook', 100, 60)) {
            return false;
        }
        
        // 全局限制：每秒30个请求
        if (CacheHelper::rateLimitCheck('webhook_global', 'webhook', 30, 1)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查请求大小
     *
     * @param Request $request
     * @return bool
     */
    private function checkRequestSize(Request $request): bool
    {
        $maxSize = config('telegram.max_file_size', 20 * 1024 * 1024); // 20MB
        $contentLength = $request->header('Content-Length', 0);
        
        return (int)$contentLength <= $maxSize;
    }
    
    /**
     * 检查Content-Type
     *
     * @param Request $request
     * @return bool
     */
    private function checkContentType(Request $request): bool
    {
        $contentType = $request->header('Content-Type', '');
        $allowedTypes = [
            'application/json',
            'application/json; charset=utf-8',
        ];
        
        foreach ($allowedTypes as $allowedType) {
            if (strpos($contentType, $allowedType) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 验证Secret Token
     *
     * @param Request $request
     * @return bool
     */
    private function verifySecretToken(Request $request): bool
    {
        $configuredSecret = config('telegram.webhook_secret_token');
        
        // 如果没有配置Secret Token，跳过验证
        if (empty($configuredSecret)) {
            return true;
        }
        
        $receivedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        
        if (empty($receivedSecret)) {
            return false;
        }
        
        return hash_equals($configuredSecret, $receivedSecret);
    }
    
    /**
     * 验证请求体
     *
     * @param Request $request
     * @return bool
     */
    private function validateRequestBody(Request $request): bool
    {
        $content = $request->getContent();
        
        // 检查是否为空
        if (empty($content)) {
            return false;
        }
        
        // 检查JSON格式
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // 检查必要字段
        if (!isset($data['update_id'])) {
            return false;
        }
        
        // 检查update_id格式
        if (!is_integer($data['update_id']) || $data['update_id'] <= 0) {
            return false;
        }
        
        // 检查是否包含有效的更新类型
        $validUpdateTypes = [
            'message',
            'edited_message',
            'channel_post',
            'edited_channel_post',
            'inline_query',
            'chosen_inline_result',
            'callback_query',
            'shipping_query',
            'pre_checkout_query',
            'poll',
            'poll_answer',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
        ];
        
        $hasValidType = false;
        foreach ($validUpdateTypes as $type) {
            if (isset($data[$type])) {
                $hasValidType = true;
                break;
            }
        }
        
        if (!$hasValidType) {
            return false;
        }
        
        // 检查恶意内容
        if (SecurityHelper::detectMaliciousInput($content)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查重放攻击
     *
     * @param Request $request
     * @return bool
     */
    private function checkReplayAttack(Request $request): bool
    {
        $content = $request->getContent();
        $data = json_decode($content, true);
        
        if (!isset($data['update_id'])) {
            return false;
        }
        
        $updateId = $data['update_id'];
        $cacheKey = CacheHelper::key('webhook', 'update_id', $updateId);
        
        // 检查update_id是否已经处理过
        if (CacheHelper::has($cacheKey)) {
            return false; // 重复的update_id
        }
        
        // 记录已处理的update_id（保存1小时）
        CacheHelper::set($cacheKey, time(), 3600);
        
        return true;
    }
    
    /**
     * 记录Webhook请求
     *
     * @param Request $request
     */
    private function logWebhookRequest(Request $request): void
    {
        $content = $request->getContent();
        $data = json_decode($content, true);
        
        $logData = [
            'update_id' => $data['update_id'] ?? 0,
            'ip' => $this->getClientIp($request),
            'content_length' => strlen($content),
            'timestamp' => time(),
            'user_agent' => $request->header('User-Agent'),
        ];
        
        // 记录到日志文件
        trace($logData, 'webhook_request');
        
        // 统计webhook请求数量
        $statsKey = CacheHelper::key('webhook', 'stats', date('Y-m-d-H'));
        CacheHelper::increment($statsKey);
        
        // 设置统计数据过期时间（保存7天）
        if (CacheHelper::get($statsKey) === 1) {
            CacheHelper::set($statsKey, 1, 7 * 24 * 3600);
        }
    }
    
    /**
     * 记录安全事件
     *
     * @param Request $request
     * @param string $event
     */
    private function logSecurityEvent(Request $request, string $event): void
    {
        $logData = [
            'event' => $event,
            'ip' => $this->getClientIp($request),
            'path' => $request->pathinfo(),
            'method' => $request->method(),
            'user_agent' => $request->header('User-Agent'),
            'content_length' => $request->header('Content-Length', 0),
            'timestamp' => time(),
        ];
        
        // 记录到安全日志
        trace($logData, 'webhook_security');
        
        // 记录可疑IP
        if (in_array($event, ['ip_not_allowed', 'rate_limit_exceeded', 'replay_attack'])) {
            $this->recordSuspiciousIp($request);
        }
    }
    
    /**
     * 记录可疑IP
     *
     * @param Request $request
     */
    private function recordSuspiciousIp(Request $request): void
    {
        $ip = $this->getClientIp($request);
        $suspiciousKey = CacheHelper::key('security', 'suspicious_ip', $ip);
        
        $count = CacheHelper::increment($suspiciousKey);
        
        // 设置过期时间（1小时）
        if ($count === 1) {
            CacheHelper::set($suspiciousKey, 1, 3600);
        }
        
        // 如果可疑行为超过阈值，加入黑名单
        if ($count >= 10) {
            $blacklistKey = CacheHelper::key('security', 'ip_blacklist', $ip);
            CacheHelper::set($blacklistKey, time(), 86400); // 黑名单24小时
            
            // 记录严重安全事件
            trace([
                'event' => 'ip_blacklisted',
                'ip' => $ip,
                'suspicious_count' => $count,
                'timestamp' => time(),
            ], 'security_alert');
        }
    }
    
    /**
     * 获取Webhook统计信息
     *
     * @return array
     */
    public function getWebhookStats(): array
    {
        $stats = [];
        $now = time();
        
        // 最近24小时的统计
        for ($i = 0; $i < 24; $i++) {
            $hour = date('Y-m-d-H', $now - ($i * 3600));
            $key = CacheHelper::key('webhook', 'stats', $hour);
            $count = CacheHelper::get($key, 0);
            $stats[$hour] = $count;
        }
        
        return array_reverse($stats, true);
    }
    
    /**
     * 清理过期数据
     */
    public function cleanup(): void
    {
        // 这个方法可以通过定时任务调用，清理过期的缓存数据
        // 具体实现可以根据缓存系统的特性来优化
    }
}