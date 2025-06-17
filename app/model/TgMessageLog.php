<?php
declare(strict_types=1);

namespace app\model;

use think\Model;
use think\model\concern\SoftDelete;

/**
 * Telegram消息发送日志模型
 * 适用于 ThinkPHP8 + PHP8.2
 * 对应数据表：ntp_tg_message_logs
 */
class TgMessageLog extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_message_logs';
    
    /**
     * 数据表前缀
     */
    protected $connection = 'mysql';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'send_status' => 'integer',
        'source_id' => 'integer'
    ];
    
    /**
     * 字段自动完成
     */
    protected $auto = [];
    protected $insert = ['sent_at'];
    protected $update = [];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'sent_at'];
    
    /**
     * 发送状态常量
     */
    public const STATUS_SENDING = 0;    // 发送中
    public const STATUS_SUCCESS = 1;    // 发送成功
    public const STATUS_FAILED = 2;     // 发送失败
    
    /**
     * 消息类型常量
     */
    public const TYPE_NOTIFICATION = 'notification';  // 通知消息
    public const TYPE_ADVERTISEMENT = 'advertisement'; // 广告消息
    public const TYPE_SYSTEM = 'system';              // 系统消息
    public const TYPE_BROADCAST = 'broadcast';        // 广播消息
    
    /**
     * 目标类型常量
     */
    public const TARGET_USER = 'user';      // 用户
    public const TARGET_GROUP = 'group';    // 群组
    public const TARGET_CHANNEL = 'channel'; // 频道
    
    /**
     * 源类型常量
     */
    public const SOURCE_RECHARGE = 'recharge';       // 充值
    public const SOURCE_WITHDRAW = 'withdraw';       // 提现
    public const SOURCE_REDPACKET = 'redpacket';     // 红包
    public const SOURCE_ADVERTISEMENT = 'advertisement'; // 广告
    
    /**
     * 设置发送时间
     */
    protected function setSentAtAttr($value): string
    {
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 获取发送状态文本
     */
    public function getSendStatusTextAttr($value, $data): string
    {
        $statusMap = [
            self::STATUS_SENDING => '发送中',
            self::STATUS_SUCCESS => '发送成功',
            self::STATUS_FAILED => '发送失败'
        ];
        
        return $statusMap[$data['send_status']] ?? '未知状态';
    }
    
    /**
     * 获取消息类型文本
     */
    public function getMessageTypeTextAttr($value, $data): string
    {
        $typeMap = [
            self::TYPE_NOTIFICATION => '通知消息',
            self::TYPE_ADVERTISEMENT => '广告消息',
            self::TYPE_SYSTEM => '系统消息',
            self::TYPE_BROADCAST => '广播消息'
        ];
        
        return $typeMap[$data['message_type']] ?? '未知类型';
    }
    
    /**
     * 获取目标类型文本
     */
    public function getTargetTypeTextAttr($value, $data): string
    {
        $targetMap = [
            self::TARGET_USER => '用户',
            self::TARGET_GROUP => '群组',
            self::TARGET_CHANNEL => '频道'
        ];
        
        return $targetMap[$data['target_type']] ?? '未知目标';
    }
    
    /**
     * 获取源类型文本
     */
    public function getSourceTypeTextAttr($value, $data): string
    {
        $sourceMap = [
            self::SOURCE_RECHARGE => '充值通知',
            self::SOURCE_WITHDRAW => '提现通知',
            self::SOURCE_REDPACKET => '红包通知',
            self::SOURCE_ADVERTISEMENT => '广告推送'
        ];
        
        return $sourceMap[$data['source_type']] ?? '其他';
    }
    
    /**
     * 获取格式化的发送时间
     */
    public function getFormattedSentAtAttr($value, $data): string
    {
        if (empty($data['sent_at'])) {
            return '';
        }
        
        return date('Y-m-d H:i:s', strtotime($data['sent_at']));
    }
    
    /**
     * 获取内容摘要
     */
    public function getContentSummaryAttr($value, $data): string
    {
        $content = $data['content'] ?? '';
        
        if (mb_strlen($content) <= 50) {
            return $content;
        }
        
        return mb_substr($content, 0, 50) . '...';
    }
    
    /**
     * 创建消息日志
     */
    public static function createLog(array $data): self
    {
        $log = new static();
        
        // 设置默认值
        $data = array_merge([
            'message_type' => self::TYPE_NOTIFICATION,
            'target_type' => self::TARGET_GROUP,
            'send_status' => self::STATUS_SENDING,
            'sent_at' => date('Y-m-d H:i:s')
        ], $data);
        
        // 验证必需字段
        $required = ['target_id', 'content'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("字段 {$field} 不能为空");
            }
        }
        
        $log->save($data);
        return $log;
    }
    
    /**
     * 更新发送状态
     */
    public function updateSendStatus(int $status, ?string $errorMessage = null, ?string $telegramMessageId = null): bool
    {
        $updateData = ['send_status' => $status];
        
        if ($errorMessage !== null) {
            $updateData['error_message'] = $errorMessage;
        }
        
        if ($telegramMessageId !== null) {
            $updateData['telegram_message_id'] = $telegramMessageId;
        }
        
        return $this->save($updateData);
    }
    
    /**
     * 获取发送统计
     */
    public static function getSendStatistics(array $filters = []): array
    {
        $query = static::query();
        
        // 应用过滤条件
        if (!empty($filters['date_start'])) {
            $query->where('sent_at', '>=', $filters['date_start'] . ' 00:00:00');
        }
        
        if (!empty($filters['date_end'])) {
            $query->where('sent_at', '<=', $filters['date_end'] . ' 23:59:59');
        }
        
        if (!empty($filters['message_type'])) {
            $query->where('message_type', $filters['message_type']);
        }
        
        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }
        
        // 统计数据
        $total = $query->count();
        $success = $query->where('send_status', self::STATUS_SUCCESS)->count();
        $failed = $query->where('send_status', self::STATUS_FAILED)->count();
        $sending = $query->where('send_status', self::STATUS_SENDING)->count();
        
        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'sending' => $sending,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 0
        ];
    }
    
    /**
     * 获取每日发送统计
     */
    public static function getDailyStatistics(int $days = 7): array
    {
        $stats = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $startTime = $date . ' 00:00:00';
            $endTime = $date . ' 23:59:59';
            
            $dayStats = static::getSendStatistics([
                'date_start' => $date,
                'date_end' => $date
            ]);
            
            $stats[$date] = array_merge($dayStats, ['date' => $date]);
        }
        
        return $stats;
    }
    
    /**
     * 获取发送失败的日志
     */
    public static function getFailedLogs(int $limit = 100): array
    {
        return static::where('send_status', self::STATUS_FAILED)
                    ->order('sent_at', 'desc')
                    ->limit($limit)
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取指定源的发送记录
     */
    public static function getSourceLogs(string $sourceType, int $sourceId): array
    {
        return static::where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->order('sent_at', 'desc')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 清理过期日志
     */
    public static function cleanExpiredLogs(int $keepDays = 30): int
    {
        $expireTime = date('Y-m-d H:i:s', time() - ($keepDays * 86400));
        
        return static::where('sent_at', '<', $expireTime)->delete();
    }
    
    /**
     * 批量更新发送状态
     */
    public static function batchUpdateStatus(array $ids, int $status, ?string $errorMessage = null): int
    {
        $updateData = ['send_status' => $status];
        
        if ($errorMessage !== null) {
            $updateData['error_message'] = $errorMessage;
        }
        
        return static::whereIn('id', $ids)->update($updateData);
    }
    
    /**
     * 获取群组发送统计
     */
    public static function getGroupStatistics(array $groupIds = []): array
    {
        $query = static::where('target_type', self::TARGET_GROUP);
        
        if (!empty($groupIds)) {
            $query->whereIn('target_id', $groupIds);
        }
        
        return $query->field('target_id, COUNT(*) as total, SUM(CASE WHEN send_status = 1 THEN 1 ELSE 0 END) as success')
                    ->group('target_id')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 关联群组信息
     */
    public function group()
    {
        return $this->belongsTo(TgCrowdList::class, 'target_id', 'crowd_id')
                    ->where('target_type', self::TARGET_GROUP);
    }
    
    /**
     * 搜索日志
     */
    public static function searchLogs(array $params, int $page = 1, int $limit = 20): array
    {
        $query = static::query();
        
        // 搜索条件
        if (!empty($params['message_type'])) {
            $query->where('message_type', $params['message_type']);
        }
        
        if (!empty($params['target_type'])) {
            $query->where('target_type', $params['target_type']);
        }
        
        if (!empty($params['send_status'])) {
            $query->where('send_status', $params['send_status']);
        }
        
        if (!empty($params['source_type'])) {
            $query->where('source_type', $params['source_type']);
        }
        
        if (!empty($params['target_id'])) {
            $query->where('target_id', 'like', '%' . $params['target_id'] . '%');
        }
        
        if (!empty($params['content'])) {
            $query->where('content', 'like', '%' . $params['content'] . '%');
        }
        
        if (!empty($params['date_start'])) {
            $query->where('sent_at', '>=', $params['date_start'] . ' 00:00:00');
        }
        
        if (!empty($params['date_end'])) {
            $query->where('sent_at', '<=', $params['date_end'] . ' 23:59:59');
        }
        
        // 分页查询
        $total = $query->count();
        $list = $query->order('sent_at', 'desc')
                     ->page($page, $limit)
                     ->select()
                     ->toArray();
        
        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ];
    }
}