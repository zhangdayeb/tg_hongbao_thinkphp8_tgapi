<?php
declare(strict_types=1);

namespace app\middleware;

use app\common\ResponseHelper;
use app\common\CacheHelper;
use app\model\Admin;
use think\Request;
use think\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * 管理员认证中间件
 * 
 * 管理员身份验证、权限检查、操作日志记录、会话管理
 */
class AdminAuth
{
    /**
     * 不需要认证的路由
     */
    private array $publicRoutes = [
        'admin/login',
        'admin/captcha',
    ];
    
    /**
     * JWT密钥
     */
    private string $jwtKey;
    
    /**
     * JWT算法
     */
    private string $jwtAlgorithm = 'HS256';
    
    public function __construct()
    {
        $this->jwtKey = config('app.jwt_key', 'default_jwt_key');
    }
    
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
        
        // 验证管理员身份
        $authResult = $this->authenticateAdmin($request);
        if (!$authResult['success']) {
            return ResponseHelper::unauthorized($authResult['message']);
        }
        
        // 设置当前管理员信息
        $request->admin = $authResult['admin'];
        
        // 权限检查
        if (!$this->checkPermission($request)) {
            return ResponseHelper::forbidden('权限不足');
        }
        
        // 会话检查
        if (!$this->checkSession($request)) {
            return ResponseHelper::unauthorized('会话已过期');
        }
        
        // 频率限制
        if (!$this->checkRateLimit($request)) {
            return ResponseHelper::tooManyRequests('操作过于频繁');
        }
        
        // 记录操作日志
        $this->logOperation($request);
        
        $response = $next($request);
        
        // 更新会话
        $this->updateSession($request);
        
        return $response;
    }
    
    /**
     * 检查是否为公开路由
     *
     * @param string $route
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
     * 验证管理员身份
     *
     * @param Request $request
     * @return array
     */
    private function authenticateAdmin(Request $request): array
    {
        // 获取JWT Token
        $token = $this->extractToken($request);
        if (empty($token)) {
            return [
                'success' => false,
                'message' => '缺少访问令牌'
            ];
        }
        
        // 验证JWT Token
        try {
            $payload = JWT::decode($token, new Key($this->jwtKey, $this->jwtAlgorithm));
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '无效的访问令牌'
            ];
        }
        
        // 检查Token是否过期
        if ($payload->exp < time()) {
            return [
                'success' => false,
                'message' => '访问令牌已过期'
            ];
        }
        
        // 查找管理员
        $admin = $this->findAdmin($payload->admin_id);
        if (!$admin) {
            return [
                'success' => false,
                'message' => '管理员不存在'
            ];
        }
        
        // 检查管理员状态
        if ($admin->status !== 1) {
            return [
                'success' => false,
                'message' => '管理员账号已被禁用'
            ];
        }
        
        // 检查Token版本
        if (isset($payload->token_version) && $payload->token_version !== $admin->token_version) {
            return [
                'success' => false,
                'message' => '访问令牌已失效'
            ];
        }
        
        return [
            'success' => true,
            'admin' => $admin,
            'payload' => $payload
        ];
    }
    
    /**
     * 提取Token
     *
     * @param Request $request
     * @return string
     */
    private function extractToken(Request $request): string
    {
        // 从Authorization头获取
        $authorization = $request->header('Authorization');
        if (!empty($authorization) && strpos($authorization, 'Bearer ') === 0) {
            return substr($authorization, 7);
        }
        
        // 从查询参数获取
        $token = $request->get('token');
        if (!empty($token)) {
            return $token;
        }
        
        // 从Cookie获取
        $cookieToken = $request->cookie('admin_token');
        if (!empty($cookieToken)) {
            return $cookieToken;
        }
        
        return '';
    }
    
    /**
     * 查找管理员
     *
     * @param int $adminId
     * @return Admin|null
     */
    private function findAdmin(int $adminId): ?Admin
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('admin', 'info', $adminId);
        $cachedAdmin = CacheHelper::get($cacheKey);
        
        if ($cachedAdmin) {
            return Admin::find($adminId);
        }
        
        // 从数据库查询
        $admin = Admin::find($adminId);
        
        if ($admin) {
            // 缓存管理员信息
            CacheHelper::set($cacheKey, [
                'id' => $admin->id,
                'user_name' => $admin->user_name,
                'role' => $admin->role,
                'status' => $admin->status
            ], 1800);
        }
        
        return $admin;
    }
    
    /**
     * 检查权限
     *
     * @param Request $request
     * @return bool
     */
    private function checkPermission(Request $request): bool
    {
        $admin = $request->admin;
        $route = $request->pathinfo();
        $method = $request->method();
        
        // 超级管理员拥有所有权限
        if ($admin->role === 1) {
            return true;
        }
        
        // 获取角色权限
        $permissions = $this->getRolePermissions($admin->role);
        
        // 检查路由权限
        return $this->hasRoutePermission($route, $method, $permissions);
    }
    
    /**
     * 获取角色权限
     *
     * @param int $role
     * @return array
     */
    private function getRolePermissions(int $role): array
    {
        $permissions = [
            2 => [ // 普通管理员
                'admin/dashboard' => ['GET'],
                'admin/users' => ['GET'],
                'admin/users/*' => ['GET'],
                'admin/recharge/pending' => ['GET'],
                'admin/recharge/*/approve' => ['POST'],
                'admin/withdraw/pending' => ['GET'],
                'admin/redpackets' => ['GET'],
                'admin/message/logs' => ['GET'],
            ],
            3 => [ // 客服管理员
                'admin/dashboard' => ['GET'],
                'admin/users' => ['GET'],
                'admin/users/*' => ['GET'],
                'admin/message/broadcast' => ['POST'],
                'admin/message/logs' => ['GET'],
                'admin/redpackets' => ['GET'],
            ],
        ];
        
        return $permissions[$role] ?? [];
    }
    
    /**
     * 检查路由权限
     *
     * @param string $route
     * @param string $method
     * @param array $permissions
     * @return bool
     */
    private function hasRoutePermission(string $route, string $method, array $permissions): bool
    {
        foreach ($permissions as $pattern => $allowedMethods) {
            if ($this->matchRoute($route, $pattern) && in_array($method, $allowedMethods)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 匹配路由模式
     *
     * @param string $route
     * @param string $pattern
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
            $regex = str_replace('*', '[^/]+', $pattern);
            return preg_match('#^' . $regex . '$#', $route) === 1;
        }
        
        return false;
    }
    
    /**
     * 检查会话
     *
     * @param Request $request
     * @return bool
     */
    private function checkSession(Request $request): bool
    {
        $admin = $request->admin;
        
        // 检查会话是否存在
        $sessionKey = CacheHelper::key('admin', 'session', $admin->id);
        $session = CacheHelper::get($sessionKey);
        
        if (!$session) {
            return false;
        }
        
        // 检查IP变化
        if (isset($session['ip']) && $session['ip'] !== $request->ip()) {
            // IP变化，需要重新登录
            CacheHelper::delete($sessionKey);
            return false;
        }
        
        // 检查User-Agent变化
        $userAgent = $request->header('User-Agent');
        if (isset($session['user_agent']) && $session['user_agent'] !== $userAgent) {
            // User-Agent变化，需要重新登录
            CacheHelper::delete($sessionKey);
            return false;
        }
        
        return true;
    }
    
    /**
     * 频率限制检查
     *
     * @param Request $request
     * @return bool
     */
    private function checkRateLimit(Request $request): bool
    {
        $admin = $request->admin;
        $route = $request->pathinfo();
        
        // 敏感操作限制
        $sensitiveRoutes = [
            'admin/users/*/freeze' => ['requests' => 10, 'window' => 3600],
            'admin/users/*/adjust-balance' => ['requests' => 5, 'window' => 3600],
            'admin/recharge/*/approve' => ['requests' => 100, 'window' => 3600],
            'admin/withdraw/*/approve' => ['requests' => 50, 'window' => 3600],
            'admin/message/broadcast' => ['requests' => 5, 'window' => 3600],
        ];
        
        foreach ($sensitiveRoutes as $pattern => $limit) {
            if ($this->matchRoute($route, $pattern)) {
                $identifier = 'admin_' . $admin->id;
                return !CacheHelper::rateLimitCheck($identifier, $pattern, $limit['requests'], $limit['window']);
            }
        }
        
        // 全局管理员操作限制
        $identifier = 'admin_' . $admin->id;
        return !CacheHelper::rateLimitCheck($identifier, 'admin_ops', 1000, 3600);
    }
    
    /**
     * 记录操作日志
     *
     * @param Request $request
     */
    private function logOperation(Request $request): void
    {
        $admin = $request->admin;
        $route = $request->pathinfo();
        $method = $request->method();
        
        // 需要记录的操作
        $logRoutes = [
            'admin/users/*/freeze',
            'admin/users/*/adjust-balance',
            'admin/recharge/*/approve',
            'admin/withdraw/*/approve',
            'admin/message/broadcast',
            'admin/redpackets/*/revoke',
        ];
        
        $shouldLog = false;
        foreach ($logRoutes as $pattern) {
            if ($this->matchRoute($route, $pattern)) {
                $shouldLog = true;
                break;
            }
        }
        
        if (!$shouldLog) {
            return;
        }
        
        $logData = [
            'admin_id' => $admin->id,
            'admin_name' => $admin->user_name,
            'action' => $method . ' ' . $route,
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'params' => $request->param(),
            'timestamp' => time(),
        ];
        
        // 记录到日志文件
        trace($logData, 'admin_operation');
        
        // 记录到数据库（如果有操作日志表）
        // AdminOperationLog::create($logData);
        
        // 记录到缓存（用于实时监控）
        $recentOpsKey = CacheHelper::key('admin', 'recent_operations', $admin->id);
        $recentOps = CacheHelper::get($recentOpsKey, []);
        $recentOps[] = $logData;
        
        // 只保留最近20条记录
        if (count($recentOps) > 20) {
            $recentOps = array_slice($recentOps, -20);
        }
        
        CacheHelper::set($recentOpsKey, $recentOps, 86400);
    }
    
    /**
     * 更新会话
     *
     * @param Request $request
     */
    private function updateSession(Request $request): void
    {
        $admin = $request->admin;
        
        $sessionKey = CacheHelper::key('admin', 'session', $admin->id);
        $session = CacheHelper::get($sessionKey, []);
        
        // 更新最后活动时间
        $session['last_activity'] = time();
        $session['ip'] = $request->ip();
        $session['user_agent'] = $request->header('User-Agent');
        
        // 延长会话时间
        CacheHelper::set($sessionKey, $session, 7200); // 2小时
    }
    
    /**
     * 生成JWT Token
     *
     * @param Admin $admin
     * @return string
     */
    public function generateToken(Admin $admin): string
    {
        $payload = [
            'admin_id' => $admin->id,
            'user_name' => $admin->user_name,
            'role' => $admin->role,
            'token_version' => $admin->token_version ?? 1,
            'iat' => time(),
            'exp' => time() + 7200, // 2小时过期
        ];
        
        return JWT::encode($payload, $this->jwtKey, $this->jwtAlgorithm);
    }
    
    /**
     * 创建管理员会话
     *
     * @param Admin $admin
     * @param Request $request
     */
    public function createSession(Admin $admin, Request $request): void
    {
        $sessionKey = CacheHelper::key('admin', 'session', $admin->id);
        
        $session = [
            'admin_id' => $admin->id,
            'login_time' => time(),
            'last_activity' => time(),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
        ];
        
        CacheHelper::set($sessionKey, $session, 7200);
    }
    
    /**
     * 销毁管理员会话
     *
     * @param int $adminId
     */
    public function destroySession(int $adminId): void
    {
        $sessionKey = CacheHelper::key('admin', 'session', $adminId);
        CacheHelper::delete($sessionKey);
        
        // 清除缓存的管理员信息
        $infoKey = CacheHelper::key('admin', 'info', $adminId);
        CacheHelper::delete($infoKey);
    }
}