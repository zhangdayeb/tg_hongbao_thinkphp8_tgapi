<?php
declare(strict_types=1);

namespace app\command;

use app\service\MonitorNotificationService;
use app\service\TelegramNotificationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

/**
 * 监控任务命令类
 * 适用于 ThinkPHP8 + PHP8.2
 * 
 * 使用方法：
 * php think monitor:start                  // 启动监控系统
 */
class MonitorCommand extends Command
{
    protected static $defaultName = 'monitor:start';
    protected static $defaultDescription = '启动数据监控通知系统';

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('monitor:start')
             ->setDescription('启动数据监控通知系统，默认每30秒检查一次所有监控表');
    }

    /**
     * 执行命令
     */
    protected function execute(Input $input, Output $output)
    {
        try {
            // 设置内存和时间限制
            ini_set('memory_limit', '512M');
            set_time_limit(0); // 无限制，因为是持续运行
            
            $output->writeln("<info>🚀 正在启动数据监控通知系统...</info>");
            $output->writeln("<comment>启动时间: " . date('Y-m-d H:i:s') . "</comment>");
            
            // 执行系统自检
            if (!$this->performSystemCheck($output)) {
                return self::FAILURE;
            }
            
            // 发送启动通知
            $this->sendStartupNotification($output);
            
            // 开始监控循环
            $this->startMonitoringLoop($output);
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>❌ 命令执行失败: " . $e->getMessage() . "</error>");
            Log::error("监控命令执行失败: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return self::FAILURE;
        }
    }

    /**
     * 执行系统自检
     */
    private function performSystemCheck(Output $output): bool
    {
        $output->writeln("<info>🔍 正在进行系统自检...</info>");
        
        try {
            $monitorService = new MonitorNotificationService();
            $status = $monitorService->checkSystemStatus();
            
            // 检查监控系统开关
            if (!$status['monitor_enabled']) {
                $output->writeln("<error>❌ 监控系统已禁用，请检查配置</error>");
                return false;
            }
            
            // 检查Telegram Bot状态
            if (!$status['telegram_bot_status']) {
                $output->writeln("<error>❌ Telegram Bot 状态异常，请检查配置</error>");
                return false;
            }
            
            // 检查数据库状态
            if (!$status['database_status']) {
                $output->writeln("<error>❌ 数据库连接异常</error>");
                return false;
            }
            
            // 检查缓存状态
            if (!$status['cache_status']) {
                $output->writeln("<error>❌ 缓存系统异常</error>");
                return false;
            }
            
            // 显示详细信息
            $output->writeln("  ✅ 监控开关: 已启用");
            $output->writeln("  ✅ Telegram Bot: 正常");
            $output->writeln("  ✅ 数据库连接: 正常");
            $output->writeln("  ✅ 缓存系统: 正常");
            
            // 显示配置信息
            $config = config('monitor_config');
            $output->writeln("  📋 监控配置:");
            $output->writeln("     - 充值监控: " . ($config['notify_rules']['recharge']['enabled'] ? '启用' : '禁用'));
            $output->writeln("     - 提现监控: " . ($config['notify_rules']['withdraw']['enabled'] ? '启用' : '禁用'));
            $output->writeln("     - 红包监控: " . ($config['notify_rules']['redpacket']['enabled'] ? '启用' : '禁用'));
            $output->writeln("     - 广告监控: " . ($config['notify_rules']['advertisement']['enabled'] ? '启用' : '禁用'));
            
            $output->writeln("<info>✅ 系统自检完成，所有组件正常</info>");
            return true;
            
        } catch (\Exception $e) {
            $output->writeln("<error>❌ 系统自检失败: " . $e->getMessage() . "</error>");
            return false;
        }
    }

    /**
     * 发送启动通知
     */
    private function sendStartupNotification(Output $output): void
    {
        $output->writeln("<info>📢 发送系统启动通知...</info>");
        
        try {
            // 直接使用 TelegramNotificationService
            $telegramService = new TelegramNotificationService();
            
            // 创建启动通知数据
            $notificationData = [
                'title' => '🚀 监控系统已启动',
                'message' => '数据监控通知系统已成功启动，正在监控以下业务：\n\n' .
                           '• 💰 充值业务监控\n' .
                           '• 💸 提现业务监控\n' .
                           '• 🧧 红包业务监控\n' .
                           '• 📢 广告业务监控\n\n' .
                           '系统将每30秒检查一次，如有新业务将及时通知。',
                'time' => date('Y-m-d H:i:s')
            ];
            
            // 使用现有的 sendToAllGroups 方法
            $sendResults = $telegramService->sendToAllGroups(
                'system_startup_notify',
                $notificationData,
                'system',
                0
            );
            
            $successCount = 0;
            $failedCount = 0;
            
            foreach ($sendResults as $result) {
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }
            
            if ($successCount > 0) {
                $output->writeln("<info>✅ 启动通知发送成功</info>");
                $output->writeln("  📊 发送统计: 成功{$successCount}个群组, 失败{$failedCount}个群组");
            } else {
                $output->writeln("<comment>⚠️ 启动通知发送失败，但监控将继续运行</comment>");
            }
            
        } catch (\Exception $e) {
            $output->writeln("<comment>⚠️ 启动通知发送异常: " . $e->getMessage() . "，但监控将继续运行</comment>");
        }
    }

    /**
     * 开始监控循环
     */
    private function startMonitoringLoop(Output $output): void
    {
        $interval = 30; // 固定30秒检查间隔
        
        $output->writeln("<info>🔄 监控系统已启动，检查间隔: {$interval}秒 (每{$interval}秒进行一轮全体表检测)</info>");
        $output->writeln("<comment>按 Ctrl+C 停止监控</comment>");
        $output->writeln("");
        
        $monitorService = new MonitorNotificationService();
        $checkCount = 0;
        
        // 注册信号处理器（优雅停止）
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
        
        while (true) {
            try {
                $checkCount++;
                $startTime = microtime(true);
                
                $output->writeln("<comment>[" . date('H:i:s') . "] 执行第 {$checkCount} 次检查...</comment>");
                
                // 执行监控检查
                $results = $monitorService->runMonitor();
                
                $executionTime = round(microtime(true) - $startTime, 2);
                
                // 输出详细结果
                if ($results['summary']['total_processed'] > 0) {
                    $output->writeln("<info>[" . date('H:i:s') . "] 🔔 发现 {$results['summary']['total_processed']} 条新记录，发送成功 {$results['summary']['total_sent']} 条</info>");
                } else {
                    $output->writeln("<comment>[" . date('H:i:s') . "] ✅ 检查完成，无新记录 (耗时: {$executionTime}s)</comment>");
                }
                
                // 如果有失败的消息，显示警告
                if ($results['summary']['total_failed'] > 0) {
                    $output->writeln("<error>[" . date('H:i:s') . "] ⚠️  有 {$results['summary']['total_failed']} 条消息发送失败</error>");
                }
                
                // 记录统计信息
                Log::info("监控检查完成", [
                    'check_count' => $checkCount,
                    'processed' => $results['summary']['total_processed'],
                    'sent' => $results['summary']['total_sent'],
                    'failed' => $results['summary']['total_failed'],
                    'execution_time' => $executionTime
                ]);
                
                // 等待下次检查
                sleep($interval);
                
                // 处理信号
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                
            } catch (\Exception $e) {
                $output->writeln("<error>[" . date('H:i:s') . "] ❌ 监控检查异常: " . $e->getMessage() . "</error>");
                Log::error("监控检查异常: " . $e->getMessage());
                
                // 出错后等待较短时间再继续
                sleep(min($interval, 30));
            }
        }
    }

    /**
     * 处理停止信号
     */
    public function handleSignal(int $signo): void
    {
        echo "\n🛑 收到停止信号，正在优雅关闭监控系统...\n";
        Log::info("监控系统收到停止信号，正在关闭");
        exit(0);
    }
}