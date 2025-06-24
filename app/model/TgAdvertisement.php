<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * Telegram广告模型
 */
class TgAdvertisement extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_advertisements';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'target_type' => 'integer',
        'status' => 'integer',
        'sent_count' => 'integer',
        'total_count' => 'integer',
        'created_by' => 'integer',
        'send_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'created_at', 'created_by'];
    
    /**
     * JSON字段
     */
    protected $json = ['target_groups', 'target_users'];
    
    /**
     * 目标类型常量
     */
    public const TARGET_ALL_USERS = 1;        // 所有用户
    public const TARGET_GROUPS = 2;           // 指定群组
    public const TARGET_USERS = 3;            // 指定用户
    public const TARGET_ACTIVE_USERS = 4;     // 活跃用户
    public const TARGET_VIP_USERS = 5;        // VIP用户
    
    /**
     * 广告状态常量
     */
    public const STATUS_DRAFT = 0;            // 草稿
    public const STATUS_PENDING = 1;          // 待发送
    public const STATUS_SENDING = 2;          // 发送中
    public const STATUS_SENT = 3;             // 已发送
    public const STATUS_CANCELLED = 4;        // 已取消
    public const STATUS_FAILED = 5;           // 发送失败
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|maxLength:200',
            'content' => 'required',
            'target_type' => 'required|in:1,2,3,4,5',
            'status' => 'in:0,1,2,3,4,5',
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
     * 目标用户修改器
     */
    public function setTargetUsersAttr($value)
    {
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return is_array($value) ? $value : [];
    }
    
    /**
     * 发送时间修改器
     */
    public function setSendTimeAttr($value)
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
     * 目标类型获取器
     */
    public function getTargetTypeTextAttr($value, $data)
    {
        $types = [
            self::TARGET_ALL_USERS => '所有用户',
            self::TARGET_GROUPS => '指定群组',
            self::TARGET_USERS => '指定用户',
            self::TARGET_ACTIVE_USERS => '活跃用户',
            self::TARGET_VIP_USERS => 'VIP用户',
        ];
        return $types[$data['target_type']] ?? '未知';
    }
    
    /**
     * 状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PENDING => '待发送',
            self::STATUS_SENDING => '发送中',
            self::STATUS_SENT => '已发送',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_FAILED => '发送失败',
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 状态颜色获取器
     */
    public function getStatusColorAttr($value, $data)
    {
        $colors = [
            self::STATUS_DRAFT => 'secondary',
            self::STATUS_PENDING => 'warning',
            self::STATUS_SENDING => 'info',
            self::STATUS_SENT => 'success',
            self::STATUS_CANCELLED => 'secondary',
            self::STATUS_FAILED => 'danger',
        ];
        return $colors[$data['status']] ?? 'secondary';
    }
    
    /**
     * 是否为草稿
     */
    public function getIsDraftAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_DRAFT;
    }
    
    /**
     * 是否可以编辑
     */
    public function getCanEditAttr($value, $data)
    {
        $status = $data['status'] ?? 0;
        return in_array($status, [self::STATUS_DRAFT, self::STATUS_PENDING]);
    }
    
    /**
     * 是否可以取消
     */
    public function getCanCancelAttr($value, $data)
    {
        $status = $data['status'] ?? 0;
        return in_array($status, [self::STATUS_PENDING, self::STATUS_SENDING]);
    }
    
    /**
     * 是否可以发送
     */
    public function getCanSendAttr($value, $data)
    {
        $status = $data['status'] ?? 0;
        return in_array($status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_FAILED]);
    }
    
    /**
     * 发送进度获取器
     */
    public function getProgressAttr($value, $data)
    {
        $total = $data['total_count'] ?? 0;
        $sent = $data['sent_count'] ?? 0;
        
        if ($total <= 0) {
            return 0;
        }
        
        return round(($sent / $total) * 100, 1);
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
     * 发送时间格式化
     */
    public function getSendTimeTextAttr($value, $data)
    {
        $sendTime = $data['send_time'] ?? null;
        if (empty($sendTime)) {
            return '立即发送';
        }
        
        return $sendTime;
    }
    
    /**
     * 是否定时发送
     */
    public function getIsScheduledAttr($value, $data)
    {
        $sendTime = $data['send_time'] ?? null;
        if (empty($sendTime)) {
            return false;
        }
        
        $sendTimestamp = strtotime($sendTime);
        return $sendTimestamp > time();
    }
    
    /**
     * 目标群组数量
     */
    public function getTargetGroupCountAttr($value, $data)
    {
        $groups = $data['target_groups'] ?? [];
        return is_array($groups) ? count($groups) : 0;
    }
    
    /**
     * 目标用户数量
     */
    public function getTargetUserCountAttr($value, $data)
    {
        $users = $data['target_users'] ?? [];
        return is_array($users) ? count($users) : 0;
    }
    
    /**
     * 关联创建者
     */
    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
    
    /**
     * 关联发送日志
     */
    public function messageLogs()
    {
        return $this->hasMany(TgMessageLog::class, 'source_id')
                    ->where('source_type', 'advertisement');
    }
    
    /**
     * 创建广告
     */
    public static function createAdvertisement(array $data): TgAdvertisement
    {
        $ad = new static();
        
        // 设置默认值
        $data = array_merge([
            'status' => self::STATUS_DRAFT,
            'sent_count' => 0,
            'total_count' => 0,
            'target_groups' => [],
            'target_users' => [],
            'image_url' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $data);
        
        $ad->save($data);
        
        // 记录创建日志
        trace([
            'action' => 'advertisement_created',
            'ad_id' => $ad->id,
            'title' => $ad->title,
            'target_type' => $ad->target_type,
            'created_by' => $ad->created_by,
            'timestamp' => date('Y-m-d H:i:s'),
        ], 'telegram_advertisement');
        
        return $ad;
    }
    
    /**
     * 更新广告内容
     */
    public function updateContent(array $data): bool
    {
        if (!$this->can_edit) {
            return false;
        }
        
        $updateFields = ['title', 'content', 'image_url', 'target_type', 'target_groups', 'target_users', 'send_time'];
        
        foreach ($updateFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->$field = $data[$field];
            }
        }
        
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            // 记录更新日志
            trace([
                'action' => 'advertisement_updated',
                'ad_id' => $this->id,
                'updated_fields' => array_keys($data),
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_advertisement');
        }
        
        return $result;
    }
    
    /**
     * 设置为待发送状态
     */
    public function setPending(): bool
    {
        if (!$this->can_send) {
            return false;
        }
        
        // 计算目标数量
        $this->total_count = $this->calculateTargetCount();
        $this->status = self::STATUS_PENDING;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'advertisement_set_pending',
                'ad_id' => $this->id,
                'total_count' => $this->total_count,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_advertisement');
        }
        
        return $result;
    }
    
    /**
     * 开始发送
     */
    public function startSending(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }
        
        $this->status = self::STATUS_SENDING;
        $this->sent_count = 0;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'advertisement_start_sending',
                'ad_id' => $this->id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_advertisement');
        }
        
        return $result;
    }
    
    /**
     * 更新发送进度
     */
    public function updateProgress(int $sentCount): bool
    {
        $this->sent_count = $sentCount;
        
        // 检查是否发送完成
        if ($this->sent_count >= $this->total_count) {
            $this->status = self::STATUS_SENT;
        }
        
        $this->updated_at = date('Y-m-d H:i:s');
        
        return $this->save();
    }
    
    /**
     * 标记发送完成
     */
    public function markSent(): bool
    {
        $this->status = self::STATUS_SENT;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'advertisement_sent_completed',
                'ad_id' => $this->id,
                'sent_count' => $this->sent_count,
                'total_count' => $this->total_count,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_advertisement');
        }
        
        return $result;
    }
    
    /**
     * 标记发送失败
     */
    public function markFailed(string $reason = ''): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'advertisement_send_failed',
                'ad_id' => $this->id,
                'reason' => $reason,
                'sent_count' => $this->sent_count,
                'total_count' => $this->total_count,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_advertisement');
        }
        
        return $result;
    }
    
    /**
     * 取消发送
     */
    public function cancel(): bool
    {
        if (!$this->can_cancel) {
            return false;
        }
        
        $this->status = self::STATUS_CANCELLED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            trace([
                'action' => 'advertisement_cancelled',
                'ad_id' => $this->id,
                'sent_count' => $this->sent_count,
                'total_count' => $this->total_count,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_advertisement');
        }
        
        return $result;
    }
    
    /**
     * 获取目标列表
     */
    public function getTargets(): array
    {
        switch ($this->target_type) {
            case self::TARGET_ALL_USERS:
                return $this->getAllUsers();
                
            case self::TARGET_GROUPS:
                return $this->getTargetGroups();
                
            case self::TARGET_USERS:
                return $this->getTargetUsers();
                
            case self::TARGET_ACTIVE_USERS:
                return $this->getActiveUsers();
                
            case self::TARGET_VIP_USERS:
                return $this->getVipUsers();
                
            default:
                return [];
        }
    }
    
    /**
     * 计算目标数量
     */
    public function calculateTargetCount(): int
    {
        $targets = $this->getTargets();
        return count($targets);
    }
    
    /**
     * 获取所有用户
     */
    private function getAllUsers(): array
    {
        return User::where('status', User::STATUS_NORMAL)
                  ->where('tg_id', '<>', '')
                  ->field('id,tg_id,tg_username')
                  ->select()
                  ->toArray();
    }
    
    /**
     * 获取目标群组
     */
    private function getTargetGroups(): array
    {
        $groupIds = $this->target_groups;
        if (empty($groupIds)) {
            return [];
        }
        
        return TgCrowdList::whereIn('crowd_id', $groupIds)
                         ->where('is_active', 1)
                         ->where('broadcast_enabled', 1)
                         ->where('del', 0)
                         ->field('crowd_id,title')
                         ->select()
                         ->toArray();
    }
    
    /**
     * 获取目标用户
     */
    private function getTargetUsers(): array
    {
        $userIds = $this->target_users;
        if (empty($userIds)) {
            return [];
        }
        
        return User::whereIn('tg_id', $userIds)
                  ->where('status', User::STATUS_NORMAL)
                  ->field('id,tg_id,tg_username')
                  ->select()
                  ->toArray();
    }
    
    /**
     * 获取活跃用户
     */
    private function getActiveUsers(): array
    {
        $activeTime = date('Y-m-d H:i:s', time() - (7 * 86400)); // 7天内活跃
        
        return User::where('status', User::STATUS_NORMAL)
                  ->where('tg_id', '<>', '')
                  ->where('last_activity_at', '>', $activeTime)
                  ->field('id,tg_id,tg_username')
                  ->select()
                  ->toArray();
    }
    
    /**
     * 获取VIP用户
     */
    private function getVipUsers(): array
    {
        return User::where('status', User::STATUS_NORMAL)
                  ->where('tg_id', '<>', '')
                  ->where('type', User::TYPE_AGENT)
                  ->field('id,tg_id,tg_username')
                  ->select()
                  ->toArray();
    }
    
    /**
     * 获取待发送的广告
     */
    public static function getPendingAds(): array
    {
        $currentTime = date('Y-m-d H:i:s');
        
        return static::where('status', self::STATUS_PENDING)
                    ->where(function($query) use ($currentTime) {
                        $query->whereNull('send_time')
                              ->whereOr('send_time', '<=', $currentTime);
                    })
                    ->order('created_at ASC')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取广告统计
     */
    public static function getAdStats(): array
    {
        return [
            'total_ads' => static::count(),
            'draft_ads' => static::where('status', self::STATUS_DRAFT)->count(),
            'pending_ads' => static::where('status', self::STATUS_PENDING)->count(),
            'sending_ads' => static::where('status', self::STATUS_SENDING)->count(),
            'sent_ads' => static::where('status', self::STATUS_SENT)->count(),
            'cancelled_ads' => static::where('status', self::STATUS_CANCELLED)->count(),
            'failed_ads' => static::where('status', self::STATUS_FAILED)->count(),
        ];
    }
    
    /**
     * 获取发送统计
     */
    public static function getSendStats(int $days = 30): array
    {
        $startTime = date('Y-m-d H:i:s', time() - ($days * 86400));
        
        $totalSentQuery = static::where('created_at', '>=', $startTime);
        $sentCount = $totalSentQuery->where('status', self::STATUS_SENT)->count();
        $totalCount = $totalSentQuery->count();
        
        return [
            'total_sent' => static::where('status', self::STATUS_SENT)
                                  ->where('created_at', '>=', $startTime)
                                  ->sum('sent_count'),
            'total_targets' => static::where('status', self::STATUS_SENT)
                                    ->where('created_at', '>=', $startTime)
                                    ->sum('total_count'),
            'success_rate' => $totalCount > 0 ? 
                round(($sentCount / $totalCount) * 100, 2) : 0,
        ];
    }
    
    /**
     * 复制广告
     */
    public function duplicate(): TgAdvertisement
    {
        $data = $this->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        
        $data['title'] = $data['title'] . ' (副本)';
        $data['status'] = self::STATUS_DRAFT;
        $data['sent_count'] = 0;
        $data['total_count'] = 0;
        
        return static::createAdvertisement($data);
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_DRAFT => '草稿',
            self::STATUS_PENDING => '待发送',
            self::STATUS_SENDING => '发送中',
            self::STATUS_SENT => '已发送',
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_FAILED => '发送失败',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '广告ID',
            'title' => '广告标题',
            'content' => '广告内容',
            'image_url' => '图片地址',
            'target_type' => '目标类型',
            'target_groups' => '目标群组ID数组',
            'target_users' => '目标用户ID数组',
            'send_time' => '计划发送时间',
            'status' => '状态',
            'sent_count' => '已发送数量',
            'total_count' => '总发送数量',
            'created_by' => '创建人ID',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return 'Telegram广告表';
    }
}