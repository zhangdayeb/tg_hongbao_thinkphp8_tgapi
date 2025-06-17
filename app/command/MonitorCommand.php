<?php
declare(strict_types=1);

namespace app\command;

use app\service\MonitorNotificationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

/**
 * 监控任务命令类
 * 适用于 ThinkPHP8 + PHP8.2
 * 
 * 使用方法：
 * php think monitor:run                    // 执行一次监控
 * php think monitor:status                 // 检查系统状态
 * php think monitor:check recharge         // 手动检查指定类型
 */
class MonitorCommand extends Command
{
    protected static $defaultName = 'monitor';
    protected static $defaultDescription = '数据监控通知系统';

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('monitor')
             ->setDescription('数据监控通知系统')
             ->addArgument('action', \think\console\input\Argument::OPTIONAL, '操作类型：run/status/check', 'run')
             ->addArgument('type', \think\console\input\Argument::OPTIONAL, '检查类型：recharge/withdraw/redpacket/advertisement', '')
             ->addOption('limit', 'l', \think\console\input\Option::VALUE_OPTIONAL, '限制处理数量', 50)
             ->addOption('force', 'f', \think\console\input\Option::VALUE_NONE, '强制执行（忽略配置开关）')
             ->addOption('verbose', 'v', \think\console\input\Option::VALUE_NONE, '详细输出')
             ->addOption('dry-run', 'd', \think\console\input\Option::VALUE_NONE, '模拟运行（不实际发送消息）');
    }

    /**
     * 执行命令
     */
    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        $verbose = $input->hasOption('verbose');
        
        try {
            // 设置内存和时间限制
            ini_set('memory_limit', '256M');
            set_time_limit(300);
            
            $startTime = microtime(true);
            
            if ($verbose) {
                $output->writeln("<info>开始执行监控任务...</info>");
                $output->writeln("<comment>执行时间: " . date('Y-m-d H:i:s') . "</comment>");
            }
            
            $result = match($action) {
                'run' => $this->executeMonitor($input, $output),
                'status' => $this->executeStatus($input, $output),
                'check' => $this->executeCheck($input, $output),
                default => $this->showHelp($output)
            };
            
            $executionTime = round(microtime(true) - $startTime, 2);
            
            if ($verbose) {
                $output->writeln("<comment>任务完成，耗时: {$executionTime}秒</comment>");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $output->writeln("<error>命令执行失败: " . $e->getMessage() . "</error>");
            Log::error("监控命令执行失败: " . $e->getMessage(), [
                'action' => $action,
                'trace' => $e->getTraceAsString()
            ]);
            return self::FAILURE;
        }
    }

    /**
     * 执行监控任务
     */
    private function executeMonitor(Input $input, Output $output): int
    {
        $force = $input->hasOption('force');
        $dryRun = $input->hasOption('dry-run');
        $verbose = $input->hasOption('verbose');
        
        try {
            if ($dryRun) {
                $output->writeln("<comment>模拟运行模式，不会实际发送消息</comment>");
            }
            
            $monitorService = new MonitorNotificationService();
            
            // 检查系统状态
            if (!$force) {
                $status = $monitorService->checkSystemStatus();
                if (!$status['monitor_enabled']) {
                    $output->writeln("<error>监控系统已禁用，使用 --force 强制执行</error>");
                    return self::FAILURE;
                }
                
                if (!$status['telegram_bot_status']) {
                    $output->writeln("<error>Telegram Bot 状态异常</error>");
                    return self::FAILURE;
                }
            }
            
            // 执行监控
            $results = $monitorService->runMonitor();
            
            // 输出结果
            $this->outputMonitorResults($results, $output, $verbose);
            
            // 记录成功日志
            Log::info("监控任务执行成功", [
                'processed' => $results['summary']['total_processed'],
                'sent' => $results['summary']['total_sent'],
                'failed' => $results['summary']['total_failed'],
                'execution_time' => $results['execution_time'] ?? 0
            ]);
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>监控任务执行失败: " . $e->getMessage() . "</error>");
            Log::error("监控任务执行失败: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * 检查系统状态
     */
    private function executeStatus(Input $input, Output $output): int
    {
        try {
            $monitorService = new MonitorNotificationService();
            $status = $monitorService->checkSystemStatus();
            
            $output->writeln("<info>系统状态检查结果:</info>");
            $output->writeln("监控启用状态: " . ($status['monitor_enabled'] ? '<info>启用</info>' : '<error>禁用</error>'));
            $output->writeln("Telegram Bot: " . ($status['telegram_bot_status'] ? '<info>正常</info>' : '<error>异常</error>'));
            $output->writeln("数据库状态: " . ($status['database_status'] ? '<info>正常</info>' : '<error>异常</error>'));
            $output->writeln("缓存状态: " . ($status['cache_status'] ? '<info>正常</info>' : '<error>异常</error>'));
            $output->writeln("最后检查时间: " . ($status['last_check_time'] ?: '<comment>未执行过</comment>'));
            
            // 检查配置
            $config = config('monitor_config');
            $output->writeln("\n<info>配置信息:</info>");
            $output->writeln("检查间隔: {$config['check_interval']}秒");
            $output->writeln("充值监控: " . ($config['notify_rules']['recharge']['enabled'] ? '启用' : '禁用'));
            $output->writeln("提现监控: " . ($config['notify_rules']['withdraw']['enabled'] ? '启用' : '禁用'));
            $output->writeln("红包监控: " . ($config['notify_rules']['redpacket']['enabled'] ? '启用' : '禁用'));
            $output->writeln("广告监控: " . ($config['notify_rules']['advertisement']['enabled'] ? '启用' : '禁用'));
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>状态检查失败: " . $e->getMessage() . "</error>");
            return self::FAILURE;
        }
    }

    /**
     * 手动检查指定类型
     */
    private function executeCheck(Input $input, Output $output): int
    {
        $type = $input->getArgument('type');
        $limit = (int)$input->getOption('limit');
        $verbose = $input->hasOption('verbose');
        
        if (empty($type)) {
            $output->writeln("<error>请指定检查类型: recharge, withdraw, redpacket, advertisement</error>");
            return self::FAILURE;
        }
        
        if (!in_array($type, ['recharge', 'withdraw', 'redpacket', 'advertisement'])) {
            $output->writeln("<error>不支持的检查类型: {$type}</error>");
            return self::FAILURE;
        }
        
        try {
            $monitorService = new MonitorNotificationService();
            $results = $monitorService->manualCheck($type, $limit);
            
            $output->writeln("<info>手动检查 {$type} 结果:</info>");
            $output->writeln("发现记录: {$results['count']}条");
            $output->writeln("发送成功: {$results['sent']}条");
            $output->writeln("发送失败: {$results['failed']}条");
            
            if (!empty($results['errors']) && $verbose) {
                $output->writeln("\n<comment>错误详情:</comment>");
                foreach ($results['errors'] as $error) {
                    $output->writeln("<error>  - {$error}</error>");
                }
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>手动检查失败: " . $e->getMessage() . "</error>");
            return self::FAILURE;
        }
    }

    /**
     * 显示帮助信息
     */
    private function showHelp(Output $output): int
    {
        $output->writeln("<info>数据监控通知系统</info>");
        $output->writeln("");
        $output->writeln("<comment>使用方法:</comment>");
        $output->writeln("  php think monitor:run                    执行一次完整监控");
        $output->writeln("  php think monitor:status                 检查系统状态");
        $output->writeln("  php think monitor:check recharge         手动检查充值");
        $output->writeln("  php think monitor:check withdraw         手动检查提现");
        $output->writeln("  php think monitor:check redpacket        手动检查红包");
        $output->writeln("  php think monitor:check advertisement    手动检查广告");
        $output->writeln("");
        $output->writeln("<comment>选项:</comment>");
        $output->writeln("  -f, --force                              强制执行（忽略配置开关）");
        $output->writeln("  -v, --verbose                            详细输出");
        $output->writeln("  -d, --dry-run                            模拟运行（不实际发送）");
        $output->writeln("  -l, --limit <数量>                       限制处理数量（默认50）");
        $output->writeln("");
        $output->writeln("<comment>示例:</comment>");
        $output->writeln("  php think monitor:run --verbose          详细模式执行监控");
        $output->writeln("  php think monitor:run --force            强制执行监控");
        $output->writeln("  php think monitor:check recharge -l 10   检查最近10条充值");
        
        return self::SUCCESS;
    }

    /**
     * 输出监控结果
     */
    private function outputMonitorResults(array $results, Output $output, bool $verbose = false): void
    {
        $summary = $results['summary'];
        
        $output->writeln("<info>监控任务执行完成</info>");
        $output->writeln("总处理记录: {$summary['total_processed']}条");
        $output->writeln("发送成功: {$summary['total_sent']}条");
        $output->writeln("发送失败: {$summary['total_failed']}条");
        
        if (isset($results['execution_time'])) {
            $output->writeln("执行耗时: {$results['execution_time']}秒");
        }
        
        if ($verbose && isset($results['processed'])) {
            $output->writeln("\n<comment>详细结果:</comment>");
            
            foreach ($results['processed'] as $type => $data) {
                if ($data['count'] > 0) {
                    $output->writeln("  {$type}: 发现{$data['count']}条, 成功{$data['sent']}条, 失败{$data['failed']}条");
                    
                    if (!empty($data['errors'])) {
                        foreach ($data['errors'] as $error) {
                            $output->writeln("    <error>- {$error}</error>");
                        }
                    }
                }
            }
        }
        
        // 如果有错误，显示警告
        if ($summary['total_failed'] > 0) {
            $output->writeln("\n<comment>注意: 有 {$summary['total_failed']} 条消息发送失败，请检查日志</comment>");
        }
    }
}