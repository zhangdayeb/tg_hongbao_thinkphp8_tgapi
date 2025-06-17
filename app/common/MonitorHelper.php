<?php
declare(strict_types=1);

namespace app\common;

use think\facade\Cache;
use think\facade\Log;
use think\facade\Db;

/**
 * 监控系统辅助类
 * 适用于 ThinkPHP8 + PHP8.2
 */
class MonitorHelper
{
    /**
     * 缓存键前缀
     */
    private const CACHE_PREFIX = 'monitor_';
    
    /**
     * 获取缓存键
     */
    public static function getCacheKey(string $type, string $key = ''): string
    {
        return self::CACHE_PREFIX . $type . ($key ? '_' . $key : '');
    }
    
    /**
     * 设置监控缓存
     */
    public static function setCache(string $type, mixed $value, int $ttl = 3600, string $key = ''): bool
    {
        try {
            $cacheKey = self::getCacheKey($type, $key);
            return Cache::set($cacheKey, $value, $ttl);
        } catch (\Exception $e) {
            Log::error("设置监控缓存失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取监控缓存
     */
    public static function getCache(string $type, mixed $default = null, string $key = ''): mixed
    {
        try {
            $cacheKey = self::getCacheKey($type, $key);
            return Cache::get($cacheKey, $default);
        } catch (\Exception $e) {
            Log::error("获取监控缓存失败: " . $e->getMessage());
            return $default;
        }
    }
    
    /**
     * 删除监控缓存
     */
    public static function deleteCache(string $type, string $key = ''): bool
    {
        try {
            $cacheKey = self::getCacheKey($type, $key);
            return Cache::delete($cacheKey);
        } catch (\Exception $e) {
            Log::error("删除监控缓存失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取系统配置
     */
    public static function getMonitorConfig(string $key = ''): mixed
    {
        $config = config('monitor_config');
        
        if (empty($key)) {
            return $config;
        }
        
        return self::getNestedValue($config, $key);
    }
    
    /**
     * 获取嵌套数组值
     */
    private static function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $array;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * 记录监控日志
     */
    public static function logMonitor(string $level, string $message, array $context = []): void
    {
        try {
            $context['timestamp'] = date('Y-m-d H:i:s');
            $context['memory_usage'] = self::formatBytes(memory_get_usage(true));
            $context['peak_memory'] = self::formatBytes(memory_get_peak_usage(true));
            
            match(strtolower($level)) {
                'debug' => Log::debug($message, $context),
                'info' => Log::info($message, $context),
                'warning' => Log::warning($message, $context),
                'error' => Log::error($message, $context),
                default => Log::info($message, $context)
            };
        } catch (\Exception $e) {
            // 日志记录失败时不抛出异常，避免影响主流程
        }
    }
    
    /**
     * 格式化字节数
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * 格式化执行时间
     */
    public static function formatDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000) . 'ms';
        } elseif ($seconds < 60) {
            return round($seconds, 2) . 's';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . 'm ' . round($secs, 1) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $secs = $seconds % 60;
            return $hours . 'h ' . $minutes . 'm ' . round($secs, 1) . 's';
        }
    }
    
    /**
     * 检查表是否存在
     */
    public static function checkTableExists(string $tableName): bool
    {
        try {
            $prefix = config('database.connections.mysql.prefix', '');
            $fullTableName = $prefix . $tableName;
            
            $result = Db::query("SHOW TABLES LIKE '{$fullTableName}'");
            return !empty($result);
        } catch (\Exception $e) {
            Log::error("检查表存在性失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查字段是否存在
     */
    public static function checkFieldExists(string $tableName, string $fieldName): bool
    {
        try {
            $prefix = config('database.connections.mysql.prefix', '');
            $fullTableName = $prefix . $tableName;
            
            $result = Db::query("SHOW COLUMNS FROM `{$fullTableName}` LIKE '{$fieldName}'");
            return !empty($result);
        } catch (\Exception $e) {
            Log::error("检查字段存在性失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取数据库表信息
     */
    public static function getTableInfo(string $tableName): array
    {
        try {
            $prefix = config('database.connections.mysql.prefix', '');
            $fullTableName = $prefix . $tableName;
            
            // 获取表状态
            $status = Db::query("SHOW TABLE STATUS LIKE '{$fullTableName}'");
            
            // 获取字段信息
            $columns = Db::query("SHOW COLUMNS FROM `{$fullTableName}`");
            
            return [
                'exists' => !empty($status),
                'status' => $status[0] ?? [],
                'columns' => $columns,
                'row_count' => $status[0]['Rows'] ?? 0,
                'data_length' => $status[0]['Data_length'] ?? 0,
                'index_length' => $status[0]['Index_length'] ?? 0
            ];
        } catch (\Exception $e) {
            Log::error("获取表信息失败: " . $e->getMessage());
            return ['exists' => false];
        }
    }
    
    /**
     * 验证时间字段格式
     */
    public static function validateTimeField(string $time): bool
    {
        if (empty($time)) {
            return false;
        }
        
        // 验证 datetime 格式
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return false;
        }
        
        // 验证格式是否正确
        $formatted = date('Y-m-d H:i:s', $timestamp);
        return $formatted === $time;
    }
    
    /**
     * 安全获取数组值
     */
    public static function safeArrayGet(array $array, string $key, mixed $default = null): mixed
    {
        return $array[$key] ?? $default;
    }
    
    /**
     * 生成唯一ID
     */
    public static function generateUniqueId(string $prefix = ''): string
    {
        $microtime = explode(' ', microtime());
        $timestamp = $microtime[1];
        $microseconds = substr($microtime[0], 2, 6);
        
        $uniqueId = $timestamp . $microseconds . sprintf('%04d', mt_rand(0, 9999));
        
        return $prefix ? $prefix . '_' . $uniqueId : $uniqueId;
    }
    
    /**
     * 清理过期缓存
     */
    public static function cleanExpiredCache(int $expireHours = 24): int
    {
        try {
            $cleaned = 0;
            $cacheKeys = Cache::tag('monitor')->clear();
            
            self::logMonitor('info', "清理过期监控缓存完成", ['cleaned_count' => $cleaned]);
            return $cleaned;
        } catch (\Exception $e) {
            self::logMonitor('error', "清理过期缓存失败: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * 检查系统资源使用情况
     */
    public static function getSystemResources(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_usage_formatted' => self::formatBytes(memory_get_usage(true)),
            'peak_memory' => memory_get_peak_usage(true),
            'peak_memory_formatted' => self::formatBytes(memory_get_peak_usage(true)),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'php_version' => PHP_VERSION,
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ];
    }
    
    /**
     * 验证监控配置完整性
     */
    public static function validateMonitorConfig(): array
    {
        $errors = [];
        $config = self::getMonitorConfig();
        
        // 检查基本配置
        if (!isset($config['enabled'])) {
            $errors[] = '缺少 enabled 配置项';
        }
        
        if (!isset($config['check_interval']) || !is_numeric($config['check_interval'])) {
            $errors[] = '缺少或无效的 check_interval 配置项';
        }
        
        // 检查通知规则
        if (!isset($config['notify_rules']) || !is_array($config['notify_rules'])) {
            $errors[] = '缺少 notify_rules 配置项';
        } else {
            $requiredRules = ['recharge', 'withdraw', 'redpacket', 'advertisement'];
            foreach ($requiredRules as $rule) {
                if (!isset($config['notify_rules'][$rule])) {
                    $errors[] = "缺少 notify_rules.{$rule} 配置项";
                }
            }
        }
        
        // 检查消息配置
        if (!isset($config['message_config'])) {
            $errors[] = '缺少 message_config 配置项';
        }
        
        // 检查缓存配置
        if (!isset($config['cache_config'])) {
            $errors[] = '缺少 cache_config 配置项';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'config' => $config
        ];
    }
    
    /**
     * 获取监控统计摘要
     */
    public static function getMonitorSummary(): array
    {
        $summary = [
            'last_check_time' => self::getCache('last_check_time'),
            'total_checks' => self::getCache('total_checks', 0),
            'total_sent' => self::getCache('total_sent', 0),
            'total_failed' => self::getCache('total_failed', 0),
            'success_rate' => 0,
            'uptime' => self::getCache('monitor_start_time'),
            'system_status' => 'unknown'
        ];
        
        // 计算成功率
        $total = $summary['total_sent'] + $summary['total_failed'];
        if ($total > 0) {
            $summary['success_rate'] = round(($summary['total_sent'] / $total) * 100, 2);
        }
        
        // 计算运行时间
        if ($summary['uptime']) {
            $uptimeSeconds = time() - strtotime($summary['uptime']);
            $summary['uptime_formatted'] = self::formatDuration($uptimeSeconds);
        }
        
        // 判断系统状态
        $lastCheckTime = $summary['last_check_time'];
        if ($lastCheckTime) {
            $timeDiff = time() - strtotime($lastCheckTime);
            if ($timeDiff < 120) { // 2分钟内
                $summary['system_status'] = 'healthy';
            } elseif ($timeDiff < 300) { // 5分钟内
                $summary['system_status'] = 'warning';
            } else {
                $summary['system_status'] = 'error';
            }
        }
        
        return $summary;
    }
    
    /**
     * 更新监控统计
     */
    public static function updateMonitorStats(array $results): void
    {
        try {
            // 更新计数器
            $totalChecks = self::getCache('total_checks', 0) + 1;
            $totalSent = self::getCache('total_sent', 0) + ($results['summary']['total_sent'] ?? 0);
            $totalFailed = self::getCache('total_failed', 0) + ($results['summary']['total_failed'] ?? 0);
            
            self::setCache('total_checks', $totalChecks, 86400 * 30); // 保存30天
            self::setCache('total_sent', $totalSent, 86400 * 30);
            self::setCache('total_failed', $totalFailed, 86400 * 30);
            
            // 设置监控启动时间（如果还没有的话）
            if (!self::getCache('monitor_start_time')) {
                self::setCache('monitor_start_time', date('Y-m-d H:i:s'), 86400 * 365);
            }
            
        } catch (\Exception $e) {
            self::logMonitor('error', "更新监控统计失败: " . $e->getMessage());
        }
    }
    
    /**
     * 重置监控统计
     */
    public static function resetMonitorStats(): bool
    {
        try {
            $keys = ['total_checks', 'total_sent', 'total_failed', 'monitor_start_time'];
            
            foreach ($keys as $key) {
                self::deleteCache($key);
            }
            
            self::setCache('monitor_start_time', date('Y-m-d H:i:s'), 86400 * 365);
            
            self::logMonitor('info', "监控统计已重置");
            return true;
            
        } catch (\Exception $e) {
            self::logMonitor('error', "重置监控统计失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查监控系统健康状态
     */
    public static function checkMonitorHealth(): array
    {
        $health = [
            'overall' => 'healthy',
            'checks' => []
        ];
        
        // 检查配置
        $configCheck = self::validateMonitorConfig();
        $health['checks']['config'] = [
            'status' => $configCheck['valid'] ? 'healthy' : 'error',
            'message' => $configCheck['valid'] ? '配置正常' : '配置有误',
            'details' => $configCheck['errors']
        ];
        
        // 检查缓存
        $testKey = 'health_check_' . time();
        $cacheWorking = self::setCache('test', 'value', 10, $testKey) && self::getCache('test', null, $testKey) === 'value';
        $health['checks']['cache'] = [
            'status' => $cacheWorking ? 'healthy' : 'error',
            'message' => $cacheWorking ? '缓存正常' : '缓存异常'
        ];
        
        // 检查数据库
        try {
            Db::query('SELECT 1');
            $health['checks']['database'] = [
                'status' => 'healthy',
                'message' => '数据库连接正常'
            ];
        } catch (\Exception $e) {
            $health['checks']['database'] = [
                'status' => 'error',
                'message' => '数据库连接异常: ' . $e->getMessage()
            ];
        }
        
        // 检查最后执行时间
        $lastCheck = self::getCache('last_check_time');
        if ($lastCheck) {
            $timeDiff = time() - strtotime($lastCheck);
            if ($timeDiff > 300) { // 5分钟未执行
                $health['checks']['last_execution'] = [
                    'status' => 'warning',
                    'message' => '超过5分钟未执行监控任务'
                ];
            } else {
                $health['checks']['last_execution'] = [
                    'status' => 'healthy',
                    'message' => '监控任务执行正常'
                ];
            }
        } else {
            $health['checks']['last_execution'] = [
                'status' => 'warning',
                'message' => '从未执行过监控任务'
            ];
        }
        
        // 判断整体状态
        $hasError = false;
        $hasWarning = false;
        
        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'error') {
                $hasError = true;
            } elseif ($check['status'] === 'warning') {
                $hasWarning = true;
            }
        }
        
        if ($hasError) {
            $health['overall'] = 'error';
        } elseif ($hasWarning) {
            $health['overall'] = 'warning';
        }
        
        return $health;
    }
}