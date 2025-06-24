<?php
// 文件位置: app/service/PaymentConfigService.php
// 支付配置服务 - 处理支付相关的配置管理

declare(strict_types=1);

namespace app\service;

use app\model\DepositMethod;
use think\facade\Log;

class PaymentConfigService
{
    // 手续费配置
    const WITHDRAW_FEE_RATE = 0.01; // 提现手续费率 1%
    const MIN_WITHDRAW_AMOUNT = 10; // 最小提现金额
    const MAX_WITHDRAW_AMOUNT = 10000; // 最大提现金额
    
    // =================== 1. 获取支付配置 ===================
    
    /**
     * 获取支付配置
     */
    public function getPaymentConfig(): array
    {
        try {
            $config = [
                'recharge' => [
                    'methods' => $this->getDepositMethods()['data'],
                    'min_amount' => 10,
                    'max_amount' => 50000
                ],
                'withdraw' => [
                    'min_amount' => self::MIN_WITHDRAW_AMOUNT,
                    'max_amount' => self::MAX_WITHDRAW_AMOUNT,
                    'fee_rate' => self::WITHDRAW_FEE_RATE,
                    'processing_time' => '1-24小时'
                ]
            ];
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $config
            ];
            
        } catch (\Exception $e) {
            Log::error('获取支付配置失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 计算手续费
     */
    public function getFeeCalculation(float $amount, string $type): array
    {
        try {
            $fee = 0;
            $actualAmount = $amount;
            
            if ($type === 'withdraw') {
                $fee = $amount * self::WITHDRAW_FEE_RATE;
                $actualAmount = $amount; // 提现是从总额中扣除手续费
            }
            
            return [
                'code' => 200,
                'msg' => '计算成功',
                'data' => [
                    'amount' => $amount,
                    'fee' => $fee,
                    'actual_amount' => $actualAmount,
                    'total_deduct' => $amount + $fee
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('计算手续费失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 获取充值方式列表
     */
    private function getDepositMethods(): array
    {
        try {
            $methods = DepositMethod::where('is_enabled', 1)
                                  ->order('sort_order', 'asc')
                                  ->select();
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => $methods
            ];
            
        } catch (\Exception $e) {
            Log::error('获取充值方式失败: ' . $e->getMessage());
            throw $e;
        }
    }
}