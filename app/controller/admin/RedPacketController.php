<?php
// 文件位置: app/controller/admin/RedPacketController.php
// 后台红包管理控制器 + Telegram群组功能

declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\RedPacket;
use app\model\RedPacketRecord;
use app\model\User;
use app\model\UserLog;
use app\service\TelegramService;
use app\service\TelegramBroadcastService;
use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class RedPacketController extends BaseController
{
    private TelegramService $telegramService;
    private TelegramBroadcastService $telegramBroadcastService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->telegramBroadcastService = new TelegramBroadcastService();
    }
    
    /**
     * 红包列表
     */
    public function packetList(Request $request): Response
    {
        try {
            $page = $request->param('page', 1);
            $limit = $request->param('limit', 20);
            $status = $request->param('status', '');
            $type = $request->param('type', '');
            $startTime = $request->param('start_time', '');
            $endTime = $request->param('end_time', '');
            $keyword = $request->param('keyword', '');
            
            // 使用RedPacket Model查询
            $query = RedPacket::with(['sender'])->order('create_time', 'desc');
            
            // 状态筛选
            if ($status !== '') {
                $query->where('status', $status);
            }
            
            // 类型筛选
            if (!empty($type)) {
                $query->where('packet_type', $type);
            }
            
            // 时间范围
            if (!empty($startTime)) {
                $query->where('create_time', '>=', strtotime($startTime));
            }
            if (!empty($endTime)) {
                $query->where('create_time', '<=', strtotime($endTime . ' 23:59:59'));
            }
            
            // 关键词搜索
            if (!empty($keyword)) {
                $query->where(function($q) use ($keyword) {
                    $q->whereLike('packet_id', "%{$keyword}%")
                      ->whereOr('title', 'like', "%{$keyword}%");
                });
            }
            
            $packets = $query->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
            
            // 格式化数据
            $list = [];
            foreach ($packets->items() as $packet) {
                $packetData = $packet->toArray();
                $packetData['create_time_text'] = date('Y-m-d H:i:s', strtotime($packet->create_time));
                $packetData['expire_time_text'] = date('Y-m-d H:i:s', $packet->expire_time);
                $packetData['status_text'] = $this->getPacketStatusText($packet->status);
                $packetData['type_text'] = $this->getPacketTypeText($packet->packet_type);
                $packetData['remain_amount'] = $packet->total_amount - $packet->grabbed_amount;
                $packetData['remain_acount'] = $packet->total_count - $packet->grabbed_count;
                $packetData['sender_name'] = $packet->sender->username ?? '';
                $packetData['is_telegram_sent'] = !empty($packet->telegram_message_id); // 🔥 新增：是否已发送到Telegram
                
                $list[] = $packetData;
            }
            
            // 统计数据
            $stats = $this->getPacketStats($startTime, $endTime);
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $packets->total(),
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
     * 红包详情
     */
    public function packetDetail(Request $request): Response
    {
        try {
            $packetId = $request->param('id');
            
            // 获取红包信息
            $packet = RedPacket::with(['sender'])->where('id', $packetId)->find();
            
            if (!$packet) {
                return json(['code' => 404, 'msg' => '红包不存在']);
            }
            
            // 格式化红包信息
            $packetData = $packet->toArray();
            $packetData['create_time_text'] = date('Y-m-d H:i:s', strtotime($packet->create_time));
            $packetData['expire_time_text'] = date('Y-m-d H:i:s', $packet->expire_time);
            $packetData['status_text'] = $this->getPacketStatusText($packet->status);
            $packetData['type_text'] = $this->getPacketTypeText($packet->packet_type);
            $packetData['remain_amount'] = $packet->total_amount - $packet->grabbed_amount;
            $packetData['remain_acount'] = $packet->total_count - $packet->grabbed_count;
            $packetData['sender_name'] = $packet->sender->username ?? '';
            
            // 获取抢红包记录
            $grabs = RedPacketRecord::with(['user'])
                                   ->where('packet_id', $packetId)
                                   ->order('grab_order', 'asc')
                                   ->select();
            
            // 格式化抢红包记录
            $grabList = [];
            foreach ($grabs as $grab) {
                $grabData = $grab->toArray();
                $grabData['create_time_text'] = date('Y-m-d H:i:s', strtotime($grab->create_time));
                $grabData['user_name'] = $grab->user->username ?? '';
                
                $grabList[] = $grabData;
            }
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'packet' => $packetData,
                    'grabs' => $grabList,
                    'grab_count' => count($grabList)
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
     * 创建系统红包
     */
    public function createSystemPacket(Request $request): Response
    {
        try {
            $title = $request->param('title', '系统红包');
            $totalAmount = $request->param('total_amount');
            $totalCount = $request->param('total_count');
            $packetType = $request->param('packet_type', 1); // 1-拼手气，2-平均
            $expireHours = $request->param('expire_hours', 24);
            $remark = $request->param('remark', '');
            $sendToTelegram = $request->param('send_to_telegram', true); // 🔥 新增：是否发送到Telegram群组
            $targetGroups = $request->param('target_groups', []); // 🔥 新增：目标群组
            
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
            
            Db::startTrans();
            
            try {
                // 生成红包ID
                $packetId = 'SYS_' . date('YmdHis') . mt_rand(1000, 9999);
                
                // 创建红包记录
                $packetData = [
                    'packet_id' => $packetId,
                    'title' => $title,
                    'sender_id' => 0, // 系统红包
                    'total_amount' => $totalAmount,
                    'total_count' => $totalCount,
                    'grabbed_amount' => 0,
                    'grabbed_count' => 0,
                    'packet_type' => $packetType,
                    'status' => 1, // 进行中
                    'expire_time' => time() + ($expireHours * 3600),
                    'remark' => $remark,
                    'create_time' => date('Y-m-d H:i:s'),
                    'is_system' => 1 // 🔥 新增：标记为系统红包
                ];
                
                $packet = RedPacket::create($packetData);
                
                // 🔥 新增：发送到Telegram群组
                if ($sendToTelegram) {
                    $this->sendPacketToTelegramGroups($packet, $targetGroups);
                }
                
                Db::commit();
                
                Log::info('创建系统红包成功', [
                    'packet_id' => $packetId,
                    'total_amount' => $totalAmount,
                    'total_count' => $totalCount,
                    'send_to_telegram' => $sendToTelegram
                ]);
                
                return json([
                    'code' => 200,
                    'msg' => '系统红包创建成功',
                    'data' => [
                        'packet_id' => $packetId,
                        'total_amount' => $totalAmount,
                        'total_count' => $totalCount,
                        'expire_time' => date('Y-m-d H:i:s', $packet->expire_time),
                        'telegram_sent' => $sendToTelegram
                    ]
                ]);
                
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '创建失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 发送红包到Telegram群组
     */
    public function sendPacketToTelegram(Request $request): Response
    {
        try {
            $packetId = $request->param('id');
            $targetGroups = $request->param('target_groups', []); // 目标群组ID数组
            
            $packet = RedPacket::find($packetId);
            if (!$packet) {
                return json(['code' => 404, 'msg' => '红包不存在']);
            }
            
            if ($packet->status != 1) {
                return json(['code' => 400, 'msg' => '红包状态不允许发送']);
            }
            
            if (!empty($packet->telegram_message_id)) {
                return json(['code' => 400, 'msg' => '红包已发送到Telegram群组']);
            }
            
            // 🔥 新增：发送到指定群组
            $result = $this->sendPacketToTelegramGroups($packet, $targetGroups);
            
            return json([
                'code' => 200,
                'msg' => '红包已发送到Telegram群组',
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '发送失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 撤销红包
     */
    public function revokePacket(Request $request): Response
    {
        try {
            $packetId = $request->param('id');
            $reason = $request->param('reason', '管理员撤销');
            
            $packet = RedPacket::find($packetId);
            if (!$packet) {
                return json(['code' => 404, 'msg' => '红包不存在']);
            }
            
            if ($packet->status == 4) { // 已撤销
                return json(['code' => 400, 'msg' => '红包已被撤销']);
            }
            
            if ($packet->grabbed_count > 0) {
                return json(['code' => 400, 'msg' => '红包已有人抢取，无法撤销']);
            }
            
            Db::startTrans();
            
            try {
                // 更新红包状态
                $packet->status = 4; // 已撤销
                $packet->finished_at = date('Y-m-d H:i:s');
                $packet->save();
                
                // 如果是用户红包，退回用户余额
                if ($packet->sender_id > 0) {
                    $sender = User::find($packet->sender_id);
                    if ($sender) {
                        $sender->balance += $packet->total_amount;
                        $sender->save();
                        
                        // 记录余额变动
                        UserLog::create([
                            'user_id' => $packet->sender_id,
                            'action' => 'balance_add',
                            'description' => "红包撤销退款 - {$packet->packet_id}，原因：{$reason}",
                            'ip' => request()->ip(),
                            'user_agent' => request()->header('User-Agent'),
                            'create_time' => time()
                        ]);
                    }
                }
                
                Db::commit();
                
                return json([
                    'code' => 200,
                    'msg' => '红包撤销成功' . ($packet->sender_id > 0 ? '，余额已退回' : '')
                ]);
                
            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '撤销失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 强制过期
     */
    public function forceExpire(Request $request): Response
    {
        try {
            $packetId = $request->param('id');
            $reason = $request->param('reason', '管理员强制过期');
            
            $packet = RedPacket::find($packetId);
            if (!$packet) {
                return json(['code' => 404, 'msg' => '红包不存在']);
            }
            
            if ($packet->status == 2) {
                return json(['code' => 400, 'msg' => '红包已完成']);
            }
            
            if ($packet->expire_time < time()) {
                return json(['code' => 400, 'msg' => '红包已过期']);
            }
            
            Db::startTrans();
            
            try {
                // 更新红包状态
                $packet->status = 3; // 已过期
                $packet->expire_time = time();
                $packet->finished_at = date('Y-m-d H:i:s');
                $packet->save();
                
                // 如果有剩余金额，退回用户
                $remainingAmount = $packet->total_amount - $packet->grabbed_amount;
                if ($remainingAmount > 0 && $packet->sender_id > 0) {
                    $sender = User::find($packet->sender_id);
                    if ($sender) {
                        $sender->balance += $remainingAmount;
                        $sender->save();
                        
                        // 记录余额变动
                        UserLog::create([
                            'user_id' => $packet->sender_id,
                            'action' => 'balance_add',
                            'description' => "红包过期退款 - {$packet->packet_id}，原因：{$reason}",
                            'ip' => request()->ip(),
                            'user_agent' => request()->header('User-Agent'),
                            'create_time' => time()
                        ]);
                    }
                }
                
                Db::commit();
                
                return json([
                    'code' => 200,
                    'msg' => '红包已强制过期' . ($remainingAmount > 0 ? '，剩余金额已退回' : '')
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
    
    // =================== 🔥 新增：Telegram相关功能 ===================
    
    /**
     * 获取Telegram群组列表
     */
    public function getTelegramGroups(Request $request): Response
    {
        try {
            $groups = $this->telegramService->getActiveGroups();
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $groups
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取红包广播配置
     */
    public function getRedPacketBroadcastConfig(Request $request): Response
    {
        try {
            $config = [
                'auto_send_system_packet' => $this->isBroadcastEnabled('auto_send_system_packet'),
                'send_grab_notify' => $this->isBroadcastEnabled('send_grab_notify'),
                'send_complete_notify' => $this->isBroadcastEnabled('send_complete_notify'),
                'default_expire_hours' => Cache::get('redpacket_default_expire_hours', 24),
                'max_packet_amount' => Cache::get('redpacket_max_amount', 10000),
                'max_packet_count' => Cache::get('redpacket_max_count', 100),
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
     * 设置红包广播配置
     */
    public function setRedPacketBroadcastConfig(Request $request): Response
    {
        try {
            $config = $request->only([
                'auto_send_system_packet',
                'send_grab_notify',
                'send_complete_notify',
                'default_expire_hours',
                'max_packet_amount',
                'max_packet_count'
            ]);
            
            foreach ($config as $key => $value) {
                if (in_array($key, ['auto_send_system_packet', 'send_grab_notify', 'send_complete_notify'])) {
                    $this->setBroadcastEnabled($key, (bool)$value);
                } else {
                    Cache::set("redpacket_{$key}", $value, 86400 * 30); // 30天
                }
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
     * 红包统计（包含Telegram群组数据）
     */
    public function packetStat(Request $request): Response
    {
        try {
            $startTime = $request->param('start_time', date('Y-m-01'));
            $endTime = $request->param('end_time', date('Y-m-d'));
            $type = $request->param('type', '');
            
            $startTimestamp = strtotime($startTime);
            $endTimestamp = strtotime($endTime . ' 23:59:59');
            
            $query = RedPacket::where('create_time', '>=', $startTimestamp)
                             ->where('create_time', '<=', $endTimestamp);
            
            if (!empty($type)) {
                $query->where('packet_type', $type);
            }
            
            // 基础统计
            $basicStats = [
                'total_packets' => $query->count(),
                'total_amount' => $query->sum('total_amount') ?: 0,
                'grabbed_amount' => $query->sum('grabbed_amount') ?: 0,
                'total_count' => $query->sum('total_count') ?: 0,
                'grabbed_count' => $query->sum('grabbed_count') ?: 0,
                'system_packets' => $query->where('is_system', 1)->count(), // 🔥 新增：系统红包统计
                'telegram_sent_packets' => $query->where('telegram_message_id', '<>', '')->count(), // 🔥 新增：Telegram发送统计
            ];
            
            // 计算完成率
            $basicStats['completion_rate'] = $basicStats['total_count'] > 0 
                ? round($basicStats['grabbed_count'] / $basicStats['total_count'] * 100, 2) 
                : 0;
            $basicStats['grab_rate'] = $basicStats['total_amount'] > 0 
                ? round($basicStats['grabbed_amount'] / $basicStats['total_amount'] * 100, 2) 
                : 0;
            
            // 🔥 新增：Telegram群组统计
            $telegramStats = [
                'active_groups' => count($this->telegramService->getActiveGroups()),
                'sent_to_telegram_rate' => $basicStats['total_packets'] > 0 
                    ? round($basicStats['telegram_sent_packets'] / $basicStats['total_packets'] * 100, 2) 
                    : 0,
                'system_packet_rate' => $basicStats['total_packets'] > 0 
                    ? round($basicStats['system_packets'] / $basicStats['total_packets'] * 100, 2) 
                    : 0
            ];
            
            // 按状态统计
            $statusStats = [];
            $statuses = [1 => '进行中', 2 => '已完成', 3 => '已过期', 4 => '已撤回'];
            foreach ($statuses as $status => $statusText) {
                $statusStats[] = [
                    'status' => $status,
                    'status_text' => $statusText,
                    'count' => RedPacket::where('create_time', 'between', [$startTimestamp, $endTimestamp])
                                       ->where('status', $status)->count(),
                    'amount' => RedPacket::where('create_time', 'between', [$startTimestamp, $endTimestamp])
                                        ->where('status', $status)->sum('total_amount') ?: 0
                ];
            }
            
            // 参与用户统计
            $userStats = [
                'sender_count' => RedPacket::where('create_time', 'between', [$startTimestamp, $endTimestamp])
                                          ->distinct('sender_id')->count(),
                'grabber_count' => RedPacketRecord::where('create_time', 'between', [$startTimestamp, $endTimestamp])
                                                 ->distinct('user_id')->count()
            ];
            
            return json([
                'code' => 200,
                'msg' => '获取成功',
                'data' => [
                    'basic' => $basicStats,
                    'telegram' => $telegramStats, // 🔥 新增：Telegram统计
                    'status' => $statusStats,
                    'user' => $userStats,
                    'period' => [
                        'start_time' => $startTime,
                        'end_time' => $endTime
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '获取失败: ' . $e->getMessage()
            ]);
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 发送红包到Telegram群组
     */
    private function sendPacketToTelegramGroups(RedPacket $packet, array $targetGroups = []): array
    {
        try {
            // 如果未指定目标群组，发送到所有活跃群组
            if (empty($targetGroups)) {
                $groups = $this->telegramService->getActiveGroups();
                $targetGroups = array_column($groups, 'chat_id');
            }
            
            if (empty($targetGroups)) {
                return ['code' => 404, 'msg' => '没有可用的群组'];
            }
            
            // 准备红包数据
            $redpacketData = [
                'redpacket_id' => $packet->id,
                'amount' => $packet->total_amount,
                'count' => $packet->total_count,
                'from_user' => $packet->sender_id > 0 ? ($packet->sender->username ?? '用户') : '系统',
                'remark' => $packet->remark ?: '恭喜发财，大吉大利！',
                'expire_time' => date('m-d H:i', $packet->expire_time)
            ];
            
            // 发送到群组
            $result = $this->telegramBroadcastService->broadcastRedPacketToGroups($redpacketData);
            
            // 更新红包记录
            if ($result['code'] == 200) {
                $packet->telegram_message_id = json_encode($result['data']);
                $packet->telegram_sent_at = date('Y-m-d H:i:s');
                $packet->save();
            }
            
            // 记录发送日志
            $this->logRedPacketBroadcast('send_to_telegram', [
                'packet_id' => $packet->packet_id,
                'target_groups' => $targetGroups,
                'packet_data' => $redpacketData
            ], $result);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('发送红包到Telegram群组失败: ' . $e->getMessage());
            return ['code' => 500, 'msg' => '发送失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 检查广播是否启用
     */
    private function isBroadcastEnabled(string $type): bool
    {
        return (bool)Cache::get("redpacket_broadcast_enabled_{$type}", true);
    }
    
    /**
     * 设置广播启用状态
     */
    private function setBroadcastEnabled(string $type, bool $enabled): void
    {
        Cache::set("redpacket_broadcast_enabled_{$type}", $enabled, 86400 * 30); // 30天
    }
    
    /**
     * 记录红包广播日志
     */
    private function logRedPacketBroadcast(string $type, array $data, array $result): void
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
            $cacheKey = 'redpacket_broadcast_logs';
            $logs = Cache::get($cacheKey, []);
            
            // 添加新日志
            array_unshift($logs, $log);
            
            // 保留最新500条日志
            $logs = array_slice($logs, 0, 500);
            
            // 保存到缓存
            Cache::set($cacheKey, $logs, 86400 * 7); // 7天
            
        } catch (\Exception $e) {
            Log::error('记录红包广播日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取红包状态文本
     */
    private function getPacketStatusText(int $status): string
    {
        $statusMap = [
            1 => '进行中',
            2 => '已完成', 
            3 => '已过期',
            4 => '已撤回'
        ];
        
        return $statusMap[$status] ?? '未知';
    }
    
    /**
     * 获取红包类型文本
     */
    private function getPacketTypeText(int $type): string
    {
        $typeMap = [
            1 => '拼手气红包',
            2 => '平均红包'
        ];
        
        return $typeMap[$type] ?? '未知';
    }
    
    /**
     * 获取红包统计数据
     */
    private function getPacketStats(string $startTime = '', string $endTime = ''): array
    {
        $query = RedPacket::query();
        
        if (!empty($startTime)) {
            $query->where('create_time', '>=', strtotime($startTime));
        }
        if (!empty($endTime)) {
            $query->where('create_time', '<=', strtotime($endTime . ' 23:59:59'));
        }
        
        return [
            'total_packets' => $query->count(),
            'total_amount' => $query->sum('total_amount') ?: 0,
            'grabbed_amount' => $query->sum('grabbed_amount') ?: 0,
            'active_packets' => $query->where('status', 1)->count(),
            'expired_packets' => $query->where('status', 3)->count(),
            'completed_packets' => $query->where('status', 2)->count(),
            'revoked_packets' => $query->where('status', 4)->count(),
            'system_packets' => $query->where('is_system', 1)->count(), // 🔥 新增
            'telegram_sent_packets' => $query->where('telegram_message_id', '<>', '')->count() // 🔥 新增
        ];
    }
}