<?php
declare(strict_types=1);

namespace app\service;

use app\model\RedPacket;
use app\model\RedPacketRecord;
use app\model\User;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Redis;
use think\exception\ValidateException;

/**
 * Telegram红包功能服务 - 精简版
 * 职责：仅负责数据库操作，不处理发送逻辑
 * 发送逻辑统一由后台计划任务处理
 */
class TelegramRedPacketService
{
    // 红包状态常量
    const STATUS_ACTIVE = 1;      // 进行中
    const STATUS_COMPLETED = 2;   // 已抢完
    const STATUS_EXPIRED = 3;     // 已过期
    const STATUS_REVOKED = 4;     // 已撤回
    
    // 红包类型常量
    const TYPE_RANDOM = 1;        // 拼手气
    const TYPE_AVERAGE = 2;       // 平均分配
    
    // 命令常量
    const COMMAND_RED = '/red';           // 发红包命令
    const COMMAND_HONGBAO = '/hongbao';   // 红包命令（中文）
    const COMMAND_HB = '/hb';             // 红包简写命令
    
    // 并发控制常量
    const GRAB_LOCK_PREFIX = 'redpacket_grab_lock_';
    const USER_LIMIT_PREFIX = 'redpacket_user_limit_';
    const LOCK_EXPIRE_TIME = 30;
    const USER_GRAB_INTERVAL = 1;
    
    public function __construct()
    {
        // 精简版不需要初始化 TelegramService
    }
    
    // =================== 权限验证功能 ===================
    
    /**
     * 验证用户红包权限
     */
    public function validateUserRedPacketPermission(User $user, $params = []): array
    {
        try {
            // 基础验证
            if (!$user) {
                return [
                    'valid' => false,
                    'success' => false,
                    'msg' => '用户信息无效',
                    'message' => '用户信息无效'
                ];
            }
            
            // 参数类型转换处理
            if (is_numeric($params)) {
                // 如果传入的是数字（金额），转换为数组格式
                $params = ['amount' => (float)$params];
            } elseif (!is_array($params)) {
                // 如果不是数组也不是数字，设为空数组
                $params = [];
            }
            
            // 检查用户状态
            if (isset($user->status) && $user->status != 1) {
                return [
                    'valid' => false,
                    'success' => false,
                    'msg' => '账户状态异常，无法发送红包',
                    'message' => '账户状态异常，无法发送红包'
                ];
            }
            
            // 检查余额（如果提供了金额参数）
            if (isset($params['amount']) && isset($user->money_balance) && $user->money_balance < $params['amount']) {
                return [
                    'valid' => false,
                    'success' => false,
                    'msg' => '余额不足，当前余额：' . $user->money_balance . ' USDT',
                    'message' => '余额不足，当前余额：' . $user->money_balance . ' USDT'
                ];
            }
            
            // 检查今日发红包限制
            $config = config('redpacket.daily_limits', []);
            if (!empty($config['max_daily_amount']) || !empty($config['max_daily_count'])) {
                $todayStats = $this->getUserTodayRedPacketStats($user->id);
                
                if (!empty($config['max_daily_amount']) && $todayStats['total_amount'] >= $config['max_daily_amount']) {
                    return [
                        'valid' => false,
                        'success' => false,
                        'msg' => '今日发红包金额已达上限',
                        'message' => '今日发红包金额已达上限'
                    ];
                }
                
                if (!empty($config['max_daily_count']) && $todayStats['total_count'] >= $config['max_daily_count']) {
                    return [
                        'valid' => false,
                        'success' => false,
                        'msg' => '今日发红包次数已达上限',
                        'message' => '今日发红包次数已达上限'
                    ];
                }
            }
            
            return [
                'valid' => true,
                'success' => true,
                'msg' => '权限验证通过',
                'message' => '权限验证通过'
            ];
            
        } catch (\Exception $e) {
            Log::error('用户红包权限验证失败', [
                'user_id' => $user->id ?? null,
                'params' => $params,
                'error' => $e->getMessage()
            ]);
            
            return [
                'valid' => false,
                'success' => false,
                'msg' => '权限验证异常',
                'message' => '权限验证异常'
            ];
        }
    }
    
    /**
     * 获取用户今日红包统计
     */
    private function getUserTodayRedPacketStats(int $userId): array
    {
        try {
            $today = date('Y-m-d');
            $result = Db::name('tg_red_packets')
                       ->where('sender_id', $userId)
                       ->where('created_at', 'between', [$today . ' 00:00:00', $today . ' 23:59:59'])
                       ->field('COUNT(*) as total_count, SUM(total_amount) as total_amount')
                       ->find();
            
            return [
                'total_count' => (int)($result['total_count'] ?? 0),
                'total_amount' => (float)($result['total_amount'] ?? 0)
            ];
            
        } catch (\Exception $e) {
            Log::warning('获取用户今日红包统计失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_count' => 0,
                'total_amount' => 0
            ];
        }
    }
    
    // =================== 命令解析功能 ===================
    
    /**
     * 解析红包命令
     * 支持格式：/hongbao 20 3 新年快乐
     * 
     * @param string $messageText 命令文本
     * @param array|null $chatContext 聊天上下文
     * @return array|null 解析结果
     */
    public function parseRedPacketCommand(string $messageText, ?array $chatContext = null): ?array
    {
        $messageText = trim($messageText);
        
        // 检查是否为红包命令
        if (!$this->isRedPacketCommand($messageText)) {
            return null;
        }
        
        try {
            // 验证聊天上下文
            if ($chatContext && !$this->validateCommandContext($chatContext)) {
                throw new ValidateException('该聊天环境不支持红包命令');
            }
            
            // 按空格分割命令
            $parts = preg_split('/\s+/', $messageText, -1, PREG_SPLIT_NO_EMPTY);
            
            if (count($parts) < 3) {
                throw new ValidateException('命令格式错误，正确格式：/hongbao <金额> <个数> [标题]');
            }
            
            $command = $parts[0];
            $amount = $this->parseAmount($parts[1]);
            $count = $this->parseCount($parts[2]);
            $title = isset($parts[3]) ? implode(' ', array_slice($parts, 3)) : '恭喜发财，大吉大利';
            
            // 验证参数
            $this->validateRedPacketParams($amount, $count, $title);
            
            return [
                'command' => $command,
                'amount' => $amount,
                'count' => $count,
                'title' => $title,
                'type' => self::TYPE_RANDOM,
                'chat_context' => $chatContext,
                'parsed_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (\Exception $e) {
            Log::warning('红包命令解析失败', [
                'message' => $messageText,
                'chat_context' => $chatContext,
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => true,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 验证命令上下文
     */
    private function validateCommandContext(array $chatContext): bool
    {
        $chatType = $chatContext['chat_type'] ?? '';
        $config = config('redpacket.command_restrictions', []);
        
        // 私聊限制检查
        if ($chatType === 'private' && !($config['allow_in_private'] ?? false)) {
            return false;
        }
        
        // 群组权限检查
        if (in_array($chatType, ['group', 'supergroup']) && !($config['allow_in_groups'] ?? true)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查是否为红包命令
     */
    private function isRedPacketCommand(string $text): bool
    {
        $commands = [self::COMMAND_RED, self::COMMAND_HONGBAO, self::COMMAND_HB];
        
        foreach ($commands as $command) {
            if (stripos($text, $command) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 解析金额参数
     */
    private function parseAmount(string $amountStr): float
    {
        // 移除可能的USDT后缀
        $amountStr = preg_replace('/usdt$/i', '', trim($amountStr));
        
        if (!is_numeric($amountStr)) {
            throw new ValidateException('金额格式错误，请输入数字');
        }
        
        return (float)$amountStr;
    }
    
    /**
     * 解析个数参数
     */
    private function parseCount(string $countStr): int
    {
        // 移除可能的"个"字
        $countStr = preg_replace('/个$/', '', trim($countStr));
        
        if (!ctype_digit($countStr)) {
            throw new ValidateException('个数格式错误，请输入整数');
        }
        
        return (int)$countStr;
    }
    
    /**
     * 验证红包参数
     */
    private function validateRedPacketParams(float $amount, int $count, string $title): void
    {
        // 基础参数验证
        if ($amount <= 0) {
            throw new ValidateException('红包金额必须大于0');
        }
        
        if ($count <= 0) {
            throw new ValidateException('红包个数必须大于0');
        }
        
        if (empty(trim($title))) {
            throw new ValidateException('红包标题不能为空');
        }
        
        // 配置限制验证
        $config = config('redpacket.basic', []);
        
        if ($amount < ($config['min_amount'] ?? 1.0)) {
            throw new ValidateException("红包总金额不能少于 " . ($config['min_amount'] ?? 1.0) . " USDT");
        }
        
        if ($amount > ($config['max_amount'] ?? 10000.0)) {
            throw new ValidateException("红包总金额不能超过 " . ($config['max_amount'] ?? 10000.0) . " USDT");
        }
        
        if ($count > ($config['max_count'] ?? 100)) {
            throw new ValidateException("红包个数不能超过 " . ($config['max_count'] ?? 100) . " 个");
        }
        
        if (strlen($title) > 50) {
            throw new ValidateException("红包标题不能超过50个字符");
        }
    }
    
    // =================== 核心数据库操作 ===================
    
    /**
     * 创建红包记录 - 仅写入数据库
     * 
     * @param User $user 发送用户
     * @param float $amount 红包总金额
     * @param int $count 红包个数
     * @param string $title 红包标题
     * @param array|null $chatContext 聊天上下文
     * @return array 写入结果
     */
    public function createRedPacket(User $user, float $amount, int $count, string $title, ?array $chatContext = null): array
    {
        try {
            Log::info('写入红包数据', [
                'user_id' => $user->id,
                'tg_id' => $user->tg_id ?? $user->user_id,
                'amount' => $amount,
                'count' => $count,
                'title' => $title,
                'chat_context' => $chatContext
            ]);
            
            // 基础参数验证
            $this->validateRedPacketParams($amount, $count, $title);
            
            // 构建红包数据（完全匹配数据库字段）
            $redPacketData = [
                'packet_id' => 'HB' . time() . rand(1000, 9999), // 红包唯一标识
                'title' => $title,
                'total_amount' => $amount,
                'total_count' => $count,
                'remain_amount' => $amount,      // 剩余金额
                'remain_count' => $count,        // 剩余个数  
                'packet_type' => self::TYPE_RANDOM, // 红包类型
                'sender_id' => $user->id,
                'sender_tg_id' => $user->tg_id ?? $user->user_id,
                'expire_time' => date('Y-m-d H:i:s', time() + 86400), // 24小时后过期
                'status' => 1, // 1正常
                'is_system' => 0, // 0非系统红包
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // 添加聊天上下文信息
            if ($chatContext) {
                $redPacketData['chat_id'] = (string)($chatContext['chat_id'] ?? 0);
                $redPacketData['chat_type'] = $chatContext['chat_type'] ?? 'group';
                // 注意：数据库中没有 chat_title 字段，所以不添加
            }
            
            // 写入数据库
            $insertId = Db::name('tg_red_packets')->insertGetId($redPacketData);
            
            if (!$insertId) {
                throw new \Exception('红包数据写入失败');
            }
            
            Log::info('红包数据写入成功', [
                'record_id' => $insertId,
                'user_id' => $user->id,
                'amount' => $amount,
                'count' => $count
            ]);
            
            return [
                'success' => true,
                'msg' => '红包数据已记录',
                'packet_id' => $insertId,
                'data' => [
                    'id' => $insertId,
                    'amount' => $amount,
                    'count' => $count,
                    'title' => $title
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('红包数据写入失败', [
                'user_id' => $user->id,
                'amount' => $amount,
                'count' => $count,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'msg' => '红包数据写入失败: ' . $e->getMessage(),
                'data' => [
                    'error_code' => $e->getCode(),
                    'error_message' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * 检查红包发送重复（新增方法）
     */
    public function checkRedPacketSendDuplicate(int $userId, array $redPacketData): bool
    {
        $key = "redpacket_send_lock_{$userId}";
        $lockData = [
            'amount' => $redPacketData['amount'],
            'count' => $redPacketData['count'],
            'title' => $redPacketData['title'],
            'timestamp' => time()
        ];
        
        $existing = Cache::get($key);
        
        if ($existing && $this->isSameRedPacketData($existing, $lockData)) {
            // 同样的红包数据在短时间内不允许重复发送
            return true;
        }
        
        // 设置锁定，5秒内防止重复
        Cache::set($key, $lockData, 5);
        return false;
    }
    
    /**
     * 比较红包数据是否相同（宽松模式 - 标题不同就算不同红包）
     */
    private function isSameRedPacketData(array $data1, array $data2): bool
    {
        // 如果标题不同，就认为是不同的红包
        if ($data1['title'] !== $data2['title']) {
            return false;
        }
        
        // 标题相同时，再检查金额和个数
        return $data1['amount'] === $data2['amount'] &&
               $data1['count'] === $data2['count'] &&
               (time() - $data1['timestamp']) < 5; // 5秒内认为是重复操作
    }
    
    /**
     * 清除红包发送锁定
     */
    public function clearRedPacketSendLock(int $userId): void
    {
        $key = "redpacket_send_lock_{$userId}";
        Cache::delete($key);
    }
    
    /**
     * 抢红包 - 带并发控制
     */
    public function grabRedPacket(int $packetId, User $user): array
    {
        $lockKey = self::GRAB_LOCK_PREFIX . $packetId . '_' . $user->id;
        $userLimitKey = self::USER_LIMIT_PREFIX . $user->id;
        
        try {
            // 并发控制：检查用户操作频率
            if (Redis::exists($userLimitKey)) {
                return [
                    'success' => false,
                    'msg' => '操作过于频繁，请稍候再试',
                    'code' => 'TOO_FREQUENT'
                ];
            }
            
            // 设置用户操作锁定
            Redis::setex($userLimitKey, self::USER_GRAB_INTERVAL, 1);
            
            // 并发控制：抢红包锁
            if (!Redis::set($lockKey, 1, 'EX', self::LOCK_EXPIRE_TIME, 'NX')) {
                return [
                    'success' => false,
                    'msg' => '系统繁忙，请稍候再试',
                    'code' => 'SYSTEM_BUSY'
                ];
            }
            
            // 查询红包信息
            $redPacket = RedPacket::find($packetId);
            if (!$redPacket) {
                return [
                    'success' => false,
                    'msg' => '红包不存在',
                    'code' => 'NOT_FOUND'
                ];
            }
            
            // 检查红包状态
            if ($redPacket->status != self::STATUS_ACTIVE) {
                return [
                    'success' => false,
                    'msg' => '红包已结束',
                    'code' => 'PACKET_ENDED'
                ];
            }
            
            // 检查是否已抢过
            $existingRecord = RedPacketRecord::where('packet_id', $packetId)
                                            ->where('user_id', $user->id)
                                            ->find();
            if ($existingRecord) {
                return [
                    'success' => false,
                    'msg' => '您已经抢过这个红包了',
                    'code' => 'ALREADY_GRABBED'
                ];
            }
            
            // 检查红包是否还有剩余
            if ($redPacket->remain_acount <= 0 || $redPacket->remain_amount <= 0) {
                // 更新红包状态为已完成
                $redPacket->status = self::STATUS_COMPLETED;
                $redPacket->save();
                
                return [
                    'success' => false,
                    'msg' => '红包已被抢完',
                    'code' => 'PACKET_EMPTY'
                ];
            }
            
            // 开始数据库事务
            Db::startTrans();
            
            // 计算抢到的金额
            $grabAmount = $this->calculateGrabAmount($redPacket);
            
            // 创建抢红包记录
            $grabRecord = RedPacketRecord::create([
                'packet_id' => $packetId,
                'user_id' => $user->id,
                'user_tg_id' => $user->user_id,
                'amount' => $grabAmount,
                'grab_time' => date('Y-m-d H:i:s'),
                'grab_order' => $redPacket->total_count - $redPacket->remain_acount + 1
            ]);
            
            // 更新红包剩余信息
            $redPacket->remain_acount -= 1;
            $redPacket->remain_amount -= $grabAmount;
            
            // 检查是否抢完
            $isCompleted = $redPacket->remain_acount <= 0 || $redPacket->remain_amount <= 0.01;
            if ($isCompleted) {
                $redPacket->status = self::STATUS_COMPLETED;
            }
            
            $redPacket->save();
            
            // 增加用户余额
            $user->money_balance += $grabAmount;
            $user->save();
            
            // 记录资金变动
            $this->recordMoneyLog($user->id, $grabAmount, '抢红包', $packetId);
            
            Db::commit();
            
            // 释放锁
            Redis::del($lockKey);
            
            Log::info('抢红包成功', [
                'packet_id' => $packetId,
                'user_id' => $user->id,
                'amount' => $grabAmount,
                'grab_order' => $grabRecord->grab_order,
                'is_completed' => $isCompleted
            ]);
            
            return [
                'success' => true,
                'msg' => '恭喜抢到红包！',
                'data' => [
                    'amount' => $grabAmount,
                    'grab_order' => $grabRecord->grab_order,
                    'is_completed' => $isCompleted,
                    'is_best_luck' => $this->checkBestLuck($packetId, $grabAmount),
                    'remain_acount' => $redPacket->remain_acount,
                    'remain_amount' => $redPacket->remain_amount
                ]
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Redis::del($lockKey);
            
            Log::error('抢红包失败', [
                'packet_id' => $packetId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'msg' => '抢红包失败: ' . $e->getMessage(),
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }
    
    /**
     * 计算抢红包金额
     */
    private function calculateGrabAmount(RedPacket $redPacket): float
    {
        if ($redPacket->type == self::TYPE_AVERAGE) {
            // 平均分配
            return round($redPacket->remain_amount / $redPacket->remain_acount, 2);
        } else {
            // 拼手气红包
            if ($redPacket->remain_acount == 1) {
                // 最后一个红包，返回剩余所有金额
                return $redPacket->remain_amount;
            }
            
            // 简单随机算法
            $min = 0.01;
            $max = ($redPacket->remain_amount / $redPacket->remain_acount) * 2;
            $max = min($max, $redPacket->remain_amount - ($redPacket->remain_acount - 1) * $min);
            
            return round(mt_rand($min * 100, $max * 100) / 100, 2);
        }
    }
    
    /**
     * 检查是否手气最佳
     */
    private function checkBestLuck(int $packetId, float $amount): bool
    {
        $maxAmount = RedPacketRecord::where('packet_id', $packetId)
                                   ->max('amount');
        
        return $amount >= $maxAmount;
    }
    
    /**
     * 记录资金变动
     */
    private function recordMoneyLog(int $userId, float $amount, string $reason, int $relatedId = 0): void
    {
        try {
            Db::name('money_log')->insert([
                'user_id' => $userId,
                'amount' => $amount,
                'type' => $amount > 0 ? 1 : 2, // 1=收入, 2=支出
                'reason' => $reason,
                'related_id' => $relatedId,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            Log::warning('资金记录失败', [
                'user_id' => $userId,
                'amount' => $amount,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // =================== 查询功能 ===================
    
    /**
     * 获取红包详情
     */
    public function getRedPacketInfo(int $packetId): array
    {
        try {
            $redPacket = RedPacket::find($packetId);
            if (!$redPacket) {
                return [
                    'success' => false,
                    'msg' => '红包不存在'
                ];
            }
            
            $records = RedPacketRecord::where('packet_id', $packetId)
                                     ->order('grab_time', 'asc')
                                     ->select();
            
            return [
                'success' => true,
                'data' => [
                    'packet' => $redPacket->toArray(),
                    'records' => $records->toArray(),
                    'grab_count' => count($records),
                    'status_text' => $this->getStatusText($redPacket->status)
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取红包详情失败', [
                'packet_id' => $packetId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'msg' => '获取红包详情失败'
            ];
        }
    }
    
    /**
     * 获取状态文本
     */
    private function getStatusText(int $status): string
    {
        switch ($status) {
            case self::STATUS_ACTIVE:
                return '进行中';
            case self::STATUS_COMPLETED:
                return '已抢完';
            case self::STATUS_EXPIRED:
                return '已过期';
            case self::STATUS_REVOKED:
                return '已撤回';
            default:
                return '未知状态';
        }
    }
    
    /**
     * 获取用户红包历史
     */
    public function getUserRedPacketHistory(int $userId, int $page = 1, int $limit = 20): array
    {
        try {
            $offset = ($page - 1) * $limit;
            
            // 发送的红包
            $sentPackets = RedPacket::where('sender_id', $userId)
                                   ->order('created_at', 'desc')
                                   ->limit($offset, $limit)
                                   ->select();
            
            // 抢到的红包
            $grabbedRecords = RedPacketRecord::alias('r')
                                            ->join('red_packet p', 'r.packet_id = p.id')
                                            ->where('r.user_id', $userId)
                                            ->order('r.grab_time', 'desc')
                                            ->limit($offset, $limit)
                                            ->select();
            
            return [
                'success' => true,
                'data' => [
                    'sent_packets' => $sentPackets->toArray(),
                    'grabbed_records' => $grabbedRecords->toArray(),
                    'page' => $page,
                    'limit' => $limit
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取用户红包历史失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'msg' => '获取历史记录失败'
            ];
        }
    }
}