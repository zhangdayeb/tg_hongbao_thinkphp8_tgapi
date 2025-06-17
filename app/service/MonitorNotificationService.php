<?php
declare(strict_types=1);

namespace app\service;

use app\model\Recharge;
use app\model\Withdraw;
use app\model\RedPacket;
use app\model\Advertisement;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Db;

/**
 * 监控通知核心服务
 * 适用于 ThinkPHP8 + PHP8.2
 */
class MonitorNotificationService
{
    private TelegramNotificationService $telegramService;
    private array $config;
    private string $lastCheckTimeKey;
    
    public function __construct()
    {
        $this->telegramService = new TelegramNotificationService();
        $this->config = config('monitor_config');
        $this->lastCheckTimeKey = $this->config['cache_config']['last_check_key'];
        
        if (!$this->config['enabled']) {
            throw new \Exception('监控系统已禁用');
        }
    }
    
    /**
     * 运行监控任务 - 主入口方法
     */
    public function runMonitor(): array
    {
        $startTime = microtime(true);
        $results = [
            'start_time' => date('Y-m-d H:i:s'),
            'processed' => [],
            'summary' => [
                'total_processed' => 0,
                'total_sent' => 0,
                'total_failed' => 0
            ]
        ];
        
        try {
            Log::info('开始执行监控任务');
            
            // 获取上次检查时间
            $lastCheckTime = $this->getLastCheckTime();
            $currentTime = date('Y-m-d H:i:s');
            
            Log::info("监控时间窗口: {$lastCheckTime} -> {$currentTime}");
            
            // 检查各个表
            $results['processed']['recharge'] = $this->checkRechargeTable($lastCheckTime);
            $results['processed']['withdraw'] = $this->checkWithdrawTable($lastCheckTime);
            $results['processed']['redpacket'] = $this->checkRedPacketTable($lastCheckTime);
            $results['processed']['advertisement'] = $this->checkAdvertisementTable($currentTime);
            
            // 更新检查时间
            $this->updateLastCheckTime($currentTime);
            
            // 统计结果
            $this->calculateSummary($results);
            
            $executionTime = round(microtime(true) - $startTime, 2);
            $results['execution_time'] = $executionTime;
            $results['end_time'] = date('Y-m-d H:i:s');
            
            Log::info("监控任务完成，耗时: {$executionTime}秒，处理: {$results['summary']['total_processed']}条");
            
        } catch (\Exception $e) {
            Log::error("监控任务执行失败: " . $e->getMessage());
            $results['error'] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * 检查充值表
     */
    private function checkRechargeTable(string $lastCheckTime): array
    {
        $result = ['count' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        
        try {
            if (!$this->config['notify_rules']['recharge']['enabled']) {
                Log::info('充值监控已禁用');
                return $result;
            }
            
            $newRecharges = Recharge::where('create_time', '>', $lastCheckTime)
                                  ->order('create_time', 'asc')
                                  ->select();
            
            $result['count'] = count($newRecharges);
            Log::info("发现 {$result['count']} 条新充值记录");
            
            foreach ($newRecharges as $recharge) {
                try {
                    $sendResults = $this->telegramService->sendToAllGroups(
                        'recharge_notify',
                        $recharge->toArray(),
                        'recharge',
                        $recharge->id
                    );
                    
                    $success = array_sum(array_column($sendResults, 'success'));
                    $result['sent'] += $success;
                    $result['failed'] += (count($sendResults) - $success);
                    
                    Log::info("充值通知发送完成 - ID: {$recharge->id}, 成功: {$success}, 失败: " . (count($sendResults) - $success));
                    
                } catch (\Exception $e) {
                    $result['failed']++;
                    $result['errors'][] = "充值ID {$recharge->id}: " . $e->getMessage();
                    Log::error("充值通知发送失败 - ID: {$recharge->id}, 错误: " . $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = "充值表检查失败: " . $e->getMessage();
            Log::error("充值表检查失败: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 检查提现表
     */
    private function checkWithdrawTable(string $lastCheckTime): array
    {
        $result = ['count' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        
        try {
            if (!$this->config['notify_rules']['withdraw']['enabled']) {
                Log::info('提现监控已禁用');
                return $result;
            }
            
            $newWithdraws = Withdraw::where('create_time', '>', $lastCheckTime)
                                  ->order('create_time', 'asc')
                                  ->select();
            
            $result['count'] = count($newWithdraws);
            Log::info("发现 {$result['count']} 条新提现记录");
            
            foreach ($newWithdraws as $withdraw) {
                try {
                    $sendResults = $this->telegramService->sendToAllGroups(
                        'withdraw_notify',
                        $withdraw->toArray(),
                        'withdraw',
                        $withdraw->id
                    );
                    
                    $success = array_sum(array_column($sendResults, 'success'));
                    $result['sent'] += $success;
                    $result['failed'] += (count($sendResults) - $success);
                    
                    Log::info("提现通知发送完成 - ID: {$withdraw->id}, 成功: {$success}, 失败: " . (count($sendResults) - $success));
                    
                } catch (\Exception $e) {
                    $result['failed']++;
                    $result['errors'][] = "提现ID {$withdraw->id}: " . $e->getMessage();
                    Log::error("提现通知发送失败 - ID: {$withdraw->id}, 错误: " . $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = "提现表检查失败: " . $e->getMessage();
            Log::error("提现表检查失败: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 检查红包表
     */
    private function checkRedPacketTable(string $lastCheckTime): array
    {
        $result = ['count' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        
        try {
            if (!$this->config['notify_rules']['redpacket']['enabled']) {
                Log::info('红包监控已禁用');
                return $result;
            }
            
            $newRedPackets = RedPacket::where('created_at', '>', $lastCheckTime)
                                    ->order('created_at', 'asc')
                                    ->select();
            
            $result['count'] = count($newRedPackets);
            Log::info("发现 {$result['count']} 条新红包记录");
            
            foreach ($newRedPackets as $packet) {
                try {
                    // 红包通知只发送到对应群组
                    $sendResult = $this->telegramService->sendToTargetGroup(
                        $packet->chat_id,
                        'redpacket_notify',
                        $packet->toArray(),
                        'redpacket',
                        $packet->id
                    );
                    
                    if ($sendResult['success']) {
                        $result['sent']++;
                        Log::info("红包通知发送成功 - ID: {$packet->id}, 群组: {$packet->chat_id}");
                    } else {
                        $result['failed']++;
                        $result['errors'][] = "红包ID {$packet->id}: " . $sendResult['message'];
                        Log::error("红包通知发送失败 - ID: {$packet->id}, 错误: " . $sendResult['message']);
                    }
                    
                } catch (\Exception $e) {
                    $result['failed']++;
                    $result['errors'][] = "红包ID {$packet->id}: " . $e->getMessage();
                    Log::error("红包通知发送失败 - ID: {$packet->id}, 错误: " . $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = "红包表检查失败: " . $e->getMessage();
            Log::error("红包表检查失败: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 检查广告表
     */
    private function checkAdvertisementTable(string $currentTime): array
    {
        $result = ['count' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
        
        try {
            if (!$this->config['notify_rules']['advertisement']['enabled']) {
                Log::info('广告监控已禁用');
                return $result;
            }
            
            // 查找需要发送的广告（定时发送 + 立即发送）
            $pendingAds = Advertisement::where('status', 0)
                                    ->where(function($query) use ($currentTime) {
                                        // 定时发送且时间已到
                                        $query->where('send_type', 2)
                                              ->where('send_time', '<=', $currentTime);
                                    })
                                    ->whereOr(function($query) {
                                        // 立即发送
                                        $query->where('send_type', 1);
                                    })
                                    ->order('created_at', 'asc')
                                    ->select();
            
            $result['count'] = count($pendingAds);
            Log::info("发现 {$result['count']} 条待发送广告");
            
            foreach ($pendingAds as $ad) {
                try {
                    // 更新状态为发送中
                    $ad->status = 1;
                    $ad->save();
                    
                    $sendResults = $this->telegramService->sendToAllGroups(
                        'advertisement_notify',
                        $ad->toArray(),
                        'advertisement',
                        $ad->id
                    );
                    
                    $success = array_sum(array_column($sendResults, 'success'));
                    $total = count($sendResults);
                    
                    // 更新广告状态
                    $ad->status = 2; // 已发送
                    $ad->sent_count = $success;
                    $ad->total_count = $total;
                    $ad->save();
                    
                    $result['sent'] += $success;
                    $result['failed'] += ($total - $success);
                    
                    Log::info("广告发送完成 - ID: {$ad->id}, 成功: {$success}, 失败: " . ($total - $success));
                    
                } catch (\Exception $e) {
                    // 发送失败，恢复状态
                    $ad->status = 0;
                    $ad->save();
                    
                    $result['failed']++;
                    $result['errors'][] = "广告ID {$ad->id}: " . $e->getMessage();
                    Log::error("广告发送失败 - ID: {$ad->id}, 错误: " . $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $result['errors'][] = "广告表检查失败: " . $e->getMessage();
            Log::error("广告表检查失败: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * 获取上次检查时间
     */
    private function getLastCheckTime(): string
    {
        $lastTime = Cache::get($this->lastCheckTimeKey);
        
        if (!$lastTime) {
            // 首次运行，设置为当前时间减去检查间隔
            $interval = $this->config['check_interval'] + $this->config['overlap_time'];
            $lastTime = date('Y-m-d H:i:s', time() - $interval);
            Log::info("首次运行，设置检查时间为: {$lastTime}");
        }
        
        return $lastTime;
    }
    
    /**
     * 更新最后检查时间
     */
    private function updateLastCheckTime(string $time): void
    {
        $ttl = $this->config['cache_config']['cache_ttl'];
        Cache::set($this->lastCheckTimeKey, $time, $ttl);
        Log::info("更新检查时间为: {$time}");
    }
    
    /**
     * 计算统计摘要
     */
    private function calculateSummary(array &$results): void
    {
        $summary = &$results['summary'];
        
        foreach ($results['processed'] as $type => $data) {
            $summary['total_processed'] += $data['count'];
            $summary['total_sent'] += $data['sent'];
            $summary['total_failed'] += $data['failed'];
        }
    }
    
    /**
     * 检查系统状态
     */
    public function checkSystemStatus(): array
    {
        $status = [
            'monitor_enabled' => $this->config['enabled'],
            'last_check_time' => Cache::get($this->lastCheckTimeKey),
            'telegram_bot_status' => $this->checkTelegramBotStatus(),
            'database_status' => $this->checkDatabaseStatus(),
            'cache_status' => $this->checkCacheStatus()
        ];
        
        return $status;
    }
    
    /**
     * 检查 Telegram Bot 状态
     */
    private function checkTelegramBotStatus(): bool
    {
        try {
            // 简单检查：尝试获取机器人信息
            $testResult = $this->telegramService->checkBotInGroup('@test');
            return true; // 如果没有异常，说明 Bot Token 有效
        } catch (\Exception $e) {
            Log::error("Telegram Bot 状态检查失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查数据库状态
     */
    private function checkDatabaseStatus(): bool
    {
        try {
            Db::query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            Log::error("数据库状态检查失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查缓存状态
     */
    private function checkCacheStatus(): bool
    {
        try {
            $testKey = 'monitor_cache_test_' . time();
            Cache::set($testKey, 'test', 10);
            $result = Cache::get($testKey) === 'test';
            Cache::delete($testKey);
            return $result;
        } catch (\Exception $e) {
            Log::error("缓存状态检查失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 手动触发指定类型的检查
     */
    public function manualCheck(string $type, int $limit = 10): array
    {
        $lastCheckTime = date('Y-m-d H:i:s', time() - 3600); // 检查最近1小时
        
        return match($type) {
            'recharge' => $this->checkRechargeTable($lastCheckTime),
            'withdraw' => $this->checkWithdrawTable($lastCheckTime),
            'redpacket' => $this->checkRedPacketTable($lastCheckTime),
            'advertisement' => $this->checkAdvertisementTable(date('Y-m-d H:i:s')),
            default => throw new \Exception("不支持的检查类型: {$type}")
        };
    }
}