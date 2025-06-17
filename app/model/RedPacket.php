<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use app\utils\RedPacketAlgorithm; // 🔥 新增：引入算法工具类
use think\Model;
use think\facade\Db;

/**
 * 红包模型
 */
class RedPacket extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_red_packets';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'total_amount' => 'float',
        'total_count' => 'integer',
        'remain_amount' => 'float',
        'remain_count' => 'integer',
        'packet_type' => 'integer',
        'sender_id' => 'integer',
        'status' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'packet_id', 'total_amount', 'total_count', 'sender_id', 'created_at'];
    
    /**
     * 红包类型常量
     */
    public const TYPE_RANDOM = 1;     // 拼手气红包
    public const TYPE_AVERAGE = 2;    // 平均红包
    public const TYPE_CUSTOM = 3;     // 定制红包
    
    /**
     * 红包状态常量
     */
    public const STATUS_ACTIVE = 1;      // 进行中
    public const STATUS_COMPLETED = 2;   // 已抢完
    public const STATUS_EXPIRED = 3;     // 已过期
    public const STATUS_REVOKED = 4;     // 已撤回
    public const STATUS_CANCELED = 5;    // 已取消
    
    /**
     * 聊天类型常量
     */
    public const CHAT_TYPE_GROUP = 'group';
    public const CHAT_TYPE_SUPERGROUP = 'supergroup';
    public const CHAT_TYPE_PRIVATE = 'private';
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'packet_id' => 'required|unique:tg_red_packets',
            'total_amount' => 'required|float|min:0.01',
            'total_count' => 'required|integer|min:1',
            'packet_type' => 'required|in:1,2,3',
            'sender_id' => 'required|integer',
            'sender_tg_id' => 'required',
            'chat_id' => 'required',
            'expire_time' => 'required|date',
        ];
    }
    
    /**
     * 金额修改器
     */
    public function setTotalAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 剩余金额修改器
     */
    public function setRemainAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 红包ID修改器
     */
    public function setPacketIdAttr($value)
    {
        return strtoupper(trim($value));
    }
    
    /**
     * 过期时间修改器 - 修复：使用 datetime 格式
     */
    public function setExpireTimeAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        // 如果是时间戳，转换为datetime格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果是字符串，检查格式
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return $value;
            }
            
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        return null;
    }
    
    /**
     * 完成时间修改器 - 修复：使用 datetime 格式
     */
    public function setFinishedAtAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        // 如果是时间戳，转换为datetime格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果是字符串，检查格式
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return $value;
            }
            
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        return null;
    }
    
    /**
     * 红包类型获取器
     */
    public function getTypeTextAttr($value, $data)
    {
        $types = [
            self::TYPE_RANDOM => '拼手气红包',
            self::TYPE_AVERAGE => '平均红包',
            self::TYPE_CUSTOM => '定制红包',
        ];
        return $types[$data['packet_type']] ?? '未知';
    }
    
    /**
     * 红包状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_ACTIVE => '进行中',
            self::STATUS_COMPLETED => '已抢完',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_REVOKED => '已撤回',
            self::STATUS_CANCELED => '已取消',
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 红包状态颜色获取器
     */
    public function getStatusColorAttr($value, $data)
    {
        $colors = [
            self::STATUS_ACTIVE => 'success',
            self::STATUS_COMPLETED => 'info',
            self::STATUS_EXPIRED => 'warning',
            self::STATUS_REVOKED => 'danger',
            self::STATUS_CANCELED => 'secondary',
        ];
        return $colors[$data['status']] ?? 'secondary';
    }
    
    /**
     * 格式化总金额
     */
    public function getFormattedTotalAttr($value, $data)
    {
        return number_format($data['total_amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 格式化剩余金额
     */
    public function getFormattedRemainAttr($value, $data)
    {
        return number_format($data['remain_amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 已抢金额获取器
     */
    public function getGrabbedAmountAttr($value, $data)
    {
        return ($data['total_amount'] ?? 0) - ($data['remain_amount'] ?? 0);
    }
    
    /**
     * 已抢个数获取器
     */
    public function getGrabbedCountAttr($value, $data)
    {
        return ($data['total_count'] ?? 0) - ($data['remain_count'] ?? 0);
    }
    
    /**
     * 进度百分比获取器
     */
    public function getProgressAttr($value, $data)
    {
        $total = $data['total_count'] ?? 0;
        $remain = $data['remain_count'] ?? 0;
        
        if ($total <= 0) {
            return 0;
        }
        
        return round((($total - $remain) / $total) * 100, 1);
    }
    
    /**
     * 是否已过期 - 修复：使用 datetime 比较
     */
    public function getIsExpiredAttr($value, $data)
    {
        $expireTime = $data['expire_time'] ?? '';
        if (empty($expireTime)) {
            return false;
        }
        
        $currentTime = date('Y-m-d H:i:s');
        return $expireTime < $currentTime;
    }
    
    /**
     * 是否可以抢
     */
    public function getCanGrabAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_ACTIVE 
            && ($data['remain_count'] ?? 0) > 0 
            && !$this->is_expired;
    }
    
    /**
     * 是否可以撤回
     */
    public function getCanRevokeAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_ACTIVE 
            && ($data['remain_count'] ?? 0) > 0;
    }
    
    /**
     * 剩余时间获取器 - 修复：使用 datetime 计算
     */
    public function getRemainTimeAttr($value, $data)
    {
        $expireTime = $data['expire_time'] ?? '';
        if (empty($expireTime)) {
            return '永不过期';
        }
        
        $expireTimestamp = strtotime($expireTime);
        $currentTimestamp = strtotime(date('Y-m-d H:i:s'));
        $remainSeconds = $expireTimestamp - $currentTimestamp;
        
        if ($remainSeconds <= 0) {
            return '已过期';
        }
        
        if ($remainSeconds < 60) {
            return $remainSeconds . '秒';
        } elseif ($remainSeconds < 3600) {
            return round($remainSeconds / 60) . '分钟';
        } else {
            return round($remainSeconds / 3600, 1) . '小时';
        }
    }
    
    /**
     * 关联发送者
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
    
    /**
     * 关联红包记录
     */
    public function records()
    {
        return $this->hasMany(RedPacketRecord::class, 'packet_id', 'packet_id');
    }
    
    /**
     * 关联手气最佳记录
     */
    public function bestLuckRecord()
    {
        return $this->hasOne(RedPacketRecord::class, 'packet_id', 'packet_id')
                    ->where('is_best', 1);
    }
    
    /**
     * 创建红包 - 修复：使用事务和MoneyLog集成
     */
    public static function createPacket(array $data): RedPacket
    {
        // 开启事务
        Db::startTrans();
        
        try {
            $packet = new static();
            
            // 生成红包ID
            if (empty($data['packet_id'])) {
                $data['packet_id'] = $packet->generatePacketId();
            }
            
            // 设置过期时间 - 修复：使用 datetime 格式
            if (empty($data['expire_time'])) {
                $expireHours = config('redpacket.basic.expire_hours', 24);
                $expireTimestamp = time() + ($expireHours * 3600);
                $data['expire_time'] = date('Y-m-d H:i:s', $expireTimestamp);
            }
            
            // 设置默认值 - 修复：使用 datetime 格式
            $data = array_merge([
                'status' => self::STATUS_ACTIVE,
                'remain_amount' => $data['total_amount'],
                'remain_count' => $data['total_count'],
                'chat_type' => self::CHAT_TYPE_GROUP,
                'title' => '恭喜发财，大吉大利',
                'created_at' => date('Y-m-d H:i:s'),
            ], $data);
            
            $packet->save($data);
            
            // 扣除发送者余额 - 修复：使用新的方法
            $sender = User::find($data['sender_id']);
            if (!$sender) {
                throw new \Exception('发送者不存在');
            }
            
            if (!$sender->sendRedPacket($data['total_amount'], $packet->packet_id)) {
                throw new \Exception('余额不足或扣款失败');
            }
            
            // 生成红包分配金额 - 🔥 调整：使用新的算法工具类
            $packet->generateAmounts();
            
            // 记录创建日志
            trace([
                'action' => 'redpacket_created',
                'packet_id' => $packet->packet_id,
                'sender_id' => $packet->sender_id,
                'total_amount' => $packet->total_amount,
                'total_count' => $packet->total_count,
                'type' => $packet->packet_type,
                'chat_id' => $packet->chat_id,
                'timestamp' => time(),
            ], 'redpacket');
            
            Db::commit();
            return $packet;
            
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }
    
    /**
     * 抢红包方法 - 修复状态更新问题
     */
    public function grab(int $userId, string $userTgId, string $username): array
    {
        // 检查是否已抢过
        $existRecord = RedPacketRecord::where('packet_id', $this->packet_id)
                                    ->where('user_id', $userId)  // 使用user_id更准确
                                    ->find();
        
        if ($existRecord) {
            return ['success' => false, 'message' => '您已经抢过这个红包了'];
        }
        
        // 检查是否自己发的红包
        if ($this->sender_id == $userId) {
            return ['success' => false, 'message' => '不能抢自己发的红包'];
        }
        
        // 🔥 修复1：先检查红包状态，避免重复检查
        if ($this->status !== self::STATUS_ACTIVE) {
            return ['success' => false, 'message' => '红包已结束'];
        }
        
        // 🔥 修复2：实时检查剩余数量
        if ($this->remain_count <= 0) {
            // 如果发现剩余数量为0但状态未更新，立即更新状态
            $this->updateToCompleted();
            return ['success' => false, 'message' => '红包已被抢完'];
        }
        
        // 开启事务
        Db::startTrans();
        
        try {
            // 🔥 修复3：加行锁重新查询，确保数据一致性
            $currentPacket = self::lock(true)->find($this->id);
            if (!$currentPacket) {
                throw new \Exception('红包不存在');
            }
            
            // 再次检查剩余数量（防止并发）
            if ($currentPacket->remain_count <= 0) {
                $currentPacket->updateToCompleted();
                throw new \Exception('红包已被抢完');
            }
            
            // 获取一个红包金额
            $amount = $this->getOneAmount();
            if ($amount <= 0) {
                throw new \Exception('红包已被抢完');
            }
            
            // 🔥 修复4：原子性更新红包数据
            $newRemainAmount = $currentPacket->remain_amount - $amount;
            $newRemainCount = $currentPacket->remain_count - 1;
            
            // 计算抢红包顺序
            $grabOrder = $currentPacket->total_count - $newRemainCount;
            
            // 🔥 修复5：先创建抢红包记录
            $record = RedPacketRecord::create([
                'packet_id' => $this->packet_id,
                'user_id' => $userId,
                'user_tg_id' => $userTgId,
                'username' => $username,
                'amount' => $amount,
                'grab_order' => $grabOrder,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            
            // 🔥 修复6：然后更新红包主表数据
            $updateData = [
                'remain_amount' => $newRemainAmount,
                'remain_count' => $newRemainCount,
            ];
            
            // 检查是否抢完 - 自动更新状态
            if ($newRemainCount <= 0) {
                $updateData['status'] = self::STATUS_COMPLETED;
                $updateData['finished_at'] = date('Y-m-d H:i:s');
            }
            
            // 执行更新
            $updateResult = self::where('id', $this->id)->update($updateData);
            
            if (!$updateResult) {
                throw new \Exception('红包状态更新失败');
            }
            
            // 🔥 修复7：更新当前对象状态
            $this->remain_amount = $newRemainAmount;
            $this->remain_count = $newRemainCount;
            if ($newRemainCount <= 0) {
                $this->status = self::STATUS_COMPLETED;
                $this->finished_at = date('Y-m-d H:i:s');
            }
            
            // 给用户加余额
            $user = User::find($userId);
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            if (!$user->receiveRedPacket($amount, $this->packet_id)) {
                throw new \Exception('余额更新失败');
            }
            
            Db::commit();
            
            // 🔥 修复8：记录详细的成功信息
            Log::info('抢红包成功', [
                'packet_id' => $this->packet_id,
                'user_id' => $userId,
                'amount' => $amount,
                'grab_order' => $grabOrder,
                'new_remain_count' => $newRemainCount,
                'new_remain_amount' => $newRemainAmount,
                'is_completed' => $newRemainCount <= 0
            ]);
            
            return [
                'success' => true,
                'amount' => $amount,
                'grab_order' => $grabOrder,
                'is_completed' => $newRemainCount <= 0,
                'is_best' => false, // 手气最佳需要等红包抢完后确定
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            
            Log::error('抢红包失败', [
                'packet_id' => $this->packet_id,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 🔥 新增：更新红包为已完成状态
     */
    public function updateToCompleted(): bool
    {
        try {
            $updateData = [
                'status' => self::STATUS_COMPLETED,
                'finished_at' => date('Y-m-d H:i:s'),
            ];
            
            // 如果剩余数量不为0，也要清零
            if ($this->remain_count > 0) {
                $updateData['remain_count'] = 0;
            }
            
            $result = self::where('id', $this->id)->update($updateData);
            
            // 更新当前对象状态
            if ($result) {
                $this->status = self::STATUS_COMPLETED;
                $this->finished_at = date('Y-m-d H:i:s');
                if ($this->remain_count > 0) {
                    $this->remain_count = 0;
                }
            }
            
            Log::info('红包状态更新为已完成', [
                'packet_id' => $this->packet_id,
                'result' => $result
            ]);
            
            return $result > 0;
            
        } catch (\Exception $e) {
            Log::error('更新红包完成状态失败', [
                'packet_id' => $this->packet_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    
    /**
     * 撤回红包 - 修复：使用事务和MoneyLog集成
     */
    public function revoke(): bool
    {
        if (!$this->can_revoke) {
            return false;
        }
        
        // 开启事务
        Db::startTrans();
        
        try {
            $this->status = self::STATUS_REVOKED;
            $this->finished_at = date('Y-m-d H:i:s');
            $this->save();
            
            // 退还剩余金额给发送者 - 修复：使用新的方法
            if ($this->remain_amount > 0) {
                $sender = $this->sender;
                if ($sender) {
                    $beforeBalance = $sender->money_balance;
                    $sender->addBalance($this->remain_amount);
                    
                    // 记录退款流水
                    MoneyLog::createLog([
                        'uid' => $sender->id,
                        'type' => MoneyLog::TYPE_REFUND,
                        'status' => MoneyLog::STATUS_REDPACKET_SEND,
                        'money_before' => $beforeBalance,
                        'money_end' => $beforeBalance + $this->remain_amount,
                        'money' => $this->remain_amount,
                        'mark' => "红包撤回退款 - {$this->packet_id}",
                    ]);
                }
            }
            
            // 更新统计
            $this->updateStats($this->sender_id, true);
            
            // 清除缓存
            $this->clearCache();
            
            // 记录撤回日志
            trace([
                'action' => 'redpacket_revoked',
                'packet_id' => $this->packet_id,
                'sender_id' => $this->sender_id,
                'remain_amount' => $this->remain_amount,
                'timestamp' => time(),
            ], 'redpacket');
            
            Db::commit();
            return true;
            
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    /**
     * 过期处理 - 修复：使用事务和MoneyLog集成
     */
    public function expire(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        
        // 开启事务
        Db::startTrans();
        
        try {
            $this->status = self::STATUS_EXPIRED;
            $this->finished_at = date('Y-m-d H:i:s');
            $this->save();
            
            // 退还剩余金额给发送者 - 修复：使用新的方法
            if ($this->remain_amount > 0) {
                $sender = $this->sender;
                if ($sender) {
                    $beforeBalance = $sender->money_balance;
                    $sender->addBalance($this->remain_amount);
                    
                    // 记录退款流水
                    MoneyLog::createLog([
                        'uid' => $sender->id,
                        'type' => MoneyLog::TYPE_REFUND,
                        'status' => MoneyLog::STATUS_REDPACKET_SEND,
                        'money_before' => $beforeBalance,
                        'money_end' => $beforeBalance + $this->remain_amount,
                        'money' => $this->remain_amount,
                        'mark' => "红包过期退款 - {$this->packet_id}",
                    ]);
                }
            }
            
            // 如果有人抢过，设置手气最佳
            if ($this->grabbed_count > 0) {
                $this->setBestLuck();
            }
            
            // 清除缓存
            $this->clearCache();
            
            // 记录过期日志
            trace([
                'action' => 'redpacket_expired',
                'packet_id' => $this->packet_id,
                'sender_id' => $this->sender_id,
                'remain_amount' => $this->remain_amount,
                'grabbed_count' => $this->grabbed_count,
                'timestamp' => time(),
            ], 'redpacket');
            
            Db::commit();
            return true;
            
        } catch (\Exception $e) {
            Db::rollback();
            return false;
        }
    }
    
    /**
     * 根据红包ID查找
     */
    public static function findByPacketId(string $packetId): ?RedPacket
    {
        return static::where('packet_id', strtoupper($packetId))->find();
    }
    
    /**
     * 获取用户红包统计
     */
    public static function getUserStats(int $userId): array
    {
        $sentQuery = static::where('sender_id', $userId);
        $receivedQuery = RedPacketRecord::where('user_id', $userId);
        
        return [
            'sent_count' => $sentQuery->count(),
            'sent_amount' => $sentQuery->sum('total_amount'),
            'received_count' => $receivedQuery->count(),
            'received_amount' => $receivedQuery->sum('amount'),
            'best_luck_count' => $receivedQuery->where('is_best', 1)->count(),
        ];
    }
    
    /**
     * 获取每日统计 - 修复：使用 datetime 格式比较
     */
    public static function getDailyStats(string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $startTime = $date . ' 00:00:00';
        $endTime = $date . ' 23:59:59';
        
        $query = static::where('created_at', '>=', $startTime)
                      ->where('created_at', '<=', $endTime);
        
        return [
            'total_count' => $query->count(),
            'completed_count' => $query->where('status', self::STATUS_COMPLETED)->count(),
            'total_amount' => $query->sum('total_amount'),
            'avg_amount' => $query->avg('total_amount'),
            'max_amount' => $query->max('total_amount'),
            'active_users' => $query->distinct('sender_id')->count(),
        ];
    }
    
    /**
     * 生成红包金额分配 - 🔥 重构：使用RedPacketAlgorithm工具类
     */
    private function generateAmounts(): void
    {
        try {
            // 确定算法类型
            $algorithmType = match($this->packet_type) {
                self::TYPE_RANDOM => RedPacketAlgorithm::TYPE_RANDOM,
                self::TYPE_AVERAGE => RedPacketAlgorithm::TYPE_AVERAGE,
                self::TYPE_CUSTOM => RedPacketAlgorithm::TYPE_CUSTOM,
                default => RedPacketAlgorithm::TYPE_RANDOM
            };
            
            // 准备算法参数
            $options = [
                'min_amount' => config('redpacket.basic.min_single_amount', 0.01),
                'precision' => config('redpacket.basic.precision', 2),
            ];
            
            // 使用算法工具类生成金额
            $amounts = RedPacketAlgorithm::generateAmounts(
                $this->total_amount,
                $this->total_count,
                $algorithmType,
                $options
            );
            
            if (!empty($amounts)) {
                // 验证总额
                $calculatedTotal = array_sum($amounts);
                if (abs($calculatedTotal - $this->total_amount) > 0.01) {
                    throw new \Exception("红包分配总额不匹配，期望：{$this->total_amount}，实际：{$calculatedTotal}");
                }
                
                // 将金额数组存储到缓存中
                $cacheKey = 'redpacket_amounts_' . $this->packet_id;
                cache($cacheKey, $amounts, 86400); // 缓存24小时
                
                // 记录算法统计信息
                $stats = RedPacketAlgorithm::getStatistics($amounts);
                trace([
                    'action' => 'redpacket_amounts_generated',
                    'packet_id' => $this->packet_id,
                    'algorithm_type' => $algorithmType,
                    'amounts_count' => count($amounts),
                    'statistics' => $stats,
                ], 'redpacket');
            }
            
        } catch (\Exception $e) {
            // 如果算法失败，回退到简单算法
            trace([
                'action' => 'redpacket_algorithm_fallback',
                'packet_id' => $this->packet_id,
                'error' => $e->getMessage(),
            ], 'redpacket');
            
            $this->generateSimpleAmounts();
        }
    }
    
    /**
     * 简单算法备用方案 - 🔥 新增：作为算法工具类的备用方案
     */
    private function generateSimpleAmounts(): void
    {
        $amounts = [];
        $remaining = $this->total_amount;
        $count = $this->total_count;
        $minAmount = config('redpacket.basic.min_single_amount', 0.01);
        
        if ($this->packet_type === self::TYPE_AVERAGE) {
            // 平均分配
            $avgAmount = round($remaining / $count, 2);
            for ($i = 0; $i < $count - 1; $i++) {
                $amounts[] = $avgAmount;
                $remaining -= $avgAmount;
            }
            $amounts[] = round($remaining, 2);
        } else {
            // 随机分配
            for ($i = 0; $i < $count - 1; $i++) {
                $maxAmount = $remaining - ($count - $i - 1) * $minAmount;
                $amount = mt_rand($minAmount * 100, $maxAmount * 100) / 100;
                $amounts[] = round($amount, 2);
                $remaining -= $amount;
            }
            $amounts[] = round($remaining, 2);
            shuffle($amounts);
        }
        
        // 存储到缓存
        $cacheKey = 'redpacket_amounts_' . $this->packet_id;
        cache($cacheKey, $amounts, 86400);
    }
    
    /**
     * 🔥 修复：改进金额分配算法
     */
    private function getOneAmount(): float
    {
        // 如果是最后一个红包，返回剩余所有金额
        if ($this->remain_count == 1) {
            return $this->remain_amount;
        }
        
        // 确保至少留给每个剩余红包 0.01
        $minReserve = ($this->remain_count - 1) * 0.01;
        $maxAmount = $this->remain_amount - $minReserve;
        
        // 确保金额不会太小
        $minAmount = 0.01;
        $maxAmount = max($minAmount, min($maxAmount, $this->remain_amount * 0.5));
        
        if ($maxAmount <= $minAmount) {
            return $minAmount;
        }
        
        // 生成随机金额
        $amount = mt_rand($minAmount * 100, $maxAmount * 100) / 100;
        
        return round($amount, 2);
    }
    
    /**
     * 设置手气最佳 - 🔥 增强：使用算法工具类查找最佳
     */
    private function setBestLuck(): void
    {
        $records = $this->records()->select();
        if ($records->isEmpty()) {
            return;
        }
        
        // 使用算法工具类查找手气最佳
        $amounts = $records->column('amount');
        $bestIndex = RedPacketAlgorithm::findBestLuck($amounts);
        
        if ($bestIndex !== false && isset($records[$bestIndex])) {
            $records[$bestIndex]->is_best = 1;
            $records[$bestIndex]->save();
            
            trace([
                'action' => 'redpacket_best_luck_set',
                'packet_id' => $this->packet_id,
                'best_user_id' => $records[$bestIndex]->user_id,
                'best_amount' => $records[$bestIndex]->amount,
            ], 'redpacket');
        }
    }
    
    /**
     * 更新统计
     */
    private function updateStats(int $userId, bool $isRevoke = false): void
    {
        $date = date('Y-m-d');
        $statsKey = 'redpacket_user_stats_' . $userId . '_' . $date;
        $stats = cache($statsKey) ?: [
            'send_count' => 0,
            'send_amount' => 0,
            'receive_count' => 0,
            'receive_amount' => 0,
            'best_count' => 0,
        ];
        
        if ($isRevoke) {
            // 撤回操作，减少发送统计
            $stats['send_count'] = max(0, $stats['send_count'] - 1);
            $stats['send_amount'] = max(0, $stats['send_amount'] - $this->total_amount);
        } else {
            // 正常操作，更新统计
            if ($userId === $this->sender_id) {
                $stats['send_count']++;
                $stats['send_amount'] += $this->total_amount;
            }
        }
        
        cache($statsKey, $stats, 86400);
    }
    
    /**
     * 生成红包ID
     */
    private function generatePacketId(): string
    {
        do {
            $packetId = 'RP' . date('YmdHis') . SecurityHelper::generateRandomString(6, '0123456789');
        } while (static::where('packet_id', $packetId)->find());
        
        return $packetId;
    }
    
    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        // 清除红包详情缓存
        $detailKey = 'redpacket_detail_' . $this->packet_id;
        cache($detailKey, null);
        
        // 清除用户统计缓存
        $userStatsKey = 'redpacket_user_stats_' . $this->sender_id . '_' . date('Y-m-d');
        cache($userStatsKey, null);
        
        // 清除红包金额缓存
        $amountsKey = 'redpacket_amounts_' . $this->packet_id;
        cache($amountsKey, null);
    }
    
    /**
     * 获取红包算法统计信息 - 🔥 新增：获取红包分配统计
     */
    public function getAlgorithmStats(): array
    {
        $cacheKey = 'redpacket_amounts_' . $this->packet_id;
        $amounts = cache($cacheKey) ?: [];
        
        // 如果没有缓存的金额，从记录中获取
        if (empty($amounts)) {
            $records = $this->records()->select();
            $amounts = $records->column('amount');
        }
        
        if (empty($amounts)) {
            return [];
        }
        
        return RedPacketAlgorithm::getStatistics($amounts);
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_ACTIVE => '进行中',
            self::STATUS_COMPLETED => '已抢完',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_REVOKED => '已撤回',
            self::STATUS_CANCELED => '已取消',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '红包ID',
            'packet_id' => '红包唯一标识',
            'title' => '红包标题',
            'total_amount' => '红包总金额',
            'total_count' => '红包总个数',
            'remain_amount' => '剩余金额',
            'remain_count' => '剩余个数',
            'packet_type' => '红包类型',
            'sender_id' => '发送者用户ID',
            'sender_tg_id' => '发送者TG_ID',
            'chat_id' => '群组/聊天ID',
            'chat_type' => '聊天类型',
            'expire_time' => '过期时间',
            'status' => '红包状态',
            'tg_message_id' => '红包消息ID',
            'finished_at' => '完成时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '红包主表';
    }
}