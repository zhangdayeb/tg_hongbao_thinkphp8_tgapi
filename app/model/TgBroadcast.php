<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * Telegram广播任务模型
 */
class TgBroadcast extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_broadcasts';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
        'total_groups' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
        'retry_count' => 'integer',
        'created_by' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'created_at'];
    
    /**
     * JSON字段
     */
    protected $json = ['target_groups', 'template_data', 'buttons', 'result_data'];
    
    /**
     * 广播状态常量
     */
    public const STATUS_PENDING = 0;      // 待发送
    public const STATUS_SENDING = 1;      // 发送中
    public const STATUS_COMPLETED = 2;    // 已完成
    public const STATUS_FAILED = 3;       // 发送失败
    public const STATUS_CANCELLED = 4;    // 已取消
    
    /**
     * 广播类型常量
     */
    public const TYPE_GENERAL = 'general';        // 通用广播
    public const TYPE_PROMOTION = 'promotion';    // 推广广播
    public const TYPE_REDPACKET = 'redpacket';    // 红包广播
    public const TYPE_GAME = 'game';              // 游戏广播
    public const TYPE_NOTIFICATION = 'notification'; // 通知广播
    public const TYPE_WELCOME = 'welcome';        // 欢迎广播
    public const TYPE_PAYMENT = 'payment';        // 支付广播
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'type' => 'required|in:general,promotion,redpacket,game,notification,welcome,payment',
            'title' => 'required|maxLength:200',
            'content' => 'required',
            'status' => 'in:0,1,2,3,4',
        ];
    }
    
    /**
     * 标题修改器
     */
    public function setTitleAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 内容修改器
     */
    public function setContentAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 目标群组修改器
     */
    public function setTargetGroupsAttr($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return is_array($value) ? $value : [];
    }
    
    /**
     * 模板数据修改器
     */
    public function setTemplateDataAttr($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return is_array($value) ? $value : [];
    }
    
    /**
     * 按钮修改器
     */
    public function setButtonsAttr($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return is_array($value) ? $value : [];
    }
    
    /**
     * 结果数据修改器
     */
    public function setResultDataAttr($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return is_array($value) ? $value : [];
    }
    
    /**
     * 计划时间修改器
     */
    public function setScheduledAtAttr($value)
    {
        if (is_string($value) && !empty($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 开始时间修改器
     */
    public function setStartedAtAttr($value)
    {
        if (is_string($value) && !empty($value)) {
            return $value;
        }
        return null;
    }
    
    /**
     * 完成时间修改器
     */
    public function setCompletedAtAttr($value)
    {
        if (is_string($value) && !empty($value)) {
            return $value;
        }
        return null;
    }
    
    /**
     * 创建时间修改器
     */
    public function setCreatedAtAttr($value)
    {
        if (is_string($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 更新时间修改器
     */
    public function setUpdatedAtAttr($value)
    {
        if (is_string($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 状态文本获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_PENDING => '待发送',
            self::STATUS_SENDING => '发送中',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_FAILED => '发送失败',
            self::STATUS_CANCELLED => '已取消',
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 类型文本获取器
     */
    public function getTypeTextAttr($value, $data)
    {
        $types = [
            self::TYPE_GENERAL => '通用广播',
            self::TYPE_PROMOTION => '推广广播',
            self::TYPE_REDPACKET => '红包广播',
            self::TYPE_GAME => '游戏广播',
            self::TYPE_NOTIFICATION => '通知广播',
            self::TYPE_WELCOME => '欢迎广播',
            self::TYPE_PAYMENT => '支付广播',
        ];
        return $types[$data['type']] ?? '未知类型';
    }
    
    /**
     * 成功率获取器
     */
    public function getSuccessRateAttr($value, $data)
    {
        $total = $data['total_groups'] ?? 0;
        $success = $data['success_count'] ?? 0;
        
        if ($total <= 0) {
            return 0;
        }
        
        return round(($success / $total) * 100, 2);
    }
    
    /**
     * 格式化计划时间获取器
     */
    public function getScheduledAtTextAttr($value, $data)
    {
        $scheduledAt = $data['scheduled_at'] ?? '';
        return !empty($scheduledAt) ? $scheduledAt : '';
    }
    
    /**
     * 格式化开始时间获取器
     */
    public function getStartedAtTextAttr($value, $data)
    {
        $startedAt = $data['started_at'] ?? '';
        return !empty($startedAt) ? $startedAt : '';
    }
    
    /**
     * 格式化完成时间获取器
     */
    public function getCompletedAtTextAttr($value, $data)
    {
        $completedAt = $data['completed_at'] ?? '';
        return !empty($completedAt) ? $completedAt : '';
    }
    
    /**
     * 内容预览获取器
     */
    public function getContentPreviewAttr($value, $data)
    {
        $content = $data['content'] ?? '';
        return mb_strlen($content) > 100 ? mb_substr($content, 0, 100) . '...' : $content;
    }
    
    /**
     * 是否可编辑获取器
     */
    public function getCanEditAttr($value, $data)
    {
        $status = $data['status'] ?? self::STATUS_PENDING;
        return in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED]);
    }
    
    /**
     * 是否可删除获取器
     */
    public function getCanDeleteAttr($value, $data)
    {
        $status = $data['status'] ?? self::STATUS_PENDING;
        return $status !== self::STATUS_SENDING;
    }
    
    /**
     * 是否可重试获取器
     */
    public function getCanRetryAttr($value, $data)
    {
        $status = $data['status'] ?? self::STATUS_PENDING;
        $retryCount = $data['retry_count'] ?? 0;
        return $status === self::STATUS_FAILED && $retryCount < 3;
    }
    
    /**
     * 是否可取消获取器
     */
    public function getCanCancelAttr($value, $data)
    {
        $status = $data['status'] ?? self::STATUS_PENDING;
        return in_array($status, [self::STATUS_PENDING, self::STATUS_SENDING]);
    }
    
    /**
     * 执行时长获取器
     */
    public function getDurationAttr($value, $data)
    {
        $startedAt = $data['started_at'] ?? '';
        $completedAt = $data['completed_at'] ?? '';
        
        if (empty($startedAt)) {
            return 0;
        }
        
        $endTime = !empty($completedAt) ? $completedAt : date('Y-m-d H:i:s');
        
        return strtotime($endTime) - strtotime($startedAt);
    }
    
    /**
     * 格式化执行时长获取器
     */
    public function getFormattedDurationAttr($value, $data)
    {
        $duration = $this->duration;
        
        if ($duration <= 0) {
            return '0秒';
        }
        
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;
        
        $result = '';
        if ($hours > 0) {
            $result .= $hours . '小时';
        }
        if ($minutes > 0) {
            $result .= $minutes . '分钟';
        }
        if ($seconds > 0 || $result === '') {
            $result .= $seconds . '秒';
        }
        
        return $result;
    }
    
    /**
     * 创建广播任务
     */
    public static function createBroadcast(array $data): TgBroadcast
    {
        $broadcast = new static();
        
        // 设置默认值
        $data = array_merge([
            'status' => self::STATUS_PENDING,
            'total_groups' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'retry_count' => 0,
            'target_groups' => [],
            'template_data' => [],
            'buttons' => [],
            'result_data' => [],
            'scheduled_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $data);
        
        $broadcast->save($data);
        
        // 记录创建日志
        trace([
            'action' => 'broadcast_created',
            'broadcast_id' => $broadcast->id,
            'type' => $broadcast->type,
            'title' => $broadcast->title,
            'created_by' => $broadcast->created_by,
            'timestamp' => date('Y-m-d H:i:s'),
        ], 'telegram_broadcast');
        
        return $broadcast;
    }
    
    /**
     * 开始广播
     */
    public function startBroadcast(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_SENDING;
        $this->started_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'broadcast_started',
                'broadcast_id' => $this->id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_broadcast');
        }
        
        return $result;
    }
    
    /**
     * 完成广播
     */
    public function completeBroadcast(): bool
    {
        if ($this->status !== self::STATUS_SENDING) {
            return false;
        }
        
        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'broadcast_completed',
                'broadcast_id' => $this->id,
                'success_count' => $this->success_count,
                'failed_count' => $this->failed_count,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_broadcast');
        }
        
        return $result;
    }
    
    /**
     * 标记广播失败
     */
    public function markFailed(string $reason = ''): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
        
        // 记录失败原因
        $resultData = $this->result_data ?: [];
        $resultData['failure_reason'] = $reason;
        $resultData['failed_at'] = date('Y-m-d H:i:s');
        $this->result_data = $resultData;
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'broadcast_failed',
                'broadcast_id' => $this->id,
                'reason' => $reason,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_broadcast');
        }
        
        return $result;
    }
    
    /**
     * 取消广播
     */
    public function cancelBroadcast(): bool
    {
        if (!$this->can_cancel) {
            return false;
        }
        
        $this->status = self::STATUS_CANCELLED;
        $this->completed_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'broadcast_cancelled',
                'broadcast_id' => $this->id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_broadcast');
        }
        
        return $result;
    }
    
    /**
     * 重试广播
     */
    public function retryBroadcast(): bool
    {
        if (!$this->can_retry) {
            return false;
        }
        
        $this->status = self::STATUS_PENDING;
        $this->retry_count = ($this->retry_count ?? 0) + 1;
        $this->started_at = null;
        $this->completed_at = null;
        $this->success_count = 0;
        $this->failed_count = 0;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'broadcast_retried',
                'broadcast_id' => $this->id,
                'retry_count' => $this->retry_count,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_broadcast');
        }
        
        return $result;
    }
    
    /**
     * 更新进度
     */
    public function updateProgress(int $successCount, int $failedCount): bool
    {
        $this->success_count = $successCount;
        $this->failed_count = $failedCount;
        $this->updated_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
    
    /**
     * 获取活跃的广播任务
     */
    public static function getActiveBroadcasts()
    {
        return static::whereIn('status', [self::STATUS_PENDING, self::STATUS_SENDING])
                    ->order('scheduled_at', 'asc')
                    ->select();
    }
    
    /**
     * 获取待执行的广播任务
     */
    public static function getPendingBroadcasts()
    {
        $currentTime = date('Y-m-d H:i:s');
        
        return static::where('status', self::STATUS_PENDING)
                    ->where('scheduled_at', '<=', $currentTime)
                    ->order('scheduled_at', 'asc')
                    ->select();
    }
    
    /**
     * 获取今日广播统计
     */
    public static function getTodayStats()
    {
        $today = date('Y-m-d');
        $startTime = $today . ' 00:00:00';
        $endTime = $today . ' 23:59:59';
        
        return [
            'total' => static::where('scheduled_at', '>=', $startTime)
                            ->where('scheduled_at', '<=', $endTime)
                            ->count(),
            'completed' => static::where('status', self::STATUS_COMPLETED)
                                ->where('scheduled_at', '>=', $startTime)
                                ->where('scheduled_at', '<=', $endTime)
                                ->count(),
            'failed' => static::where('status', self::STATUS_FAILED)
                             ->where('scheduled_at', '>=', $startTime)
                             ->where('scheduled_at', '<=', $endTime)
                             ->count(),
            'pending' => static::where('status', self::STATUS_PENDING)
                              ->where('scheduled_at', '>=', $startTime)
                              ->where('scheduled_at', '<=', $endTime)
                              ->count(),
            'cancelled' => static::where('status', self::STATUS_CANCELLED)
                                ->where('scheduled_at', '>=', $startTime)
                                ->where('scheduled_at', '<=', $endTime)
                                ->count()
        ];
    }
    
    /**
     * 获取广播统计
     */
    public static function getBroadcastStats(int $days = 30): array
    {
        $startTime = date('Y-m-d H:i:s', time() - ($days * 86400));
        
        return [
            'total_broadcasts' => static::where('created_at', '>=', $startTime)->count(),
            'completed_broadcasts' => static::where('status', self::STATUS_COMPLETED)
                                           ->where('created_at', '>=', $startTime)
                                           ->count(),
            'failed_broadcasts' => static::where('status', self::STATUS_FAILED)
                                        ->where('created_at', '>=', $startTime)
                                        ->count(),
            'total_groups_targeted' => static::where('created_at', '>=', $startTime)
                                            ->sum('total_groups'),
            'total_success_sends' => static::where('created_at', '>=', $startTime)
                                          ->sum('success_count'),
            'total_failed_sends' => static::where('created_at', '>=', $startTime)
                                         ->sum('failed_count'),
        ];
    }
    
    /**
     * 批量删除广播任务
     */
    public static function batchDelete(array $broadcastIds): int
    {
        if (empty($broadcastIds)) {
            return 0;
        }
        
        // 只能删除非发送中状态的任务
        return static::whereIn('id', $broadcastIds)
                    ->where('status', '<>', self::STATUS_SENDING)
                    ->delete();
    }
    
    /**
     * 关联创建者
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_PENDING => '待发送',
            self::STATUS_SENDING => '发送中',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_FAILED => '发送失败',
            self::STATUS_CANCELLED => '已取消',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '广播任务ID',
            'type' => '广播类型',
            'title' => '广播标题',
            'content' => '广播内容',
            'target_groups' => '目标群组',
            'template_data' => '模板数据',
            'buttons' => '按钮配置',
            'status' => '状态',
            'total_groups' => '总群组数',
            'success_count' => '成功数量',
            'failed_count' => '失败数量',
            'retry_count' => '重试次数',
            'result_data' => '结果数据',
            'scheduled_at' => '计划时间',
            'started_at' => '开始时间',
            'completed_at' => '完成时间',
            'created_by' => '创建人',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return 'Telegram广播任务表';
    }
}