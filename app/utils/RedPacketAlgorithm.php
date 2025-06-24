<?php
declare(strict_types=1);

namespace app\utils;

use think\facade\Log;
use think\exception\ValidateException;

/**
 * 红包算法工具类
 * 负责各种红包金额分配算法的实现
 */
class RedPacketAlgorithm
{
    // 算法类型常量
    const TYPE_RANDOM = 'random';        // 拼手气红包
    const TYPE_AVERAGE = 'average';      // 平均分配红包
    const TYPE_CUSTOM = 'custom';        // 自定义红包
    
    // 默认配置
    const DEFAULT_MIN_AMOUNT = 0.01;     // 最小单个红包金额
    const DEFAULT_PRECISION = 2;         // 金额精度（小数位）
    
    /**
     * 生成红包金额分配
     * 
     * @param float $totalAmount 红包总金额
     * @param int $totalCount 红包个数
     * @param string $type 分配类型
     * @param array $options 额外选项
     * @return array 金额数组
     * @throws ValidateException
     */
    public static function generateAmounts(
        float $totalAmount, 
        int $totalCount, 
        string $type = self::TYPE_RANDOM,
        array $options = []
    ): array {
        // 参数验证
        self::validateParams($totalAmount, $totalCount, $options);
        
        switch ($type) {
            case self::TYPE_RANDOM:
                return self::generateRandomAmounts($totalAmount, $totalCount, $options);
            
            case self::TYPE_AVERAGE:
                return self::generateAverageAmounts($totalAmount, $totalCount, $options);
            
            case self::TYPE_CUSTOM:
                return self::generateCustomAmounts($totalAmount, $totalCount, $options);
            
            default:
                throw new ValidateException("不支持的红包类型: {$type}");
        }
    }
    
    /**
     * 生成拼手气红包金额（二倍均值算法）
     * 核心算法：每次随机金额不超过剩余平均值的2倍
     * 
     * @param float $totalAmount 总金额
     * @param int $totalCount 红包个数
     * @param array $options 选项
     * @return array
     */
    public static function generateRandomAmounts(float $totalAmount, int $totalCount, array $options = []): array
    {
        $amounts = [];
        $remainAmount = $totalAmount;
        $remainCount = $totalCount;
        $minAmount = $options['min_amount'] ?? self::DEFAULT_MIN_AMOUNT;
        $precision = $options['precision'] ?? self::DEFAULT_PRECISION;
        
        // 确保最小金额合理
        if ($minAmount * $totalCount > $totalAmount) {
            throw new ValidateException('红包总金额不足以分配');
        }
        
        try {
            // 生成前 n-1 个红包
            for ($i = 0; $i < $totalCount - 1; $i++) {
                $amount = self::calculateRandomAmount($remainAmount, $remainCount, $minAmount);
                $amounts[] = round($amount, $precision);
                
                $remainAmount -= $amount;
                $remainCount--;
                
                // 防止剩余金额不足
                if ($remainAmount < $minAmount) {
                    $remainAmount = $minAmount;
                }
            }
            
            // 最后一个红包为剩余金额
            $amounts[] = round($remainAmount, $precision);
            
            // 验证总额
            $calculatedTotal = array_sum($amounts);
            if (abs($calculatedTotal - $totalAmount) > 0.01) {
                Log::warning('红包分配金额不匹配', [
                    'expected' => $totalAmount,
                    'calculated' => $calculatedTotal,
                    'amounts' => $amounts
                ]);
                
                // 调整最后一个红包金额
                $amounts[count($amounts) - 1] = round($totalAmount - array_sum(array_slice($amounts, 0, -1)), $precision);
            }
            
            // 打乱红包顺序，增加随机性
            shuffle($amounts);
            
            // 确保所有金额都大于最小值
            foreach ($amounts as &$amount) {
                if ($amount < $minAmount) {
                    $amount = $minAmount;
                }
            }
            
            return $amounts;
            
        } catch (\Exception $e) {
            Log::error('生成随机红包金额失败', [
                'total_amount' => $totalAmount,
                'total_count' => $totalCount,
                'error' => $e->getMessage()
            ]);
            throw new ValidateException('红包金额生成失败');
        }
    }
    
    /**
     * 计算单个随机红包金额（二倍均值算法核心）
     * 
     * @param float $remainAmount 剩余金额
     * @param int $remainCount 剩余个数
     * @param float $minAmount 最小金额
     * @return float
     */
    private static function calculateRandomAmount(float $remainAmount, int $remainCount, float $minAmount): float
    {
        // 如果只剩1个红包，返回全部剩余金额
        if ($remainCount == 1) {
            return $remainAmount;
        }
        
        // 计算平均值
        $avgAmount = $remainAmount / $remainCount;
        
        // 计算随机范围：最小值 到 平均值的2倍
        $minRandom = $minAmount;
        $maxRandom = $avgAmount * 2;
        
        // 确保不会导致后续红包无法分配
        $maxAllowed = $remainAmount - ($remainCount - 1) * $minAmount;
        $maxRandom = min($maxRandom, $maxAllowed);
        
        // 确保范围有效
        if ($maxRandom <= $minRandom) {
            return $minAmount;
        }
        
        // 生成随机金额（使用更好的随机算法）
        $randomAmount = $minRandom + (mt_rand() / mt_getrandmax()) * ($maxRandom - $minRandom);
        
        return $randomAmount;
    }
    
    /**
     * 生成平均分配红包金额
     * 
     * @param float $totalAmount 总金额
     * @param int $totalCount 红包个数
     * @param array $options 选项
     * @return array
     */
    public static function generateAverageAmounts(float $totalAmount, int $totalCount, array $options = []): array
    {
        $precision = $options['precision'] ?? self::DEFAULT_PRECISION;
        $amounts = [];
        
        // 计算平均金额
        $avgAmount = $totalAmount / $totalCount;
        $avgAmount = floor($avgAmount * pow(10, $precision)) / pow(10, $precision);
        
        // 生成 n-1 个平均金额
        for ($i = 0; $i < $totalCount - 1; $i++) {
            $amounts[] = $avgAmount;
        }
        
        // 最后一个红包处理剩余金额
        $lastAmount = $totalAmount - ($avgAmount * ($totalCount - 1));
        $amounts[] = round($lastAmount, $precision);
        
        return $amounts;
    }
    
    /**
     * 生成自定义红包金额
     * 
     * @param float $totalAmount 总金额
     * @param int $totalCount 红包个数
     * @param array $options 选项（必须包含 custom_amounts）
     * @return array
     */
    public static function generateCustomAmounts(float $totalAmount, int $totalCount, array $options = []): array
    {
        if (empty($options['custom_amounts'])) {
            throw new ValidateException('自定义红包需要提供 custom_amounts 参数');
        }
        
        $customAmounts = $options['custom_amounts'];
        
        // 验证个数
        if (count($customAmounts) !== $totalCount) {
            throw new ValidateException('自定义金额个数与红包个数不匹配');
        }
        
        // 验证总额
        $customTotal = array_sum($customAmounts);
        if (abs($customTotal - $totalAmount) > 0.01) {
            throw new ValidateException('自定义金额总和与红包总金额不匹配');
        }
        
        return $customAmounts;
    }
    
    /**
     * 计算手气最佳
     * 
     * @param array $amounts 所有红包金额
     * @return int 手气最佳的索引
     */
    public static function findBestLuck(array $amounts): int
    {
        if (empty($amounts)) {
            return 0;
        }
        
        $maxAmount = max($amounts);
        return array_search($maxAmount, $amounts);
    }
    
    /**
     * 验证红包参数
     * 
     * @param float $totalAmount
     * @param int $totalCount
     * @param array $options
     * @throws ValidateException
     */
    private static function validateParams(float $totalAmount, int $totalCount, array $options = []): void
    {
        $minAmount = $options['min_amount'] ?? self::DEFAULT_MIN_AMOUNT;
        
        if ($totalAmount <= 0) {
            throw new ValidateException('红包总金额必须大于0');
        }
        
        if ($totalCount <= 0) {
            throw new ValidateException('红包个数必须大于0');
        }
        
        if ($totalAmount < $minAmount * $totalCount) {
            throw new ValidateException("红包总金额不能少于 " . ($minAmount * $totalCount) . " 元");
        }
        
        if ($totalCount > 1000) {
            throw new ValidateException('红包个数不能超过1000个');
        }
    }
    
    /**
     * 获取算法统计信息
     * 
     * @param array $amounts 金额数组
     * @return array 统计信息
     */
    public static function getStatistics(array $amounts): array
    {
        if (empty($amounts)) {
            return [];
        }
        
        $total = array_sum($amounts);
        $count = count($amounts);
        $average = $total / $count;
        $max = max($amounts);
        $min = min($amounts);
        
        // 计算方差
        $variance = 0;
        foreach ($amounts as $amount) {
            $variance += pow($amount - $average, 2);
        }
        $variance = $variance / $count;
        $stdDev = sqrt($variance);
        
        return [
            'total_amount' => round($total, 2),
            'count' => $count,
            'average' => round($average, 2),
            'max_amount' => round($max, 2),
            'min_amount' => round($min, 2),
            'variance' => round($variance, 4),
            'std_deviation' => round($stdDev, 4),
            'coefficient_variation' => $average > 0 ? round($stdDev / $average, 4) : 0
        ];
    }
    
    /**
     * 模拟红包分配测试
     * 
     * @param float $totalAmount
     * @param int $totalCount
     * @param string $type
     * @param int $iterations 测试次数
     * @return array 测试结果
     */
    public static function testAlgorithm(
        float $totalAmount, 
        int $totalCount, 
        string $type = self::TYPE_RANDOM,
        int $iterations = 100
    ): array {
        $results = [];
        $allStats = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $amounts = self::generateAmounts($totalAmount, $totalCount, $type);
                $stats = self::getStatistics($amounts);
                $allStats[] = $stats;
                
                $results[] = [
                    'iteration' => $i + 1,
                    'amounts' => $amounts,
                    'statistics' => $stats,
                    'total_check' => abs($stats['total_amount'] - $totalAmount) < 0.01
                ];
                
            } catch (\Exception $e) {
                $results[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // 计算总体统计
        $overallStats = [
            'algorithm_type' => $type,
            'test_params' => [
                'total_amount' => $totalAmount,
                'total_count' => $totalCount,
                'iterations' => $iterations
            ],
            'success_rate' => count(array_filter($results, fn($r) => isset($r['total_check']) && $r['total_check'])) / $iterations,
            'avg_variance' => !empty($allStats) ? array_sum(array_column($allStats, 'variance')) / count($allStats) : 0,
            'avg_std_deviation' => !empty($allStats) ? array_sum(array_column($allStats, 'std_deviation')) / count($allStats) : 0
        ];
        
        return [
            'overall_statistics' => $overallStats,
            'test_results' => $results
        ];
    }
}