<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

/**
 * 广告模型
 * 适用于 ThinkPHP8 + PHP8.2
 * 对应数据表：ntp_tg_advertisements
 */
class Advertisement extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_advertisements';
    
    /**
     * 数据表前缀
     */
    protected $connection = 'mysql';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'target_type' => 'integer',
        'send_type' => 'integer',
        'status' => 'integer',
        'sent_count' => 'integer',
        'total_count' => 'integer',
        'created_by' => 'integer'
    ];
    
    /**
     * JSON字段
     */
    protected $json = ['target_groups', 'target_users'];
    
    /**
     * 字段自动完成
     */
    protected $auto = [];
    protected $insert = ['created_at', 'updated_at'];
    protected $update = ['updated_at'];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'created_at'];
    
    /**
     * 发送类型常量
     */
    public const SEND_TYPE_IMMEDIATE = 1;  // 立即发送
    public const SEND_TYPE_SCHEDULED = 2;  // 定时发送
    
    /**
     * 状态常量
     */
    public const STATUS_PENDING = 0;     // 待发送
    public const STATUS_SENDING = 1;     // 发送中
    public const STATUS_SENT = 2;        // 已发送
    public const STATUS_CANCELLED = 3;   // 已取消
    public const STATUS_FAILED = 4;      // 发送失败
    
    /**
     * 目标类型常量
     */
    public const TARGET_ALL_USERS = 1;      // 所有用户
    public const TARGET_GROUPS = 2;         // 指定群组
    public const TARGET_USERS = 3;          // 指定用户
    
    /**
     * 设置创建时间
     */
    protected function setCreatedAtAttr($value): string
    {
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 设置更新时间
     */
    protected function setUpdatedAtAttr($value): string
    {
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 获取发送类型文本
     */
    public function getSendTypeTextAttr($value, $data): string
    {
        $typeMap = [
            self::SEND_TYPE_IMMEDIATE => '立即发送',
            self::SEND_TYPE_SCHEDULED => '定时发送'
        ];
        
        return $typeMap[$data['send_type']] ?? '未知类型';
    }
    
    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data): string
    {
        $statusMap = [
            self::STATUS_PENDING => '待发送',
            self::STATUS_SENDING => '发送中',
            self::STATUS_SENT => '已发送',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_FAILED => '发送失败'
        ];
        
        return $statusMap[$data['status']] ?? '未知状态';
    }
    
    /**
     * 获取目标类型文本
     */
    public function getTargetTypeTextAttr($value, $data): string
    {
        $targetMap = [
            self::TARGET_ALL_USERS => '所有用户',
            self::TARGET_GROUPS => '指定群组',
            self::TARGET_USERS => '指定用户'
        ];
        
        return $targetMap[$data['target_type']] ?? '未知目标';
    }
    
    /**
     * 获取状态样式类
     */
    public function getStatusClassAttr($value, $data): string
    {
        $classMap = [
            self::STATUS_PENDING => 'warning',
            self::STATUS_SENDING => 'info',
            self::STATUS_SENT => 'success',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_FAILED => 'danger'
        ];
        
        return $classMap[$data['status']] ?? 'secondary';
    }
    
    /**
     * 获取发送进度
     */
    public function getProgressAttr($value, $data): float
    {
        $total = $data['total_count'] ?? 0;
        $sent = $data['sent_count'] ?? 0;
        
        if ($total <= 0) {
            return 0.0;
        }
        
        return round(($sent / $total) * 100, 2);
    }
    
    /**
     * 获取内容摘要
     */
    public function getContentSummaryAttr($value, $data): string
    {
        $content = $data['content'] ?? '';
        
        if (mb_strlen($content) <= 100) {
            return $content;
        }
        
        return mb_substr($content, 0, 100) . '...';
    }
    
    /**
     * 获取格式化的发送时间
     */
    public function getFormattedSendTimeAttr($value, $data): string
    {
        if (empty($data['send_time'])) {
            return '';
        }
        
        return date('Y-m-d H:i:s', strtotime($data['send_time']));
    }
    
    /**
     * 检查是否可以发送
     */
    public function canSend(): bool
    {
        // 只有待发送状态的广告可以发送
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        // 定时发送需要检查时间
        if ($this->send_type === self::SEND_TYPE_SCHEDULED) {
            return !empty($this->send_time) && strtotime($this->send_time) <= time();
        }
        
        // 立即发送直接返回true
        return true;
    }
    
    /**
     * 检查是否可以取消
     */
    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SENDING]);
    }
    
    /**
     * 检查是否可以编辑
     */
    public function canEdit(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    
    /**
     * 创建广告
     */
    public static function createAdvertisement(array $data): self
    {
        $ad = new static();
        
        // 设置默认值
        $data = array_merge([
            'send_type' => self::SEND_TYPE_IMMEDIATE,
            'target_type' => self::TARGET_ALL_USERS,
            'status' => self::STATUS_PENDING,
            'sent_count' => 0,
            'total_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], $data);
        
        // 验证必需字段
        $required = ['title', 'content'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("字段 {$field} 不能为空");
            }
        }
        
        // 如果是定时发送，验证发送时间
        if ($data['send_type'] === self::SEND_TYPE_SCHEDULED) {
            if (empty($data['send_time'])) {
                throw new \InvalidArgumentException("定时发送必须设置发送时间");
            }
            
            if (strtotime($data['send_time']) <= time()) {
                throw new \InvalidArgumentException("发送时间必须晚于当前时间");
            }
        }
        
        $ad->save($data);
        return $ad;
    }
    
    /**
     * 更新发送状态
     */
    public function updateSendStatus(int $status, int $sentCount = 0, int $totalCount = 0): bool
    {
        $updateData = [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if ($sentCount > 0) {
            $updateData['sent_count'] = $sentCount;
        }
        
        if ($totalCount > 0) {
            $updateData['total_count'] = $totalCount;
        }
        
        return $this->save($updateData);
    }
    
    /**
     * 取消广告
     */
    public function cancel(): bool
    {
        if (!$this->canCancel()) {
            throw new \Exception('当前状态不允许取消');
        }
        
        return $this->updateSendStatus(self::STATUS_CANCELLED);
    }
    
    /**
     * 开始发送
     */
    public function startSending(): bool
    {
        if (!$this->canSend()) {
            throw new \Exception('当前状态不允许发送');
        }
        
        return $this->updateSendStatus(self::STATUS_SENDING);
    }
    
    /**
     * 完成发送
     */
    public function completeSending(int $sentCount, int $totalCount): bool
    {
        return $this->updateSendStatus(self::STATUS_SENT, $sentCount, $totalCount);
    }
    
    /**
     * 发送失败
     */
    public function failSending(): bool
    {
        return $this->updateSendStatus(self::STATUS_FAILED);
    }
    
    /**
     * 获取待发送的广告
     */
    public static function getPendingAdvertisements(): array
    {
        $currentTime = date('Y-m-d H:i:s');
        
        return static::where('status', self::STATUS_PENDING)
                    ->where(function($query) use ($currentTime) {
                        // 立即发送的广告
                        $query->where('send_type', self::SEND_TYPE_IMMEDIATE)
                              // 或者定时发送且时间已到的广告
                              ->whereOr(function($subQuery) use ($currentTime) {
                                  $subQuery->where('send_type', self::SEND_TYPE_SCHEDULED)
                                           ->where('send_time', '<=', $currentTime);
                              });
                    })
                    ->order('created_at', 'asc')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取定时发送列表
     */
    public static function getScheduledAdvertisements(string $date = null): array
    {
        $query = static::where('send_type', self::SEND_TYPE_SCHEDULED)
                      ->where('status', self::STATUS_PENDING);
        
        if ($date) {
            $query->whereDay('send_time', $date);
        }
        
        return $query->order('send_time', 'asc')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取广告统计
     */
    public static function getStatistics(array $filters = []): array
    {
        $query = static::query();
        
        // 应用过滤条件
        if (!empty($filters['date_start'])) {
            $query->where('created_at', '>=', $filters['date_start'] . ' 00:00:00');
        }
        
        if (!empty($filters['date_end'])) {
            $query->where('created_at', '<=', $filters['date_end'] . ' 23:59:59');
        }
        
        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }
        
        // 统计数据
        $total = $query->count();
        $pending = $query->where('status', self::STATUS_PENDING)->count();
        $sent = $query->where('status', self::STATUS_SENT)->count();
        $failed = $query->where('status', self::STATUS_FAILED)->count();
        $cancelled = $query->where('status', self::STATUS_CANCELLED)->count();
        
        return [
            'total' => $total,
            'pending' => $pending,
            'sent' => $sent,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'success_rate' => $total > 0 ? round(($sent / $total) * 100, 2) : 0
        ];
    }
    
    /**
     * 搜索广告
     */
    public static function searchAdvertisements(array $params, int $page = 1, int $limit = 20): array
    {
        $query = static::query();
        
        // 搜索条件
        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        
        if (!empty($params['content'])) {
            $query->where('content', 'like', '%' . $params['content'] . '%');
        }
        
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }
        
        if (isset($params['send_type']) && $params['send_type'] !== '') {
            $query->where('send_type', $params['send_type']);
        }
        
        if (isset($params['target_type']) && $params['target_type'] !== '') {
            $query->where('target_type', $params['target_type']);
        }
        
        if (!empty($params['created_by'])) {
            $query->where('created_by', $params['created_by']);
        }
        
        if (!empty($params['date_start'])) {
            $query->where('created_at', '>=', $params['date_start'] . ' 00:00:00');
        }
        
        if (!empty($params['date_end'])) {
            $query->where('created_at', '<=', $params['date_end'] . ' 23:59:59');
        }
        
        // 分页查询
        $total = $query->count();
        $list = $query->order('created_at', 'desc')
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
    
    /**
     * 清理过期的待发送广告
     */
    public static function cleanExpiredPending(int $expireDays = 7): int
    {
        $expireTime = date('Y-m-d H:i:s', time() - ($expireDays * 86400));
        
        return static::where('status', self::STATUS_PENDING)
                    ->where('send_type', self::SEND_TYPE_SCHEDULED)
                    ->where('send_time', '<', $expireTime)
                    ->update(['status' => self::STATUS_CANCELLED]);
    }
    
    /**
     * 批量取消广告
     */
    public static function batchCancel(array $ids): int
    {
        return static::whereIn('id', $ids)
                    ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENDING])
                    ->update([
                        'status' => self::STATUS_CANCELLED,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
    }
    
    /**
     * 关联创建者
     */
    public function creator()
    {
        return $this->belongsTo(\app\model\User::class, 'created_by', 'id');
    }
    
    /**
     * 关联发送日志
     */
    public function sendLogs()
    {
        return $this->hasMany(TgMessageLog::class, 'source_id', 'id')
                    ->where('source_type', 'advertisement');
    }
}