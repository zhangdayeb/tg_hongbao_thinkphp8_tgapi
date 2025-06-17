<?php
// 文件位置: app/controller/admin/StatController.php
// 后台统计报表控制器 - 精简版

declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\User;
use app\model\Recharge;
use app\model\Withdraw;
use app\model\RedPacket;
use app\model\RedPacketRecord;
use app\model\MoneyLog;
use think\Request;
use think\Response;

class StatController extends BaseController
{
    /**
     * 总览仪表板 - 核心数据展示
     */
    public function dashboard(Request $request): Response
    {
        try {
            $today = date('Y-m-d');
            $todayStart = strtotime($today . ' 00:00:00');
            $todayEnd = strtotime($today . ' 23:59:59');
            
            // 用户数据
            $userStats = [
                'total_users' => User::count(),
                'today_new_users' => User::where('create_time', 'between', [$todayStart, $todayEnd])->count(),
                'active_users' => User::where('last_activity_at', '>', date('Y-m-d H:i:s', time() - 86400))->count(),
                'agent_count' => User::where('type', User::TYPE_AGENT)->count(),
                'member_count' => User::where('type', User::TYPE_MEMBER)->count(),
            ];
            
            // 财务数据
            $financeStats = [
                'today_recharge_amount' => Recharge::where('status', 1)
                    ->where('create_time', 'between', [$todayStart, $todayEnd])
                    ->sum('money') ?: 0,
                'today_recharge_count' => Recharge::where('status', 1)
                    ->where('create_time', 'between', [$todayStart, $todayEnd])
                    ->count(),
                'today_withdraw_amount' => Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$todayStart, $todayEnd])
                    ->sum('money') ?: 0,
                'today_withdraw_count' => Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$todayStart, $todayEnd])
                    ->count(),
                'pending_recharge' => Recharge::where('status', 0)->count(),
                'pending_withdraw' => Withdraw::where('status', 0)->count(),
            ];
            
            // 红包数据
            $redPacketStats = [
                'today_packet_count' => RedPacket::where('create_time', 'between', [$todayStart, $todayEnd])->count(),
                'today_packet_amount' => RedPacket::where('create_time', 'between', [$todayStart, $todayEnd])
                    ->sum('total_amount') ?: 0,
                'today_grabbed_amount' => RedPacketRecord::where('create_time', 'between', [$todayStart, $todayEnd])
                    ->sum('amount') ?: 0,
                'active_packets' => RedPacket::where('status', 1)->count(),
            ];
            
            // 系统状态
            $systemStats = [
                'total_balance' => User::sum('money_balance') ?: 0,
                'online_users' => User::where('last_activity_at', '>', date('Y-m-d H:i:s', time() - 300))->count(), // 5分钟内活跃
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'user' => $userStats,
                    'finance' => $financeStats,
                    'redpacket' => $redPacketStats,
                    'system' => $systemStats,
                    'update_time' => date('Y-m-d H:i:s')
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 用户统计 - 增长趋势和分布
     */
    public function userStat(Request $request): Response
    {
        try {
            $days = $request->param('days', 7); // 统计天数
            $groupBy = $request->param('group_by', 'day'); // day, week, month
            
            // 用户增长趋势
            $growthTrend = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', time() - ($i * 86400));
                $dayStart = strtotime($date . ' 00:00:00');
                $dayEnd = strtotime($date . ' 23:59:59');
                
                $growthTrend[] = [
                    'date' => $date,
                    'new_users' => User::where('create_time', 'between', [$dayStart, $dayEnd])->count(),
                    'active_users' => User::where('last_activity_at', 'between', 
                        [date('Y-m-d H:i:s', $dayStart), date('Y-m-d H:i:s', $dayEnd)])->count(),
                ];
            }
            
            // 用户类型分布
            $typeDistribution = [
                'agent' => User::where('type', User::TYPE_AGENT)->count(),
                'member' => User::where('type', User::TYPE_MEMBER)->count(),
            ];
            
            // 用户状态分布
            $statusDistribution = [
                'normal' => User::where('status', User::STATUS_NORMAL)->count(),
                'disabled' => User::where('status', User::STATUS_DISABLED)->count(),
            ];
            
            // 用户注册来源分布（按是否绑定TG）
            $sourceDistribution = [
                'telegram_bound' => User::where('tg_id', '<>', '')->count(),
                'not_bound' => User::where('tg_id', '')->count(),
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'growth_trend' => $growthTrend,
                    'type_distribution' => $typeDistribution,
                    'status_distribution' => $statusDistribution,
                    'source_distribution' => $sourceDistribution,
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 活跃用户分析
     */
    public function activeUsers(Request $request): Response
    {
        try {
            $days = $request->param('days', 7);
            
            // 各类活跃用户统计
            $activeStats = [];
            
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', time() - ($i * 86400));
                $dayStart = strtotime($date . ' 00:00:00');
                $dayEnd = strtotime($date . ' 23:59:59');
                
                $activeStats[] = [
                    'date' => $date,
                    // 登录活跃用户（有最后活动时间的）
                    'login_active' => User::where('last_activity_at', 'between', 
                        [date('Y-m-d H:i:s', $dayStart), date('Y-m-d H:i:s', $dayEnd)])->count(),
                    
                    // 充值活跃用户
                    'recharge_active' => Recharge::where('create_time', 'between', [$dayStart, $dayEnd])
                        ->where('status', 1)->distinct('user_id')->count(),
                    
                    // 提现活跃用户
                    'withdraw_active' => Withdraw::where('create_time', 'between', [$dayStart, $dayEnd])
                        ->distinct('user_id')->count(),
                    
                    // 发红包活跃用户
                    'send_packet_active' => RedPacket::where('create_time', 'between', [$dayStart, $dayEnd])
                        ->distinct('sender_id')->count(),
                    
                    // 抢红包活跃用户
                    'grab_packet_active' => RedPacketRecord::where('create_time', 'between', [$dayStart, $dayEnd])
                        ->distinct('user_id')->count(),
                ];
            }
            
            // 活跃用户TOP10（按红包金额）
            $topActiveUsers = RedPacketRecord::with(['user'])
                ->where('create_time', '>', time() - ($days * 86400))
                ->field('user_id, count(*) as grab_count, sum(amount) as total_amount')
                ->group('user_id')
                ->order('total_amount desc')
                ->limit(10)
                ->select();
            
            $topUsers = [];
            foreach ($topActiveUsers as $record) {
                $topUsers[] = [
                    'user_id' => $record->user_id,
                    'user_name' => $record->user->user_name ?? '',
                    'grab_count' => $record->grab_count,
                    'total_amount' => $record->total_amount,
                ];
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'daily_active' => $activeStats,
                    'top_users' => $topUsers,
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 财务统计 - 收入分析
     */
    public function revenueStat(Request $request): Response
    {
        try {
            $days = $request->param('days', 30);
            
            // 每日收入趋势
            $dailyRevenue = [];
            $totalRecharge = 0;
            $totalWithdraw = 0;
            $totalCommission = 0;
            
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', time() - ($i * 86400));
                $dayStart = strtotime($date . ' 00:00:00');
                $dayEnd = strtotime($date . ' 23:59:59');
                
                $rechargeAmount = Recharge::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])
                    ->sum('money') ?: 0;
                
                $withdrawAmount = Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])
                    ->sum('money') ?: 0;
                
                $withdrawCommission = Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])
                    ->sum('money_fee') ?: 0;
                
                $dailyRevenue[] = [
                    'date' => $date,
                    'recharge' => $rechargeAmount,
                    'withdraw' => $withdrawAmount,
                    'commission' => $withdrawCommission,
                    'net_revenue' => $rechargeAmount - $withdrawAmount + $withdrawCommission,
                ];
                
                $totalRecharge += $rechargeAmount;
                $totalWithdraw += $withdrawAmount;
                $totalCommission += $withdrawCommission;
            }
            
            // 收入来源分布
            $revenueSource = [
                'recharge_income' => $totalRecharge,
                'withdraw_commission' => $totalCommission,
                'total_income' => $totalRecharge + $totalCommission,
                'total_withdraw' => $totalWithdraw,
                'net_profit' => $totalRecharge + $totalCommission - $totalWithdraw,
            ];
            
            // 充值方式分布
            $rechargeByMethod = Recharge::where('status', 1)
                ->where('create_time', '>', time() - ($days * 86400))
                ->field('payment_method, count(*) as count, sum(money) as amount')
                ->group('payment_method')
                ->select();
            
            $methodDistribution = [];
            foreach ($rechargeByMethod as $method) {
                $methodDistribution[] = [
                    'method' => $method->payment_method,
                    'method_text' => $method->payment_method === 'usdt' ? 'USDT充值' : '汇旺充值',
                    'count' => $method->count,
                    'amount' => $method->amount,
                ];
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'daily_revenue' => $dailyRevenue,
                    'revenue_summary' => $revenueSource,
                    'method_distribution' => $methodDistribution,
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 每日运营报表 - 综合数据汇总
     */
    public function dailyReport(Request $request): Response
    {
        try {
            $date = $request->param('date', date('Y-m-d'));
            $dayStart = strtotime($date . ' 00:00:00');
            $dayEnd = strtotime($date . ' 23:59:59');
            
            // 用户数据
            $userReport = [
                'new_users' => User::where('create_time', 'between', [$dayStart, $dayEnd])->count(),
                'active_users' => User::where('last_activity_at', 'between', 
                    [date('Y-m-d H:i:s', $dayStart), date('Y-m-d H:i:s', $dayEnd)])->count(),
            ];
            
            // 财务数据
            $financeReport = [
                'recharge_count' => Recharge::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])->count(),
                'recharge_amount' => Recharge::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])->sum('money') ?: 0,
                'withdraw_count' => Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])->count(),
                'withdraw_amount' => Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])->sum('money') ?: 0,
                'commission_amount' => Withdraw::where('status', 1)
                    ->where('create_time', 'between', [$dayStart, $dayEnd])->sum('money_fee') ?: 0,
            ];
            
            // 红包数据
            $redpacketReport = [
                'packet_count' => RedPacket::where('create_time', 'between', [$dayStart, $dayEnd])->count(),
                'packet_amount' => RedPacket::where('create_time', 'between', [$dayStart, $dayEnd])
                    ->sum('total_amount') ?: 0,
                'grabbed_count' => RedPacketRecord::where('create_time', 'between', [$dayStart, $dayEnd])->count(),
                'grabbed_amount' => RedPacketRecord::where('create_time', 'between', [$dayStart, $dayEnd])
                    ->sum('amount') ?: 0,
                'active_groups' => RedPacket::where('create_time', 'between', [$dayStart, $dayEnd])
                    ->where('chat_id', '<', 0)->distinct('chat_id')->count(),
            ];
            
            // 昨日对比数据
            $yesterdayStart = $dayStart - 86400;
            $yesterdayEnd = $dayEnd - 86400;
            
            $yesterdayData = [
                'new_users' => User::where('create_time', 'between', [$yesterdayStart, $yesterdayEnd])->count(),
                'recharge_amount' => Recharge::where('status', 1)
                    ->where('create_time', 'between', [$yesterdayStart, $yesterdayEnd])->sum('money') ?: 0,
                'packet_amount' => RedPacket::where('create_time', 'between', [$yesterdayStart, $yesterdayEnd])
                    ->sum('total_amount') ?: 0,
            ];
            
            // 计算增长率
            $growth = [
                'user_growth' => $this->calculateGrowthRate($yesterdayData['new_users'], $userReport['new_users']),
                'recharge_growth' => $this->calculateGrowthRate($yesterdayData['recharge_amount'], $financeReport['recharge_amount']),
                'packet_growth' => $this->calculateGrowthRate($yesterdayData['packet_amount'], $redpacketReport['packet_amount']),
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'date' => $date,
                    'user' => $userReport,
                    'finance' => $financeReport,
                    'redpacket' => $redpacketReport,
                    'growth' => $growth,
                    'yesterday' => $yesterdayData,
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 实时数据 - 用于仪表板实时更新
     */
    public function realtime(Request $request): Response
    {
        try {
            $now = time();
            $todayStart = strtotime(date('Y-m-d 00:00:00'));
            
            // 实时数据
            $realtimeData = [
                // 在线用户（5分钟内活跃）
                'online_users' => User::where('last_activity_at', '>', date('Y-m-d H:i:s', $now - 300))->count(),
                
                // 今日数据
                'today_new_users' => User::where('create_time', '>=', $todayStart)->count(),
                'today_recharge' => Recharge::where('status', 1)->where('create_time', '>=', $todayStart)->sum('money') ?: 0,
                'today_packets' => RedPacket::where('create_time', '>=', $todayStart)->count(),
                
                // 待处理事项
                'pending_recharge' => Recharge::where('status', 0)->count(),
                'pending_withdraw' => Withdraw::where('status', 0)->count(),
                'active_packets' => RedPacket::where('status', 1)->count(),
                
                // 系统状态
                'total_users' => User::count(),
                'total_balance' => User::sum('money_balance') ?: 0,
                
                'timestamp' => $now,
                'update_time' => date('H:i:s')
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $realtimeData
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 计算增长率
     */
    private function calculateGrowthRate($yesterday, $today): float
    {
        if ($yesterday == 0) {
            return $today > 0 ? 100.0 : 0.0;
        }
        
        return round((($today - $yesterday) / $yesterday) * 100, 1);
    }
}