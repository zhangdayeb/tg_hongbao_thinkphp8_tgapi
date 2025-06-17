<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\User;
use app\model\TgCrowdList;
use app\model\TgMessageLog;
use app\model\TgAdvertisement;
use app\model\RedPacket;
use app\service\TelegramService;
use app\service\TelegramBroadcastService;
use app\service\TelegramRedPacketService;
use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use think\exception\ValidateException;

/**
 * 后台Telegram管理控制器
 */
class TelegramController extends BaseController
{
    private TelegramService $telegramService;
    private TelegramBroadcastService $telegramBroadcastService;
    private TelegramRedPacketService $telegramRedPacketService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->telegramBroadcastService = new TelegramBroadcastService();
        $this->telegramRedPacketService = new TelegramRedPacketService();
    }
    
    // =================== 群组管理 ===================
    
    /**
     * 群组列表
     */
    public function groupList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $keyword = $request->param('keyword', '');
            $status = $request->param('status', '');
            $botStatus = $request->param('bot_status', '');
            
            $query = TgCrowdList::where('del', 0)->order('id', 'desc');
            
            // 关键词搜索
            if (!empty($keyword)) {
                $query->where('title|crowd_id', 'like', "%{$keyword}%");
            }
            
            // 状态筛选
            if ($status !== '') {
                $query->where('is_active', $status);
            }
            
            // 机器人状态筛选
            if (!empty($botStatus)) {
                $query->where('bot_status', $botStatus);
            }
            
            $total = $query->count();
            $groups = $query->page($page, $limit)->select();
            
            // 格式化数据
            $groupList = [];
            foreach ($groups as $group) {
                $groupData = $group->toArray();
                $groupData['status_text'] = $group->is_active ? '活跃' : '不活跃';
                $groupData['bot_status_text'] = $this->getBotStatusText($group->bot_status);
                $groupData['broadcast_status_text'] = $group->broadcast_enabled ? '启用' : '禁用';
                $groupData['created_time'] = date('Y-m-d H:i:s', strtotime($group->created_at));
                $groupData['updated_time'] = date('Y-m-d H:i:s', strtotime($group->updated_at));
                $groupList[] = $groupData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $groupList,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取群组列表失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取群组列表失败']);
        }
    }
    
    /**
     * 添加群组
     */
    public function addGroup(Request $request): Response
    {
        try {
            $crowdId = $request->param('crowd_id');
            $title = $request->param('title');
            $description = $request->param('description', '');
            
            // 参数验证
            if (empty($crowdId) || empty($title)) {
                return json(['code' => 400, 'msg' => '群组ID和名称不能为空']);
            }
            
            // 检查群组是否已存在
            $existGroup = TgCrowdList::where('crowd_id', $crowdId)->where('del', 0)->find();
            if ($existGroup) {
                return json(['code' => 400, 'msg' => '群组已存在']);
            }
            
            // 验证群组是否真实存在并获取信息
            $chatInfo = $this->telegramService->getChat($crowdId);
            if ($chatInfo['code'] !== 200) {
                return json(['code' => 400, 'msg' => '无法获取群组信息，请检查群组ID']);
            }
            
            $chat = $chatInfo['data'];
            
            // 检查机器人在群组中的状态
            $botInfo = $this->telegramService->getChatMember($crowdId, $this->telegramService->getBotId());
            $botStatus = 'member';
            if ($botInfo['code'] === 200) {
                $member = $botInfo['data'];
                $botStatus = $member['status'] ?? 'member';
            }
            
            // 创建群组记录
            $groupData = [
                'title' => $title,
                'crowd_id' => $crowdId,
                'first_name' => $chat['title'] ?? $title,
                'member_count' => $chat['member_count'] ?? 0,
                'is_active' => 1,
                'broadcast_enabled' => 1,
                'bot_status' => $botStatus,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            TgCrowdList::create($groupData);
            
            return json([
                'code' => 200,
                'msg' => '群组添加成功',
                'data' => $groupData
            ]);
            
        } catch (\Exception $e) {
            Log::error('添加群组失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '添加群组失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 更新群组
     */
    public function updateGroup(Request $request): Response
    {
        try {
            $id = $request->param('id');
            $title = $request->param('title');
            $isActive = $request->param('is_active');
            $broadcastEnabled = $request->param('broadcast_enabled');
            
            $group = TgCrowdList::find($id);
            if (!$group) {
                return json(['code' => 404, 'msg' => '群组不存在']);
            }
            
            $updateData = [
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            if ($title !== null) {
                $updateData['title'] = $title;
            }
            
            if ($isActive !== null) {
                $updateData['is_active'] = (int)$isActive;
            }
            
            if ($broadcastEnabled !== null) {
                $updateData['broadcast_enabled'] = (int)$broadcastEnabled;
            }
            
            $group->save($updateData);
            
            return json([
                'code' => 200,
                'msg' => '群组更新成功'
            ]);
            
        } catch (\Exception $e) {
            Log::error('更新群组失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '更新群组失败']);
        }
    }
    
    /**
     * 删除群组
     */
    public function deleteGroup(Request $request): Response
    {
        try {
            $id = $request->param('id');
            
            $group = TgCrowdList::find($id);
            if (!$group) {
                return json(['code' => 404, 'msg' => '群组不存在']);
            }
            
            // 软删除
            $group->save([
                'del' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            return json([
                'code' => 200,
                'msg' => '群组删除成功'
            ]);
            
        } catch (\Exception $e) {
            Log::error('删除群组失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '删除群组失败']);
        }
    }
    
    /**
     * 群组统计
     */
    public function groupStats(Request $request): Response
    {
        try {
            $stats = [
                'total_groups' => TgCrowdList::where('del', 0)->count(),
                'active_groups' => TgCrowdList::where('del', 0)->where('is_active', 1)->count(),
                'broadcast_enabled_groups' => TgCrowdList::where('del', 0)->where('broadcast_enabled', 1)->count(),
                'admin_groups' => TgCrowdList::where('del', 0)->where('bot_status', 'administrator')->count(),
                'total_members' => TgCrowdList::where('del', 0)->sum('member_count') ?: 0
            ];
            
            // 按状态分组统计
            $statusStats = TgCrowdList::where('del', 0)
                ->field('bot_status, count(*) as count')
                ->group('bot_status')
                ->select()
                ->toArray();
            
            $stats['status_breakdown'] = $statusStats;
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取群组统计失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取群组统计失败']);
        }
    }
    
    // =================== 广播消息管理 ===================
    
    /**
     * 广播列表
     */
    public function broadcastList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $status = $request->param('status', '');
            $type = $request->param('type', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            
            $query = TgAdvertisement::order('id', 'desc');
            
            // 状态筛选
            if ($status !== '') {
                $query->where('status', $status);
            }
            
            // 类型筛选
            if (!empty($type)) {
                $query->where('type', $type);
            }
            
            // 时间范围
            if (!empty($startTime)) {
                $query->where('created_at', '>=', $startTime);
            }
            if (!empty($endTime)) {
                $query->where('created_at', '<=', $endTime . ' 23:59:59');
            }
            
            $total = $query->count();
            $broadcasts = $query->page($page, $limit)->select();
            
            // 格式化数据
            $broadcastList = [];
            foreach ($broadcasts as $broadcast) {
                $broadcastData = $broadcast->toArray();
                $broadcastData['status_text'] = $this->getBroadcastStatusText($broadcast->status);
                $broadcastData['success_rate'] = $broadcast->total_count > 0 
                    ? round(($broadcast->sent_count / $broadcast->total_count) * 100, 2) 
                    : 0;
                $broadcastData['send_time_text'] = $broadcast->send_time ? date('Y-m-d H:i:s', strtotime($broadcast->send_time)) : '';
                $broadcastData['created_time'] = date('Y-m-d H:i:s', strtotime($broadcast->created_at));
                $broadcastList[] = $broadcastData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $broadcastList,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取广播列表失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取广播列表失败']);
        }
    }
    
    /**
     * 创建广播
     */
    public function createBroadcast(Request $request): Response
    {
        try {
            $title = $request->param('title');
            $content = $request->param('content');
            $imageUrl = $request->param('image_url', '');
            $targetType = $request->param('target_type', 1); // 1-所有群组 2-指定群组
            $targetGroups = $request->param('target_groups', []);
            $sendTime = $request->param('send_time', ''); // 定时发送时间
            $sendNow = $request->param('send_now', false); // 是否立即发送
            
            // 参数验证
            if (empty($title) || empty($content)) {
                return json(['code' => 400, 'msg' => '标题和内容不能为空']);
            }
            
            // 获取目标群组
            if ($targetType == 2 && empty($targetGroups)) {
                return json(['code' => 400, 'msg' => '请选择目标群组']);
            }
            
            if ($targetType == 1) {
                // 所有活跃群组
                $activeGroups = TgCrowdList::where('del', 0)
                    ->where('is_active', 1)
                    ->where('broadcast_enabled', 1)
                    ->column('crowd_id');
                $targetGroups = $activeGroups;
            }
            
            // 创建广播记录
            $broadcastData = [
                'title' => $title,
                'content' => $content,
                'image_url' => $imageUrl,
                'target_type' => $targetType,
                'target_groups' => json_encode($targetGroups),
                'total_count' => count($targetGroups),
                'status' => $sendNow ? 1 : 0, // 0-待发送 1-发送中
                'created_by' => $this->getAdminId(),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            if (!empty($sendTime) && !$sendNow) {
                $broadcastData['send_time'] = $sendTime;
            }
            
            $broadcast = TgAdvertisement::create($broadcastData);
            
            // 如果立即发送，执行广播
            if ($sendNow) {
                $this->executeBroadcast($broadcast->id);
            }
            
            return json([
                'code' => 200,
                'msg' => $sendNow ? '广播已开始发送' : '广播创建成功',
                'data' => ['id' => $broadcast->id]
            ]);
            
        } catch (\Exception $e) {
            Log::error('创建广播失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '创建广播失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 发送广播
     */
    public function sendBroadcast(Request $request): Response
    {
        try {
            $id = $request->param('id');
            
            $broadcast = TgAdvertisement::find($id);
            if (!$broadcast) {
                return json(['code' => 404, 'msg' => '广播任务不存在']);
            }
            
            if ($broadcast->status !== 0) {
                return json(['code' => 400, 'msg' => '只能发送待发送状态的广播']);
            }
            
            $result = $this->executeBroadcast($id);
            
            return json($result);
            
        } catch (\Exception $e) {
            Log::error('发送广播失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '发送广播失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 广播统计
     */
    public function broadcastStats(Request $request): Response
    {
        try {
            $date = $request->param('date', date('Y-m-d'));
            $startTime = $date . ' 00:00:00';
            $endTime = $date . ' 23:59:59';
            
            $stats = [
                'today_broadcasts' => TgAdvertisement::where('created_at', '>=', $startTime)
                    ->where('created_at', '<=', $endTime)->count(),
                'today_messages' => TgMessageLog::where('sent_at', '>=', $startTime)
                    ->where('sent_at', '<=', $endTime)->count(),
                'success_rate' => 0,
                'total_groups' => TgCrowdList::where('del', 0)->where('broadcast_enabled', 1)->count()
            ];
            
            // 计算成功率
            $todayLogs = TgMessageLog::where('sent_at', '>=', $startTime)
                ->where('sent_at', '<=', $endTime)
                ->field('send_status, count(*) as count')
                ->group('send_status')
                ->select()
                ->toArray();
            
            $totalSent = 0;
            $successSent = 0;
            foreach ($todayLogs as $log) {
                $totalSent += $log['count'];
                if ($log['send_status'] == 1) {
                    $successSent += $log['count'];
                }
            }
            
            $stats['success_rate'] = $totalSent > 0 ? round(($successSent / $totalSent) * 100, 2) : 0;
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取广播统计失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取广播统计失败']);
        }
    }
    
    // =================== 红包发送管理 ===================
    
    /**
     * 创建系统红包并发送到群组
     */
    public function createSystemRedPacket(Request $request): Response
    {
        try {
            $title = $request->param('title', '系统红包');
            $totalAmount = $request->param('total_amount');
            $totalCount = $request->param('total_count');
            $packetType = $request->param('packet_type', 1); // 1-拼手气 2-平均
            $targetGroups = $request->param('target_groups', []);
            $expireHours = $request->param('expire_hours', 24);
            
            // 参数验证
            if (!is_numeric($totalAmount) || $totalAmount <= 0) {
                return json(['code' => 400, 'msg' => '红包总金额必须大于0']);
            }
            
            if (!is_numeric($totalCount) || $totalCount <= 0 || $totalCount > 100) {
                return json(['code' => 400, 'msg' => '红包个数必须在1-100之间']);
            }
            
            if ($totalAmount < $totalCount * 0.01) {
                return json(['code' => 400, 'msg' => '红包金额太小，每个红包至少0.01']);
            }
            
            // 获取目标群组
            if (empty($targetGroups)) {
                $activeGroups = TgCrowdList::where('del', 0)
                    ->where('is_active', 1)
                    ->where('broadcast_enabled', 1)
                    ->where('bot_status', 'administrator')
                    ->select()
                    ->toArray();
                $targetGroups = $activeGroups;
            } else {
                $targetGroups = TgCrowdList::whereIn('id', $targetGroups)
                    ->where('del', 0)
                    ->select()
                    ->toArray();
            }
            
            if (empty($targetGroups)) {
                return json(['code' => 400, 'msg' => '没有可用的目标群组']);
            }
            
            // 创建系统红包数据
            $redPacketData = [
                'title' => $title,
                'total_amount' => floatval($totalAmount),
                'total_count' => intval($totalCount),
                'packet_type' => intval($packetType),
                'sender_id' => 0, // 系统发送
                'sender_tg_id' => 'system',
                'expire_hours' => intval($expireHours),
                'is_system' => 1
            ];
            
            // 发送红包到群组
            $result = $this->telegramRedPacketService->sendRedPacketToGroups($redPacketData, $targetGroups);
            
            return json($result);
            
        } catch (\Exception $e) {
            Log::error('创建系统红包失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '创建系统红包失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 红包发送统计
     */
    public function redPacketSendStats(Request $request): Response
    {
        try {
            $startTime = $request->param('start_time', date('Y-m-01'));
            $endTime = $request->param('end_time', date('Y-m-d'));
            
            $startTimestamp = strtotime($startTime . ' 00:00:00');
            $endTimestamp = strtotime($endTime . ' 23:59:59');
            
            $stats = [
                'total_sent' => RedPacket::where('is_system', 1)
                    ->where('created_at', '>=', date('Y-m-d H:i:s', $startTimestamp))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', $endTimestamp))
                    ->count(),
                'total_amount' => RedPacket::where('is_system', 1)
                    ->where('created_at', '>=', date('Y-m-d H:i:s', $startTimestamp))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', $endTimestamp))
                    ->sum('total_amount') ?: 0,
                'completed_packets' => RedPacket::where('is_system', 1)
                    ->where('created_at', '>=', date('Y-m-d H:i:s', $startTimestamp))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', $endTimestamp))
                    ->where('status', 2)
                    ->count(),
                'active_packets' => RedPacket::where('is_system', 1)
                    ->where('status', 1)
                    ->count()
            ];
            
            // 计算完成率
            $stats['completion_rate'] = $stats['total_sent'] > 0 
                ? round(($stats['completed_packets'] / $stats['total_sent']) * 100, 2) 
                : 0;
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取红包发送统计失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取红包发送统计失败']);
        }
    }
    
    // =================== 用户绑定管理 ===================
    
    /**
     * Telegram用户列表
     */
    public function telegramUserList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $keyword = $request->param('keyword', '');
            $bindStatus = $request->param('bind_status', '');
            
            $query = User::where('tg_id', '<>', '')->order('id', 'desc');
            
            // 关键词搜索
            if (!empty($keyword)) {
                $query->where(function($q) use ($keyword) {
                    $q->where('tg_username', 'like', "%{$keyword}%")
                      ->whereOr('tg_first_name', 'like', "%{$keyword}%")
                      ->whereOr('user_name', 'like', "%{$keyword}%");
                });
            }
            
            // 绑定状态筛选
            if ($bindStatus !== '') {
                if ($bindStatus == '1') {
                    $query->where('auto_created', 0); // 手动绑定
                } else {
                    $query->where('auto_created', 1); // 自动创建
                }
            }
            
            $total = $query->count();
            $users = $query->page($page, $limit)->select();
            
            // 格式化数据
            $userList = [];
            foreach ($users as $user) {
                $userData = $user->toArray();
                $userData['bind_type'] = $user->auto_created ? '自动创建' : '手动绑定';
                $userData['status_text'] = $user->status ? '正常' : '冻结';
                $userData['balance_formatted'] = number_format($user->money_balance, 2) . ' USDT';
                $userData['created_time'] = date('Y-m-d H:i:s', strtotime($user->create_time));
                $userData['bind_time'] = $user->telegram_bind_time ? date('Y-m-d H:i:s', strtotime($user->telegram_bind_time)) : '';
                $userData['last_activity'] = $user->last_activity_at ? date('Y-m-d H:i:s', strtotime($user->last_activity_at)) : '';
                $userList[] = $userData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $userList,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取Telegram用户列表失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取用户列表失败']);
        }
    }
    
    /**
     * 用户绑定统计
     */
    public function userBindStats(Request $request): Response
    {
        try {
            $stats = [
                'total_telegram_users' => User::where('tg_id', '<>', '')->count(),
                'auto_created_users' => User::where('auto_created', 1)->count(),
                'manual_bind_users' => User::where('tg_id', '<>', '')->where('auto_created', 0)->count(),
                'active_users_today' => User::where('last_activity_at', '>=', date('Y-m-d 00:00:00'))->count(),
                'new_bind_today' => User::where('telegram_bind_time', '>=', date('Y-m-d 00:00:00'))->count()
            ];
            
            // 按语言统计
            $languageStats = User::where('tg_id', '<>', '')
                ->field('language_code, count(*) as count')
                ->group('language_code')
                ->select()
                ->toArray();
            
            $stats['language_breakdown'] = $languageStats;
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取用户绑定统计失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取用户绑定统计失败']);
        }
    }
    
    // =================== 消息模板管理 ===================
    
    /**
     * 获取消息模板列表
     */
    public function messageTemplateList(Request $request): Response
    {
        try {
            // 从配置文件读取模板
            $templates = config('telegram.message_templates');
            
            $templateList = [];
            foreach ($templates as $key => $template) {
                $templateList[] = [
                    'key' => $key,
                    'name' => $template['name'] ?? $key,
                    'description' => $template['description'] ?? '',
                    'content' => $template['content'] ?? '',
                    'variables' => $template['variables'] ?? [],
                    'category' => $template['category'] ?? 'general'
                ];
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $templateList
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取消息模板失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取消息模板失败']);
        }
    }
    
    /**
     * 更新消息模板
     */
    public function updateMessageTemplate(Request $request): Response
    {
        try {
            $key = $request->param('key');
            $content = $request->param('content');
            
            if (empty($key) || empty($content)) {
                return json(['code' => 400, 'msg' => '模板标识和内容不能为空']);
            }
            
            // 这里应该将模板保存到数据库或配置文件
            // 暂时使用缓存保存
            Cache::set("telegram_template_{$key}", $content, 86400 * 30);
            
            return json([
                'code' => 200,
                'msg' => '模板更新成功'
            ]);
            
        } catch (\Exception $e) {
            Log::error('更新消息模板失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '更新消息模板失败']);
        }
    }
    
    // =================== 日志查看 ===================
    
    /**
     * 消息发送日志
     */
    public function messageLogList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $type = $request->param('type', '');
            $status = $request->param('status', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            
            $query = TgMessageLog::order('id', 'desc');
            
            // 类型筛选
            if (!empty($type)) {
                $query->where('message_type', $type);
            }
            
            // 状态筛选
            if ($status !== '') {
                $query->where('send_status', $status);
            }
            
            // 时间范围
            if (!empty($startTime)) {
                $query->where('sent_at', '>=', $startTime);
            }
            if (!empty($endTime)) {
                $query->where('sent_at', '<=', $endTime . ' 23:59:59');
            }
            
            $total = $query->count();
            $logs = $query->page($page, $limit)->select();
            
            // 格式化数据
            $logList = [];
            foreach ($logs as $log) {
                $logData = $log->toArray();
                $logData['status_text'] = $this->getMessageStatusText($log->send_status);
                $logData['sent_time'] = date('Y-m-d H:i:s', strtotime($log->sent_at));
                $logData['content_preview'] = mb_substr($log->content, 0, 50) . '...';
                $logList[] = $logData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $logList,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取消息日志失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取消息日志失败']);
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 执行广播
     */
    private function executeBroadcast(int $broadcastId): array
    {
        try {
            $broadcast = TgAdvertisement::find($broadcastId);
            if (!$broadcast) {
                return ['code' => 404, 'msg' => '广播任务不存在'];
            }
            
            // 更新状态为发送中
            $broadcast->save(['status' => 1]);
            
            $targetGroups = json_decode($broadcast->target_groups, true) ?: [];
            $successCount = 0;
            $failedCount = 0;
            
            foreach ($targetGroups as $groupId) {
                try {
                    if (!empty($broadcast->image_url)) {
                        $result = $this->telegramService->sendPhoto(
                            (int)$groupId,
                            $broadcast->image_url,
                            ['caption' => $broadcast->content]
                        );
                    } else {
                        $result = $this->telegramService->sendMessage(
                            (int)$groupId,
                            $broadcast->content,
                            []
                        );
                    }
                    
                    if ($result['code'] === 200) {
                        $successCount++;
                    } else {
                        $failedCount++;
                    }
                    
                    // 记录发送日志
                    TgMessageLog::create([
                        'message_type' => 'broadcast',
                        'target_type' => 'group',
                        'target_id' => (string)$groupId,
                        'content' => $broadcast->content,
                        'send_status' => $result['code'] === 200 ? 1 : 2,
                        'error_message' => $result['code'] !== 200 ? $result['msg'] : '',
                        'source_id' => $broadcast->id,
                        'source_type' => 'advertisement',
                        'sent_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    // 防止频率限制
                    usleep(100000); // 0.1秒延迟
                    
                } catch (\Exception $e) {
                    $failedCount++;
                    Log::error('广播发送失败', [
                        'group_id' => $groupId,
                        'broadcast_id' => $broadcastId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // 更新广播状态
            $broadcast->save([
                'status' => 2, // 已完成
                'sent_count' => $successCount,
                'total_count' => $successCount + $failedCount,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            return [
                'code' => 200,
                'msg' => "广播发送完成，成功 {$successCount} 个，失败 {$failedCount} 个",
                'data' => [
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'total_count' => $successCount + $failedCount
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('执行广播失败: ' . $e->getMessage());
            return ['code' => 500, 'msg' => '执行广播失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取管理员ID
     */
    private function getAdminId(): int
    {
        // 这里应该从会话或JWT中获取管理员ID
        return 1; // 临时返回1
    }
    
    /**
     * 获取机器人状态文本
     */
    private function getBotStatusText(string $status): string
    {
        $statusMap = [
            'member' => '普通成员',
            'administrator' => '管理员',
            'left' => '已离开',
            'kicked' => '被踢出'
        ];
        
        return $statusMap[$status] ?? '未知状态';
    }
    
    /**
     * 获取广播状态文本
     */
    private function getBroadcastStatusText(int $status): string
    {
        $statusMap = [
            0 => '待发送',
            1 => '发送中',
            2 => '已完成',
            3 => '已取消'
        ];
        
        return $statusMap[$status] ?? '未知状态';
    }
    
    /**
     * 获取消息状态文本
     */
    private function getMessageStatusText(int $status): string
    {
        $statusMap = [
            0 => '发送中',
            1 => '成功',
            2 => '失败'
        ];
        
        return $statusMap[$status] ?? '未知状态';
    }
}