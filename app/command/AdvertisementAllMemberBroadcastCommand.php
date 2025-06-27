<?php
declare(strict_types=1);

namespace app\command;

use app\service\AdvertisementAllMemberBroadcastService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

/**
 * 全体会员广告私发命令
 * 执行命令: php think advertisement:allmember
 */
class AdvertisementAllMemberBroadcastCommand extends Command
{
    protected function configure()
    {
        $this->setName('advertisement:allmember')
             ->setDescription('全体会员广告私发命令 - 处理 is_all_member=1 的广告');
    }
    
    protected function execute(Input $input, Output $output)
    {
        $startTime = microtime(true);
        
        try {
            $output->writeln("<info>🚀 开始执行全体会员广告私发...</info>");
            Log::info("=== 全体会员广告私发任务开始 ===");
            
            // 创建服务实例
            $service = new AdvertisementAllMemberBroadcastService();
            
            // 执行私发任务
            $result = $service->processBroadcast();
            
            // 计算执行时间
            $executionTime = round(microtime(true) - $startTime, 2);
            $result['execution_time'] = $executionTime;
            
            // 输出执行结果
            $this->outputResults($output, $result);
            
            Log::info("=== 全体会员广告私发任务完成 ===", [
                'execution_time' => $executionTime,
                'summary' => $result['summary']
            ]);
            
            return 0; // 成功
            
        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            
            $output->writeln("<error>❌ 执行失败: " . $e->getMessage() . "</error>");
            $output->writeln("<comment>执行耗时: {$executionTime} 秒</comment>");
            
            Log::error("全体会员广告私发任务失败", [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime,
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1; // 失败
        }
    }
    
    /**
     * 输出执行结果
     */
    private function outputResults(Output $output, array $result): void
    {
        $summary = $result['summary'];
        $executionTime = $result['execution_time'];
        
        $output->writeln("");
        $output->writeln("<info>📊 执行统计:</info>");
        $output->writeln("   ├─ 处理广告: <comment>{$summary['ads_processed']}</comment> 条");
        $output->writeln("   ├─ 目标会员: <comment>{$summary['total_members']}</comment> 个");
        $output->writeln("   ├─ 总发送量: <comment>{$summary['total_messages']}</comment> 条");
        $output->writeln("   ├─ 成功发送: <comment>{$summary['success_count']}</comment> 条");
        $output->writeln("   ├─ 失败发送: <comment>{$summary['failed_count']}</comment> 条");
        
        // 计算成功率
        if ($summary['total_messages'] > 0) {
            $successRate = round(($summary['success_count'] / $summary['total_messages']) * 100, 1);
            $output->writeln("   ├─ 成功率: <comment>{$successRate}%</comment>");
        }
        
        $output->writeln("   └─ 执行耗时: <comment>{$executionTime}</comment> 秒");
        $output->writeln("");
        
        // 详细广告处理结果
        if (!empty($result['advertisements'])) {
            $output->writeln("<info>📤 广告发送详情:</info>");
            foreach ($result['advertisements'] as $ad) {
                $adSuccessRate = $ad['total_sent'] > 0 ? 
                    round(($ad['success_count'] / $ad['total_sent']) * 100, 1) : 0;
                    
                $output->writeln("   ├─ 广告ID {$ad['id']}: " . 
                    "成功 <comment>{$ad['success_count']}</comment> / " .
                    "失败 <comment>{$ad['failed_count']}</comment> " .
                    "(<comment>{$adSuccessRate}%</comment>)");
            }
            $output->writeln("");
        }
        
        // 错误信息
        if (!empty($result['errors'])) {
            $output->writeln("<error>⚠️  执行错误:</error>");
            foreach ($result['errors'] as $error) {
                $output->writeln("   └─ {$error}");
            }
            $output->writeln("");
        }
        
        if ($summary['ads_processed'] > 0) {
            $output->writeln("<info>✅ 全体会员广告私发执行完成!</info>");
        } else {
            $output->writeln("<comment>ℹ️  当前没有需要私发的广告</comment>");
        }
    }
}