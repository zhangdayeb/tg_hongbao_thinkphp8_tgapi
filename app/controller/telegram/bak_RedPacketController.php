<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\TelegramRedPacketService;
use app\model\User;
use app\model\RedPacket;
use app\model\TgCrowdList;

/**
 * 红包控制器 - 完整功能版本 + 🔥 聊天类型限制 + 命令解析修复
 * 集成命令解析、并发控制、消息模板等完整红包功能
 */
class RedPacketController extends BaseTelegramController
{
    private TelegramRedPacketService $redPacketService;
    private ?User $currentUser = null;
    private ?array $chatContext = null; // 🔥 新增：聊天上下文
    private ?string $originalMessage = null; // 🔥 新增：保存原始完整消息
    
    public function __construct()
    {
        parent::__construct();
        $this->redPacketService = new TelegramRedPacketService();
    }
    
    /**
     * 设置当前用户（由CommandDispatcher调用）
     */
    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
    
    /**
     * 🔥 新增：设置聊天上下文
     */
    public function setChatContext(array $chatContext): void
    {
        $this->chatContext = $chatContext;
    }
    
    /**
     * 处理红包相关命令 - 🔥 增强聊天类型验证 + 命令解析修复
     */
    public function handle(string $command, int $chatId, string $debugFile, ?string $fullMessage = null): void
    {
        try {
            $this->log($debugFile, "🧧 RedPacketController 处理命令: {$command}");
            
            // 🔥 保存原始完整消息
            $this->originalMessage = $fullMessage;
            $this->log($debugFile, "原始消息: " . ($fullMessage ?? 'null'));
            
            if (!$this->currentUser) {
                $this->log($debugFile, "❌ 用户对象未设置");
                $this->sendMessage($chatId, "❌ 用户信息错误，请重新开始", $debugFile);
                return;
            }
            
            // 🔥 检查聊天类型权限
            if (!$this->validateChatTypePermission($chatId, $command, $debugFile)) {
                return; // 权限检查失败，已发送相应提示
            }
            
            switch ($command) {
                case 'redpacket':
                    $this->showRedPacketMenu($chatId, $debugFile);
                    break;
                    
                case 'red':
                case 'hongbao':
                case 'hb':
                    // 🔥 红包发送命令需要额外的群组权限检查
                    if (!$this->validateGroupRedPacketPermission($chatId, $debugFile)) {
                        return;
                    }
                    
                    // 🔥 修复：检查是否有完整的命令参数
                    if ($this->hasCompleteRedPacketParams($debugFile)) {
                        // 有完整参数，直接解析并创建红包
                        $this->handleCompleteRedPacketCommand($chatId, $debugFile);
                    } else {
                        // 无参数或参数不完整，显示指南
                        $this->showSendRedPacketGuide($chatId, $debugFile);
                    }
                    break;
                    
                default:
                    $this->handleUnknownCommand($command, $chatId, $debugFile);
                    break;
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包命令处理", $debugFile);
            $this->sendMessage($chatId, "❌ 处理失败，请稍后重试", $debugFile);
        }
    }
    
    // =================== 🔥 新增：命令解析修复方法 ===================
    
    /**
     * 🔥 新增：检查是否有完整的红包参数
     */
    private function hasCompleteRedPacketParams(string $debugFile): bool
    {
        if (empty($this->originalMessage)) {
            $this->log($debugFile, "原始消息为空");
            return false;
        }
        
        // 检查命令格式：/red 金额 个数 [标题]
        $pattern = '/^\/(?:red|hb|hongbao)\s+(\d+(?:\.\d+)?)\s+(\d+)(?:\s+(.+))?/i';
        $hasParams = preg_match($pattern, trim($this->originalMessage), $matches);
        
        $this->log($debugFile, "参数检查 - 原始消息: '{$this->originalMessage}', 匹配结果: " . ($hasParams ? '是' : '否'));
        
        if ($hasParams) {
            $this->log($debugFile, "解析到参数 - 金额: {$matches[1]}, 个数: {$matches[2]}, 标题: " . ($matches[3] ?? '默认'));
        }
        
        return $hasParams > 0;
    }
    
    /**
     * 🔥 新增：处理完整的红包命令
     */
    private function handleCompleteRedPacketCommand(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🎯 开始处理完整红包命令");
            
            // 构建聊天上下文
            $chatContext = [
                'chat_id' => $chatId,
                'chat_type' => $this->getChatType($chatId),
                'message_id' => 0, // 可以从消息中获取
            ];
            
            // 使用 TelegramRedPacketService 解析命令
            $parsed = $this->redPacketService->parseRedPacketCommand($this->originalMessage, $chatContext);
            
            if ($parsed && !isset($parsed['error'])) {
                $this->log($debugFile, "✅ 命令解析成功");
                // 解析成功，创建红包
                $this->createRedPacketFromParsed($chatId, $parsed, $debugFile);
            } else {
                $this->log($debugFile, "❌ 命令解析失败");
                // 解析失败，显示错误和指南
                $errorMsg = $parsed['message'] ?? '命令格式错误';
                $this->sendMessage($chatId, "❌ " . $errorMsg, $debugFile);
                $this->showSendRedPacketGuide($chatId, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "完整红包命令处理", $debugFile);
            $this->log($debugFile, "❌ 红包命令处理异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 红包创建失败：" . $e->getMessage(), $debugFile);
        }
    }
    
    /**
     * 🔥 新增：从解析结果创建红包
     */
    private function createRedPacketFromParsed(int $chatId, array $parsed, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🎁 开始创建红包");
            
            // 验证用户余额
            $amount = $parsed['amount'];
            if ($this->currentUser->money_balance < $amount) {
                $this->sendMessage($chatId, 
                    "❌ 余额不足\n\n💰 当前余额：{$this->currentUser->money_balance} USDT\n💸 需要金额：{$amount} USDT", 
                    $debugFile);
                return;
            }
            
            // 准备红包数据
            $redPacketData = [
                'sender_id' => $this->currentUser->id,
                'sender_tg_id' => $this->currentUser->tg_id,
                'total_amount' => $parsed['amount'],
                'total_count' => $parsed['count'],
                'title' => $parsed['title'],
                'chat_id' => $chatId,
                'chat_type' => $parsed['chat_context']['chat_type'] ?? 'group',
                'packet_type' => $parsed['type'],
                'expire_time' => date('Y-m-d H:i:s', time() + 86400), // 24小时后过期
            ];
            
            $this->log($debugFile, "红包数据准备完成: " . json_encode($redPacketData, JSON_UNESCAPED_UNICODE));
            
            // 使用 TelegramRedPacketService 创建并发送红包
            $result = $this->redPacketService->sendRedPacketToCurrentGroup($redPacketData, $chatId);
            
            if ($result['code'] === 200) {
                $this->log($debugFile, "✅ 红包创建并发送成功");
                
                // 重新获取用户余额（红包发送后会扣减）
                $this->currentUser->refresh();
                
                // 发送成功通知给发送者
                $packetId = $result['data']['packet_id'] ?? '';
                $successMessage = "🎉 *红包发送成功！*\n\n" .
                                "🧧 红包ID：`{$packetId}`\n" .
                                "💰 总金额：{$parsed['amount']} USDT\n" .
                                "📦 红包个数：{$parsed['count']} 个\n" .
                                "🎯 红包标题：{$parsed['title']}\n" .
                                "💼 当前余额：{$this->currentUser->money_balance} USDT\n\n" .
                                "🎯 红包已发送到群组，快去看看吧！";
                
                $keyboard = [
                    [
                        ['text' => '📊 查看详情', 'callback_data' => 'redpacket_detail_' . $packetId]
                    ],
                    [
                        ['text' => '🧧 再发一个', 'callback_data' => 'send_red_packet'],
                        ['text' => '🏠 主菜单', 'callback_data' => 'back_to_main']
                    ]
                ];
                
                // 如果是群组，发送私聊通知；如果是私聊，直接在当前聊天发送
                if ($this->getChatType($chatId) !== 'private') {
                    $this->sendMessageWithKeyboard($this->currentUser->tg_id, $successMessage, $keyboard, $debugFile);
                } else {
                    $this->sendMessageWithKeyboard($chatId, $successMessage, $keyboard, $debugFile);
                }
                
            } else {
                $this->log($debugFile, "❌ 红包发送失败: " . $result['msg']);
                $this->sendMessage($chatId, "❌ 红包发送失败：" . $result['msg'], $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包创建", $debugFile);
            $this->sendMessage($chatId, "❌ 红包创建失败：" . $e->getMessage(), $debugFile);
        }
    }
    
    // =================== 原有方法保持不变 ===================
    
    /**
     * 处理红包相关回调 - 🔥 增强聊天类型验证
     */
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🧧 RedPacketController 处理回调: {$callbackData}");
            
            if (!$this->currentUser) {
                $this->log($debugFile, "❌ 用户对象未设置");
                $this->sendMessage($chatId, "❌ 用户信息错误，请重新开始", $debugFile);
                return;
            }
            
            // 🔥 处理抢红包回调（群组内操作）
            if (strpos($callbackData, 'grab_redpacket_') === 0) {
                if (!$this->validateGroupOperation($chatId, $debugFile)) {
                    return;
                }
                $this->handleGrabRedPacket($callbackData, $chatId, $debugFile);
                return;
            }
            
            // 🔥 处理红包详情回调
            if (strpos($callbackData, 'redpacket_detail_') === 0) {
                $this->handleRedPacketDetail($callbackData, $chatId, $debugFile);
                return;
            }
            
            // 🔥 处理刷新红包回调
            if (strpos($callbackData, 'refresh_redpacket_') === 0) {
                $this->handleRefreshRedPacket($callbackData, $chatId, $debugFile);
                return;
            }
            
            // 处理常规回调
            switch ($callbackData) {
                case 'redpacket':
                    $this->showRedPacketMenu($chatId, $debugFile);
                    break;
                    
                case 'send_red_packet':
                    // 🔥 发红包需要群组权限
                    if (!$this->validateGroupRedPacketPermission($chatId, $debugFile)) {
                        return;
                    }
                    $this->showSendRedPacketGuide($chatId, $debugFile);
                    break;
                    
                case 'red_packet_history':
                    $this->showRedPacketHistory($chatId, $debugFile);
                    break;
                    
                case 'confirm_send_redpacket':
                    $this->handleConfirmSendRedPacket($chatId, $debugFile);
                    break;
                    
                case 'cancel_send_redpacket':
                    $this->handleCancelSendRedPacket($chatId, $debugFile);
                    break;
                    
                default:
                    $this->handleUnknownCallback($callbackData, $chatId, $debugFile);
                    break;
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包回调处理", $debugFile);
            $this->sendMessage($chatId, "❌ 操作失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 🔥 新增：处理确认发送红包
     */
    private function handleConfirmSendRedPacket(int $chatId, string $debugFile): void
    {
        // 获取用户状态中的红包数据
        $userState = $this->getUserState($chatId);
        $redPacketData = $userState['data']['redpacket_data'] ?? null;
        
        if ($redPacketData) {
            $this->processSendRedPacket($chatId, $redPacketData, $debugFile);
        } else {
            $this->sendMessage($chatId, "❌ 红包数据丢失，请重新开始", $debugFile);
        }
        
        // 清除状态
        $this->clearUserState($chatId);
    }
    
    /**
     * 🔥 新增：处理取消发送红包
     */
    private function handleCancelSendRedPacket(int $chatId, string $debugFile): void
    {
        $this->sendMessage($chatId, "❌ 红包发送已取消", $debugFile);
        
        // 清除状态
        $this->clearUserState($chatId);
    }
    
    /**
     * 处理文本输入（红包相关状态） - 🔥 增强聊天类型验证
     */
    public function handleTextInput(int $chatId, string $text, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🧧 RedPacketController 处理文本输入: {$text}");
            
            if (!$this->currentUser) {
                $this->log($debugFile, "❌ 用户对象未设置");
                return;
            }
            
            $userState = $this->getUserState($chatId);
            $currentState = $userState['state'] ?? 'idle';
            
            $this->log($debugFile, "用户状态: {$currentState}");
            
            switch ($currentState) {
                case 'waiting_red_packet_command':
                    // 🔥 红包命令需要群组权限
                    if (!$this->validateGroupRedPacketPermission($chatId, $debugFile)) {
                        return;
                    }
                    $this->handleRedPacketCommand($chatId, $text, $debugFile);
                    break;
                    
                case 'waiting_red_packet_title':
                    $this->handleRedPacketTitle($chatId, $text, $debugFile);
                    break;
                    
                case 'confirming_red_packet':
                    $this->handleRedPacketConfirmation($chatId, $text, $debugFile);
                    break;
                    
                default:
                    // 检查是否是红包命令
                    if ($this->isRedPacketCommand($text)) {
                        // 🔥 红包命令需要群组权限
                        if (!$this->validateGroupRedPacketPermission($chatId, $debugFile)) {
                            return;
                        }
                        $this->handleRedPacketCommand($chatId, $text, $debugFile);
                    } else {
                        $this->log($debugFile, "非红包相关的文本输入，忽略");
                    }
                    break;
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包文本输入处理", $debugFile);
            $this->sendMessage($chatId, "❌ 输入处理失败，请重试", $debugFile);
        }
    }
    
    // =================== 🔥 新增：聊天类型验证方法 ===================
    
    /**
     * 验证聊天类型权限
     */
    private function validateChatTypePermission(int $chatId, string $command, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        $config = config('redpacket.command_restrictions', []);
        
        $this->log($debugFile, "聊天类型验证 - ChatID: {$chatId}, Type: {$chatType}, Command: {$command}");
        
        // 🔥 私聊限制检查
        if ($chatType === 'private' && !($config['allow_in_private'] ?? false)) {
            $this->handlePrivateRedPacketAttempt($chatId, $command, $debugFile);
            return false;
        }
        
        // 🔥 群组权限检查
        if (in_array($chatType, ['group', 'supergroup']) && !($config['allow_in_groups'] ?? true)) {
            $this->sendMessage($chatId, "❌ 群组红包功能已禁用", $debugFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证群组红包权限
     */
    private function validateGroupRedPacketPermission(int $chatId, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        
        // 私聊直接拒绝
        if ($chatType === 'private') {
            $this->handlePrivateRedPacketAttempt($chatId, 'red_packet_operation', $debugFile);
            return false;
        }
        
        // 群组权限检查
        if (in_array($chatType, ['group', 'supergroup'])) {
            return $this->validateGroupPermission($chatId, $debugFile);
        }
        
        return false;
    }
    
    /**
     * 验证群组权限（机器人管理员等）
     */
    private function validateGroupPermission(int $chatId, string $debugFile): bool
    {
        try {
            $config = config('redpacket.command_restrictions', []);
            
            // 检查是否需要机器人管理员权限
            if ($config['require_bot_admin'] ?? true) {
                $group = TgCrowdList::where('crowd_id', (string)$chatId)
                                   ->where('is_active', 1)
                                   ->where('broadcast_enabled', 1)
                                   ->where('bot_status', 'administrator')
                                   ->where('del', 0)
                                   ->find();
                
                if (!$group) {
                    $this->log($debugFile, "❌ 群组权限验证失败 - ChatID: {$chatId}");
                    $this->sendGroupPermissionError($chatId, $debugFile);
                    return false;
                }
            }
            
            $this->log($debugFile, "✅ 群组权限验证通过 - ChatID: {$chatId}");
            return true;
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 群组权限验证异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 权限验证失败，请稍后重试", $debugFile);
            return false;
        }
    }
    
    /**
     * 验证群组操作权限（抢红包等）
     */
    private function validateGroupOperation(int $chatId, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        
        // 群组操作允许在群组和私聊中进行（查看红包详情等）
        if (in_array($chatType, ['group', 'supergroup', 'private'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取聊天类型
     */
    private function getChatType(int $chatId): string
    {
        // 优先使用设置的聊天上下文
        if ($this->chatContext && isset($this->chatContext['chat_type'])) {
            return $this->chatContext['chat_type'];
        }
        
        // 根据 chatId 判断类型
        if ($chatId > 0) {
            return 'private';
        } else {
            // 负数ID通常是群组，具体类型需要从数据库查询
            $group = TgCrowdList::where('crowd_id', (string)$chatId)->find();
            return $group ? 'group' : 'supergroup'; // 简化处理
        }
    }
    
    /**
     * 处理私聊红包尝试
     */
    private function handlePrivateRedPacketAttempt(int $chatId, string $command, string $debugFile): void
    {
        $this->log($debugFile, "🚫 私聊红包尝试被拒绝 - Command: {$command}");
        
        $message = "❌ *无法在私聊中发送红包*\n\n" .
                  "🧧 *红包功能说明：*\n" .
                  "• 红包命令只能在群组中使用\n" .
                  "• 发送的红包仅在当前群组有效\n" .
                  "• 请在群组中发送 `/red 100 10` 命令\n\n" .
                  "💡 *可用功能：*\n" .
                  "• 查看红包记录\n" .
                  "• 查看红包统计\n" .
                  "• 设置红包偏好";
        
        $keyboard = [
            [
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 发送群组权限错误消息
     */
    private function sendGroupPermissionError(int $chatId, string $debugFile): void
    {
        $message = "❌ *当前群组无法使用红包功能*\n\n" .
                  "🔍 *可能的原因：*\n" .
                  "• 机器人不是群组管理员\n" .
                  "• 群组未启用红包功能\n" .
                  "• 群组状态异常\n\n" .
                  "💡 *解决方法：*\n" .
                  "• 请联系群组管理员\n" .
                  "• 确保机器人具有管理员权限\n" .
                  "• 检查群组设置";
        
        $keyboard = [
            [
                ['text' => '🔄 重试', 'callback_data' => 'redpacket']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    // =================== 🔥 修改：红包处理逻辑 ===================
    
    /**
     * 处理红包命令解析 - 🔥 增强聊天上下文
     */
    private function handleRedPacketCommand(int $chatId, string $text, string $debugFile): void
    {
        // 🔥 使用 TelegramRedPacketService 解析命令（传递聊天上下文）
        $parsed = $this->redPacketService->parseRedPacketCommand($text, $this->chatContext);
        
        if (!$parsed) {
            $this->sendMessage($chatId, "❌ 不是有效的红包命令，请参考格式：/red 100 10 恭喜发财", $debugFile);
            return;
        }
        
        if (isset($parsed['error'])) {
            $this->sendMessage($chatId, "❌ " . $parsed['message'], $debugFile);
            return;
        }
        
        // 🔥 验证用户权限
        $permission = $this->redPacketService->validateUserRedPacketPermission($this->currentUser, $parsed['amount']);
        if (!$permission['valid']) {
            $this->sendMessage($chatId, "❌ " . $permission['message'], $debugFile);
            return;
        }
        
        // 显示红包确认信息
        $this->showRedPacketConfirmation($chatId, $parsed, $debugFile);
    }
    
    /**
     * 处理发送红包 - 🔥 限制到当前群组
     */
    private function processSendRedPacket(int $chatId, array $redPacketData, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🚀 开始发送红包到当前群组: " . json_encode($redPacketData));
            
            $chatType = $this->getChatType($chatId);
            
            // 准备红包数据 - 🔥 记录聊天上下文
            $packetData = [
                'total_amount' => $redPacketData['amount'],
                'total_count' => $redPacketData['count'],
                'title' => $redPacketData['title'],
                'packet_type' => RedPacket::TYPE_RANDOM,
                'sender_id' => $this->currentUser->id,
                'sender_tg_id' => $this->currentUser->tg_id,
                'chat_id' => (string)$chatId,        // 🔥 记录来源群组
                'chat_type' => $chatType,            // 🔥 记录聊天类型
            ];
            
            // 🔥 使用单群组发送模式
            $result = $this->redPacketService->sendRedPacketToCurrentGroup($packetData, $chatId);
            
            if ($result['code'] === 200) {
                $packetId = $result['data']['packet_id'] ?? '';
                
                $message = "🎉 *红包发送成功！*\n\n" .
                          "🧧 红包ID：`{$packetId}`\n" .
                          "💰 金额：`{$redPacketData['amount']} USDT`\n" .
                          "📦 个数：{$redPacketData['count']} 个\n" .
                          "🎯 发送范围：当前群组\n\n" .
                          "💡 群组成员现在可以抢红包了！";
                
                $keyboard = [
                    [
                        ['text' => '📊 查看详情', 'callback_data' => 'redpacket_detail_' . $packetId]
                    ],
                    [
                        ['text' => '🧧 再发一个', 'callback_data' => 'send_red_packet'],
                        ['text' => '🏠 主菜单', 'callback_data' => 'back_to_main']
                    ]
                ];
                
                $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
                
            } else {
                $this->sendMessage($chatId, "❌ 红包发送失败：" . ($result['msg'] ?? '未知错误'), $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包发送处理", $debugFile);
            $this->sendMessage($chatId, "❌ 红包发送失败，请稍后重试", $debugFile);
        }
    }
    
    // =================== 原有方法保持不变，只补充缺失的方法 ===================
    
    /**
     * 显示红包主菜单 - 🔥 根据聊天类型调整
     */
    private function showRedPacketMenu(int $chatId, string $debugFile): void
    {
        $stats = $this->getUserRedPacketStats();
        $chatType = $this->getChatType($chatId);
        
        $message = "🧧 *红包功能*\n\n" .
                  "📊 *我的红包统计*\n" .
                  "├ 发送红包：{$stats['sent_count']} 个\n" .
                  "├ 发送金额：{$stats['sent_amount']} USDT\n" .
                  "├ 抢到红包：{$stats['received_count']} 个\n" .
                  "├ 抢到金额：{$stats['received_amount']} USDT\n" .
                  "└ 手气最佳：{$stats['best_luck_count']} 次\n\n" .
                  "💰 当前余额：`{$this->currentUser->money_balance} USDT`\n\n";
        
        // 🔥 根据聊天类型显示不同的提示
        if ($chatType === 'private') {
            $message .= "💡 *使用说明：*\n" .
                       "• 红包发送需要在群组中进行\n" .
                       "• 可在此查看红包记录和统计\n\n";
        } else {
            $message .= "🎯 当前群组可以发送红包\n\n";
        }
        
        $message .= "🎯 选择操作：";
        
        $keyboard = [];
        
        // 🔥 根据聊天类型显示不同的按钮
        if ($chatType === 'private') {
            $keyboard[] = [
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ];
        } else {
            $keyboard[] = [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
            ];
        }
        
        $keyboard[] = [
            ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 显示发红包指南 - 🔥 增强群组提示
     */
    private function showSendRedPacketGuide(int $chatId, string $debugFile): void
    {
        $balance = $this->currentUser->money_balance;
        $config = config('redpacket.basic', []);
        $minAmount = $config['min_amount'] ?? 1.00;
        $maxAmount = $config['max_amount'] ?? 10000.00;
        $minCount = $config['min_count'] ?? 1;
        $maxCount = $config['max_count'] ?? 100;
        $chatType = $this->getChatType($chatId);
        
        $message = "🧧 *发红包指南*\n\n" .
                  "💰 当前余额：`{$balance} USDT`\n";
        
        // 🔥 根据聊天类型显示不同提示
        if ($chatType !== 'private') {
            $message .= "🎯 发送范围：仅当前群组\n";
        }
        
        $message .= "\n📝 *命令格式：*\n" .
                   "`/red <金额> <个数> [标题]`\n\n" .
                   "🌰 *使用示例：*\n" .
                   "• `/red 100 10` - 100USDT分10个\n" .
                   "• `/red 50 5 恭喜发财` - 带标题\n" .
                   "• `/hongbao 20 3 新年快乐`\n\n" .
                   "⚠️ *限制说明：*\n" .
                   "• 金额范围：{$minAmount} - {$maxAmount} USDT\n" .
                   "• 个数范围：{$minCount} - {$maxCount} 个\n" .
                   "• 单个最小：0.01 USDT\n\n" .
                   "💡 请在下方输入红包命令：";
        
        $keyboard = [
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
        
        // 设置用户状态为等待红包命令
        $this->setUserState($chatId, 'waiting_red_packet_command');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    // =================== 原有方法完全保持不变 ===================
    // 以下方法保持原样，包括：
    // - showRedPacketConfirmation
    // - handleGrabRedPacket  
    // - showGrabSuccessMessage
    // - handleRedPacketDetail
    // - showRedPacketDetailMessage
    // - handleRefreshRedPacket
    // - showRedPacketHistory
    // - isRedPacketCommand
    // - getUserRedPacketStats
    // - handleRedPacketTitle
    // - handleRedPacketConfirmation
    // - handleUnknownCommand
    // - handleUnknownCallback
    
    /**
     * 显示红包确认信息
     */
    private function showRedPacketConfirmation(int $chatId, array $redPacketData, string $debugFile): void
    {
        $amount = $redPacketData['amount'];
        $count = $redPacketData['count'];
        $title = $redPacketData['title'];
        $avgAmount = round($amount / $count, 2);
        $chatType = $this->getChatType($chatId);
        
        $message = "🧧 *确认发红包*\n\n" .
                  "🏷️ 标题：{$title}\n" .
                  "💰 总金额：`{$amount} USDT`\n" .
                  "📦 红包个数：{$count} 个\n" .
                  "💎 平均金额：`{$avgAmount} USDT`\n" .
                  "🎲 红包类型：拼手气红包\n";
        
        // 🔥 根据聊天类型显示发送范围
        if ($chatType !== 'private') {
            $message .= "🎯 发送范围：当前群组\n";
        }
        
        $message .= "\n💸 扣除余额：`{$amount} USDT`\n" .
                   "💰 剩余余额：`" . ($this->currentUser->money_balance - $amount) . " USDT`\n\n" .
                   "确认发送吗？";
        
        $keyboard = [
            [
                ['text' => '✅ 确认发送', 'callback_data' => 'confirm_send_redpacket'],
                ['text' => '❌ 取消', 'callback_data' => 'cancel_send_redpacket']
            ]
        ];
        
        // 保存红包数据到用户状态
        $this->setUserState($chatId, 'confirming_red_packet', [
            'redpacket_data' => $redPacketData
        ]);
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    // 其他所有原有方法完全保持不变...
    // 由于篇幅限制，这里不重复列出所有原有方法
    // 您可以保留原文件中的所有其他方法不变
    
    /**
     * 处理抢红包回调
     */
    private function handleGrabRedPacket(string $callbackData, int $chatId, string $debugFile): void
    {
        // 提取红包ID
        $packetId = str_replace('grab_redpacket_', '', $callbackData);
        
        $this->log($debugFile, "🎯 用户 {$this->currentUser->id} 尝试抢红包: {$packetId}");
        
        // 构造用户数据
        $from = [
            'id' => $this->currentUser->tg_id,
            'username' => $this->currentUser->tg_username,
            'first_name' => $this->currentUser->tg_first_name,
            'last_name' => $this->currentUser->tg_last_name,
        ];
        
        // 🔥 使用带并发控制的抢红包方法
        $result = $this->redPacketService->grabRedPacketWithLock(
            $packetId,
            $this->currentUser->tg_id,
            $from,
            $chatId
        );
        
        $this->log($debugFile, "抢红包结果: " . json_encode($result));
        
        // 响应用户（通过 BaseTelegramController 的方法统一处理）
        if ($result['code'] === 200) {
            // 成功抢到红包，显示详细信息
            $this->showGrabSuccessMessage($chatId, $result, $debugFile);
        } else {
            // 抢红包失败，显示错误信息
            $this->sendMessage($chatId, $result['msg'], $debugFile);
        }
    }
    
    /**
     * 显示抢红包成功消息
     */
    private function showGrabSuccessMessage(int $chatId, array $result, string $debugFile): void
    {
        $amount = $result['data']['amount'] ?? 0;
        $grabOrder = $result['data']['grab_order'] ?? 0;
        $isCompleted = $result['data']['is_completed'] ?? false;
        
        $message = "🎉 *恭喜抢到红包！*\n\n" .
                  "💰 金额：`{$amount} USDT`\n" .
                  "🏆 第 {$grabOrder} 个抢到\n" .
                  "💎 当前余额：`{$this->currentUser->money_balance} USDT`\n\n";
        
        if ($isCompleted) {
            $message .= "🎊 红包已被抢完！\n";
        }
        
        $message .= "💡 红包金额已自动加入您的余额";
        
        $keyboard = [
            [
                ['text' => '💰 查看余额', 'callback_data' => 'check_balance'],
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 检查是否是红包命令
     */
    private function isRedPacketCommand(string $text): bool
    {
        $commands = ['/red', '/hongbao', '/hb'];
        
        foreach ($commands as $command) {
            if (stripos(trim($text), $command) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取用户红包统计
     */
    private function getUserRedPacketStats(): array
    {
        if (!$this->currentUser) {
            return [
                'sent_count' => 0,
                'sent_amount' => 0,
                'received_count' => 0,
                'received_amount' => 0,
                'best_luck_count' => 0,
            ];
        }
        
        return RedPacket::getUserStats($this->currentUser->id);
    }
    
    /**
     * 处理红包标题输入
     */
    private function handleRedPacketTitle(int $chatId, string $text, string $debugFile): void
    {
        // 获取之前的红包数据
        $userState = $this->getUserState($chatId);
        $redPacketData = $userState['data']['redpacket_data'] ?? null;
        
        if (!$redPacketData) {
            $this->sendMessage($chatId, "❌ 红包数据丢失，请重新开始", $debugFile);
            $this->clearUserState($chatId);
            return;
        }
        
        // 更新标题
        $redPacketData['title'] = trim($text);
        
        // 显示确认信息
        $this->showRedPacketConfirmation($chatId, $redPacketData, $debugFile);
    }
    
    /**
     * 处理红包确认
     */
    private function handleRedPacketConfirmation(int $chatId, string $text, string $debugFile): void
    {
        if (trim(strtolower($text)) === 'yes' || trim($text) === '确认') {
            // 用户确认发送
            $userState = $this->getUserState($chatId);
            $redPacketData = $userState['data']['redpacket_data'] ?? null;
            
            if ($redPacketData) {
                $this->processSendRedPacket($chatId, $redPacketData, $debugFile);
            } else {
                $this->sendMessage($chatId, "❌ 红包数据丢失，请重新开始", $debugFile);
            }
        } else {
            $this->sendMessage($chatId, "❌ 红包发送已取消", $debugFile);
        }
        
        // 清除状态
        $this->clearUserState($chatId);
    }
    
    /**
     * 处理红包详情查询
     */
    private function handleRedPacketDetail(string $callbackData, int $chatId, string $debugFile): void
    {
        $packetId = str_replace('redpacket_detail_', '', $callbackData);
        
        $this->log($debugFile, "📊 查询红包详情: {$packetId}");
        
        try {
            $redPacket = RedPacket::findByPacketId($packetId);
            
            if (!$redPacket) {
                $this->sendMessage($chatId, "❌ 红包不存在或已失效", $debugFile);
                return;
            }
            
            $this->showRedPacketDetailMessage($chatId, $redPacket, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包详情查询", $debugFile);
            $this->sendMessage($chatId, "❌ 查询失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 显示红包详情消息
     */
    private function showRedPacketDetailMessage(int $chatId, RedPacket $redPacket, string $debugFile): void
    {
        $senderName = $redPacket->sender->tg_first_name ?? $redPacket->sender->user_name ?? '匿名用户';
        $records = $redPacket->records()->with('user')->select();
        
        $message = "📊 *红包详情*\n\n" .
                  "🧧 标题：{$redPacket->title}\n" .
                  "💰 总金额：`{$redPacket->total_amount} USDT`\n" .
                  "📦 总个数：{$redPacket->total_count} 个\n" .
                  "👤 发送者：{$senderName}\n" .
                  "📅 创建时间：" . date('m-d H:i', strtotime($redPacket->created_at)) . "\n" .
                  "📊 状态：{$redPacket->status_text}\n";
        
        if ($redPacket->status === RedPacket::STATUS_ACTIVE) {
            $message .= "💎 剩余：{$redPacket->remain_count}个 | {$redPacket->remain_amount} USDT\n";
            $message .= "⏰ 剩余时间：{$redPacket->remain_time}\n";
        }
        
        // 显示领取记录
        if (!$records->isEmpty()) {
            $message .= "\n📋 *领取记录*\n";
            foreach ($records as $index => $record) {
                $userName = $record->user->tg_first_name ?? $record->user->user_name ?? '匿名用户';
                $emoji = $record->is_best ? '🏆' : '💰';
                $time = date('H:i', strtotime($record->created_at));
                $message .= "{$emoji} {$userName} - `{$record->amount} USDT` ({$time})\n";
                
                // 限制显示条数，避免消息太长
                if ($index >= 9) {
                    $remaining = count($records) - 10;
                    if ($remaining > 0) {
                        $message .= "... 还有 {$remaining} 条记录\n";
                    }
                    break;
                }
            }
        }
        
        $keyboard = [
            [
                ['text' => '🔄 刷新', 'callback_data' => 'refresh_redpacket_' . $redPacket->packet_id]
            ],
            [
                ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                ['text' => '🏠 主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理刷新红包状态
     */
    private function handleRefreshRedPacket(string $callbackData, int $chatId, string $debugFile): void
    {
        $packetId = str_replace('refresh_redpacket_', '', $callbackData);
        
        $this->log($debugFile, "🔄 刷新红包状态: {$packetId}");
        
        // 重新查询并显示详情
        $this->handleRedPacketDetail('redpacket_detail_' . $packetId, $chatId, $debugFile);
    }
    
    /**
     * 显示红包历史记录
     */
    private function showRedPacketHistory(int $chatId, string $debugFile): void
    {
        try {
            // 获取用户最近的红包记录
            $sentPackets = RedPacket::where('sender_id', $this->currentUser->id)
                                   ->order('created_at', 'desc')
                                   ->limit(5)
                                   ->select();
            
            $receivedRecords = \app\model\RedPacketRecord::where('user_id', $this->currentUser->id)
                                                        ->with('redPacket')
                                                        ->order('created_at', 'desc')
                                                        ->limit(5)
                                                        ->select();
            
            $message = "📊 *红包记录*\n\n";
            
            // 发送的红包
            if (!$sentPackets->isEmpty()) {
                $message .= "📤 *发送的红包*\n";
                foreach ($sentPackets as $packet) {
                    $date = date('m-d H:i', strtotime($packet->created_at));
                    $message .= "• {$packet->title} - `{$packet->total_amount} USDT` ({$date})\n";
                }
                $message .= "\n";
            }
            
            // 抢到的红包
            if (!$receivedRecords->isEmpty()) {
                $message .= "📥 *抢到的红包*\n";
                foreach ($receivedRecords as $record) {
                    $date = date('m-d H:i', strtotime($record->created_at));
                    $emoji = $record->is_best ? '🏆' : '💰';
                    $title = $record->redPacket->title ?? '红包';
                    $message .= "{$emoji} {$title} - `{$record->amount} USDT` ({$date})\n";
                }
            }
            
            if ($sentPackets->isEmpty() && $receivedRecords->isEmpty()) {
                $message .= "暂无红包记录\n\n";
                $message .= "💡 快去发送或抢取你的第一个红包吧！";
            }
            
            $keyboard = [
                [
                    ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet']
                ],
                [
                    ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "红包历史查询", $debugFile);
            $this->sendMessage($chatId, "❌ 查询失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 处理未知命令
     */
    private function handleUnknownCommand(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "❌ 未知红包命令: {$command}");
        
        $message = "❓ *未知的红包命令*\n\n" .
                  "请使用以下有效命令：\n" .
                  "• `/red 100 10` - 发红包\n" .
                  "• `/hongbao 50 5 恭喜发财` - 带标题红包\n\n" .
                  "💡 或使用菜单按钮操作";
        
        $keyboard = [
            [
                ['text' => '🧧 发红包指南', 'callback_data' => 'send_red_packet']
            ],
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理未知回调
     */
    private function handleUnknownCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "❌ 未知红包回调: {$callbackData}");
        
        $message = "❌ *未知操作*\n\n请使用菜单重新操作";
        
        $keyboard = [
            [
                ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
            ]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
}