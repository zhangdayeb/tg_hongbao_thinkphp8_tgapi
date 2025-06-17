<?php
// 文件位置: app/service/TelegramService.php
// Telegram服务 - 核心API功能 + 群组广播功能 + 新增扩展功能

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\TgCrowdList;
use app\model\TgMessageLog;
use app\model\TgBroadcast;
use think\facade\Log;
use think\facade\Cache;
use think\exception\ValidateException;
use GuzzleHttp\Client;

class TelegramService
{
    // Telegram Bot 配置
    private string $botToken;
    private string $apiUrl;
    private Client $httpClient;
    
    // 消息类型常量
    const MESSAGE_TYPE_TEXT = 'text';
    const MESSAGE_TYPE_PHOTO = 'photo';
    
    // 广播状态常量
    const BROADCAST_STATUS_PENDING = 0;    // 待发送
    const BROADCAST_STATUS_SENDING = 1;    // 发送中
    const BROADCAST_STATUS_COMPLETED = 2;  // 已完成
    const BROADCAST_STATUS_FAILED = 3;     // 发送失败
    
    public function __construct()
    {
        $this->botToken = config('telegram.bot_token', '');
        $this->apiUrl = 'https://api.telegram.org/bot' . $this->botToken;
        $this->httpClient = new Client([
            'timeout' => 30,
            'verify' => false
        ]);
    }
    
    // =================== 1. 基础API功能 ===================
    
    /**
     * 设置Webhook
     */
    public function setWebhook(string $url, array $options = []): array
    {
        try {
            $params = [
                'url' => $url,
                'allowed_updates' => $options['allowed_updates'] ?? ['message', 'callback_query', 'my_chat_member']
            ];
            
            if (isset($options['secret_token'])) {
                $params['secret_token'] = $options['secret_token'];
            }
            
            if (isset($options['max_connections'])) {
                $params['max_connections'] = $options['max_connections'];
            }
            
            $response = $this->httpClient->post($this->apiUrl . '/setWebhook', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                Log::info('Telegram Webhook设置成功', ['url' => $url]);
                return [
                    'code' => 200,
                    'msg' => 'Webhook设置成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('Webhook设置失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('设置Telegram Webhook失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取Webhook信息
     */
    public function getWebhookInfo(): array
    {
        try {
            $response = $this->httpClient->get($this->apiUrl . '/getWebhookInfo');
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('获取Webhook信息失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('获取Telegram Webhook信息失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 删除Webhook
     */
    public function deleteWebhook(): array
    {
        try {
            $response = $this->httpClient->post($this->apiUrl . '/deleteWebhook');
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                Log::info('Telegram Webhook删除成功');
                return [
                    'code' => 200,
                    'msg' => 'Webhook删除成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('删除Webhook失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('删除Telegram Webhook失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取Bot信息
     */
    public function getMe(): array
    {
        try {
            $response = $this->httpClient->get($this->apiUrl . '/getMe');
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('获取Bot信息失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('获取Telegram Bot信息失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 发送文本消息
     */
    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $options['parse_mode'] ?? 'HTML',
                'disable_web_page_preview' => $options['disable_preview'] ?? true
            ];
            
            // 添加可选参数
            if (isset($options['reply_markup'])) {
                $params['reply_markup'] = is_string($options['reply_markup']) 
                    ? $options['reply_markup'] 
                    : json_encode($options['reply_markup']);
            }
            
            if (isset($options['reply_to_message_id'])) {
                $params['reply_to_message_id'] = $options['reply_to_message_id'];
            }
            
            if (isset($options['message_thread_id'])) {
                $params['message_thread_id'] = $options['message_thread_id'];
            }
            
            if (isset($options['disable_notification'])) {
                $params['disable_notification'] = $options['disable_notification'];
            }
            
            $response = $this->httpClient->post($this->apiUrl . '/sendMessage', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                // 记录消息发送日志
                $this->logMessageSent($chatId, 'text', $text, $result['result']);
                
                return [
                    'code' => 200,
                    'msg' => '消息发送成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('消息发送失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('发送Telegram消息失败: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'text_length' => strlen($text)
            ]);
            throw $e;
        }
    }
    
    /**
     * 发送图片消息
     */
    public function sendPhoto(int $chatId, string $photo, string $caption = '', array $options = []): array
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'photo' => $photo,
                'caption' => $caption,
                'parse_mode' => $options['parse_mode'] ?? 'HTML'
            ];
            
            if (isset($options['reply_markup'])) {
                $params['reply_markup'] = is_string($options['reply_markup']) 
                    ? $options['reply_markup'] 
                    : json_encode($options['reply_markup']);
            }
            
            if (isset($options['reply_to_message_id'])) {
                $params['reply_to_message_id'] = $options['reply_to_message_id'];
            }
            
            if (isset($options['disable_notification'])) {
                $params['disable_notification'] = $options['disable_notification'];
            }
            
            $response = $this->httpClient->post($this->apiUrl . '/sendPhoto', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                // 记录消息发送日志
                $this->logMessageSent($chatId, 'photo', $caption, $result['result']);
                
                return [
                    'code' => 200,
                    'msg' => '图片发送成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('图片发送失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('发送Telegram图片失败: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'photo' => $photo
            ]);
            throw $e;
        }
    }
    
    /**
     * 编辑消息
     */
    public function editMessage(int $chatId, int $messageId, string $text, array $options = []): array
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => $options['parse_mode'] ?? 'HTML'
            ];
            
            if (isset($options['reply_markup'])) {
                $params['reply_markup'] = is_string($options['reply_markup']) 
                    ? $options['reply_markup'] 
                    : json_encode($options['reply_markup']);
            }
            
            if (isset($options['disable_web_page_preview'])) {
                $params['disable_web_page_preview'] = $options['disable_web_page_preview'];
            }
            
            $response = $this->httpClient->post($this->apiUrl . '/editMessageText', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '消息编辑成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('消息编辑失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('编辑Telegram消息失败: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
            throw $e;
        }
    }
    
    /**
     * 删除消息
     */
    public function deleteMessage(int $chatId, int $messageId): array
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ];
            
            $response = $this->httpClient->post($this->apiUrl . '/deleteMessage', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '消息删除成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('消息删除失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('删除Telegram消息失败: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
            throw $e;
        }
    }
    
    /**
     * 回答回调查询
     */
    public function answerCallbackQuery(string $callbackQueryId, array $options = []): array
    {
        try {
            $params = [
                'callback_query_id' => $callbackQueryId
            ];
            
            if (isset($options['text'])) {
                $params['text'] = $options['text'];
            }
            
            if (isset($options['show_alert'])) {
                $params['show_alert'] = $options['show_alert'];
            }
            
            if (isset($options['url'])) {
                $params['url'] = $options['url'];
            }
            
            if (isset($options['cache_time'])) {
                $params['cache_time'] = $options['cache_time'];
            }
            
            $response = $this->httpClient->post($this->apiUrl . '/answerCallbackQuery', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '回调查询应答成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('回调查询应答失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('应答Telegram回调查询失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 2. 群组管理功能 ===================
    
    /**
     * 获取活跃群组列表
     */
    public function getActiveGroups(): array
    {
        try {
            $groups = TgCrowdList::where('is_active', 1)
                                ->where('bot_status', 'member')
                                ->where('broadcast_enabled', 1)
                                ->where('del', 0)
                                ->order('member_count', 'desc')
                                ->select();
            
            return $groups->toArray();
            
        } catch (\Exception $e) {
            Log::error('获取活跃群组失败: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取群组信息
     */
    public function getChat(int $chatId): array
    {
        try {
            $response = $this->httpClient->get($this->apiUrl . '/getChat', [
                'query' => ['chat_id' => $chatId]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => $result['result']
                ];
            } else {
                throw new ValidateException('获取群组信息失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('获取Telegram群组信息失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取群组成员数量
     */
    public function getChatMemberCount(int $chatId): array
    {
        try {
            $response = $this->httpClient->get($this->apiUrl . '/getChatMemberCount', [
                'query' => ['chat_id' => $chatId]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
                return [
                    'code' => 200,
                    'msg' => '获取成功',
                    'data' => ['count' => $result['result']]
                ];
            } else {
                throw new ValidateException('获取群组成员数量失败: ' . $result['description']);
            }
            
        } catch (\Exception $e) {
            Log::error('获取Telegram群组成员数量失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 保存群组信息
     */
    public function saveGroupInfo(array $chat): void
    {
        try {
            $groupData = [
                'crowd_id' => (string)$chat['id'],
                'title' => $chat['title'] ?? '',
                'username' => $chat['username'] ?? '',
                'description' => $chat['description'] ?? '',
                'member_count' => $chat['member_count'] ?? 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 检查群组是否已存在
            $existGroup = TgCrowdList::where('crowd_id', (string)$chat['id'])->find();
            if ($existGroup) {
                $existGroup->save($groupData);
                Log::info('Telegram群组信息已更新', ['chat_id' => $chat['id']]);
            } else {
                $groupData['created_at'] = date('Y-m-d H:i:s');
                $groupData['is_active'] = 1;
                $groupData['bot_status'] = 'member';
                $groupData['broadcast_enabled'] = 1;
                $groupData['del'] = 0;
                TgCrowdList::create($groupData);
                Log::info('Telegram群组信息已创建', ['chat_id' => $chat['id']]);
            }
            
        } catch (\Exception $e) {
            Log::error('保存Telegram群组信息失败: ' . $e->getMessage(), [
                'chat_id' => $chat['id'] ?? 'unknown'
            ]);
        }
    }
    
    /**
     * 更新群组状态
     */
    public function updateGroupStatus(string $chatId, array $status): bool
    {
        try {
            $group = TgCrowdList::where('crowd_id', $chatId)->find();
            if (!$group) {
                return false;
            }
            
            $updateData = [];
            
            if (isset($status['is_active'])) {
                $updateData['is_active'] = $status['is_active'];
            }
            
            if (isset($status['bot_status'])) {
                $updateData['bot_status'] = $status['bot_status'];
            }
            
            if (isset($status['broadcast_enabled'])) {
                $updateData['broadcast_enabled'] = $status['broadcast_enabled'];
            }
            
            if (!empty($updateData)) {
                $updateData['updated_at'] = date('Y-m-d H:i:s');
                $group->save($updateData);
                
                Log::info('群组状态已更新', [
                    'chat_id' => $chatId,
                    'updates' => $updateData
                ]);
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('更新群组状态失败: ' . $e->getMessage(), ['chat_id' => $chatId]);
            return false;
        }
    }
    
    // =================== 3. 群组广播功能 ===================
    
    /**
     * 群组广播消息
     */
    public function broadcastToGroups(array $groups, string $text, array $options = []): array
    {
        $success = 0;
        $failed = 0;
        $results = [];
        
        // 获取广播配置
        $batchSize = config('telegram.broadcast.max_groups_per_batch', 50);
        $delayBetweenMessages = config('telegram.broadcast.delay_between_messages', 1);
        $retryCount = config('telegram.broadcast.retry_count', 3);
        
        // 分批处理
        $batches = array_chunk($groups, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            Log::info("开始处理广播批次 " . ($batchIndex + 1), [
                'batch_size' => count($batch),
                'total_batches' => count($batches)
            ]);
            
            foreach ($batch as $group) {
                $chatId = is_array($group) ? ($group['crowd_id'] ?? $group['chat_id']) : $group;
                
                $attempts = 0;
                $sent = false;
                
                while ($attempts < $retryCount && !$sent) {
                    $attempts++;
                    
                    try {
                        $result = $this->sendMessage((int)$chatId, $text, $options);
                        if ($result['code'] == 200) {
                            $success++;
                            $sent = true;
                            
                            $results[] = [
                                'chat_id' => $chatId,
                                'status' => 'success',
                                'attempts' => $attempts,
                                'result' => $result
                            ];
                        }
                        
                    } catch (\Exception $e) {
                        if ($attempts >= $retryCount) {
                            $failed++;
                            $results[] = [
                                'chat_id' => $chatId,
                                'status' => 'failed',
                                'attempts' => $attempts,
                                'error' => $e->getMessage()
                            ];
                            
                            Log::error('群组广播失败', [
                                'chat_id' => $chatId,
                                'attempts' => $attempts,
                                'error' => $e->getMessage()
                            ]);
                        } else {
                            // 重试前等待
                            sleep(1);
                        }
                    }
                }
                
                // 避免API限制，发送间隔
                if ($delayBetweenMessages > 0) {
                    usleep($delayBetweenMessages * 1000000);
                }
            }
            
            // 批次间延迟
            if ($batchIndex < count($batches) - 1) {
                $batchDelay = config('telegram.broadcast.delay_between_batches', 5);
                if ($batchDelay > 0) {
                    sleep($batchDelay);
                }
            }
        }
        
        Log::info('Telegram群组广播完成', [
            'total' => count($groups),
            'success' => $success,
            'failed' => $failed
        ]);
        
        return [
            'code' => 200,
            'msg' => '群组广播完成',
            'data' => [
                'total' => count($groups),
                'success' => $success,
                'failed' => $failed,
                'success_rate' => count($groups) > 0 ? round(($success / count($groups)) * 100, 2) : 0,
                'details' => $results
            ]
        ];
    }
    
    /**
     * 广播图片到群组
     */
    public function broadcastPhotoToGroups(array $groups, string $photo, string $caption = '', array $options = []): array
    {
        $success = 0;
        $failed = 0;
        $results = [];
        
        foreach ($groups as $group) {
            $chatId = is_array($group) ? ($group['crowd_id'] ?? $group['chat_id']) : $group;
            
            try {
                $result = $this->sendPhoto((int)$chatId, $photo, $caption, $options);
                if ($result['code'] == 200) {
                    $success++;
                } else {
                    $failed++;
                }
                $results[] = [
                    'chat_id' => $chatId,
                    'status' => 'success',
                    'result' => $result
                ];
                
                usleep(100000); // 0.1秒
                
            } catch (\Exception $e) {
                $failed++;
                $results[] = [
                    'chat_id' => $chatId,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
                
                Log::error('群组图片广播失败', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return [
            'code' => 200,
            'msg' => '图片广播完成',
            'data' => [
                'total' => count($groups),
                'success' => $success,
                'failed' => $failed,
                'details' => $results
            ]
        ];
    }
    
    // =================== 4. 新增：广播模板管理 ===================
    
    /**
     * 获取广播模板
     */
    public function getBroadcastTemplate(string $type): string
    {
        try {
            $templates = config('telegram.message_templates', []);
            return $templates[$type] ?? '';
            
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
            // 这里可以实现保存到数据库或配置文件的逻辑
            // 暂时使用缓存存储
            $cacheKey = 'broadcast_template_' . $type;
            Cache::set($cacheKey, $template, 86400);
            
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
    
    // =================== 5. 新增：定时广播功能 ===================
    
    /**
     * 创建定时广播任务
     */
    public function scheduleBroadcast(array $data, int $scheduleTime = null): array
    {
        try {
            $broadcastData = [
                'type' => $data['type'] ?? 'general',
                'title' => $data['title'] ?? '',
                'content' => $data['content'] ?? '',
                'template_data' => json_encode($data['template_data'] ?? []),
                'target_groups' => json_encode($data['target_groups'] ?? []),
                'image_url' => $data['image_url'] ?? '',
                'buttons' => json_encode($data['buttons'] ?? []),
                'scheduled_at' => $scheduleTime ?? time(),
                'status' => self::BROADCAST_STATUS_PENDING,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $broadcast = TgBroadcast::create($broadcastData);
            
            Log::info('定时广播任务已创建', [
                'broadcast_id' => $broadcast->id,
                'scheduled_at' => date('Y-m-d H:i:s', $scheduleTime ?? time())
            ]);
            
            return [
                'code' => 200,
                'msg' => '定时广播任务创建成功',
                'data' => [
                    'broadcast_id' => $broadcast->id,
                    'scheduled_at' => $scheduleTime ?? time()
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('创建定时广播任务失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 处理待发送的广播
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
     * 执行广播
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
            return $this->broadcastPhotoToGroups($targetGroups, $broadcast->image_url, $content, $options);
        } else {
            return $this->broadcastToGroups($targetGroups, $content, $options);
        }
    }
    
    // =================== 6. 新增：广播统计功能 ===================
    
    /**
     * 获取广播统计
     */
    public function getBroadcastStats(int $broadcastId): array
    {
        try {
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
            
        } catch (\Exception $e) {
            Log::error('获取广播统计失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 更新广播结果
     */
    public function updateBroadcastResult(int $broadcastId, array $result): bool
    {
        try {
            $broadcast = TgBroadcast::find($broadcastId);
            if (!$broadcast) {
                return false;
            }
            
            $updateData = [
                'total_groups' => $result['data']['total'] ?? 0,
                'success_count' => $result['data']['success'] ?? 0,
                'failed_count' => $result['data']['failed'] ?? 0,
                'status' => self::BROADCAST_STATUS_COMPLETED,
                'completed_at' => date('Y-m-d H:i:s'),
                'result_data' => json_encode($result)
            ];
            
            $broadcast->save($updateData);
            
            Log::info('广播结果已更新', [
                'broadcast_id' => $broadcastId,
                'total' => $updateData['total_groups'],
                'success' => $updateData['success_count'],
                'failed' => $updateData['failed_count']
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('更新广播结果失败: ' . $e->getMessage());
            return false;
        }
    }
    
    // =================== 7. 新增：失败重试机制 ===================
    
    /**
     * 重试失败的广播
     */
    public function retryFailedBroadcasts(): array
    {
        try {
            $failedBroadcasts = TgBroadcast::where('status', self::BROADCAST_STATUS_FAILED)
                                         ->where('retry_count', '<', 3)
                                         ->select();
            
            $retried = 0;
            $results = [];
            
            foreach ($failedBroadcasts as $broadcast) {
                try {
                    // 重置状态
                    $broadcast->save([
                        'status' => self::BROADCAST_STATUS_PENDING,
                        'retry_count' => ($broadcast->retry_count ?? 0) + 1,
                        'scheduled_at' => time()
                    ]);
                    
                    $retried++;
                    $results[] = [
                        'broadcast_id' => $broadcast->id,
                        'status' => 'retried',
                        'retry_count' => $broadcast->retry_count
                    ];
                    
                } catch (\Exception $e) {
                    $results[] = [
                        'broadcast_id' => $broadcast->id,
                        'status' => 'retry_failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return [
                'code' => 200,
                'msg' => '失败广播重试完成',
                'data' => [
                    'total' => count($failedBroadcasts),
                    'retried' => $retried,
                    'results' => $results
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('重试失败广播失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 标记广播失败
     */
    public function markBroadcastFailed(int $broadcastId, string $reason): bool
    {
        try {
            $broadcast = TgBroadcast::find($broadcastId);
            if (!$broadcast) {
                return false;
            }
            
            $broadcast->save([
                'status' => self::BROADCAST_STATUS_FAILED,
                'error_message' => $reason,
                'completed_at' => date('Y-m-d H:i:s')
            ]);
            
            Log::error('广播任务标记为失败', [
                'broadcast_id' => $broadcastId,
                'reason' => $reason
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('标记广播失败状态失败: ' . $e->getMessage());
            return false;
        }
    }
    
    // =================== 8. 业务广播功能 ===================
    
    /**
     * 支付成功广播
     */
    public function broadcastPaymentSuccess(array $data): array
    {
        $groups = $this->getActiveGroups();
        if (empty($groups)) {
            return ['code' => 404, 'msg' => '没有活跃群组'];
        }
        
        if ($data['type'] === 'recharge') {
            $templateData = [
                'user_display' => $data['user']['tg_username'] 
                    ? '@' . $data['user']['tg_username'] 
                    : ($data['user']['user_name'] ?? '神秘用户'),
                'amount' => $data['amount'],
                'method' => $this->getPaymentMethodText($data['method']),
                'time' => $data['time']
            ];
            
            $text = $this->renderBroadcastTemplate('recharge_success', $templateData);
        } else {
            $templateData = [
                'user_display' => $data['user']['tg_username'] 
                    ? '@' . $data['user']['tg_username'] 
                    : ($data['user']['user_name'] ?? '神秘用户'),
                'amount' => $data['amount'],
                'time' => $data['time']
            ];
            
            $text = $this->renderBroadcastTemplate('withdraw_success', $templateData);
        }
        
        if (empty($text)) {
            // 使用默认模板
            if ($data['type'] === 'recharge') {
                $text = "🎉 <b>恭喜老板充值成功！</b>\n\n";
                $text .= "👤 用户：" . $templateData['user_display'] . "\n";
                $text .= "💰 充值金额：<b>{$data['amount']} USDT</b>\n";
                $text .= "💳 充值方式：" . $templateData['method'] . "\n";
                $text .= "⏰ 时间：{$data['time']}\n\n";
                $text .= "🔥 快来参与游戏赢大奖！";
            } else {
                $text = "💸 <b>恭喜老板提现成功！</b>\n\n";
                $text .= "👤 用户：" . $templateData['user_display'] . "\n";
                $text .= "💰 提现金额：<b>{$data['amount']} USDT</b>\n";
                $text .= "⏰ 时间：{$data['time']}\n\n";
                $text .= "🚀 财务处理神速！";
            }
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🎮 进入游戏', 'url' => 't.me/' . config('telegram.bot_username')]]
            ]
        ];
        
        return $this->broadcastToGroups($groups, $text, ['reply_markup' => $keyboard]);
    }
    
    /**
     * 广告内容广播
     */
    public function broadcastAdvertisement(array $data): array
    {
        $groups = $this->getActiveGroups();
        if (empty($groups)) {
            return ['code' => 404, 'msg' => '没有活跃群组'];
        }
        
        $text = "📢 <b>{$data['title']}</b>\n\n";
        $text .= $data['content'];
        
        if (!empty($data['image_url'])) {
            $text .= "\n\n🎯 活动详情请点击下方按钮";
        }
        
        $text .= "\n\n——————————————\n";
        $text .= "📅 " . date('Y-m-d H:i:s');
        
        $options = [];
        if (!empty($data['buttons'])) {
            $keyboard = ['inline_keyboard' => []];
            foreach ($data['buttons'] as $button) {
                $keyboard['inline_keyboard'][] = [[
                    'text' => $button['text'],
                    'url' => $button['url'] ?? 't.me/' . config('telegram.bot_username')
                ]];
            }
            $options['reply_markup'] = $keyboard;
        }
        
        // 如果有图片，使用sendPhoto方法广播
        if (!empty($data['image_url'])) {
            return $this->broadcastPhotoToGroups($groups, $data['image_url'], $text, $options);
        }
        
        return $this->broadcastToGroups($groups, $text, $options);
    }
    
    /**
     * 定时欢迎消息
     */
    public function sendWelcomeMessage(): array
    {
        $groups = $this->getActiveGroups();
        if (empty($groups)) {
            return ['code' => 404, 'msg' => '没有活跃群组'];
        }
        
        $welcomeTexts = [
            "🎉 <b>盛邦国际娱乐城欢迎您的驾临！</b>\n\n💎无需注册，无需实名，即可游戏💎\n💰USDT充提，安全可靠大额无忧💰\n🎮真人视讯/电子娱乐/捕鱼游戏🎮\n🎰精彩游戏，丰富大奖，等您来赢🎰\n🔥已上市优先担保500万🔥",
            
            "🌟 <b>盛邦娱乐城 - 您的幸运之地</b>\n\n🎯 公平公正，实时兑付\n🛡️ 资金安全，银行级加密\n🎊 24小时客服在线服务\n💎 VIP会员专享特权\n🚀 新用户注册即送体验金",
            
            "🎰 <b>今日运势爆棚时刻到！</b>\n\n⚡ 秒充秒提，资金安全无忧\n🎮 千款游戏，总有一款适合您\n🏆 累计奖池已突破千万大关\n🎁 每日签到送豪礼\n💰 推荐好友赚佣金"
        ];
        
        // 随机选择一条欢迎消息
        $text = $welcomeTexts[array_rand($welcomeTexts)];
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎮 进入游戏园', 'url' => 't.me/' . config('telegram.bot_username')],
                    ['text' => '🔥 盛邦客服', 'url' => 't.me/' . config('telegram.customer_service')]
                ],
                [
                    ['text' => '💰 盛邦财务', 'url' => 't.me/' . config('telegram.finance_service')],
                    ['text' => '🎯 包赢文化', 'callback_data' => 'view_culture']
                ]
            ]
        ];
        
        return $this->broadcastToGroups($groups, $text, ['reply_markup' => $keyboard]);
    }
    
    // =================== 9. 个人通知功能 ===================
    
    /**
     * 发送支付成功通知
     */
    public function sendPaymentSuccessNotify(int $userId, array $data): array
    {
        $user = User::find($userId);
        if (!$user || !$user->tg_id) {
            return ['code' => 404, 'msg' => '用户未绑定Telegram'];
        }
        
        $templateData = [
            'amount' => $data['amount'],
            'order_no' => $data['order_no'],
            'pay_method' => $this->getPaymentMethodText($data['pay_method']),
            'balance' => $data['balance'],
            'time' => $data['time']
        ];
        
        $text = $this->renderBroadcastTemplate('recharge_success', $templateData);
        
        if (empty($text)) {
            $text = "💰 <b>充值成功通知</b>\n\n";
            $text .= "💵 充值金额：<b>{$data['amount']}</b> USDT\n";
            $text .= "📄 订单号：<code>{$data['order_no']}</code>\n";
            $text .= "💳 支付方式：{$templateData['pay_method']}\n";
            $text .= "💰 当前余额：<b>{$data['balance']}</b> USDT\n";
            $text .= "🕐 充值时间：{$data['time']}\n\n";
            $text .= "感谢您的使用！";
        }
        
        return $this->sendMessage((int)$user->tg_id, $text);
    }
    
    /**
     * 发送提现申请通知
     */
    public function sendWithdrawApplyNotify(int $userId, array $data): array
    {
        $user = User::find($userId);
        if (!$user || !$user->tg_id) {
            return ['code' => 404, 'msg' => '用户未绑定Telegram'];
        }
        
        $text = $this->renderBroadcastTemplate('withdraw_pending', $data);
        
        if (empty($text)) {
            $text = "💸 <b>提现申请通知</b>\n\n";
            $text .= "💵 提现金额：<b>{$data['amount']}</b> USDT\n";
            $text .= "💰 手续费：{$data['fee']} USDT\n";
            $text .= "📄 订单号：<code>{$data['order_no']}</code>\n";
            $text .= "🏦 到账金额：<b>{$data['actual_amount']}</b> USDT\n";
            $text .= "🕐 申请时间：{$data['time']}\n\n";
            $text .= "⏳ 您的提现申请正在审核中，请耐心等待...";
        }
        
        return $this->sendMessage((int)$user->tg_id, $text);
    }
    
    /**
     * 发送提现成功通知
     */
    public function sendWithdrawSuccessNotify(int $userId, array $data): array
    {
        $user = User::find($userId);
        if (!$user || !$user->tg_id) {
            return ['code' => 404, 'msg' => '用户未绑定Telegram'];
        }
        
        $text = $this->renderBroadcastTemplate('withdraw_success', $data);
        
        if (empty($text)) {
            $text = "✅ <b>提现成功通知</b>\n\n";
            $text .= "💵 提现金额：<b>{$data['amount']}</b> USDT\n";
            $text .= "🏦 到账金额：<b>{$data['actual_amount']}</b> USDT\n";
            $text .= "📄 订单号：<code>{$data['order_no']}</code>\n";
            $text .= "🕐 处理时间：{$data['time']}\n\n";
            $text .= "💰 资金已成功转入您的账户！";
        }
        
        return $this->sendMessage((int)$user->tg_id, $text);
    }
    
    /**
     * 发送提现失败通知
     */
    public function sendWithdrawFailedNotify(int $userId, array $data): array
    {
        $user = User::find($userId);
        if (!$user || !$user->tg_id) {
            return ['code' => 404, 'msg' => '用户未绑定Telegram'];
        }
        
        $text = $this->renderBroadcastTemplate('withdraw_failed', $data);
        
        if (empty($text)) {
            $text = "❌ <b>提现失败通知</b>\n\n";
            $text .= "💵 提现金额：<b>{$data['amount']}</b> USDT\n";
            $text .= "📄 订单号：<code>{$data['order_no']}</code>\n";
            $text .= "❓ 失败原因：{$data['reason']}\n";
            $text .= "🕐 处理时间：{$data['time']}\n\n";
            $text .= "💰 提现金额已退还到您的账户余额";
        }
        
        return $this->sendMessage((int)$user->tg_id, $text);
    }
    
    // =================== 10. 处理Webhook消息 ===================
    
    /**
     * 处理Webhook消息 - 简化版
     */
    public function handleWebhook(array $update): array
    {
        try {
            Log::info('收到Telegram Webhook', $update);
            
            // 处理群组成员变化
            if (isset($update['my_chat_member'])) {
                $this->handleChatMemberUpdate($update['my_chat_member']);
            }
            
            // 处理新成员加入
            if (isset($update['message']['new_chat_members'])) {
                $this->handleNewChatMembers($update['message']);
            }
            
            // 处理成员离开
            if (isset($update['message']['left_chat_member'])) {
                $this->handleLeftChatMember($update['message']);
            }
            
            return [
                'code' => 200,
                'msg' => '消息处理完成',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Log::error('处理Telegram Webhook失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 处理群组成员变化
     */
    private function handleChatMemberUpdate(array $update): void
    {
        $chat = $update['chat'];
        $newMember = $update['new_chat_member'];
        
        // 处理机器人被添加到群组
        if ($newMember['user']['is_bot'] && $newMember['status'] === 'member') {
            $this->saveGroupInfo($chat);
            Log::info('机器人被添加到群组', ['chat_id' => $chat['id'], 'title' => $chat['title']]);
        }
        
        // 处理机器人被移出群组
        if (isset($update['old_chat_member']) && 
            $update['old_chat_member']['status'] === 'member' && 
            $newMember['status'] === 'left') {
            
            $this->updateGroupStatus((string)$chat['id'], [
                'is_active' => 0,
                'bot_status' => 'left'
            ]);
            
            Log::info('机器人被移出群组', ['chat_id' => $chat['id']]);
        }
        
        // 处理机器人权限变化
        if ($newMember['user']['is_bot'] && $newMember['status'] === 'administrator') {
            $this->updateGroupStatus((string)$chat['id'], [
                'bot_status' => 'administrator'
            ]);
            
            Log::info('机器人被设为管理员', ['chat_id' => $chat['id']]);
        }
    }
    
    /**
     * 处理新成员加入
     */
    private function handleNewChatMembers(array $message): void
    {
        $chat = $message['chat'];
        $newMembers = $message['new_chat_members'];
        
        foreach ($newMembers as $member) {
            if (!$member['is_bot']) {
                Log::info('新成员加入群组', [
                    'chat_id' => $chat['id'],
                    'user_id' => $member['id'],
                    'username' => $member['username'] ?? '',
                    'first_name' => $member['first_name'] ?? ''
                ]);
                
                // 这里可以发送欢迎消息或其他处理
            }
        }
    }
    
    /**
     * 处理成员离开
     */
    private function handleLeftChatMember(array $message): void
    {
        $chat = $message['chat'];
        $leftMember = $message['left_chat_member'];
        
        if (!$leftMember['is_bot']) {
            Log::info('成员离开群组', [
                'chat_id' => $chat['id'],
                'user_id' => $leftMember['id'],
                'username' => $leftMember['username'] ?? ''
            ]);
        }
    }
    
    // =================== 11. 工具方法 ===================
    
    /**
     * 获取支付方式文本
     */
    private function getPaymentMethodText(string $method): string
    {
        $methodMap = [
            'usdt' => 'USDT-TRC20',
            'huiwang' => '汇旺支付',
            'manual' => '人工充值'
        ];
        
        return $methodMap[$method] ?? $method;
    }
    
    /**
     * 记录消息发送日志
     */
    private function logMessageSent(int $chatId, string $type, string $content, array $result): void
    {
        try {
            TgMessageLog::create([
                'message_type' => $type,
                'target_type' => $chatId > 0 ? 'user' : 'group',
                'target_id' => (string)$chatId,
                'content' => mb_substr($content, 0, 1000), // 限制长度
                'send_status' => 1, // 成功
                'telegram_message_id' => $result['message_id'] ?? '',
                'sent_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            Log::error('记录消息日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证Bot Token
     */
    public function validateBotToken(): bool
    {
        try {
            $result = $this->getMe();
            return $result['code'] === 200;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取Bot配置信息
     */
    public function getBotConfig(): array
    {
        return [
            'bot_token' => $this->botToken ? substr($this->botToken, 0, 10) . '...' : '',
            'api_url' => $this->apiUrl,
            'timeout' => 30,
            'features' => [
                'webhook' => !empty(config('telegram.webhook_url')),
                'broadcast' => config('telegram.features.broadcast', true),
                'group_management' => config('telegram.features.group_management', true),
                'payment_notify' => config('telegram.features.payment_system', true),
                'redpacket' => config('telegram.features.redpacket_system', true),
            ]
        ];
    }
    
    /**
     * 清理过期数据
     */
    public function cleanup(): array
    {
        try {
            $results = [];
            
            // 清理过期的广播任务
            $expiredBroadcasts = TgBroadcast::where('status', self::BROADCAST_STATUS_PENDING)
                                           ->where('scheduled_at', '<', time() - 86400)
                                           ->delete();
            
            $results['expired_broadcasts'] = $expiredBroadcasts;
            
            // 清理过期的消息日志
            $expiredLogs = TgMessageLog::where('sent_at', '<', date('Y-m-d H:i:s', time() - 86400 * 30))
                                     ->delete();
            
            $results['expired_logs'] = $expiredLogs;
            
            Log::info('Telegram数据清理完成', $results);
            
            return [
                'code' => 200,
                'msg' => '数据清理完成',
                'data' => $results
            ];
            
        } catch (\Exception $e) {
            Log::error('Telegram数据清理失败: ' . $e->getMessage());
            throw $e;
        }
    }
}