<?php
// 文件位置: app/service/PaymentStatsService.php
// 支付统计服务 - 处理支付相关的统计和查询功能

declare(strict_types=1);

namespace app\service;

use app\model\PaymentOrder;
use app\model\WithdrawOrder;
use app\model\MoneyLog;
use think\facade\Log;

class PaymentStatsService
{
    // 支付状态常量
    const STATUS_PENDING = 0;    // 待审核
    const STATUS_SUCCESS = 1;    // 成功
    const STATUS_FAILED = 2;     // 失败
    const STATUS_CANCELLED = 3;  // 已取消
    
    // =================== 1. 用户统计功能 ===================
    
    /**
     * 获取用户充值提现统计
     */
    public function getPaymentStats(int $userId): array
    {
        try {
            // 充值统计
            $rechargeStats = PaymentOrder::where('user_id', $userId)
                                       ->where('status', self::STATUS_SUCCESS)
                                       ->field('COUNT(*) as count, SUM(money) as total_amount')
                                       ->find();
            
            // 提现统计
            $withdrawStats = WithdrawOrder::where('user_id', $userId)
                                        ->where('status', self::STATUS_SUCCESS)
                                        ->field('COUNT(*) as count, SUM(money) as total_amount, SUM(money_fee) as total_fee')
                                        ->find();
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'recharge' => [
                        'count' => $rechargeStats->count ?? 0,
                        'total_amount' => $rechargeStats->total_amount ?? 0
                    ],
                    'withdraw' => [
                        'count' => $withdrawStats->count ?? 0,
                        'total_amount' => $withdrawStats->total_amount ?? 0,
                        'total_fee' => $withdrawStats->total_fee ?? 0
                    ]
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取支付统计失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取资金流水记录
     */
    public function getMoneyLog(int $userId, array $params = []): array
    {
        try {
            $page = $params['page'] ?? 1;
            $limit = min($params['limit'] ?? 20, 100);
            $type = $params['type'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = [['uid', '=', $userId]];
            if ($type) {
                $where[] = ['type', '=', $type];
            }
            
            $logs = MoneyLog::where($where)
                          ->order('create_time', 'desc')
                          ->limit($offset, $limit)
                          ->select();
            
            $total = MoneyLog::where($where)->count();
            
            return [
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $logs,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取资金流水失败: ' . $e->getMessage());
            throw $e;
        }
    }
}