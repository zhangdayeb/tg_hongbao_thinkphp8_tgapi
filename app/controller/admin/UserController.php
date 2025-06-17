<?php
// 文件位置: app/controller/admin/UserController.php
// 后台用户管理控制器 - 使用Model方式

declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\UserService;
use app\model\User;
use app\model\UserLog;
use app\model\Recharge;
use app\model\Withdraw;
use app\model\RedPacket;
use think\Request;
use think\Response;
use think\exception\ValidateException;

class UserController extends BaseController
{
    protected $userService;
    
    public function __construct()
    {
        $this->userService = new UserService();
    }
    
    /**
     * 用户列表(分页、搜索、筛选)
     */
    public function index(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $keyword = $request->param('keyword', '');
            $status = $request->param('status', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            
            // 使用User Model查询
            $query = User::order('id', 'desc');
            
            // 关键词搜索
            if (!empty($keyword)) {
                $query->where(function($q) use ($keyword) {
                    $q->whereLike('user_name', "%{$keyword}%")
                      ->whereOr('phone', 'like', "%{$keyword}%")
                      ->whereOr('tg_username', 'like', "%{$keyword}%");
                });
            }
            
            // 状态筛选
            if ($status !== '') {
                $query->where('status', $status);
            }
            
            // 时间范围筛选
            if (!empty($startTime)) {
                $query->where('create_time', '>=', strtotime($startTime));
            }
            if (!empty($endTime)) {
                $query->where('create_time', '<=', strtotime($endTime . ' 23:59:59'));
            }
            
            $users = $query->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
            
            // 格式化数据 - 使用Model的获取器
            $list = [];
            foreach ($users->items() as $user) {
                $userData = $user->toArray();
                $userData['register_time_text'] = date('Y-m-d H:i:s', $user->create_time);
                $userData['last_activity_text'] = $user->last_activity_at ? date('Y-m-d H:i:s', strtotime($user->last_activity_at)) : '从未活跃';
                $userData['status_text'] = $user->status_text; // 使用Model获取器
                $userData['type_text'] = $user->type_text; // 使用Model获取器
                $userData['formatted_balance'] = $user->formatted_balance; // 使用Model获取器
                
                $list[] = $userData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $users->total(),
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
     * 查看用户详情
     */
    public function show(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 使用Model关联查询获取统计数据
            $userData = $user->toArray();
            $userData['register_time_text'] = date('Y-m-d H:i:s', $user->create_time);
            $userData['last_activity_text'] = $user->last_activity_at ? date('Y-m-d H:i:s', strtotime($user->last_activity_at)) : '从未活跃';
            $userData['status_text'] = $user->status_text;
            $userData['type_text'] = $user->type_text;
            $userData['formatted_balance'] = $user->formatted_balance;
            
            // 获取用户统计 - 使用Model关联
            $stats = [
                'total_recharge' => $user->recharges()->where('status', 1)->sum('money') ?: 0,
                'total_withdraw' => $user->withdraws()->where('status', 1)->sum('money') ?: 0,
                'recharge_count' => $user->recharges()->where('status', 1)->count(),
                'withdraw_count' => $user->withdraws()->where('status', 1)->count(),
                'sent_redpackets' => $user->sentRedPackets()->count(),
                'received_redpackets' => $user->redPacketRecords()->count(),
            ];
            
            // 获取最近充值记录 - 使用Model关联
            $recentRecharges = $user->recharges()
                                   ->order('create_time', 'desc')
                                   ->limit(5)
                                   ->select();
            
            // 获取最近提现记录 - 使用Model关联  
            $recentWithdraws = $user->withdraws()
                                   ->order('create_time', 'desc')
                                   ->limit(5)
                                   ->select();
            
            // 获取最近操作日志
            $recentLogs = UserLog::where('user_id', $userId)
                                ->order('create_time', 'desc')
                                ->limit(10)
                                ->select();
            
            foreach ($recentLogs as &$log) {
                $log['create_time_text'] = date('Y-m-d H:i:s', $log['create_time']);
                $log['action_text'] = $this->getActionText($log['action']);
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'user' => $userData,
                    'stats' => $stats,
                    'recent_recharges' => $recentRecharges->toArray(),
                    'recent_withdraws' => $recentWithdraws->toArray(),
                    'recent_logs' => $recentLogs->toArray()
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
     * 启用用户
     */
    public function enable(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            $user->status = User::STATUS_NORMAL;
            $user->save();
            
            // 记录操作日志 - 使用UserLog Model
            UserLog::create([
                'user_id' => $userId,
                'action' => 'admin_enable',
                'description' => '管理员启用账户',
                'ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'create_time' => time()
            ]);
            
            return json([
                'code' => 200,
                'msg' => '用户已启用'
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 禁用用户
     */
    public function disable(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            $reason = $request->param('reason', '管理员操作');
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            $user->status = User::STATUS_DISABLED;
            $user->save();
            
            // 记录操作日志 - 使用UserLog Model
            UserLog::create([
                'user_id' => $userId,
                'action' => 'admin_disable',
                'description' => '管理员禁用账户，原因: ' . $reason,
                'ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'create_time' => time()
            ]);
            
            return json([
                'code' => 200,
                'msg' => '用户已禁用'
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 手动调整余额 - 使用UserService
     */
    public function updateBalance(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            $amount = $request->param('amount');
            $type = $request->param('type'); // add 或 sub
            $remark = $request->param('remark', '管理员操作');
            
            if (!in_array($type, ['add', 'sub'])) {
                return json(['code' => 400, 'msg' => '操作类型错误']);
            }
            
            if (!is_numeric($amount) || $amount <= 0) {
                return json(['code' => 400, 'msg' => '金额必须大于0']);
            }
            
            // 使用UserService处理余额更新
            $result = $this->userService->updateBalance($userId, (float)$amount, $type, $remark);
            
            return json($result);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '操作失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取用户资金流水
     */
    public function moneyLogs(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $type = $request->param('type', '');
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 使用User Model的关联查询
            $query = $user->moneyLogs()->order('create_time', 'desc');
            
            // 按类型筛选
            if (!empty($type)) {
                $query->where('type', $type);
            }
            
            $logs = $query->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
            
            // 格式化数据 - 使用MoneyLog Model的获取器
            $list = [];
            foreach ($logs->items() as $log) {
                $logData = $log->toArray();
                $logData['create_time_text'] = date('Y-m-d H:i:s', $log->create_time);
                $logData['type_text'] = $log->type_text; // 使用Model获取器
                $logData['status_text'] = $log->status_text; // 使用Model获取器
                $logData['direction'] = $log->direction; // 使用Model获取器
                $logData['formatted_money'] = $log->formatted_money; // 使用Model获取器
                
                $list[] = $logData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $logs->total(),
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
     * 获取用户红包记录
     */
    public function redPackets(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $type = $request->param('type', 'sent'); // sent 发送的, received 收到的
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            if ($type === 'sent') {
                // 发送的红包 - 使用User Model关联
                $query = $user->sentRedPackets()->order('create_time', 'desc');
            } else {
                // 收到的红包 - 使用User Model关联
                $query = $user->redPacketRecords()->order('create_time', 'desc');
            }
            
            $records = $query->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
            
            // 格式化数据
            $list = [];
            foreach ($records->items() as $record) {
                $recordData = $record->toArray();
                $recordData['create_time_text'] = date('Y-m-d H:i:s', $record->create_time);
                
                if ($type === 'sent') {
                    // 发送的红包
                    $recordData['status_text'] = $record->status_text;
                    $recordData['type_text'] = $record->type_text;
                    $recordData['formatted_total'] = $record->formatted_total;
                } else {
                    // 收到的红包
                    $recordData['formatted_amount'] = $record->formatted_amount;
                    $recordData['luck_level'] = $record->luck_level;
                }
                
                $list[] = $recordData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $records->total(),
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
     * 重置用户密码
     */
    public function resetPassword(Request $request): Response
    {
        try {
            $userId = $request->param('id');
            $newPassword = $request->param('password', '123456');
            
            $user = User::find($userId);
            if (!$user) {
                return json(['code' => 404, 'msg' => '用户不存在']);
            }
            
            // 使用User Model的密码修改器
            $user->pwd = $newPassword; // 会自动base64编码
            $user->save();
            
            // 记录操作日志
            UserLog::create([
                'user_id' => $userId,
                'action' => 'admin_reset_password',
                'description' => '管理员重置密码',
                'ip' => request()->ip(),
                'user_agent' => request()->header('User-Agent'),
                'create_time' => time()
            ]);
            
            return json([
                'code' => 200,
                'msg' => '密码重置成功',
                'data' => [
                    'new_password' => $newPassword
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '重置失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取操作类型文本
     */
    private function getActionText(string $action): string
    {
        $actionMap = [
            'register' => '用户注册',
            'login' => '用户登录',
            'login_failed' => '登录失败',
            'logout' => '用户登出',
            'change_password' => '修改密码',
            'update_info' => '更新信息',
            'bind_telegram' => '绑定Telegram',
            'balance_add' => '余额增加',
            'balance_sub' => '余额扣减',
            'admin_enable' => '管理员启用',
            'admin_disable' => '管理员禁用',
            'admin_reset_password' => '管理员重置密码',
        ];
        
        return $actionMap[$action] ?? $action;
    }
}