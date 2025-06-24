<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * 红包记录模型
 */
class RedPacketRecord extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_red_packet_records';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'amount' => 'float',
        'is_best' => 'integer',
        'grab_order' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'packet_id', 'user_id', 'user_tg_id', 'amount', 'grab_order', 'created_at'];
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'packet_id' => 'required',
            'user_id' => 'required|integer',
            'user_tg_id' => 'required',
            'amount' => 'required|float|min:0.01',
            'grab_order' => 'required|integer|min:1',
        ];
    }
    
    /**
     * 金额修改器
     */
    public function setAmountAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 用户名修改器
     */
    public function setUsernameAttr($value)
    {
        return $value ? ltrim($value, '@') : '';
    }
    
    /**
     * 格式化金额
     */
    public function getFormattedAmountAttr($value, $data)
    {
        return number_format($data['amount'] ?? 0, 2) . ' USDT';
    }
    
    /**
     * 运气等级获取器
     */
    public function getLuckLevelAttr($value, $data)
    {
        if (($data['is_best'] ?? 0) === 1) {
            return '手气最佳';
        }
        
        $order = $data['grab_order'] ?? 0;
        
        if ($order === 1) {
            return '首抢';
        } elseif ($order <= 3) {
            return '手气不错';
        } elseif ($order <= 5) {
            return '运气一般';
        } else {
            return '慢了一步';
        }
    }
    
    /**
     * 运气等级颜色获取器
     */
    public function getLuckColorAttr($value, $data)
    {
        if (($data['is_best'] ?? 0) === 1) {
            return 'gold';
        }
        
        $order = $data['grab_order'] ?? 0;
        
        if ($order === 1) {
            return 'red';
        } elseif ($order <= 3) {
            return 'orange';
        } elseif ($order <= 5) {
            return 'blue';
        } else {
            return 'gray';
        }
    }
    
    /**
     * 显示用户名获取器
     */
    public function getDisplayNameAttr($value, $data)
    {
        $username = $data['username'] ?? '';
        $tgId = $data['user_tg_id'] ?? '';
        
        if (!empty($username)) {
            return '@' . $username;
        } elseif (!empty($tgId)) {
            return 'User' . substr($tgId, -6); // 显示TG ID的后6位
        } else {
            return '匿名用户';
        }
    }
    
    /**
     * 抢红包时间格式化 - 修复：使用 datetime 计算
     */
    public function getGrabTimeAttr($value, $data)
    {
        $createdAt = $data['created_at'] ?? '';
        
        if (empty($createdAt)) {
            return '';
        }
        
        $createTimestamp = strtotime($createdAt);
        $currentTimestamp = strtotime(date('Y-m-d H:i:s'));
        $diff = $currentTimestamp - $createTimestamp;
        
        if ($diff < 60) {
            return $diff . '秒前';
        } elseif ($diff < 3600) {
            return round($diff / 60) . '分钟前';
        } elseif ($diff < 86400) {
            return round($diff / 3600) . '小时前';
        } else {
            return date('m-d H:i', $createTimestamp);
        }
    }
    
    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * 关联红包
     */
    public function redPacket()
    {
        return $this->belongsTo(RedPacket::class, 'packet_id', 'packet_id');
    }
    
    /**
     * 创建记录 - 修复：使用 datetime 格式并集成MoneyLog
     */
    public static function createRecord(array $data): RedPacketRecord
    {
        $record = new static();
        
        // 设置默认值 - 修复：使用 datetime 格式
        $data = array_merge([
            'is_best' => 0,
            'username' => '',
            'created_at' => date('Y-m-d H:i:s'), // 修复：使用 datetime 格式
        ], $data);
        
        $record->save($data);
        
        // 更新用户统计
        $record->updateUserStats();
        
        // 记录日志
        trace([
            'action' => 'redpacket_record_created',
            'record_id' => $record->id,
            'packet_id' => $record->packet_id,
            'user_id' => $record->user_id,
            'user_tg_id' => $record->user_tg_id,
            'amount' => $record->amount,
            'grab_order' => $record->grab_order,
            'timestamp' => time(),
        ], 'redpacket');
        
        return $record;
    }
    
    /**
     * 设置为手气最佳
     */
    public function setBestLuck(): bool
    {
        $this->is_best = 1;
        $result = $this->save();
        
        if ($result) {
            // 更新用户统计
            $this->updateUserStats(true);
            
            // 记录手气最佳日志
            trace([
                'action' => 'redpacket_best_luck',
                'record_id' => $this->id,
                'packet_id' => $this->packet_id,
                'user_id' => $this->user_id,
                'amount' => $this->amount,
                'timestamp' => time(),
            ], 'redpacket');
        }
        
        return $result;
    }
    
    /**
     * 获取红包排行榜 - 修复：使用 datetime 格式比较
     */
    public static function getRanking(string $type = 'received', int $limit = 10, string $period = 'month'): array
    {
        $query = static::alias('r')
                      ->join('common_user u', 'r.user_id = u.id')
                      ->field('r.user_id, u.user_name, u.tg_username');
        
        // 时间范围 - 修复：使用 datetime 格式
        switch ($period) {
            case 'today':
                $startTime = date('Y-m-d 00:00:00');
                break;
            case 'week':
                $startTime = date('Y-m-d 00:00:00', strtotime('this week monday'));
                break;
            case 'month':
                $startTime = date('Y-m-01 00:00:00');
                break;
            default:
                $startTime = '';
        }
        
        if (!empty($startTime)) {
            $query->where('r.created_at', '>=', $startTime);
        }
        
        // 排行类型
        switch ($type) {
            case 'received':
                // 收红包排行（按金额）
                $query->field('SUM(r.amount) as total_amount, COUNT(r.id) as total_count')
                      ->group('r.user_id')
                      ->order('total_amount DESC');
                break;
                
            case 'count':
                // 收红包排行（按次数）
                $query->field('COUNT(r.id) as total_count, SUM(r.amount) as total_amount')
                      ->group('r.user_id')
                      ->order('total_count DESC');
                break;
                
            case 'best_luck':
                // 手气最佳排行
                $query->field('COUNT(r.id) as best_count, SUM(r.amount) as total_amount')
                      ->where('r.is_best', 1)
                      ->group('r.user_id')
                      ->order('best_count DESC');
                break;
                
            case 'single':
                // 单个红包最大金额排行
                $query->field('MAX(r.amount) as max_amount, r.packet_id')
                      ->group('r.user_id')
                      ->order('max_amount DESC');
                break;
        }
        
        return $query->limit($limit)->select()->toArray();
    }
    
    /**
     * 获取红包记录统计
     */
    public static function getRecordStats(string $packetId): array
    {
        $query = static::where('packet_id', $packetId);
        
        $records = $query->order('grab_order ASC')->select();
        $totalAmount = $query->sum('amount');
        $avgAmount = $query->avg('amount');
        $maxAmount = $query->max('amount');
        $minAmount = $query->min('amount');
        
        return [
            'records' => $records->toArray(),
            'total_count' => $records->count(),
            'total_amount' => $totalAmount,
            'avg_amount' => round($avgAmount, 2),
            'max_amount' => $maxAmount,
            'min_amount' => $minAmount,
            'best_luck' => $records->where('is_best', 1)->find(),
        ];
    }
    
    /**
     * 获取用户红包记录
     */
    public static function getUserRecords(int $userId, string $type = 'all', int $page = 1, int $limit = 20): array
    {
        $query = static::where('user_id', $userId);
        
        switch ($type) {
            case 'best':
                $query->where('is_best', 1);
                break;
            case 'recent':
                $recentTime = date('Y-m-d 00:00:00', time() - (86400 * 7)); // 最近7天
                $query->where('created_at', '>', $recentTime);
                break;
        }
        
        $total = $query->count();
        $records = $query->order('created_at DESC')
                        ->page($page, $limit)
                        ->select();
        
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit),
            'records' => $records->toArray(),
        ];
    }
    
    /**
     * 检查用户是否已抢过红包
     */
    public static function hasGrabbed(string $packetId, string $userTgId): bool
    {
        return static::where('packet_id', $packetId)
                    ->where('user_tg_id', $userTgId)
                    ->find() !== null;
    }
    
    /**
     * 获取红包详细信息
     */
    public static function getPacketDetail(string $packetId): array
    {
        // 获取红包信息
        $packet = RedPacket::findByPacketId($packetId);
        if (!$packet) {
            return ['exists' => false];
        }
        
        // 获取抢红包记录
        $records = static::where('packet_id', $packetId)
                        ->order('grab_order ASC')
                        ->select();
        
        // 统计信息
        $stats = [
            'total_grabbed' => $records->count(),
            'total_amount_grabbed' => $records->sum('amount'),
            'avg_amount' => $records->avg('amount'),
            'max_amount' => $records->max('amount'),
            'min_amount' => $records->min('amount'),
            'best_luck_user' => $records->where('is_best', 1)->find(),
        ];
        
        return [
            'exists' => true,
            'packet' => $packet->toArray(),
            'records' => $records->toArray(),
            'stats' => $stats,
        ];
    }
    
    /**
     * 更新用户统计 - 修复：使用简化缓存方式
     */
    private function updateUserStats(bool $isBestLuck = false): void
    {
        $date = date('Y-m-d');
        $statsKey = 'redpacket_user_stats_' . $this->user_id . '_' . $date;
        $stats = cache($statsKey) ?: [
            'send_count' => 0,
            'send_amount' => 0,
            'receive_count' => 0,
            'receive_amount' => 0,
            'best_count' => 0,
        ];
        
        $stats['receive_count']++;
        $stats['receive_amount'] += $this->amount;
        
        if ($isBestLuck) {
            $stats['best_count']++;
        }
        
        cache($statsKey, $stats, 86400);
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '记录ID',
            'packet_id' => '红包ID',
            'user_id' => '用户ID',
            'user_tg_id' => '用户TG_ID',
            'username' => '用户名',
            'amount' => '领取金额',
            'is_best' => '是否手气最佳',
            'grab_order' => '领取顺序',
            'created_at' => '领取时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '红包领取记录表';
    }
}