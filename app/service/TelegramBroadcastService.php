<?php
declare(strict_types=1);

namespace app\service;

use app\model\TgCrowdList;
use app\model\TgBroadcast;
use app\model\TgMessageLog;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Db;
use think\exception\ValidateException;

/**
 * Telegram广播服务
 * 负责处理Telegram群组广播、定时广播、模板管理等功能
 */
class TelegramBroadcastService
{
    // 广播状态常量
    const BROADCAST_STATUS_PENDING = 0;    // 待发送
    const BROADCAST_STATUS_SENDING = 1;    // 发送中
    const BROADCAST_STATUS_SUCCESS = 2;    // 成功
    const BROADCAST_STATUS_FAILED = 3;     // 失败
    const BROADCAST_STATUS_CANCELLED = 4;  // 已取消
    
    private TelegramService $telegramService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
    }
    
    // =================== 1. 广播发送功能 ===================
    
    /**
     * 发送广播消息到所有群组
     */
    public function broadcastToAllGroups(string $message, array $options = []): array
    {
        try {
            // 获取所有活跃群组
            $groups = $this->getActiveGroups();
            
            if (empty($groups)) {
                return [
                    'code' => 404,
                    'msg' => '没有找到活跃的群组',
                    'data' => []
                ];
            }
            
            // 执行广播
            $result = $this->telegramService->broadcastToGroups($groups, $message, $options);
            
            // 记录广播结果
            $this->logBroadcastResult($message, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('群组广播失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 发送广播图片到所有群组
     */
    public function broadcastPhotoToAllGroups(string $photo, string $caption = '', array $options = []): array
    {
        try {
            // 获取所有活跃群组
            $groups = $this->getActiveGroups();
            
            if (empty($groups)) {
                return [
                    'code' => 404,
                    'msg' => '没有找到活跃的群组',
                    'data' => []
                ];
            }
            
            // 执行图片广播
            $result = $this->telegramService->broadcastPhotoToGroups($groups, $photo, $caption, $options);
            
            // 记录广播结果
            $this->logBroadcastResult($caption, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('群组图片广播失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 发送广播到指定群组
     */
    public function broadcastToSpecificGroups(array $groupIds, string $message, array $options = []): array
    {
        try {
            // 验证群组ID
            $validGroups = $this->validateGroupIds($groupIds);
            
            if (empty($validGroups)) {
                return [
                    'code' => 404,
                    'msg' => '没有找到有效的群组',
                    'data' => []
                ];
            }
            
            // 执行广播
            $result = $this->telegramService->broadcastToGroups($validGroups, $message, $options);
            
            // 记录广播结果
            $this->logBroadcastResult($message, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('指定群组广播失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 2. 定时广播功能 ===================
    
    /**
     * 创建定时广播任务
     */
    public function createScheduledBroadcast(array $data): array
    {
        try {
            // 验证数据
            $this->validateBroadcastData($data);
            
            // 创建广播记录
            $broadcastData = [
                'type' => $data['type'] ?? 'general',
                'title' => $data['title'] ?? '',
                'content' => $data['content'] ?? '',
                'template_data' => json_encode($data['template_data'] ?? []),
                'target_groups' => json_encode($data['target_groups'] ?? []),
                'image_url' => $data['image_url'] ?? '',
                'buttons' => json_encode($data['buttons'] ?? []),
                'scheduled_at' => $data['scheduled_at'] ?? time(),
                'status' => self::BROADCAST_STATUS_PENDING,
                'created_at' => date('Y-m-d H:i:s'),
                'total_groups' => 0,
                'success_count' => 0,
                'failed_count' => 0
            ];
            
            $broadcast = TgBroadcast::create($broadcastData);
            
            // 计算目标群组数量
            $targetGroups = json_decode($broadcast->target_groups, true) ?: [];
            if (empty($targetGroups)) {
                $targetGroups = $this->getActiveGroups();
            }
            $broadcast->save(['total_groups' => count($targetGroups)]);
            
            Log::info('定时广播任务已创建', [
                'broadcast_id' => $broadcast->id,
                'scheduled_at' => date('Y-m-d H:i:s', $broadcast->scheduled_at)
            ]);
            
            return [
                'code' => 200,
                'msg' => '定时广播任务创建成功',
                'data' => [
                    'broadcast_id' => $broadcast->id,
                    'scheduled_at' => $broadcast->scheduled_at
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('创建定时广播任务失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 处理待发送的定时广播
     */
    public function processPendingBroadcasts(): array
    {
        try {
            $now = time();
            $pendingBroadcasts = TgBroadcast::where('status', self::BROADCAST_STATUS_PENDING)
                                           ->where('scheduled_at', '<=', $now)
                                           ->select();
            
            $processed = 0;
            $results = [];
            
            foreach ($pendingBroadcasts as $broadcast) {
                try {
                    // 更新状态为发送中
                    $broadcast->save([
                        'status' => self::BROADCAST_STATUS_SENDING,
                        'started_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    // 执行广播
                    $result = $this->executeBroadcast($broadcast);
                    
                    // 更新广播结果
                    $this->updateBroadcastResult($broadcast->id, $result);
                    
                    $processed++;
                    $results[] = [
                        'broadcast_id' => $broadcast->id,
                        'status' => 'processed',
                        'result' => $result
                    ];
                    
                } catch (\Exception $e) {
                    // 标记为失败
                    $this->markBroadcastFailed($broadcast->id, $e->getMessage());
                    
                    $results[] = [
                        'broadcast_id' => $broadcast->id,
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return [
                'code' => 200,
                'msg' => '定时广播处理完成',
                'data' => [
                    'total' => count($pendingBroadcasts),
                    'processed' => $processed,
                    'results' => $results
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('处理定时广播失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 执行单个广播任务
     */
    private function executeBroadcast(TgBroadcast $broadcast): array
    {
        // 获取目标群组
        $targetGroups = json_decode($broadcast->target_groups, true) ?: [];
        if (empty($targetGroups)) {
            $targetGroups = $this->getActiveGroups();
        }
        
        // 渲染内容
        $templateData = json_decode($broadcast->template_data, true) ?: [];
        $content = $this->renderBroadcastTemplate($broadcast->type, $templateData);
        
        if (empty($content)) {
            $content = $broadcast->content;
        }
        
        // 准备按钮
        $buttons = json_decode($broadcast->buttons, true) ?: [];
        $options = [];
        if (!empty($buttons)) {
            $keyboard = ['inline_keyboard' => []];
            foreach ($buttons as $button) {
                $keyboard['inline_keyboard'][] = [[
                    'text' => $button['text'],
                    'url' => $button['url'] ?? 't.me/' . config('telegram.bot_username')
                ]];
            }
            $options['reply_markup'] = $keyboard;
        }
        
        // 执行广播
        if (!empty($broadcast->image_url)) {
            return $this->telegramService->broadcastPhotoToGroups($targetGroups, $broadcast->image_url, $content, $options);
        } else {
            return $this->telegramService->broadcastToGroups($targetGroups, $content, $options);
        }
    }
    
    // =================== 3. 模板管理功能 ===================
    
    /**
     * 获取广播模板
     */
    public function getBroadcastTemplate(string $type): string
    {
        try {
            // 优先从缓存获取
            $cacheKey = 'broadcast_template_' . $type;
            $template = Cache::get($cacheKey);
            
            if ($template !== null) {
                return $template;
            }
            
            // 从配置文件获取
            $templates = config('telegram.message_templates', []);
            $template = $templates[$type] ?? '';
            
            // 缓存模板
            if (!empty($template)) {
                Cache::set($cacheKey, $template, 86400);
            }
            
            return $template;
            
        } catch (\Exception $e) {
            Log::error('获取广播模板失败: ' . $e->getMessage());
            return '';
        }
    }
    
    /**
     * 保存广播模板
     */
    public function saveBroadcastTemplate(string $type, string $template): bool
    {
        try {
            // 保存到缓存
            $cacheKey = 'broadcast_template_' . $type;
            Cache::set($cacheKey, $template, 86400);
            
            // 这里可以扩展保存到数据库的逻辑
            
            Log::info('广播模板已保存', ['type' => $type]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('保存广播模板失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 渲染广播模板
     */
    public function renderBroadcastTemplate(string $type, array $variables = []): string
    {
        try {
            $template = $this->getBroadcastTemplate($type);
            
            if (empty($template)) {
                return '';
            }
            
            // 替换模板变量
            foreach ($variables as $key => $value) {
                $template = str_replace('{' . $key . '}', (string)$value, $template);
            }
            
            return $template;
            
        } catch (\Exception $e) {
            Log::error('渲染广播模板失败: ' . $e->getMessage());
            return '';
        }
    }
    
    // =================== 4. 统计和管理功能 ===================
    
    /**
     * 获取广播统计
     */
    public function getBroadcastStats(int $broadcastId = null): array
    {
        try {
            if ($broadcastId) {
                // 获取单个广播统计
                $broadcast = TgBroadcast::find($broadcastId);
                if (!$broadcast) {
                    throw new ValidateException('广播任务不存在');
                }
                
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => [
                        'broadcast_id' => $broadcast->id,
                        'type' => $broadcast->type,
                        'title' => $broadcast->title,
                        'status' => $broadcast->status,
                        'total_groups' => $broadcast->total_groups ?? 0,
                        'success_count' => $broadcast->success_count ?? 0,
                        'failed_count' => $broadcast->failed_count ?? 0,
                        'success_rate' => $broadcast->total_groups > 0 
                            ? round(($broadcast->success_count / $broadcast->total_groups) * 100, 2) 
                            : 0,
                        'scheduled_at' => $broadcast->scheduled_at,
                        'started_at' => $broadcast->started_at,
                        'completed_at' => $broadcast->completed_at,
                        'created_at' => $broadcast->created_at
                    ]
                ];
            } else {
                // 获取总体统计
                $stats = $this->getOverallBroadcastStats();
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $stats
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('获取广播统计失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 取消定时广播
     */
    public function cancelScheduledBroadcast(int $broadcastId): array
    {
        try {
            $broadcast = TgBroadcast::find($broadcastId);
            if (!$broadcast) {
                throw new ValidateException('广播任务不存在');
            }
            
            if ($broadcast->status != self::BROADCAST_STATUS_PENDING) {
                throw new ValidateException('只能取消待发送的广播任务');
            }
            
            $broadcast->save([
                'status' => self::BROADCAST_STATUS_CANCELLED,
                'completed_at' => date('Y-m-d H:i:s')
            ]);
            
            Log::info('定时广播已取消', ['broadcast_id' => $broadcastId]);
            
            return [
                'code' => 200,
                'msg' => '广播任务已取消',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('取消定时广播失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 5. 私有辅助方法 ===================
    
    /**
     * 获取活跃群组列表
     */
    private function getActiveGroups(): array
    {
        try {
            $groups = TgCrowdList::where('is_active', 1)
                                ->where('broadcast_enabled', 1)
                                ->where('del', 0)
                                ->field('crowd_id,title')
                                ->select()
                                ->toArray();
            
            return array_column($groups, 'crowd_id');
            
        } catch (\Exception $e) {
            Log::error('获取活跃群组失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 验证群组ID
     */
    private function validateGroupIds(array $groupIds): array
    {
        try {
            $validGroups = TgCrowdList::whereIn('crowd_id', $groupIds)
                                     ->where('is_active', 1)
                                     ->where('del', 0)
                                     ->column('crowd_id');
            
            return $validGroups;
            
        } catch (\Exception $e) {
            Log::error('验证群组ID失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 验证广播数据
     */
    private function validateBroadcastData(array $data): void
    {
        if (empty($data['content']) && empty($data['template_data'])) {
            throw new ValidateException('广播内容不能为空');
        }
        
        if (isset($data['scheduled_at']) && $data['scheduled_at'] < time()) {
            throw new ValidateException('定时时间不能早于当前时间');
        }
    }
    
    /**
     * 记录广播结果
     */
    private function logBroadcastResult(string $content, array $result): void
    {
        try {
            $logData = [
                'message_type' => 'broadcast',
                'target_type' => 'groups',
                'content' => mb_substr($content, 0, 500),
                'send_status' => $result['code'] == 200 ? 'success' : 'failed',
                'result_data' => json_encode($result),
                'sent_at' => date('Y-m-d H:i:s')
            ];
            
            TgMessageLog::create($logData);
            
        } catch (\Exception $e) {
            Log::error('记录广播日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新广播结果
     */
    private function updateBroadcastResult(int $broadcastId, array $result): void
    {
        try {
            $updateData = [
                'status' => $result['code'] == 200 ? self::BROADCAST_STATUS_SUCCESS : self::BROADCAST_STATUS_FAILED,
                'success_count' => $result['data']['success'] ?? 0,
                'failed_count' => $result['data']['failed'] ?? 0,
                'completed_at' => date('Y-m-d H:i:s')
            ];
            
            TgBroadcast::where('id', $broadcastId)->update($updateData);
            
        } catch (\Exception $e) {
            Log::error('更新广播结果失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 标记广播失败
     */
    private function markBroadcastFailed(int $broadcastId, string $errorMessage): void
    {
        try {
            TgBroadcast::where('id', $broadcastId)->update([
                'status' => self::BROADCAST_STATUS_FAILED,
                'error_message' => $errorMessage,
                'completed_at' => date('Y-m-d H:i:s')
            ]);
            
        } catch (\Exception $e) {
            Log::error('标记广播失败状态失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取总体广播统计
     */
    private function getOverallBroadcastStats(): array
    {
        try {
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $thisMonth = date('Y-m');
            
            $stats = [
                'today' => [
                    'total' => TgBroadcast::whereTime('created_at', $today)->count(),
                    'success' => TgBroadcast::whereTime('created_at', $today)
                                          ->where('status', self::BROADCAST_STATUS_SUCCESS)
                                          ->count(),
                    'failed' => TgBroadcast::whereTime('created_at', $today)
                                         ->where('status', self::BROADCAST_STATUS_FAILED)
                                         ->count(),
                    'pending' => TgBroadcast::whereTime('created_at', $today)
                                          ->where('status', self::BROADCAST_STATUS_PENDING)
                                          ->count()
                ],
                'yesterday' => [
                    'total' => TgBroadcast::whereTime('created_at', $yesterday)->count(),
                    'success' => TgBroadcast::whereTime('created_at', $yesterday)
                                          ->where('status', self::BROADCAST_STATUS_SUCCESS)
                                          ->count()
                ],
                'this_month' => [
                    'total' => TgBroadcast::whereTime('created_at', 'month')->count(),
                    'success' => TgBroadcast::whereTime('created_at', 'month')
                                          ->where('status', self::BROADCAST_STATUS_SUCCESS)
                                          ->count()
                ],
                'active_groups' => count($this->getActiveGroups())
            ];
            
            return $stats;
            
        } catch (\Exception $e) {
            Log::error('获取总体广播统计失败: ' . $e->getMessage());
            return [];
        }
    }
    
    // =================== 6. 公共接口方法 ===================
    
    /**
     * 快速发送通知到所有群组（简化接口）
     */
    public function sendNotification(string $message, string $type = 'general'): array
    {
        try {
            // 渲染模板（如果有）
            $content = $this->renderBroadcastTemplate($type, ['message' => $message]);
            if (empty($content)) {
                $content = $message;
            }
            
            // 立即发送到所有群组
            return $this->broadcastToAllGroups($content);
            
        } catch (\Exception $e) {
            Log::error('发送通知失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取服务状态
     */
    public function getServiceStatus(): array
    {
        try {
            $activeGroups = count($this->getActiveGroups());
            $pendingBroadcasts = TgBroadcast::where('status', self::BROADCAST_STATUS_PENDING)->count();
            
            return [
                'code' => 200,
                'msg' => '服务正常',
                'data' => [
                    'service_status' => 'running',
                    'active_groups' => $activeGroups,
                    'pending_broadcasts' => $pendingBroadcasts,
                    'last_check' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('获取服务状态失败: ' . $e->getMessage());
            return [
                'code' => 500,
                'msg' => '服务异常: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
}