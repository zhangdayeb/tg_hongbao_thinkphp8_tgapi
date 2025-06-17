<?php
declare(strict_types=1);

namespace app\middleware;

use app\common\ResponseHelper;
use app\common\CacheHelper;
use app\model\User;
use think\Request;
use think\Response;

/**
 * API认证中间件
 * 
 * 用户身份验证、Token验证、请求频率限制、安全检查
 */
class ApiAuth
{
    /**
     * 不需要认证的路由
     */
    private array $publicRoutes = [
        'api/v1/user/register',
        'webhook/telegram',
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
        $route = $request->pathinfo();
        
        // 检查是否为公开路由
        if ($this->isPublicRoute($route)) {
            return $next($request);
        }
        
        // 验证用户身份
        $authResult = $this->authenticateUser($request);
        if (!$authResult['success']) {
            return ResponseHelper::unauthorized($authResult['message']);
        }
        
        // 设置当前用户信息
        $request->user = $authResult['user'];
        
        // 频率限制检查
        if (!$this->checkRateLimit($request)) {
            return ResponseHelper::tooManyRequests('请求过于频繁，请稍后再试');
        }
        
        // 安全检查
        if (!$this->securityCheck($request)) {
            return ResponseHelper::forbidden('安全检查未通过');
        }
        
        // 记录请求日志
        $this->logRequest($request);
        
        return $next($request);
    }
    
    /**
     * 检查是否为公开路由
     *
     * @param string $route 路由路径
     * @return bool
     */
    private function isPublicRoute(string $route): bool
    {
        foreach ($this->publicRoutes as $publicRoute) {
            if (strpos($route, $publicRoute) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 验证用户身份
     *
     * @param Request $request
     * @return array
     */
    private function authenticateUser(Request $request): array
    {
        // 从头部获取认证信息
        $tgId = $request->header('X-TG-ID');
        $chatId = $request->header('X-Chat-ID');
        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');
        
        // 检查必要参数
        if (empty($tgId) || empty($chatId)) {
            return [
                'success' => false,
                'message' => '缺少必要的认证参数'
            ];
        }
        
        // 检查时间戳
        if (empty($timestamp) || abs(time() - (int)$timestamp) > 300) {
            return [
                'success' => false,
                'message' => '请求已过期'
            ];
        }
        
        // 验证签名
        if (!$this->verifySignature($request, $signature, $timestamp)) {
            return [
                'success' => false,
                'message' => '签名验证失败'
            ];
        }
        
        // 查找用户
        $user = $this->findUser($tgId, $chatId);
        if (!$user) {
            return [
                'success' => false,
                'message' => '用户不存在'
            ];
        }
        
        // 检查用户状态
        if ($user->status !== 1) {
            return [
                'success' => false,
                'message' => '用户已被禁用'
            ];
        }
        
        return [
            'success' => true,
            'user' => $user
        ];
    }
    
    /**
     * 验证请求签名
     *
     * @param Request $request
     * @param string $signature
     * @param string $timestamp
     * @return bool
     */
    private function verifySignature(Request $request, ?string $signature, string $timestamp): bool
    {
        if (empty($signature)) {
            return false;
        }
        
        $tgId = $request->header('X-TG-ID');
        $chatId = $request->header('X-Chat-ID');
        $method = $request->method();
        $uri = $request->pathinfo();
        
        // 构建签名字符串
        $signString = $method . '|' . $uri . '|' . $tgId . '|' . $chatId . '|' . $timestamp;
        
        // 添加请求体（POST请求）
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $body = $request->getContent();
            if (!empty($body)) {
                $signString .= '|' . md5($body);
            }
        }
        
        // 计算签名
        $secretKey = config('app.api_secret_key', 'default_secret');
        $computedSignature = hash_hmac('sha256', $signString, $secretKey);
        
        return hash_equals($signature, $computedSignature);
    }
    
    /**
     * 查找用户
     *
     * @param string $tgId
     * @param string $chatId
     * @return User|null
     */
    private function findUser(string $tgId, string $chatId): ?User
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('user', 'auth', $tgId . '_' . $chatId);
        $cachedUser = CacheHelper::get($cacheKey);
        
        if ($cachedUser) {
            return User::find($cachedUser['id']);
        }
        
        // 从数据库查询
        $user = User::where('tg_id', $tgId)->find();
        
        if ($user) {
            // 缓存用户信息
            CacheHelper::set($cacheKey, [
                'id' => $user->id,
                'tg_id' => $user->tg_id,
                'status' => $user->status
            ], 1800);
        }
        
        return $user;
    }
    
    /**
     * 频率限制检查
     *
     * @param Request $request
     * @return bool
     */
    private function checkRateLimit(Request $request): bool
    {
        $user = $request->user;
        $ip = $request->ip();
        $route = $request->pathinfo();
        
        // 用户级别限制
        $userLimit = $this->getUserRateLimit($user);
        if (CacheHelper::rateLimitCheck('user_' . $user->id, 'api', $userLimit['requests'], $userLimit['window'])) {
            return false;
        }
        
        // IP级别限制
        $ipLimit = $this->getIpRateLimit();
        if (CacheHelper::rateLimitCheck('ip_' . $ip, 'api', $ipLimit['requests'], $ipLimit['window'])) {
            return false;
        }
        
        // 路由级别限制
        $routeLimit = $this->getRouteRateLimit($route);
        if ($routeLimit && CacheHelper::rateLimitCheck('route_' . $user->id . '_' . md5($route), $route, $routeLimit['requests'], $routeLimit['window'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取用户频率限制配置
     *
     * @param User $user
     * @return array
     */
    private function getUserRateLimit(User $user): array
    {
        // 根据用户类型设置不同限制
        switch ($user->type) {
            case 1: // 代理
                return ['requests' => 200, 'window' => 60];
            case 2: // 会员
                return ['requests' => 100, 'window' => 60];
            default:
                return ['requests' => 60, 'window' => 60];
        }
    }
    
    /**
     * 获取IP频率限制配置
     *
     * @return array
     */
    private function getIpRateLimit(): array
    {
        return ['requests' => 300, 'window' => 60];
    }
    
    /**
     * 获取路由频率限制配置
     *
     * @param string $route
     * @return array|null
     */
    private function getRouteRateLimit(string $route): ?array
    {
        $routeLimits = [
            'api/v1/payment/recharge' => ['requests' => 10, 'window' => 3600], // 充值限制
            'api/v1/payment/withdraw' => ['requests' => 5, 'window' => 3600],  // 提现限制
            'api/v1/redpacket/send' => ['requests' => 10, 'window' => 3600],   // 发红包限制
            'api/v1/redpacket/grab' => ['requests' => 100, 'window' => 60],    // 抢红包限制
            'api/v1/message/send' => ['requests' => 50, 'window' => 3600],     // 发消息限制
        ];
        
        foreach ($routeLimits as $pattern => $limit) {
            if (strpos($route, $pattern) === 0) {
                return $limit;
            }
        }
        
        return null;
    }
    
    /**
     * 安全检查
     *
     * @param Request $request
     * @return bool
     */
    private function securityCheck(Request $request): bool
    {
        $user = $request->user;
        $ip = $request->ip();
        
        // 检查用户IP白名单（如果设置）
        if ($user->ip_whitelist && !$this->checkIpWhitelist($ip, $user->ip_whitelist)) {
            return false;
        }
        
        // 检查可疑IP
        if ($this->isSuspiciousIp($ip)) {
            return false;
        }
        
        // 检查用户设备数量
        if (!$this->checkDeviceLimit($user, $request)) {
            return false;
        }
        
        // 检查请求内容安全
        if (!$this->checkRequestSecurity($request)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查IP白名单
     *
     * @param string $ip
     * @param string $whitelist
     * @return bool
     */
    private function checkIpWhitelist(string $ip, string $whitelist): bool
    {
        $allowedIps = explode(',', $whitelist);
        
        foreach ($allowedIps as $allowedIp) {
            $allowedIp = trim($allowedIp);
            if ($ip === $allowedIp || $this->ipInRange($ip, $allowedIp)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查是否为可疑IP
     *
     * @param string $ip
     * @return bool
     */
    private function isSuspiciousIp(string $ip): bool
    {
        // 检查IP黑名单
        $blacklistKey = CacheHelper::key('security', 'ip_blacklist', $ip);
        if (CacheHelper::has($blacklistKey)) {
            return true;
        }
        
        // 检查是否为已知恶意IP
        // 这里可以集成第三方IP威胁情报
        
        return false;
    }
    
    /**
     * 检查设备限制
     *
     * @param User $user
     * @param Request $request
     * @return bool
     */
    private function checkDeviceLimit(User $user, Request $request): bool
    {
        $userAgent = $request->header('User-Agent', '');
        $deviceId = md5($userAgent . $request->ip());
        
        $devicesKey = CacheHelper::key('user', 'devices', $user->id);
        $devices = CacheHelper::get($devicesKey, []);
        
        // 检查设备数量限制
        if (count($devices) >= 5 && !in_array($deviceId, $devices)) {
            return false; // 超过设备数量限制
        }
        
        // 记录当前设备
        if (!in_array($deviceId, $devices)) {
            $devices[] = $deviceId;
            CacheHelper::set($devicesKey, array_slice($devices, -5), 86400); // 保留最近5个设备
        }
        
        return true;
    }
    
    /**
     * 检查请求内容安全
     *
     * @param Request $request
     * @return bool
     */
    private function checkRequestSecurity(Request $request): bool
    {
        $content = $request->getContent();
        
        if (empty($content)) {
            return true;
        }
        
        // 检查恶意内容
        $maliciousPatterns = [
            '/script\s*:/i',
            '/javascript\s*:/i',
            '/data\s*:\s*text\/html/i',
            '/vbscript\s*:/i',
            /<script[^>]*>/i,
            /<iframe[^>]*>/i,
            /<object[^>]*>/i,
            /<embed[^>]*>/i,
        ];
        
        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        // 检查SQL注入
        $sqlPatterns = [
            '/union\s+select/i',
            '/drop\s+table/i',
            '/delete\s+from/i',
            '/insert\s+into/i',
            '/update\s+.*\s+set/i',
        ];
        
        foreach ($sqlPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * IP范围检查
     *
     * @param string $ip
     * @param string $range
     * @return bool
     */
    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $bits) = explode('/', $range);
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        
        return ($ip & $mask) === ($subnet & $mask);
    }
    
    /**
     * 记录请求日志
     *
     * @param Request $request
     */
    private function logRequest(Request $request): void
    {
        $user = $request->user;
        
        $logData = [
            'user_id' => $user->id,
            'tg_id' => $user->tg_id,
            'method' => $request->method(),
            'uri' => $request->pathinfo(),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'timestamp' => time(),
        ];
        
        // 记录到日志文件
        trace($logData, 'api_request');
        
        // 记录到缓存（用于实时监控）
        $recentRequestsKey = CacheHelper::key('user', 'recent_requests', $user->id);
        $recentRequests = CacheHelper::get($recentRequestsKey, []);
        $recentRequests[] = $logData;
        
        // 只保留最近50条记录
        if (count($recentRequests) > 50) {
            $recentRequests = array_slice($recentRequests, -50);
        }
        
        CacheHelper::set($recentRequestsKey, $recentRequests, 3600);
    }
}