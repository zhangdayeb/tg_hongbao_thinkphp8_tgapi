<?php
declare(strict_types=1);

namespace app\service;

use app\model\TgCrowdList;
use think\facade\Log;
use think\exception\ValidateException;
use GuzzleHttp\Client;

/**
 * Telegram服务 - 精简版：纯API服务 + 群组管理
 * 职责：基础API调用 + 机器人群组管理，不涉及广播业务
 */
class TelegramService
{
    private string $botToken;
    private string $apiUrl;
    private Client $httpClient;
    
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
            
            if (isset($options['reply_markup'])) {
                $params['reply_markup'] = is_string($options['reply_markup']) 
                    ? $options['reply_markup'] 
                    : json_encode($options['reply_markup']);
            }
            
            if (isset($options['reply_to_message_id'])) {
                $params['reply_to_message_id'] = $options['reply_to_message_id'];
            }
            
            $response = $this->httpClient->post($this->apiUrl . '/sendMessage', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
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
            
            $response = $this->httpClient->post($this->apiUrl . '/sendPhoto', [
                'json' => $params
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            
            if ($result['ok']) {
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
            $params = ['callback_query_id' => $callbackQueryId];
            
            if (isset($options['text'])) {
                $params['text'] = $options['text'];
            }
            
            if (isset($options['show_alert'])) {
                $params['show_alert'] = $options['show_alert'];
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
    
    // =================== 2. 核心业务：机器人群组管理 ===================
    
    /**
     * 🆕 处理机器人状态变化（核心业务逻辑）
     */
    public function handleMyChatMemberUpdate(array $myChatMember, string $debugFile): void
    {
        try {
            $chat = $myChatMember['chat'];
            $newMember = $myChatMember['new_chat_member'];
            $oldMember = $myChatMember['old_chat_member'] ?? null;
            
            $chatId = (string)$chat['id'];
            $newStatus = $newMember['status'] ?? '';
            $oldStatus = $oldMember['status'] ?? 'left';
            
            Log::info("机器人状态变化", [
                'chat_id' => $chatId,
                'chat_title' => $chat['title'] ?? '',
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
            
            // 验证是否是机器人自己
            if (!($newMember['user']['is_bot'] ?? false)) {
                Log::info('非机器人状态变化，忽略处理', ['chat_id' => $chatId]);
                return;
            }
            
            // 处理机器人成为管理员
            if ($newStatus === 'administrator' && $oldStatus !== 'administrator') {
                $this->addGroupAsAdmin($chat, $myChatMember);
                Log::info('机器人成为管理员，群组已添加', ['chat_id' => $chatId]);
            }
            // 处理机器人失去权限或离开群组
            elseif (in_array($newStatus, ['left', 'kicked']) || 
                   ($oldStatus === 'administrator' && $newStatus !== 'administrator')) {
                $this->removeGroup($chatId);
                Log::info('机器人失去权限或离开群组，群组已移除', ['chat_id' => $chatId]);
            }
            
        } catch (\Exception $e) {
            Log::error('处理机器人状态变化失败: ' . $e->getMessage(), [
                'chat_id' => $chat['id'] ?? 'unknown'
            ]);
            throw $e;
        }
    }
    
    /**
     * 🆕 添加群组（机器人成为管理员时）
     */
    private function addGroupAsAdmin(array $chat, array $myChatMember): void
    {
        try {
            $chatId = (string)$chat['id'];
            
            // 获取更详细的群组信息
            $fullGroupInfo = $this->getFullGroupInfo($chat);
            
            // 提取邀请者信息
            $inviterInfo = $this->extractInviterInfo($myChatMember);
            
            // 准备群组数据
            $groupData = [
                'crowd_id' => $chatId,
                'title' => $fullGroupInfo['title'] ?? '',
                'username' => $fullGroupInfo['username'] ?? '',
                'description' => $fullGroupInfo['description'] ?? '',
                'member_count' => $fullGroupInfo['member_count'] ?? 0,
                'first_name' => config('telegram.bot_name', ''),
                'botname' => config('telegram.bot_username', ''),
                'user_id' => $inviterInfo['user_id'],
                'username' => $inviterInfo['username'],
                'is_active' => 1,
                'bot_status' => 'administrator',
                'broadcast_enabled' => 1,
                'del' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 检查群组是否已存在
            $existGroup = TgCrowdList::where('crowd_id', $chatId)->find();
            if ($existGroup) {
                // 更新现有群组
                $existGroup->save($groupData);
                Log::info('群组信息已更新为管理员', ['chat_id' => $chatId]);
            } else {
                // 创建新群组
                TgCrowdList::create($groupData);
                Log::info('新管理员群组已创建', ['chat_id' => $chatId]);
            }
            
        } catch (\Exception $e) {
            Log::error('添加管理员群组失败: ' . $e->getMessage(), ['chat_id' => $chat['id'] ?? 'unknown']);
            throw $e;
        }
    }
    
    /**
     * 🆕 移除群组（机器人失去权限或离开时）
     */
    private function removeGroup(string $chatId): void
    {
        try {
            $group = TgCrowdList::where('crowd_id', $chatId)->find();
            if ($group) {
                // 软删除群组
                $group->save([
                    'del' => 1,
                    'is_active' => 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                Log::info('群组已移除', ['chat_id' => $chatId]);
            } else {
                Log::info('群组不存在，无需移除', ['chat_id' => $chatId]);
            }
            
        } catch (\Exception $e) {
            Log::error('移除群组失败: ' . $e->getMessage(), ['chat_id' => $chatId]);
            throw $e;
        }
    }
    
    /**
     * 获取完整的群组信息
     */
    private function getFullGroupInfo(array $basicChat): array
    {
        try {
            $chatId = (int)$basicChat['id'];
            
            // 尝试通过API获取详细信息
            try {
                $result = $this->getChat($chatId);
                if ($result['code'] === 200) {
                    return array_merge($basicChat, $result['data']);
                }
            } catch (\Exception $e) {
                Log::warning('获取群组详细信息失败，使用基础信息: ' . $e->getMessage());
            }
            
            // 尝试获取成员数量
            try {
                $memberResult = $this->getChatMemberCount($chatId);
                if ($memberResult['code'] === 200) {
                    $basicChat['member_count'] = $memberResult['data']['count'];
                }
            } catch (\Exception $e) {
                Log::warning('获取群组成员数量失败: ' . $e->getMessage());
                $basicChat['member_count'] = 0;
            }
            
            return $basicChat;
            
        } catch (\Exception $e) {
            Log::error('获取完整群组信息失败: ' . $e->getMessage());
            return $basicChat;
        }
    }
    
    /**
     * 提取邀请者信息
     */
    private function extractInviterInfo(array $myChatMember): array
    {
        $from = $myChatMember['from'] ?? [];
        
        return [
            'user_id' => $from['id'] ?? 0,
            'username' => $from['username'] ?? '',
            'first_name' => $from['first_name'] ?? '',
            'last_name' => $from['last_name'] ?? ''
        ];
    }
    
    /**
     * 获取活跃管理员群组列表（供其他服务使用）
     */
    public function getAdminGroups(): array
    {
        try {
            $groups = TgCrowdList::where('is_active', 1)
                                ->where('bot_status', 'administrator')
                                ->where('broadcast_enabled', 1)
                                ->where('del', 0)
                                ->order('member_count', 'desc')
                                ->select();
            
            return $groups->toArray();
            
        } catch (\Exception $e) {
            Log::error('获取管理员群组失败: ' . $e->getMessage());
            return [];
        }
    }
    
    // =================== 3. Webhook管理 ===================
    
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
    
    // =================== 4. 工具方法 ===================
    
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
                'group_management' => true,
                'webhook_support' => true,
            ]
        ];
    }
}