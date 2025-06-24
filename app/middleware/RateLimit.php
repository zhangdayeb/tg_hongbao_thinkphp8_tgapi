<?php
declare(strict_types=1);

namespace app\middleware;

use app\common\ResponseHelper;
use app\common\CacheHelper;
use think\Request;
use think\Response;

/**
 * 频率限制中间件
 * 
 * 请求频率控制、防刷限制、用户级别限制、异常流量检测
 */
class RateLimit
{
    /**
     * 默认限制配置
     */
    private array $defaultLimits = [
        'requests' => 60,  // 每分钟请求数
        'window' => 60,    // 时间窗口(秒)
    ];
    
    /**
     * 路由特定限制
     */
    private array $routeLimits = [
        // API路由限制
        'api/v1/user/register' => ['requests' => 5, 'window' => 3600],       // 注册限制
        'api/v1/payment/recharge' => ['requests' => 10, 'window' => 3600],   // 充值限制
        'api/v1/payment/withdraw' => ['requests' => 5, 'window' => 3600],    // 提现限制
        'api/v1/redpacket/send' => ['requests' => 10, 'window' => 3600],     // 发红包限制
        'api/v1/redpacket/grab' => ['requests' => 100, 'window' => 60],      // 抢红包限制
        'api/v1/message/send' => ['requests' => 50, 'window' => 3600],       // 发消息限制
        
        // 管理后台限制
        'admin/login' => ['requests' => 5, 'window' => 300],                  // 登录限制
        'admin/users/*/freeze' => ['requests' => 10, 'window' => 3600],       // 冻结用户限制
        'admin/users/*/adjust-balance' => ['requests' => 5, 'window' => 3600], // 调整余额限制
        'admin/message/broadcast' => ['requests' => 3, 'window' => 3600],     // 群发消息限制
    ];
    
    /**
     * 处理请求
     *
     * @param Request $request
     * @param \Closure $next
     * @param string $identifier 限制标识符类型 (ip|user|admin)
     * @return Response
     */
    public function handle(Request $request, \Closure $next, string $identifier = 'ip'): Response
    {
        $route = $request->pathinfo();
        $method = $request->method();
        
        // 获取限制配置
        $limits = $this->getLimits($route, $method);
        
        // 获取标识符
        $id = $this->getIdentifier($request, $identifier);
        
        // 检查基础频率限制
        if (!$this->checkBasicLimit($id, $route, $limits)) {
            return $this->createLimitResponse($request, $limits);
        }
        
        // 检查突发限制
        if (!$this->checkBurstLimit($id, $route)) {
            return ResponseHelper::tooManyRequests('请求过于频繁，请稍后再试');
        }
        
        // 检查异常流量
        if (!$this->checkAbnormalTraffic($id, $route)) {
            return ResponseHelper::tooManyRequests('检测到异常流量，请稍后再试');
        }
        
        // 记录请求
        $this->recordRequest($id, $route, $request);
        
        $response = $next($request);
        
        // 在响应头中添加限制信息
        $this->addRateLimitHeaders($response, $id, $route, $limits);
        
        return $response;
    }
    
    /**
     * 获取限制配置
     *
     * @param string $route 路由
     * @param string $method HTTP方法
     * @return array
     */
    private function getLimits(string $route, string $method): array
    {
        // 检查精确匹配
        if (isset($this->routeLimits[$route])) {
            return $this->routeLimits[$route];
        }
        
        // 检查通配符匹配
        foreach ($this->routeLimits as $pattern => $limits) {
            if ($this->matchRoute($route, $pattern)) {
                return $limits;
            }
        }
        
        // 根据HTTP方法调整默认限制
        $limits = $this->defaultLimits;
        
        switch ($method) {
            case 'POST':
            case 'PUT':
            case 'DELETE':
                // 写操作更严格的限制
                $limits['requests'] = (int)($limits['requests'] * 0.5);
                break;
            case 'GET':
                // 读操作相对宽松
                $limits['requests'] = (int)($limits['requests'] * 1.5);
                break;
        }
        
        return $limits;
    }
    
    /**
     * 获取标识符
     *
     * @param Request $request
     * @param string $type 标识符类型
     * @return string
     */
    private function getIdentifier(Request $request, string $type): string
    {
        switch ($type) {
            case 'user':
                // 用户ID标识
                $user = $request->user ?? null;
                return $user ? 'user_' . $user->id : 'ip_' . $request->ip();
                
            case 'admin':
                // 管理员ID标识
                $admin = $request->admin ?? null;
                return $admin ? 'admin_' . $admin->id : 'ip_' . $request->ip();
                
            case 'ip':
            default:
                // IP标识
                return 'ip_' . $request->ip();
        }
    }
    
    /**
     * 检查基础频率限制
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @param array $limits 限制配置
     * @return bool
     */
    private function checkBasicLimit(string $identifier, string $route, array $limits): bool
    {
        $key = $this->getCacheKey($identifier, $route);
        
        return !CacheHelper::rateLimitCheck(
            $identifier,
            $route,
            $limits['requests'],
            $limits['window']
        );
    }
    
    /**
     * 检查突发限制
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @return bool
     */
    private function checkBurstLimit(string $identifier, string $route): bool
    {
        $burstKey = $this->getCacheKey($identifier, $route . '_burst');
        
        // 短时间内的突发请求限制（10秒内不超过20个请求）
        return !CacheHelper::rateLimitCheck(
            $identifier,
            $route . '_burst',
            20,
            10
        );
    }
    
    /**
     * 检查异常流量
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @return bool
     */
    private function checkAbnormalTraffic(string $identifier, string $route): bool
    {
        // 检查是否在黑名单中
        if ($this->isBlacklisted($identifier)) {
            return false;
        }
        
        // 检查请求模式异常
        if ($this->detectAbnormalPattern($identifier, $route)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查是否在黑名单
     *
     * @param string $identifier 标识符
     * @return bool
     */
    private function isBlacklisted(string $identifier): bool
    {
        $blacklistKey = CacheHelper::key('rate_limit', 'blacklist', $identifier);
        return CacheHelper::has($blacklistKey);
    }
    
    /**
     * 检测异常模式
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @return bool
     */
    private function detectAbnormalPattern(string $identifier, string $route): bool
    {
        $patternKey = CacheHelper::key('rate_limit', 'pattern', $identifier);
        $requests = CacheHelper::get($patternKey, []);
        
        $now = time();
        $recentRequests = array_filter($requests, function($timestamp) use ($now) {
            return ($now - $timestamp) <= 300; // 最近5分钟的请求
        });
        
        // 检查请求频率是否异常
        if (count($recentRequests) > 100) {
            $this->addToBlacklist($identifier, 'high_frequency');
            return true;
        }
        
        // 检查请求间隔是否过于规律（可能是机器人）
        if (count($recentRequests) >= 10) {
            $intervals = [];
            $timestamps = array_values($recentRequests);
            sort($timestamps);
            
            for ($i = 1; $i < count($timestamps); $i++) {
                $intervals[] = $timestamps[$i] - $timestamps[$i-1];
            }
            
            // 计算间隔的标准差，如果过小说明过于规律
            $variance = $this->calculateVariance($intervals);
            if ($variance < 1.0) {
                $this->addToBlacklist($identifier, 'regular_pattern');
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 添加到黑名单
     *
     * @param string $identifier 标识符
     * @param string $reason 原因
     */
    private function addToBlacklist(string $identifier, string $reason): void
    {
        $blacklistKey = CacheHelper::key('rate_limit', 'blacklist', $identifier);
        $blacklistData = [
            'reason' => $reason,
            'timestamp' => time(),
            'expires_at' => time() + 3600, // 1小时黑名单
        ];
        
        CacheHelper::set($blacklistKey, $blacklistData, 3600);
        
        // 记录黑名单事件
        trace([
            'event' => 'rate_limit_blacklist',
            'identifier' => $identifier,
            'reason' => $reason,
            'timestamp' => time(),
        ], 'rate_limit');
    }
    
    /**
     * 记录请求
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @param Request $request 请求对象
     */
    private function recordRequest(string $identifier, string $route, Request $request): void
    {
        // 记录请求模式用于异常检测
        $patternKey = CacheHelper::key('rate_limit', 'pattern', $identifier);
        $requests = CacheHelper::get($patternKey, []);
        
        $requests[] = time();
        
        // 只保留最近1小时的记录
        $oneHourAgo = time() - 3600;
        $requests = array_filter($requests, function($timestamp) use ($oneHourAgo) {
            return $timestamp > $oneHourAgo;
        });
        
        CacheHelper::set($patternKey, array_values($requests), 3600);
        
        // 记录路由统计
        $this->recordRouteStats($route, $request);
    }
    
    /**
     * 记录路由统计
     *
     * @param string $route 路由
     * @param Request $request 请求对象
     */
    private function recordRouteStats(string $route, Request $request): void
    {
        $statsKey = CacheHelper::key('rate_limit', 'route_stats', date('Y-m-d-H'));
        $stats = CacheHelper::get($statsKey, []);
        
        if (!isset($stats[$route])) {
            $stats[$route] = 0;
        }
        
        $stats[$route]++;
        
        CacheHelper::set($statsKey, $stats, 86400); // 保存24小时
    }
    
    /**
     * 创建限制响应
     *
     * @param Request $request 请求对象
     * @param array $limits 限制配置
     * @return Response
     */
    private function createLimitResponse(Request $request, array $limits): Response
    {
        $identifier = $this->getIdentifier($request, 'ip');
        $route = $request->pathinfo();
        
        // 计算重试时间
        $retryAfter = $this->calculateRetryAfter($identifier, $route, $limits);
        
        // 获取剩余次数
        $remaining = CacheHelper::rateLimitRemaining($identifier, $route, $limits['requests']);
        
        $response = ResponseHelper::tooManyRequests('请求过于频繁，请稍后再试');
        
        // 添加速率限制头
        $response->header([
            'X-RateLimit-Limit' => $limits['requests'],
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => time() + $retryAfter,
            'Retry-After' => $retryAfter,
        ]);
        
        return $response;
    }
    
    /**
     * 添加速率限制头到响应
     *
     * @param Response $response 响应对象
     * @param string $identifier 标识符
     * @param string $route 路由
     * @param array $limits 限制配置
     */
    private function addRateLimitHeaders(Response $response, string $identifier, string $route, array $limits): void
    {
        $remaining = CacheHelper::rateLimitRemaining($identifier, $route, $limits['requests']);
        $resetTime = time() + $limits['window'];
        
        $response->header([
            'X-RateLimit-Limit' => $limits['requests'],
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => $resetTime,
        ]);
    }
    
    /**
     * 计算重试时间
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @param array $limits 限制配置
     * @return int
     */
    private function calculateRetryAfter(string $identifier, string $route, array $limits): int
    {
        // 基础重试时间
        $baseRetry = $limits['window'];
        
        // 检查违规次数，增加重试时间
        $violationKey = CacheHelper::key('rate_limit', 'violations', $identifier);
        $violations = CacheHelper::get($violationKey, 0);
        
        if ($violations > 0) {
            // 指数退避算法
            $baseRetry = min($baseRetry * pow(2, $violations), 3600); // 最多1小时
        }
        
        // 记录违规
        CacheHelper::set($violationKey, $violations + 1, 3600);
        
        return (int)$baseRetry;
    }
    
    /**
     * 匹配路由模式
     *
     * @param string $route 路由
     * @param string $pattern 模式
     * @return bool
     */
    private function matchRoute(string $route, string $pattern): bool
    {
        // 精确匹配
        if ($route === $pattern) {
            return true;
        }
        
        // 通配符匹配
        if (strpos($pattern, '*') !== false) {
            $regex = str_replace(['*', '/'], ['[^/]+', '\/'], $pattern);
            return preg_match('/^' . $regex . '$/', $route) === 1;
        }
        
        // 前缀匹配
        return strpos($route, $pattern) === 0;
    }
    
    /**
     * 获取缓存键
     *
     * @param string $identifier 标识符
     * @param string $suffix 后缀
     * @return string
     */
    private function getCacheKey(string $identifier, string $suffix): string
    {
        return CacheHelper::key('rate_limit', $identifier, $suffix);
    }
    
    /**
     * 计算方差
     *
     * @param array $values 数值数组
     * @return float
     */
    private function calculateVariance(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        
        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return $variance / count($values);
    }
    
    /**
     * 清理过期数据
     */
    public function cleanup(): void
    {
        // 这个方法可以通过定时任务调用
        // 清理过期的模式记录、统计数据等
    }
    
    /**
     * 获取速率限制统计
     *
     * @return array
     */
    public function getStats(): array
    {
        $stats = [];
        $hours = 24;
        
        for ($i = 0; $i < $hours; $i++) {
            $hour = date('Y-m-d-H', time() - ($i * 3600));
            $key = CacheHelper::key('rate_limit', 'route_stats', $hour);
            $hourStats = CacheHelper::get($key, []);
            $stats[$hour] = $hourStats;
        }
        
        return array_reverse($stats, true);
    }
    
    /**
     * 手动移除黑名单
     *
     * @param string $identifier 标识符
     * @return bool
     */
    public function removeFromBlacklist(string $identifier): bool
    {
        $blacklistKey = CacheHelper::key('rate_limit', 'blacklist', $identifier);
        return CacheHelper::delete($blacklistKey);
    }
    
    /**
     * 重置用户限制
     *
     * @param string $identifier 标识符
     * @param string $route 路由
     * @return bool
     */
    public function resetLimit(string $identifier, string $route = ''): bool
    {
        if (empty($route)) {
            // 清除所有相关的限制记录
            $keys = [
                CacheHelper::key('rate_limit', $identifier, '*'),
                CacheHelper::key('rate_limit', 'pattern', $identifier),
                CacheHelper::key('rate_limit', 'violations', $identifier),
            ];
            
            foreach ($keys as $key) {
                CacheHelper::delete($key);
            }
        } else {
            // 清除特定路由的限制
            return CacheHelper::rateLimitReset($identifier, $route);
        }
        
        return true;
    }
}