<?php
declare(strict_types=1);

namespace app\middleware;

use app\common\CacheHelper;
use app\common\SecurityHelper;
use think\Request;
use think\Response;

/**
 * 请求日志中间件
 * 
 * 请求日志记录、响应时间统计、错误追踪、性能监控
 */
class RequestLog
{
    /**
     * 需要记录详细日志的路由
     */
    private array $detailedLogRoutes = [
        'api/v1/payment/',
        'api/v1/redpacket/',
        'admin/users/',
        'admin/recharge/',
        'admin/withdraw/',
        'webhook/',
    ];
    
    /**
     * 敏感路由（需要加密记录）
     */
    private array $sensitiveRoutes = [
        'admin/login',
        'api/v1/user/register',
        'api/v1/user/set-withdraw-pwd',
    ];
    
    /**
     * 不记录请求体的路由
     */
    private array $excludeBodyRoutes = [
        'webhook/telegram', // Webhook数据量大且频繁
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
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        // 生成请求ID
        $requestId = $this->generateRequestId();
        $request->requestId = $requestId;
        
        // 记录请求开始
        $this->logRequestStart($request, $requestId, $startTime);
        
        // 处理请求
        $response = $next($request);
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        // 记录请求结束
        $this->logRequestEnd($request, $response, $requestId, $startTime, $endTime, $startMemory, $endMemory);
        
        // 添加请求ID到响应头
        $response->header('X-Request-ID', $requestId);
        
        return $response;
    }
    
    /**
     * 生成请求ID
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }
    
    /**
     * 记录请求开始
     *
     * @param Request $request
     * @param string $requestId
     * @param float $startTime
     */
    private function logRequestStart(Request $request, string $requestId, float $startTime): void
    {
        $route = $request->pathinfo();
        
        // 基础日志数据
        $logData = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'uri' => $request->url(true),
            'route' => $route,
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'referer' => $request->header('Referer'),
            'start_time' => $startTime,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        // 添加用户信息
        if (isset($request->user)) {
            $logData['user_id'] = $request->user->id;
            $logData['user_type'] = 'user';
        } elseif (isset($request->admin)) {
            $logData['admin_id'] = $request->admin->id;
            $logData['user_type'] = 'admin';
        }
        
        // 详细日志路由记录更多信息
        if ($this->shouldLogDetailed($route)) {
            $logData = array_merge($logData, [
                'headers' => $this->filterHeaders($request->header()),
                'query_params' => $request->get(),
                'content_type' => $request->header('Content-Type'),
                'content_length' => $request->header('Content-Length', 0),
            ]);
            
            // 记录请求体（非敏感且非排除路由）
            if (!$this->shouldExcludeBody($route) && $request->method() !== 'GET') {
                $body = $request->getContent();
                if (!empty($body)) {
                    if ($this->isSensitiveRoute($route)) {
                        $logData['request_body'] = '[ENCRYPTED]';
                        $logData['request_body_hash'] = md5($body);
                    } else {
                        $logData['request_body'] = mb_strlen($body) > 1000 ? 
                            mb_substr($body, 0, 1000) . '...[TRUNCATED]' : $body;
                    }
                }
            }
        }
        
        // 记录到日志文件
        trace($logData, 'request_start');
        
        // 缓存请求开始信息（用于计算响应时间）
        $cacheKey = CacheHelper::key('request_log', 'start', $requestId);
        CacheHelper::set($cacheKey, $logData, 300); // 5分钟过期
    }
    
    /**
     * 记录请求结束
     *
     * @param Request $request
     * @param Response $response
     * @param string $requestId
     * @param float $startTime
     * @param float $endTime
     * @param int $startMemory
     * @param int $endMemory
     */
    private function logRequestEnd(Request $request, Response $response, string $requestId, float $startTime, float $endTime, int $startMemory, int $endMemory): void
    {
        $route = $request->pathinfo();
        $responseTime = round(($endTime - $startTime) * 1000, 2); // 毫秒
        $memoryUsage = $endMemory - $startMemory;
        
        // 基础日志数据
        $logData = [
            'request_id' => $requestId,
            'status_code' => $response->getCode(),
            'response_time' => $responseTime,
            'memory_usage' => $memoryUsage,
            'end_time' => $endTime,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        // 详细日志路由记录更多信息
        if ($this->shouldLogDetailed($route)) {
            $logData = array_merge($logData, [
                'response_headers' => $this->filterResponseHeaders($response->getHeader()),
                'response_size' => strlen($response->getContent()),
            ]);
            
            // 记录响应内容（仅错误状态或敏感路由）
            if ($response->getCode() >= 400 || $this->isSensitiveRoute($route)) {
                $responseContent = $response->getContent();
                if (!empty($responseContent)) {
                    if ($this->isSensitiveRoute($route)) {
                        $logData['response_body'] = '[ENCRYPTED]';
                        $logData['response_body_hash'] = md5($responseContent);
                    } else {
                        $logData['response_body'] = mb_strlen($responseContent) > 500 ? 
                            mb_substr($responseContent, 0, 500) . '...[TRUNCATED]' : $responseContent;
                    }
                }
            }
        }
        
        // 记录到日志文件
        trace($logData, 'request_end');
        
        // 记录性能统计
        $this->recordPerformanceStats($route, $responseTime, $response->getCode(), $memoryUsage);
        
        // 记录错误统计
        if ($response->getCode() >= 400) {
            $this->recordErrorStats($route, $response->getCode(), $request);
        }
        
        // 记录慢请求
        if ($responseTime > 1000) { // 超过1秒
            $this->recordSlowRequest($request, $requestId, $responseTime);
        }
        
        // 清理缓存的请求开始信息
        $cacheKey = CacheHelper::key('request_log', 'start', $requestId);
        CacheHelper::delete($cacheKey);
    }
    
    /**
     * 是否应该记录详细日志
     *
     * @param string $route
     * @return bool
     */
    private function shouldLogDetailed(string $route): bool
    {
        foreach ($this->detailedLogRoutes as $pattern) {
            if (strpos($route, $pattern) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 是否为敏感路由
     *
     * @param string $route
     * @return bool
     */
    private function isSensitiveRoute(string $route): bool
    {
        foreach ($this->sensitiveRoutes as $pattern) {
            if (strpos($route, $pattern) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 是否应该排除请求体记录
     *
     * @param string $route
     * @return bool
     */
    private function shouldExcludeBody(string $route): bool
    {
        foreach ($this->excludeBodyRoutes as $pattern) {
            if (strpos($route, $pattern) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 过滤请求头
     *
     * @param array $headers
     * @return array
     */
    private function filterHeaders(array $headers): array
    {
        $sensitiveHeaders = [
            'authorization',
            'x-signature',
            'cookie',
            'x-telegram-bot-api-secret-token',
        ];
        
        $filtered = [];
        foreach ($headers as $key => $value) {
            $key = strtolower($key);
            if (in_array($key, $sensitiveHeaders)) {
                $filtered[$key] = '[FILTERED]';
            } else {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
    
    /**
     * 过滤响应头
     *
     * @param array $headers
     * @return array
     */
    private function filterResponseHeaders(array $headers): array
    {
        $excludeHeaders = [
            'set-cookie',
            'x-powered-by',
        ];
        
        $filtered = [];
        foreach ($headers as $key => $value) {
            $key = strtolower($key);
            if (!in_array($key, $excludeHeaders)) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
    
    /**
     * 记录性能统计
     *
     * @param string $route
     * @param float $responseTime
     * @param int $statusCode
     * @param int $memoryUsage
     */
    private function recordPerformanceStats(string $route, float $responseTime, int $statusCode, int $memoryUsage): void
    {
        $hour = date('Y-m-d-H');
        
        // 按小时统计
        $statsKey = CacheHelper::key('request_stats', 'hourly', $hour);
        $stats = CacheHelper::get($statsKey, [
            'total_requests' => 0,
            'total_response_time' => 0,
            'total_memory' => 0,
            'status_codes' => [],
            'routes' => [],
        ]);
        
        $stats['total_requests']++;
        $stats['total_response_time'] += $responseTime;
        $stats['total_memory'] += $memoryUsage;
        
        // 状态码统计
        if (!isset($stats['status_codes'][$statusCode])) {
            $stats['status_codes'][$statusCode] = 0;
        }
        $stats['status_codes'][$statusCode]++;
        
        // 路由统计
        $routePattern = $this->getRoutePattern($route);
        if (!isset($stats['routes'][$routePattern])) {
            $stats['routes'][$routePattern] = [
                'count' => 0,
                'total_time' => 0,
                'avg_time' => 0,
            ];
        }
        $stats['routes'][$routePattern]['count']++;
        $stats['routes'][$routePattern]['total_time'] += $responseTime;
        $stats['routes'][$routePattern]['avg_time'] = 
            $stats['routes'][$routePattern]['total_time'] / $stats['routes'][$routePattern]['count'];
        
        CacheHelper::set($statsKey, $stats, 86400); // 保存24小时
    }
    
    /**
     * 记录错误统计
     *
     * @param string $route
     * @param int $statusCode
     * @param Request $request
     */
    private function recordErrorStats(string $route, int $statusCode, Request $request): void
    {
        $hour = date('Y-m-d-H');
        
        $errorStatsKey = CacheHelper::key('request_stats', 'errors', $hour);
        $errorStats = CacheHelper::get($errorStatsKey, []);
        
        $routePattern = $this->getRoutePattern($route);
        
        if (!isset($errorStats[$statusCode])) {
            $errorStats[$statusCode] = [];
        }
        
        if (!isset($errorStats[$statusCode][$routePattern])) {
            $errorStats[$statusCode][$routePattern] = [
                'count' => 0,
                'ips' => [],
                'user_agents' => [],
            ];
        }
        
        $errorStats[$statusCode][$routePattern]['count']++;
        
        // 记录错误IP
        $ip = $request->ip();
        if (!isset($errorStats[$statusCode][$routePattern]['ips'][$ip])) {
            $errorStats[$statusCode][$routePattern]['ips'][$ip] = 0;
        }
        $errorStats[$statusCode][$routePattern]['ips'][$ip]++;
        
        // 记录User-Agent
        $userAgent = $request->header('User-Agent');
        if (!empty($userAgent)) {
            $userAgentHash = md5($userAgent);
            if (!isset($errorStats[$statusCode][$routePattern]['user_agents'][$userAgentHash])) {
                $errorStats[$statusCode][$routePattern]['user_agents'][$userAgentHash] = 0;
            }
            $errorStats[$statusCode][$routePattern]['user_agents'][$userAgentHash]++;
        }
        
        CacheHelper::set($errorStatsKey, $errorStats, 86400);
    }
    
    /**
     * 记录慢请求
     *
     * @param Request $request
     * @param string $requestId
     * @param float $responseTime
     */
    private function recordSlowRequest(Request $request, string $requestId, float $responseTime): void
    {
        $slowRequestData = [
            'request_id' => $requestId,
            'route' => $request->pathinfo(),
            'method' => $request->method(),
            'response_time' => $responseTime,
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'timestamp' => time(),
        ];
        
        // 记录到慢请求日志
        trace($slowRequestData, 'slow_request');
        
        // 记录到缓存（用于实时监控）
        $slowRequestsKey = CacheHelper::key('request_stats', 'slow_requests', date('Y-m-d'));
        $slowRequests = CacheHelper::get($slowRequestsKey, []);
        $slowRequests[] = $slowRequestData;
        
        // 只保留最慢的100个请求
        if (count($slowRequests) > 100) {
            usort($slowRequests, function($a, $b) {
                return $b['response_time'] <=> $a['response_time'];
            });
            $slowRequests = array_slice($slowRequests, 0, 100);
        }
        
        CacheHelper::set($slowRequestsKey, $slowRequests, 86400);
    }
    
    /**
     * 获取路由模式
     *
     * @param string $route
     * @return string
     */
    private function getRoutePattern(string $route): string
    {
        // 将数字ID替换为通配符
        $pattern = preg_replace('/\/\d+/', '/{id}', $route);
        
        // 将其他动态参数替换为通配符
        $pattern = preg_replace('/\/[a-f0-9]{32}/', '/{hash}', $pattern);
        $pattern = preg_replace('/\/[A-Z0-9]{10,}/', '/{param}', $pattern);
        
        return $pattern;
    }
    
    /**
     * 获取请求统计
     *
     * @param int $hours 小时数
     * @return array
     */
    public function getRequestStats(int $hours = 24): array
    {
        $stats = [];
        
        for ($i = 0; $i < $hours; $i++) {
            $hour = date('Y-m-d-H', time() - ($i * 3600));
            $key = CacheHelper::key('request_stats', 'hourly', $hour);
            $hourStats = CacheHelper::get($key, []);
            $stats[$hour] = $hourStats;
        }
        
        return array_reverse($stats, true);
    }
    
    /**
     * 获取错误统计
     *
     * @param int $hours 小时数
     * @return array
     */
    public function getErrorStats(int $hours = 24): array
    {
        $stats = [];
        
        for ($i = 0; $i < $hours; $i++) {
            $hour = date('Y-m-d-H', time() - ($i * 3600));
            $key = CacheHelper::key('request_stats', 'errors', $hour);
            $hourStats = CacheHelper::get($key, []);
            $stats[$hour] = $hourStats;
        }
        
        return array_reverse($stats, true);
    }
    
    /**
     * 获取慢请求统计
     *
     * @param int $days 天数
     * @return array
     */
    public function getSlowRequestStats(int $days = 7): array
    {
        $stats = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $key = CacheHelper::key('request_stats', 'slow_requests', $date);
            $dayStats = CacheHelper::get($key, []);
            $stats[$date] = $dayStats;
        }
        
        return array_reverse($stats, true);
    }
}