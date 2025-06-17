<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * Telegram消息记录模型
 */
class TgMessage extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_messages';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'message_id', 'chat_id', 'created_at'];
    
    /**
     * 消息类型常量
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_PHOTO = 'photo';
    public const TYPE_DOCUMENT = 'document';
    public const TYPE_STICKER = 'sticker';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_VOICE = 'voice';
    public const TYPE_LOCATION = 'location';
    public const TYPE_CONTACT = 'contact';
    public const TYPE_ANIMATION = 'animation';
    public const TYPE_VIDEO_NOTE = 'video_note';
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'message_id' => 'required',
            'chat_id' => 'required',
            'message_type' => 'required|in:text,photo,document,sticker,video,audio,voice,location,contact,animation,video_note',
        ];
    }
    
    /**
     * 消息内容修改器（过滤敏感内容）
     */
    public function setMessageContentAttr($value)
    {
        return SecurityHelper::filterInput($value);
    }
    
    /**
     * 消息类型获取器
     */
    public function getTypeTextAttr($value, $data)
    {
        $types = [
            self::TYPE_TEXT => '文本',
            self::TYPE_PHOTO => '图片',
            self::TYPE_DOCUMENT => '文档',
            self::TYPE_STICKER => '贴纸',
            self::TYPE_VIDEO => '视频',
            self::TYPE_AUDIO => '音频',
            self::TYPE_VOICE => '语音',
            self::TYPE_LOCATION => '位置',
            self::TYPE_CONTACT => '联系人',
            self::TYPE_ANIMATION => '动画',
            self::TYPE_VIDEO_NOTE => '视频消息',
        ];
        return $types[$data['message_type']] ?? '未知';
    }
    
    /**
     * 消息内容预览获取器
     */
    public function getContentPreviewAttr($value, $data)
    {
        $content = $data['message_content'] ?? '';
        $type = $data['message_type'] ?? '';
        
        switch ($type) {
            case self::TYPE_TEXT:
                return mb_strlen($content) > 50 ? mb_substr($content, 0, 50) . '...' : $content;
            case self::TYPE_PHOTO:
                return '[图片]';
            case self::TYPE_DOCUMENT:
                return '[文档]';
            case self::TYPE_STICKER:
                return '[贴纸]';
            case self::TYPE_VIDEO:
                return '[视频]';
            case self::TYPE_AUDIO:
                return '[音频]';
            case self::TYPE_VOICE:
                return '[语音]';
            case self::TYPE_LOCATION:
                return '[位置]';
            case self::TYPE_CONTACT:
                return '[联系人]';
            default:
                return '[' . $this->type_text . ']';
        }
    }
    
    /**
     * 是否为媒体消息
     */
    public function getIsMediaAttr($value, $data)
    {
        $mediaTypes = [
            self::TYPE_PHOTO,
            self::TYPE_DOCUMENT,
            self::TYPE_VIDEO,
            self::TYPE_AUDIO,
            self::TYPE_VOICE,
            self::TYPE_ANIMATION,
            self::TYPE_VIDEO_NOTE,
        ];
        
        return in_array($data['message_type'] ?? '', $mediaTypes);
    }
    
    /**
     * 格式化发送时间 - 修复：统一时间处理
     */
    public function getFormattedTimeAttr($value, $data)
    {
        $createdAt = $data['created_at'] ?? '';
        
        if (empty($createdAt)) {
            return '';
        }
        
        $createTimestamp = strtotime($createdAt);
        $currentTimestamp = strtotime(date('Y-m-d H:i:s'));
        $diff = $currentTimestamp - $createTimestamp;
        
        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return round($diff / 60) . '分钟前';
        } elseif ($diff < 86400) {
            return round($diff / 3600) . '小时前';
        } elseif ($diff < 86400 * 7) {
            return round($diff / 86400) . '天前';
        } else {
            return date('Y-m-d H:i', $createTimestamp);
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
     * 关联回复的消息
     */
    public function replyToMessage()
    {
        return $this->belongsTo(TgMessage::class, 'reply_to_message_id', 'message_id');
    }
    
    /**
     * 创建消息记录 - 修复：使用 datetime 格式
     */
    public static function createMessage(array $data): TgMessage
    {
        $message = new static();
        
        // 设置默认值 - 修复：使用 datetime 格式
        $data = array_merge([
            'message_type' => self::TYPE_TEXT,
            'message_content' => '',
            'file_id' => '',
            'file_path' => '',
            'direction' => 'in',
            'created_at' => date('Y-m-d H:i:s'), // 修复：使用 datetime 格式
        ], $data);
        
        $message->save($data);
        
        // 记录到日志
        trace([
            'action' => 'message_created',
            'message_id' => $message->message_id,
            'chat_id' => $message->chat_id,
            'user_id' => $message->user_id,
            'tg_user_id' => $message->tg_user_id,
            'type' => $message->message_type,
            'timestamp' => time(),
        ], 'telegram_message');
        
        return $message;
    }
    
    /**
     * 根据消息ID查找
     */
    public static function findByMessageId(string $messageId, string $chatId = ''): ?TgMessage
    {
        $query = static::where('message_id', $messageId);
        
        if (!empty($chatId)) {
            $query->where('chat_id', $chatId);
        }
        
        return $query->find();
    }
    
    /**
     * 获取聊天记录
     */
    public static function getChatHistory(string $chatId, int $limit = 50, int $offset = 0): array
    {
        $messages = static::where('chat_id', $chatId)
                         ->order('created_at DESC')
                         ->limit($limit)
                         ->page(($offset / $limit) + 1, $limit)
                         ->select();
        
        return $messages->toArray();
    }
    
    /**
     * 获取用户消息统计 - 修复：使用 datetime 格式比较
     */
    public static function getUserStats(int $userId, int $days = 30): array
    {
        $startTime = date('Y-m-d 00:00:00', time() - ($days * 86400));
        
        $query = static::where('user_id', $userId)
                      ->where('created_at', '>=', $startTime);
        
        $totalCount = $query->count();
        $textCount = $query->where('message_type', self::TYPE_TEXT)->count();
        $mediaCount = $totalCount - $textCount;
        
        return [
            'total_count' => $totalCount,
            'text_count' => $textCount,
            'media_count' => $mediaCount,
            'avg_daily' => round($totalCount / $days, 1),
            'most_active_hour' => static::getMostActiveHour($userId, $days),
        ];
    }
    
    /**
     * 获取消息类型统计 - 修复：使用 datetime 格式比较
     */
    public static function getTypeStats(string $chatId = '', int $days = 30): array
    {
        $startTime = date('Y-m-d 00:00:00', time() - ($days * 86400));
        
        $query = static::where('created_at', '>=', $startTime);
        
        if (!empty($chatId)) {
            $query->where('chat_id', $chatId);
        }
        
        $stats = [];
        $types = [
            self::TYPE_TEXT => '文本',
            self::TYPE_PHOTO => '图片',
            self::TYPE_DOCUMENT => '文档',
            self::TYPE_STICKER => '贴纸',
            self::TYPE_VIDEO => '视频',
            self::TYPE_AUDIO => '音频',
            self::TYPE_VOICE => '语音',
        ];
        
        foreach ($types as $type => $name) {
            $count = $query->where('message_type', $type)->count();
            $stats[] = [
                'type' => $type,
                'name' => $name,
                'count' => $count,
                'percentage' => 0, // 将在后面计算
            ];
        }
        
        // 计算百分比
        $total = array_sum(array_column($stats, 'count'));
        if ($total > 0) {
            foreach ($stats as &$stat) {
                $stat['percentage'] = round(($stat['count'] / $total) * 100, 1);
            }
        }
        
        return $stats;
    }
    
    /**
     * 搜索消息
     */
    public static function search(string $keyword, string $chatId = '', int $limit = 50): array
    {
        $query = static::where('message_content', 'like', '%' . $keyword . '%')
                      ->where('message_type', self::TYPE_TEXT);
        
        if (!empty($chatId)) {
            $query->where('chat_id', $chatId);
        }
        
        return $query->order('created_at DESC')
                    ->limit($limit)
                    ->select()
                    ->toArray();
    }
    
    /**
     * 获取最活跃时段 - 修复：使用 datetime 格式比较
     */
    private static function getMostActiveHour(int $userId, int $days): int
    {
        $startTime = date('Y-m-d 00:00:00', time() - ($days * 86400));
        
        $messages = static::where('user_id', $userId)
                         ->where('created_at', '>=', $startTime)
                         ->field('created_at')
                         ->select();
        
        $hourStats = [];
        
        foreach ($messages as $message) {
            $hour = (int)date('H', strtotime($message->created_at));
            $hourStats[$hour] = ($hourStats[$hour] ?? 0) + 1;
        }
        
        if (empty($hourStats)) {
            return 0;
        }
        
        arsort($hourStats);
        return array_key_first($hourStats);
    }
    
    /**
     * 清理过期消息 - 修复：使用 datetime 格式比较
     */
    public static function cleanup(int $days = 90): int
    {
        $expireTime = date('Y-m-d 00:00:00', time() - ($days * 86400));
        
        return static::where('created_at', '<', $expireTime)->delete();
    }
    
    /**
     * 获取每日消息统计 - 修复：使用 datetime 格式
     */
    public static function getDailyStats(int $days = 7): array
    {
        $stats = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $startTime = $date . ' 00:00:00';
            $endTime = $date . ' 23:59:59';
            
            $query = static::where('created_at', '>=', $startTime)
                          ->where('created_at', '<=', $endTime);
            
            $totalCount = $query->count();
            $uniqueUsers = $query->distinct('user_id')->count();
            $uniqueChats = $query->distinct('chat_id')->count();
            
            $stats[$date] = [
                'date' => $date,
                'total_messages' => $totalCount,
                'unique_users' => $uniqueUsers,
                'unique_chats' => $uniqueChats,
                'avg_per_user' => $uniqueUsers > 0 ? round($totalCount / $uniqueUsers, 1) : 0,
            ];
        }
        
        return array_reverse($stats, true);
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '记录ID',
            'message_id' => 'Telegram消息ID',
            'chat_id' => '聊天ID',
            'user_id' => '系统用户ID',
            'tg_user_id' => 'Telegram用户ID',
            'message_type' => '消息类型',
            'message_content' => '消息内容',
            'reply_to_message_id' => '回复的消息ID',
            'file_id' => '文件ID',
            'file_path' => '文件路径',
            'direction' => '消息方向',
            'created_at' => '创建时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return '消息记录表';
    }
}