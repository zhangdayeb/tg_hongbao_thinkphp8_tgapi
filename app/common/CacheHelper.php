<?php
declare(strict_types=1);

namespace app\common;

use think\facade\Cache;

/**
 * 缓存助手类
 * 
 * 提供缓存封装、键名生成、过期时间管理、批量操作等功能
 */
class CacheHelper
{
    /**
     * 缓存前缀
     */
    private static string $prefix = 'tg_bot_';
    
    /**
     * 默认过期时间(秒)
     */
    private static int $defaultTtl = 3600;
    
    /**
     * 缓存键名分隔符
     */
    private static string $separator = ':';
    
    /**
     * 设置缓存前缀
     *
     * @param string $prefix 前缀
     */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }
    
    /**
     * 设置默认过期时间
     *
     * @param int $ttl 过期时间(秒)
     */
    public static function setDefaultTtl(int $ttl): void
    {
        self::$defaultTtl = $ttl;
    }
    
    /**
     * 生成缓存键名
     *
     * @param string $module 模块名
     * @param string $action 操作名
     * @param mixed $id 标识符
     * @return string
     */
    public static function key(string $module, string $action = '', $id = ''): string
    {
        $parts = [self::$prefix, $module];
        
        if (!empty($action)) {
            $parts[] = $action;
        }
        
        if (!empty($id)) {
            $parts[] = is_array($id) ? md5(serialize($id)) : (string) $id;
        }
        
        return implode(self::$separator, $parts);
    }
    
    /**
     * 设置缓存
     *
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int|null $ttl 过期时间(秒)
     * @return bool
     */
    public static function set(string $key, $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::$defaultTtl;
        return Cache::set($key, $value, $ttl);
    }
    
    /**
     * 获取缓存
     *
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return Cache::get($key, $default);
    }
    
    /**
     * 删除缓存
     *
     * @param string $key 缓存键
     * @return bool
     */
    public static function delete(string $key): bool
    {
        return Cache::delete($key);
    }
    
    /**
     * 检查缓存是否存在
     *
     * @param string $key 缓存键
     * @return bool
     */
    public static function has(string $key): bool
    {
        return Cache::has($key);
    }
    
    /**
     * 缓存穿透保护 - 记住结果
     *
     * @param string $key 缓存键
     * @param callable $callback 回调函数
     * @param int|null $ttl 过期时间
     * @return mixed
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null)
    {
        if (self::has($key)) {
            return self::get($key);
        }
        
        $value = $callback();
        self::set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * 增加计数器
     *
     * @param string $key 缓存键
     * @param int $step 步长
     * @return int
     */
    public static function increment(string $key, int $step = 1): int
    {
        return Cache::inc($key, $step);
    }
    
    /**
     * 减少计数器
     *
     * @param string $key 缓存键
     * @param int $step 步长
     * @return int
     */
    public static function decrement(string $key, int $step = 1): int
    {
        return Cache::dec($key, $step);
    }
    
    /**
     * 批量设置缓存
     *
     * @param array $data 键值对数组
     * @param int|null $ttl 过期时间
     * @return bool
     */
    public static function setMultiple(array $data, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::$defaultTtl;
        
        foreach ($data as $key => $value) {
            if (!self::set($key, $value, $ttl)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 批量获取缓存
     *
     * @param array $keys 缓存键数组
     * @param mixed $default 默认值
     * @return array
     */
    public static function getMultiple(array $keys, $default = null): array
    {
        $result = [];
        
        foreach ($keys as $key) {
            $result[$key] = self::get($key, $default);
        }
        
        return $result;
    }
    
    /**
     * 批量删除缓存
     *
     * @param array $keys 缓存键数组
     * @return bool
     */
    public static function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!self::delete($key)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 清除指定模块的所有缓存
     *
     * @param string $module 模块名
     * @return bool
     */
    public static function clearModule(string $module): bool
    {
        $pattern = self::key($module) . '*';
        return Cache::clear($pattern);
    }
    
    /**
     * 用户缓存操作
     */
    public static function user(int $userId): UserCache
    {
        return new UserCache($userId);
    }
    
    /**
     * 支付缓存操作
     */
    public static function payment(): PaymentCache
    {
        return new PaymentCache();
    }
    
    /**
     * 红包缓存操作
     */
    public static function redpacket(): RedPacketCache
    {
        return new RedPacketCache();
    }
    
    /**
     * 消息缓存操作
     */
    public static function message(): MessageCache
    {
        return new MessageCache();
    }
    
    /**
     * 游戏缓存操作
     */
    public static function game(): GameCache
    {
        return new GameCache();
    }
    
    /**
     * 频率限制缓存
     *
     * @param string $identifier 标识符(用户ID、IP等)
     * @param string $action 操作类型
     * @param int $limit 限制次数
     * @param int $window 时间窗口(秒)
     * @return bool 是否被限制
     */
    public static function rateLimitCheck(string $identifier, string $action, int $limit, int $window): bool
    {
        $key = self::key('rate_limit', $action, $identifier);
        $current = self::get($key, 0);
        
        if ($current >= $limit) {
            return true; // 被限制
        }
        
        if ($current === 0) {
            self::set($key, 1, $window);
        } else {
            self::increment($key);
        }
        
        return false; // 未被限制
    }
    
    /**
     * 获取频率限制剩余次数
     *
     * @param string $identifier 标识符
     * @param string $action 操作类型
     * @param int $limit 限制次数
     * @return int
     */
    public static function rateLimitRemaining(string $identifier, string $action, int $limit): int
    {
        $key = self::key('rate_limit', $action, $identifier);
        $current = self::get($key, 0);
        
        return max(0, $limit - $current);
    }
    
    /**
     * 重置频率限制
     *
     * @param string $identifier 标识符
     * @param string $action 操作类型
     * @return bool
     */
    public static function rateLimitReset(string $identifier, string $action): bool
    {
        $key = self::key('rate_limit', $action, $identifier);
        return self::delete($key);
    }
    
    /**
     * 分布式锁
     *
     * @param string $resource 资源标识
     * @param int $ttl 锁定时间(秒)
     * @param string $owner 锁拥有者
     * @return bool
     */
    public static function lock(string $resource, int $ttl = 60, string $owner = ''): bool
    {
        $key = self::key('lock', $resource);
        $owner = $owner ?: uniqid();
        
        return self::set($key, $owner, $ttl);
    }
    
    /**
     * 释放锁
     *
     * @param string $resource 资源标识
     * @param string $owner 锁拥有者
     * @return bool
     */
    public static function unlock(string $resource, string $owner = ''): bool
    {
        $key = self::key('lock', $resource);
        
        if ($owner) {
            $lockOwner = self::get($key);
            if ($lockOwner !== $owner) {
                return false; // 不是锁的拥有者
            }
        }
        
        return self::delete($key);
    }
    
    /**
     * 检查锁状态
     *
     * @param string $resource 资源标识
     * @return bool
     */
    public static function isLocked(string $resource): bool
    {
        $key = self::key('lock', $resource);
        return self::has($key);
    }
}

/**
 * 用户缓存类
 */
class UserCache
{
    private int $userId;
    
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }
    
    /**
     * 用户信息缓存
     */
    public function info($data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('user', 'info', $this->userId);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 1800);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 用户余额缓存
     */
    public function balance($balance = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('user', 'balance', $this->userId);
        
        if ($balance !== null) {
            return CacheHelper::set($key, $balance, $ttl ?? 300);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 用户会话缓存
     */
    public function session($data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('user', 'session', $this->userId);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 1800);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 清除用户所有缓存
     */
    public function clear(): bool
    {
        $keys = [
            CacheHelper::key('user', 'info', $this->userId),
            CacheHelper::key('user', 'balance', $this->userId),
            CacheHelper::key('user', 'session', $this->userId),
        ];
        
        return CacheHelper::deleteMultiple($keys);
    }
}

/**
 * 支付缓存类
 */
class PaymentCache
{
    /**
     * 支付方式缓存
     */
    public function methods($data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('payment', 'methods');
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 3600);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 收款账户缓存
     */
    public function accounts(string $method, $data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('payment', 'accounts', $method);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 1800);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 订单缓存
     */
    public function order(string $orderNumber, $data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('payment', 'order', $orderNumber);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 3600);
        }
        
        return CacheHelper::get($key);
    }
}

/**
 * 红包缓存类
 */
class RedPacketCache
{
    /**
     * 红包配置缓存
     */
    public function config($data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('redpacket', 'config');
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 3600);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 红包详情缓存
     */
    public function detail(string $packetId, $data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('redpacket', 'detail', $packetId);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 1800);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 用户红包统计缓存
     */
    public function userStats(int $userId, $data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('redpacket', 'user_stats', $userId);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 300);
        }
        
        return CacheHelper::get($key);
    }
}

/**
 * 消息缓存类
 */
class MessageCache
{
    /**
     * 消息模板缓存
     */
    public function templates($data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('message', 'templates');
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 3600);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 用户消息缓存
     */
    public function userMessages(int $userId, $data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('message', 'user', $userId);
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 600);
        }
        
        return CacheHelper::get($key);
    }
}

/**
 * 游戏缓存类
 */
class GameCache
{
    /**
     * 游戏列表缓存
     */
    public function list($data = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('game', 'list');
        
        if ($data !== null) {
            return CacheHelper::set($key, $data, $ttl ?? 1800);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 用户游戏令牌缓存
     */
    public function userToken(int $userId, $token = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('game', 'token', $userId);
        
        if ($token !== null) {
            return CacheHelper::set($key, $token, $ttl ?? 3600);
        }
        
        return CacheHelper::get($key);
    }
    
    /**
     * 游戏余额缓存
     */
    public function balance(int $userId, $balance = null, ?int $ttl = null)
    {
        $key = CacheHelper::key('game', 'balance', $userId);
        
        if ($balance !== null) {
            return CacheHelper::set($key, $balance, $ttl ?? 300);
        }
        
        return CacheHelper::get($key);
    }
}