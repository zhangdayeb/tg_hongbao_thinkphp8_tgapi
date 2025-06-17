<?php
// 文件位置: app/controller/admin/PaymentController.php
// 后台支付/余额管理控制器 + Telegram广播功能

declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\User;
use app\model\UserLog;
use app\model\Recharge;
use app\model\Withdraw;
use app\service\TelegramService;
use app\service\TelegramBroadcastService;
use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class PaymentController extends BaseController
{
    private TelegramService $telegramService;
    private TelegramBroadcastService $telegramBroadcastService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->telegramBroadcastService = new TelegramBroadcastService();
    }
    
    /**
     * 充值记录列表
     */
    public function rechargeList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $status = $request->param('status', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            $keyword = $request->param('keyword', '');
            
            $query = Db::name('recharge')
                      ->alias('r')
                      ->leftJoin('user u', 'r.user_id = u.id')
                      ->field('r.*, u.username, u.email, u.telegram_username')
                      ->order('r.create_time', 'desc');
            
            // 状态筛选
            if ($status !== '') {
                $query->where('r.status', $status);
            }
            
            // 时间范围
            if (!empty($startTime)) {
                $query->where('r.create_time', '>=', strtotime($startTime));
            }
            if (!empty($endTime)) {
                $query->where('r.create_time', '<=', strtotime($endTime . ' 23:59:59'));
            }
            
            // 关键词搜索
            if (!empty($keyword)) {
                $query->where(function($q) use ($keyword) {
                    $q->whereLike('u.username', "%{$keyword}%")
                      ->whereOr('r.order_no', 'like', "%{$keyword}%")
                      ->whereOr('r.trade_no', 'like', "%{$keyword}%");
                });
            }
            
            $recharges = $query->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
            
            // 格式化数据
            $list = $recharges->items();
            foreach ($list as &$item) {
                $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
                $item['update_time_text'] = $item['update_time'] ? date('Y-m-d H:i:s', $item['update_time']) : '';
                $item['status_text'] = $this->getRechargeStatusText($item['status']);
                $item['payment_method_text'] = $this->getPaymentMethodText($item['payment_method']);
            }
            
            // 统计数据
            $stats = $this->getRechargeStats($startTime, $endTime);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $recharges->total(),
                    'page' => $page,
                    'limit' => $limit,
                    'stats' => $stats
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
     * 充值详情
     */
    public function rechargeDetail(Request $request): Response
    {
        try {
            $id = $request->param('id');
            
            $recharge = Db::name('recharge')
                         ->alias('r')
                         ->leftJoin('user u', 'r.user_id = u.id')
                         ->field('r.*, u.username, u.email, u.telegram_username')
                         ->where('r.id', $id)
                         ->find();
            
            if (!$recharge) {
                return json(['code' => 404, 'msg' => '充值记录不存在']);
            }
            
            // 格式化数据
            $recharge['create_time_text'] = date('Y-m-d H:i:s', $recharge['create_time']);
            $recharge['update_time_text'] = $recharge['update_time'] ? date('Y-m-d H:i:s', $recharge['update_time']) : '';
            $recharge['status_text'] = $this->getRechargeStatusText($recharge['status']);
            $recharge['payment_method_text'] = $this->getPaymentMethodText($recharge['payment_method']);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $recharge
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 手动确认充值
     */
    public function confirmRecharge(Request $request): Response
    {
        try {
            $id = $request->param('id');
            $remark = $request->param('remark', '管理员手动确认');
            $enableBroadcast = $request->param('enable_broadcast', true); // 🔥 新增：广播开关
            
            $recharge = Db::name('recharge')->where('id', $id)->find();
            if (!$recharge) {
                return json(['code' => 404, 'msg' => '充值记录不存在']);
            }
            
            if ($recharge['status'] != 0) {
                return json(['code' => 400, 'msg' => '该充值记录状态不允许确认']);
            }
            
            Db::startTrans();
            
            try {
                // 更新充值状态
                Db::name('recharge')->where('id', $id)->update([
                    'status' => 1,
                    'remark' => $remark,
                    'update_time' => time()
                ]);
                
                // 增加用户余额
                Db::name('user')->where('id', $recharge['user_id'])->inc('balance', $recharge['amount']);
                
                // 记录余额变动日志
                UserLog::create([
                    'user_id' => $recharge['user_id'],
                    'action' => 'balance_add',
                    'description' => sprintf('充值到账 +%.2f，订单号：%s', $recharge['amount'], $recharge['order_no']),
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent'),
                    'create_time' => time()
                ]);
                
                Db::commit();
                
                // 🔥 新增：Telegram广播功能
                if ($enableBroadcast && $this->isBroadcastEnabled('recharge_success')) {
                    $this->sendRechargeBroadcast($recharge, 'success');
                }
                
                return json([
                    'code' => 200,
                    'msg' => '充值确认成功'
                ]);
                
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '确认失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 提现申请列表
     */
    public function withdrawList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $status = $request->param('status', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            $keyword = $request->param('keyword', '');
            
            $query = Db::name('withdraw')
                      ->alias('w')
                      ->leftJoin('user u', 'w.user_id = u.id')
                      ->field('w.*, u.username, u.email, u.telegram_username')
                      ->order('w.create_time', 'desc');
            
            // 状态筛选
            if ($status !== '') {
                $query->where('w.status', $status);
            }
            
            // 时间范围
            if (!empty($startTime)) {
                $query->where('w.create_time', '>=', strtotime($startTime));
            }
            if (!empty($endTime)) {
                $query->where('w.create_time', '<=', strtotime($endTime . ' 23:59:59'));
            }
            
            // 关键词搜索
            if (!empty($keyword)) {
                $query->where(function($q) use ($keyword) {
                    $q->whereLike('u.username', "%{$keyword}%")
                      ->whereOr('w.order_no', 'like', "%{$keyword}%");
                });
            }
            
            $withdraws = $query->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
            
            // 格式化数据
            $list = $withdraws->items();
            foreach ($list as &$item) {
                $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
                $item['update_time_text'] = $item['update_time'] ? date('Y-m-d H:i:s', $item['update_time']) : '';
                $item['status_text'] = $this->getWithdrawStatusText($item['status']);
                $item['actual_amount'] = $item['amount'] - $item['fee'];
            }
            
            // 统计数据
            $stats = $this->getWithdrawStats($startTime, $endTime);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $withdraws->total(),
                    'page' => $page,
                    'limit' => $limit,
                    'stats' => $stats
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
     * 批准提现 - 移除广播功能
     */
    public function approveWithdraw(Request $request): Response
    {
        try {
            $id = $request->param('id');
            $remark = $request->param('remark', '管理员批准');
            
            $withdraw = Db::name('withdraw')->where('id', $id)->find();
            if (!$withdraw) {
                return json(['code' => 404, 'msg' => '提现记录不存在']);
            }
            
            if ($withdraw['status'] != 0) {
                return json(['code' => 400, 'msg' => '该提现申请状态不允许批准']);
            }
            
            // 更新提现状态
            Db::name('withdraw')->where('id', $id)->update([
                'status' => 1,
                'remark' => $remark,
                'update_time' => time()
            ]);
            
            // 记录操作日志
            UserLog::create([
                'user_id' => $withdraw['user_id'],
                'action' => 'withdraw_approve',
                'description' => sprintf('提现申请已批准，金额：%.2f，订单号：%s', $withdraw['amount'], $withdraw['order_no']),
                'ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'create_time' => time()
            ]);
            
            // 🔥 删除所有广播相关代码
            /*
            if ($enableBroadcast && $this->isBroadcastEnabled('withdraw_success')) {
                $this->sendWithdrawBroadcast($withdraw, 'success');
            }
            */
            
            return json([
                'code' => 200,
                'msg' => '提现批准成功'
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 拒绝提现
     */
    public function rejectWithdraw(Request $request): Response
    {
        try {
            $id = $request->param('id');
            $reason = $request->param('reason', '管理员拒绝');
            
            $withdraw = Db::name('withdraw')->where('id', $id)->find();
            if (!$withdraw) {
                return json(['code' => 404, 'msg' => '提现记录不存在']);
            }
            
            if ($withdraw['status'] != 0) {
                return json(['code' => 400, 'msg' => '该提现申请状态不允许拒绝']);
            }
            
            Db::startTrans();
            
            try {
                // 更新提现状态
                Db::name('withdraw')->where('id', $id)->update([
                    'status' => 2,
                    'remark' => $reason,
                    'update_time' => time()
                ]);
                
                // 退回用户余额
                Db::name('user')->where('id', $withdraw['user_id'])->inc('balance', $withdraw['amount']);
                
                // 记录余额变动日志
                UserLog::create([
                    'user_id' => $withdraw['user_id'],
                    'action' => 'balance_add',
                    'description' => sprintf('提现被拒绝，退回余额 +%.2f，原因：%s', $withdraw['amount'], $reason),
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent'),
                    'create_time' => time()
                ]);
                
                Db::commit();
                
                return json([
                    'code' => 200,
                    'msg' => '提现拒绝成功，余额已退回'
                ]);
                
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 手动调整用户余额
     */
    public function manualAdjust(Request $request): Response
    {
        try {
            $userId = $request->param('user_id');
            $amount = $request->param('amount');
            $type = $request->param('type'); // add 或 sub
            $remark = $request->param('remark', '管理员手动调整');
            
            if (!in_array($type, ['add', 'sub'])) {
                return json(['code' => 400, 'msg' => '操作类型错误']);
            }
            
            if (!is_numeric($amount) || $amount <= 0) {
                return json(['code' => 400, 'msg' => '金额必须大于0']);
            }
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            Db::startTrans();
            
            try {
                $oldBalance = $user->balance;
                
                if ($type === 'add') {
                    $newBalance = $oldBalance + $amount;
                } else {
                    if ($oldBalance < $amount) {
                        return json(['code' => 400, 'msg' => '用户余额不足']);
                    }
                    $newBalance = $oldBalance - $amount;
                }
                
                // 更新余额
                $user->save(['balance' => $newBalance]);
                
                // 记录操作日志
                UserLog::create([
                    'user_id' => $userId,
                    'action' => 'balance_' . $type,
                    'description' => sprintf(
                        '管理员手动调整余额: %s%.2f, 余额: %.2f -> %.2f, 备注: %s',
                        $type === 'add' ? '+' : '-',
                        $amount,
                        $oldBalance,
                        $newBalance,
                        $remark
                    ),
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent'),
                    'create_time' => time()
                ]);
                
                Db::commit();
                
                return json([
                    'code' => 200,
                    'msg' => '余额调整成功',
                    'data' => [
                        'old_balance' => $oldBalance,
                        'new_balance' => $newBalance,
                        'change_amount' => $amount
                    ]
                ]);
                
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '调整失败: ' . $e->getMessage()
            ]);
        }
    }
    
    // =================== 🔥 新增：Telegram广播相关功能 ===================
    
    /**
     * 获取广播配置
     */
    public function getBroadcastConfig(Request $request): Response
    {
        try {
            $config = [
                'recharge_success' => $this->isBroadcastEnabled('recharge_success'),
                'withdraw_success' => $this->isBroadcastEnabled('withdraw_success'),
                'recharge_apply' => $this->isBroadcastEnabled('recharge_apply'),
                'withdraw_apply' => $this->isBroadcastEnabled('withdraw_apply'),
                'active_groups' => count($this->telegramService->getActiveGroups())
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $config
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 设置广播配置
     */
    public function setBroadcastConfig(Request $request): Response
    {
        try {
            $config = $request->only([
                'recharge_success',
                'withdraw_success', 
                'recharge_apply',
                'withdraw_apply'
            ]);
            
            foreach ($config as $key => $value) {
                $this->setBroadcastEnabled($key, (bool)$value);
            }
            
            return json([
                'code' => 200,
                'msg' => '配置保存成功'
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '保存失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取广播日志
     */
    public function getBroadcastLogs(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $type = $request->param('type', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            
            // 从缓存获取广播日志
            $cacheKey = 'telegram_broadcast_logs';
            $logs = Cache::get($cacheKey, []);
            
            // 筛选日志
            $filteredLogs = array_filter($logs, function($log) use ($type, $startTime, $endTime) {
                if (!empty($type) && $log['type'] !== $type) {
                    return false;
                }
                
                if (!empty($startTime) && $log['create_time'] < strtotime($startTime)) {
                    return false;
                }
                
                if (!empty($endTime) && $log['create_time'] > strtotime($endTime . ' 23:59:59')) {
                    return false;
                }
                
                return true;
            });
            
            // 排序和分页
            usort($filteredLogs, function($a, $b) {
                return $b['create_time'] - $a['create_time'];
            });
            
            $total = count($filteredLogs);
            $offset = ($page - 1) * $limit;
            $paginatedLogs = array_slice($filteredLogs, $offset, $limit);
            
            // 格式化时间
            foreach ($paginatedLogs as &$log) {
                $log['create_time_text'] = date('Y-m-d H:i:s', $log['create_time']);
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $paginatedLogs,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
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
     * 清空广播日志
     */
    public function clearBroadcastLogs(Request $request): Response
    {
        try {
            Cache::delete('telegram_broadcast_logs');
            
            return json([
                'code' => 200,
                'msg' => '日志清空成功'
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '清空失败: ' . $e->getMessage()
            ]);
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 发送充值广播
     */
    private function sendRechargeBroadcast(array $recharge, string $action): void
    {
        try {
            $user = Db::name('user')->where('id', $recharge['user_id'])->find();
            if (!$user) return;
                        
            // 记录广播日志
            $this->logBroadcast('recharge_success', $broadcastData, $result);
            
        } catch (\Exception $e) {
            Log::error('发送充值广播失败: ' . $e->getMessage());
            
            // 记录失败日志
            $this->logBroadcast('recharge_success', ['error' => $e->getMessage()], [
                'code' => 500,
                'msg' => '广播失败'
            ]);
        }
    }
    
    /**
     * 发送提现广播
     */
    private function sendWithdrawBroadcast(array $withdraw, string $action): void
    {
        try {
            $user = Db::name('user')->where('id', $withdraw['user_id'])->find();
            if (!$user) return;
            
            $broadcastData = [
                'type' => 'withdraw',
                'action' => $action,
                'user' => $user,
                'amount' => $withdraw['amount'],
                'order_no' => $withdraw['order_no'],
                'time' => date('Y-m-d H:i:s')
            ];
            
            $result = $this->telegramBroadcastService->broadcastWithdrawSuccess($broadcastData);
            
            // 记录广播日志
            $this->logBroadcast('withdraw_success', $broadcastData, $result);
            
        } catch (\Exception $e) {
            Log::error('发送提现广播失败: ' . $e->getMessage());
            
            // 记录失败日志
            $this->logBroadcast('withdraw_success', ['error' => $e->getMessage()], [
                'code' => 500,
                'msg' => '广播失败'
            ]);
        }
    }
    
    /**
     * 检查广播是否启用
     */
    private function isBroadcastEnabled(string $type): bool
    {
        return (bool)Cache::get("telegram_broadcast_enabled_{$type}", true);
    }
    
    /**
     * 设置广播启用状态
     */
    private function setBroadcastEnabled(string $type, bool $enabled): void
    {
        Cache::set("telegram_broadcast_enabled_{$type}", $enabled, 86400 * 30); // 30天
    }
    
    /**
     * 记录广播日志
     */
    private function logBroadcast(string $type, array $data, array $result): void
    {
        try {
            $log = [
                'id' => uniqid(),
                'type' => $type,
                'data' => $data,
                'result' => $result,
                'success' => $result['code'] == 200,
                'create_time' => time(),
                'admin_user' => session('admin.username', 'system')
            ];
            
            // 获取现有日志
            $cacheKey = 'telegram_broadcast_logs';
            $logs = Cache::get($cacheKey, []);
            
            // 添加新日志
            array_unshift($logs, $log);
            
            // 保留最新1000条日志
            $logs = array_slice($logs, 0, 1000);
            
            // 保存到缓存
            Cache::set($cacheKey, $logs, 86400 * 7); // 7天
            
        } catch (\Exception $e) {
            Log::error('记录广播日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取充值状态文本
     */
    private function getRechargeStatusText(int $status): string
    {
        $statusMap = [
            0 => '待支付',
            1 => '已完成',
            2 => '已取消',
            3 => '支付失败'
        ];
        
        return $statusMap[$status] ?? '未知';
    }
    
    /**
     * 获取支付方式文本
     */
    private function getPaymentMethodText(string $method): string
    {
        $methodMap = [
            'telegram_stars' => 'Telegram Stars',
            'usdt_trc20' => 'USDT-TRC20',
            'usdt_erc20' => 'USDT-ERC20',
            'huiwang' => '汇旺支付',
            'manual' => '人工充值'
        ];
        
        return $methodMap[$method] ?? $method;
    }
    
    /**
     * 获取提现状态文本
     */
    private function getWithdrawStatusText(int $status): string
    {
        $statusMap = [
            0 => '待审核',
            1 => '已批准',
            2 => '已拒绝',
            3 => '已完成'
        ];
        
        return $statusMap[$status] ?? '未知';
    }
    
    /**
     * 获取充值统计数据
     */
    private function getRechargeStats(string $startTime = '', string $endTime = ''): array
    {
        $query = Db::name('recharge');
        
        if (!empty($startTime)) {
            $query->where('create_time', '>=', strtotime($startTime));
        }
        if (!empty($endTime)) {
            $query->where('create_time', '<=', strtotime($endTime . ' 23:59:59'));
        }
        
        return [
            'total_count' => $query->count(),
            'total_amount' => $query->sum('amount') ?: 0,
            'success_count' => $query->where('status', 1)->count(),
            'success_amount' => $query->where('status', 1)->sum('amount') ?: 0,
            'pending_count' => $query->where('status', 0)->count(),
            'pending_amount' => $query->where('status', 0)->sum('amount') ?: 0
        ];
    }
    
    /**
     * 获取提现统计数据
     */
    private function getWithdrawStats(string $startTime = '', string $endTime = ''): array
    {
        $query = Db::name('withdraw');
        
        if (!empty($startTime)) {
            $query->where('create_time', '>=', strtotime($startTime));
        }
        if (!empty($endTime)) {
            $query->where('create_time', '<=', strtotime($endTime . ' 23:59:59'));
        }
        
        return [
            'total_count' => $query->count(),
            'total_amount' => $query->sum('amount') ?: 0,
            'approved_count' => $query->where('status', 1)->count(),
            'approved_amount' => $query->where('status', 1)->sum('amount') ?: 0,
            'pending_count' => $query->where('status', 0)->count(),
            'pending_amount' => $query->where('status', 0)->sum('amount') ?: 0
        ];
    }
}