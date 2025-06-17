<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * Telegram群组模型
 */
class TgCrowdList extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_crowd_list';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'member_count' => 'integer',
        'is_active' => 'integer',
        'broadcast_enabled' => 'integer',
        'del' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'crowd_id', 'created_at'];
    
    /**
     * 机器人状态常量
     */
    public const BOT_STATUS_MEMBER = 'member';           // 普通成员
    public const BOT_STATUS_ADMINISTRATOR = 'administrator'; // 管理员
    public const BOT_STATUS_LEFT = 'left';               // 已离开
    public const BOT_STATUS_KICKED = 'kicked';           // 被踢出
    
    /**
     * 群组状态常量
     */
    public const STATUS_INACTIVE = 0;    // 不活跃
    public const STATUS_ACTIVE = 1;      // 活跃
    
    /**
     * 广播状态常量
     */
    public const BROADCAST_DISABLED = 0; // 禁用广播
    public const BROADCAST_ENABLED = 1;  // 启用广播
    
    /**
     * 删除状态常量
     */
    public const NOT_DELETED = 0;        // 未删除
    public const DELETED = 1;            // 已删除
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|maxLength:100',
            'crowd_id' => 'required|unique:tg_crowd_list',
            'first_name' => 'required|maxLength:100',
            'is_active' => 'in:0,1',
            'broadcast_enabled' => 'in:0,1',
            'bot_status' => 'in:member,administrator,left,kicked',
        ];
    }
    
    /**
     * 群名修改器
     */
    public function setTitleAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 机器人用户名修改器
     */
    public function setBotnameAttr($value)
    {
        return $value ? ltrim($value, '@') : '';
    }
    
    /**
     * 用户名修改器
     */
    public function setUsernameAttr($value)
    {
        return $value ? ltrim($value, '@') : '';
    }
    
    /**
     * 创建时间修改器
     */
    public function setCreatedAtAttr($value)
    {
        if (is_string($value) && !empty($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 更新时间修改器
     */
    public function setUpdatedAtAttr($value)
    {
        if (is_string($value) && !empty($value)) {
            return $value;
        }
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 活跃状态获取器
     */
    public function getIsActiveTextAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 ? '活跃' : '不活跃';
    }
    
    /**
     * 活跃状态颜色获取器
     */
    public function getIsActiveColorAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 ? 'success' : 'secondary';
    }
    
    /**
     * 广播状态获取器
     */
    public function getBroadcastEnabledTextAttr($value, $data)
    {
        return ($data['broadcast_enabled'] ?? 0) === 1 ? '启用' : '禁用';
    }
    
    /**
     * 广播状态颜色获取器
     */
    public function getBroadcastEnabledColorAttr($value, $data)
    {
        return ($data['broadcast_enabled'] ?? 0) === 1 ? 'success' : 'danger';
    }
    
    /**
     * 机器人状态获取器
     */
    public function getBotStatusTextAttr($value, $data)
    {
        $statuses = [
            self::BOT_STATUS_MEMBER => '普通成员',
            self::BOT_STATUS_ADMINISTRATOR => '管理员',
            self::BOT_STATUS_LEFT => '已离开',
            self::BOT_STATUS_KICKED => '被踢出',
        ];
        return $statuses[$data['bot_status']] ?? '未知';
    }
    
    /**
     * 机器人状态颜色获取器
     */
    public function getBotStatusColorAttr($value, $data)
    {
        $colors = [
            self::BOT_STATUS_MEMBER => 'info',
            self::BOT_STATUS_ADMINISTRATOR => 'success',
            self::BOT_STATUS_LEFT => 'warning',
            self::BOT_STATUS_KICKED => 'danger',
        ];
        return $colors[$data['bot_status']] ?? 'secondary';
    }
    
    /**
     * 是否可以发送广播
     */
    public function getCanBroadcastAttr($value, $data)
    {
        return ($data['is_active'] ?? 0) === 1 
            && ($data['broadcast_enabled'] ?? 0) === 1 
            && in_array($data['bot_status'] ?? '', [self::BOT_STATUS_MEMBER, self::BOT_STATUS_ADMINISTRATOR])
            && ($data['del'] ?? 0) === 0;
    }
    
    /**
     * 是否为管理员
     */
    public function getIsBotAdminAttr($value, $data)
    {
        return ($data['bot_status'] ?? '') === self::BOT_STATUS_ADMINISTRATOR;
    }
    
    /**
     * 是否可编辑
     */
    public function getCanEditAttr($value, $data)
    {
        return ($data['del'] ?? 0) === 0;
    }
    
    /**
     * 是否可删除
     */
    public function getCanDeleteAttr($value, $data)
    {
        return ($data['del'] ?? 0) === 0;
    }
    
    /**
     * 群组链接获取器
     */
    public function getGroupLinkAttr($value, $data)
    {
        $crowdId = $data['crowd_id'] ?? '';
        if (empty($crowdId)) {
            return '';
        }
        
        // 如果是负数ID，转换为正数
        if (strpos($crowdId, '-') === 0) {
            $crowdId = substr($crowdId, 1);
        }
        
        return "https://t.me/c/{$crowdId}";
    }
    
    /**
     * 成员数量格式化
     */
    public function getFormattedMemberCountAttr($value, $data)
    {
        $count = $data['member_count'] ?? 0;
        
        if ($count >= 1000000) {
            return round($count / 1000000, 1) . 'M';
        } elseif ($count >= 1000) {
            return round($count / 1000, 1) . 'K';
        } else {
            return (string)$count;
        }
    }
    
    /**
     * 创建时间格式化
     */
    public function getCreatedAtTextAttr($value, $data)
    {
        $createdAt = $data['created_at'] ?? '';
        return !empty($createdAt) ? $createdAt : '';
    }
    
    /**
     * 更新时间格式化
     */
    public function getUpdatedAtTextAttr($value, $data)
    {
        $updatedAt = $data['updated_at'] ?? '';
        return !empty($updatedAt) ? $updatedAt : '';
    }
    
    /**
     * 创建时间友好格式
     */
    public function getCreatedAtFriendlyAttr($value, $data)
    {
        $createdAt = $data['created_at'] ?? '';
        if (empty($createdAt)) {
            return '';
        }
        
        $timestamp = strtotime($createdAt);
        $now = time();
        $diff = $now - $timestamp;
        
        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . '分钟前';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . '小时前';
        } elseif ($diff < 604800) {
            return floor($diff / 86400) . '天前';
        } else {
            return date('Y-m-d', $timestamp);
        }
    }
    
    /**
     * 最后活跃时间
     */
    public function getLastActiveTimeAttr($value, $data)
    {
        $updatedAt = $data['updated_at'] ?? '';
        if (empty($updatedAt)) {
            return '';
        }
        
        $timestamp = strtotime($updatedAt);
        $now = time();
        $diff = $now - $timestamp;
        
        if ($diff < 3600) {
            return '1小时内';
        } elseif ($diff < 86400) {
            return '24小时内';
        } elseif ($diff < 604800) {
            return '1周内';
        } elseif ($diff < 2592000) {
            return '1月内';
        } else {
            return '1月前';
        }
    }
    
    /**
     * 群组状态综合描述
     */
    public function getStatusDescAttr($value, $data)
    {
        $status = [];
        
        if (($data['del'] ?? 0) === 1) {
            $status[] = '已删除';
        } else {
            $status[] = $this->is_active_text;
            $status[] = '广播' . $this->broadcast_enabled_text;
            $status[] = '机器人' . $this->bot_status_text;
        }
        
        return implode(' | ', $status);
    }
    
    /**
     * 创建群组记录
     */
    public static function createGroup(array $data): TgCrowdList
    {
        $group = new static();
        
        // 设置默认值
        $data = array_merge([
            'is_active' => self::STATUS_ACTIVE,
            'broadcast_enabled' => self::BROADCAST_ENABLED,
            'bot_status' => self::BOT_STATUS_MEMBER,
            'member_count' => 0,
            'del' => self::NOT_DELETED,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $data);
        
        $group->save($data);
        
        // 记录创建日志
        trace([
            'action' => 'group_created',
            'group_id' => $group->id,
            'crowd_id' => $group->crowd_id,
            'title' => $group->title,
            'bot_name' => $group->first_name,
            'timestamp' => date('Y-m-d H:i:s'),
        ], 'telegram_group');
        
        return $group;
    }
    
    /**
     * 更新群组信息
     */
    public function updateGroupInfo(array $data): bool
    {
        // 记录更新前的信息
        $oldData = $this->toArray();
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['title', 'member_count', 'bot_status', 'is_active', 'broadcast_enabled', 'botname', 'username'])) {
                $this->$key = $value;
            }
        }
        
        $result = $this->save();
        
        if ($result) {
            // 记录更新日志
            trace([
                'action' => 'group_updated',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'old_data' => $oldData,
                'new_data' => $data,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
            
            // 清除缓存
            $this->clearGroupCache();
        }
        
        return $result;
    }
    
    /**
     * 启用群组
     */
    public function enable(): bool
    {
        if ($this->is_active === self::STATUS_ACTIVE) {
            return true;
        }
        
        $this->is_active = self::STATUS_ACTIVE;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_enabled',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 禁用群组
     */
    public function disable(): bool
    {
        if ($this->is_active === self::STATUS_INACTIVE) {
            return true;
        }
        
        $this->is_active = self::STATUS_INACTIVE;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_disabled',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 启用广播
     */
    public function enableBroadcast(): bool
    {
        if ($this->broadcast_enabled === self::BROADCAST_ENABLED) {
            return true;
        }
        
        $this->broadcast_enabled = self::BROADCAST_ENABLED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_broadcast_enabled',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 禁用广播
     */
    public function disableBroadcast(): bool
    {
        if ($this->broadcast_enabled === self::BROADCAST_DISABLED) {
            return true;
        }
        
        $this->broadcast_enabled = self::BROADCAST_DISABLED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_broadcast_disabled',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 更新机器人状态
     */
    public function updateBotStatus(string $status): bool
    {
        if (!in_array($status, [
            self::BOT_STATUS_MEMBER,
            self::BOT_STATUS_ADMINISTRATOR,
            self::BOT_STATUS_LEFT,
            self::BOT_STATUS_KICKED
        ])) {
            return false;
        }
        
        $oldStatus = $this->bot_status;
        $this->bot_status = $status;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_bot_status_updated',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'old_status' => $oldStatus,
                'new_status' => $status,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 更新成员数量
     */
    public function updateMemberCount(int $memberCount): bool
    {
        $oldCount = $this->member_count;
        $this->member_count = $memberCount;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result && abs($oldCount - $memberCount) > 10) {
            // 只有变化较大时才记录日志
            trace([
                'action' => 'group_member_count_updated',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'old_count' => $oldCount,
                'new_count' => $memberCount,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 软删除群组
     */
    public function softDelete(): bool
    {
        $this->del = self::DELETED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_deleted',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'title' => $this->title,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 恢复群组
     */
    public function restore(): bool
    {
        $this->del = self::NOT_DELETED;
        $this->updated_at = date('Y-m-d H:i:s');
        
        $result = $this->save();
        
        if ($result) {
            $this->clearGroupCache();
            
            trace([
                'action' => 'group_restored',
                'group_id' => $this->id,
                'crowd_id' => $this->crowd_id,
                'title' => $this->title,
                'timestamp' => date('Y-m-d H:i:s'),
            ], 'telegram_group');
        }
        
        return $result;
    }
    
    /**
     * 根据群组ID查找
     */
    public static function findByCrowdId(string $crowdId): ?TgCrowdList
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('telegram', 'group', $crowdId);
        $cachedGroup = CacheHelper::get($cacheKey);
        
        if ($cachedGroup !== null) {
            $group = new static();
            $group->data($cachedGroup);
            return $group;
        }
        
        // 从数据库获取
        $group = static::where('crowd_id', $crowdId)
                      ->where('del', self::NOT_DELETED)
                      ->find();
        
        if ($group) {
            // 更新缓存
            CacheHelper::set($cacheKey, $group->toArray(), 3600);
        }
        
        return $group;
    }
    
    /**
     * 获取活跃群组列表
     */
    public static function getActiveGroups(): array
    {
        return static::where('is_active', self::STATUS_ACTIVE)
                    ->where('del', self::NOT_DELETED)
                    ->order('member_count DESC')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取可广播群组列表
     */
    public static function getBroadcastGroups(): array
    {
        return static::where('is_active', self::STATUS_ACTIVE)
                    ->where('broadcast_enabled', self::BROADCAST_ENABLED)
                    ->where('del', self::NOT_DELETED)
                    ->whereIn('bot_status', [self::BOT_STATUS_MEMBER, self::BOT_STATUS_ADMINISTRATOR])
                    ->order('member_count DESC')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取群组统计
     */
    public static function getGroupStats(): array
    {
        $query = static::where('del', self::NOT_DELETED);
        
        return [
            'total_groups' => $query->count(),
            'active_groups' => $query->where('is_active', self::STATUS_ACTIVE)->count(),
            'broadcast_enabled' => $query->where('broadcast_enabled', self::BROADCAST_ENABLED)->count(),
            'admin_groups' => $query->where('bot_status', self::BOT_STATUS_ADMINISTRATOR)->count(),
            'member_groups' => $query->where('bot_status', self::BOT_STATUS_MEMBER)->count(),
            'left_groups' => $query->where('bot_status', self::BOT_STATUS_LEFT)->count(),
            'kicked_groups' => $query->where('bot_status', self::BOT_STATUS_KICKED)->count(),
            'total_members' => $query->sum('member_count'),
            'avg_members' => $query->avg('member_count'),
            'max_members' => $query->max('member_count'),
            'min_members' => $query->min('member_count'),
        ];
    }
    
    /**
     * 获取今日新增群组
     */
    public static function getTodayNewGroups(): array
    {
        $today = date('Y-m-d');
        $startTime = $today . ' 00:00:00';
        $endTime = $today . ' 23:59:59';
        
        return static::where('created_at', '>=', $startTime)
                    ->where('created_at', '<=', $endTime)
                    ->where('del', self::NOT_DELETED)
                    ->order('created_at DESC')
                    ->select()
                    ->toArray();
    }
    
    /**
     * 批量更新机器人状态
     */
    public static function batchUpdateBotStatus(array $crowdIds, string $status): int
    {
        if (empty($crowdIds) || !in_array($status, [
            self::BOT_STATUS_MEMBER,
            self::BOT_STATUS_ADMINISTRATOR,
            self::BOT_STATUS_LEFT,
            self::BOT_STATUS_KICKED
        ])) {
            return 0;
        }
        
        $count = static::whereIn('crowd_id', $crowdIds)
                      ->where('del', self::NOT_DELETED)
                      ->update([
                          'bot_status' => $status,
                          'updated_at' => date('Y-m-d H:i:s'),
                      ]);
        
        if ($count > 0) {
            // 清除相关缓存
            foreach ($crowdIds as $crowdId) {
                $cacheKey = CacheHelper::key('telegram', 'group', $crowdId);
                CacheHelper::delete($cacheKey);
            }
        }
        
        return $count;
    }
    
    /**
     * 批量启用/禁用广播
     */
    public static function batchUpdateBroadcast(array $groupIds, int $broadcastEnabled): int
    {
        if (empty($groupIds) || !in_array($broadcastEnabled, [self::BROADCAST_DISABLED, self::BROADCAST_ENABLED])) {
            return 0;
        }
        
        return static::whereIn('id', $groupIds)
                    ->where('del', self::NOT_DELETED)
                    ->update([
                        'broadcast_enabled' => $broadcastEnabled,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
    }
    
    /**
     * 批量软删除
     */
    public static function batchSoftDelete(array $groupIds): int
    {
        if (empty($groupIds)) {
            return 0;
        }
        
        return static::whereIn('id', $groupIds)
                    ->where('del', self::NOT_DELETED)
                    ->update([
                        'del' => self::DELETED,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
    }
    
    /**
     * 同步群组信息
     */
    public function syncGroupInfo(array $telegramGroupInfo): bool
    {
        $updateData = [];
        
        // 更新群组标题
        if (isset($telegramGroupInfo['title']) && $telegramGroupInfo['title'] !== $this->title) {
            $updateData['title'] = $telegramGroupInfo['title'];
        }
        
        // 更新成员数量
        if (isset($telegramGroupInfo['member_count']) && $telegramGroupInfo['member_count'] !== $this->member_count) {
            $updateData['member_count'] = $telegramGroupInfo['member_count'];
        }
        
        // 更新机器人状态
        if (isset($telegramGroupInfo['bot_status']) && $telegramGroupInfo['bot_status'] !== $this->bot_status) {
            $updateData['bot_status'] = $telegramGroupInfo['bot_status'];
        }
        
        if (!empty($updateData)) {
            return $this->updateGroupInfo($updateData);
        }
        
        return true;
    }
    
    /**
     * 清除群组缓存
     */
    public function clearGroupCache(): void
    {
        $cacheKey = CacheHelper::key('telegram', 'group', $this->crowd_id);
        CacheHelper::delete($cacheKey);
        
        // 清除群组列表缓存
        $listKey = CacheHelper::key('telegram', 'groups', 'list');
        CacheHelper::delete($listKey);
        
        // 清除广播群组列表缓存
        $broadcastKey = CacheHelper::key('telegram', 'groups', 'broadcast');
        CacheHelper::delete($broadcastKey);
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_INACTIVE => '不活跃',
            self::STATUS_ACTIVE => '活跃',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '群组记录ID',
            'title' => '群名',
            'crowd_id' => '群组ID',
            'first_name' => '机器人名称',
            'botname' => '机器人用户名',
            'user_id' => '拉机器人进群的用户ID',
            'username' => '拉机器人进群的用户名称',
            'member_count' => '群成员数量',
            'is_active' => '是否活跃',
            'broadcast_enabled' => '是否启用广播',
            'bot_status' => '机器人状态',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
            'del' => '是否删除',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return 'Telegram群组表';
    }
}