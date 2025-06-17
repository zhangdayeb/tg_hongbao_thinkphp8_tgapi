<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\TelegramRedPacketService;
use app\model\User;
use app\trait\RedPacketValidationTrait;
use app\trait\RedPacketMessageTrait;
use app\trait\RedPacketDuplicateControlTrait;
use app\handler\RedPacketCommandHandler;
use app\handler\RedPacketCallbackHandler;
use app\handler\RedPacketMessageSender;
use think\facade\Log;

/**
 * 红包控制器 - 修复版本
 * 职责：协调各个组件，处理红包相关的所有操作
 */
class RedPacketController extends BaseTelegramController
{
    use RedPacketValidationTrait;
    use RedPacketMessageTrait;
    use RedPacketDuplicateControlTrait;

    private TelegramRedPacketService $redPacketService;
    private RedPacketCommandHandler $commandHandler;
    private RedPacketCallbackHandler $callbackHandler;
    private RedPacketMessageSender $messageSender;
    
    private ?User $currentUser = null;
    private ?array $chatContext = null;
    private ?string $originalMessage = null;
    
    public function __construct()
    {
        try {
            parent::__construct();
            
            $this->log('debug', "🔧 RedPacketController 开始初始化");
            
            $this->redPacketService = new TelegramRedPacketService();
            $this->log('debug', "✅ TelegramRedPacketService 初始化完成");
            
            $this->messageSender = new RedPacketMessageSender();
            $this->log('debug', "✅ RedPacketMessageSender 初始化完成");
            
            // 创建Handler
            $this->commandHandler = new RedPacketCommandHandler($this->redPacketService);
            $this->log('debug', "✅ RedPacketCommandHandler 初始化完成");
            
            $this->callbackHandler = new RedPacketCallbackHandler($this->redPacketService);
            $this->log('debug', "✅ RedPacketCallbackHandler 初始化完成");
            
            // 设置桥接引用
            $this->commandHandler->setControllerBridge($this);
            $this->callbackHandler->setControllerBridge($this);
            $this->log('debug', "✅ 桥接引用设置完成");
            
            $this->log('debug', "🎉 RedPacketController 初始化完成");
            
        } catch (\Exception $e) {
            $this->log('error', "❌ RedPacketController 初始化失败: " . $e->getMessage());
            $this->log('error', "错误堆栈: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    // =================== 核心接口方法 ===================
    
    /**
     * 设置当前用户（由CommandDispatcher调用）
     */
    public function setUser(User $user): void
    {
        try {
            $this->log('debug', "🔧 设置当前用户: ID={$user->id}, TG_ID={$user->tg_id}");
            
            $this->currentUser = $user;
            $this->commandHandler->setUser($user);
            $this->callbackHandler->setUser($user);
            $this->messageSender->setUser($user);
            
            $this->log('debug', "✅ 用户设置完成");
            
        } catch (\Exception $e) {
            $this->log('error', "❌ 设置用户失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 设置聊天上下文
     */
    public function setChatContext(array $chatContext): void
    {
        try {
            $this->log('debug', "🔧 设置聊天上下文: " . json_encode($chatContext));
            
            $this->chatContext = $chatContext;
            $this->commandHandler->setChatContext($chatContext);
            $this->callbackHandler->setChatContext($chatContext);
            
            $this->log('debug', "✅ 聊天上下文设置完成");
            
        } catch (\Exception $e) {
            $this->log('error', "❌ 设置聊天上下文失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 🔧 修复：处理红包相关命令 - 主入口
     */
    public function handle(string $command, int $chatId, string $debugFile, ?string $fullMessage = null): void
    {
        try {
            $this->log($debugFile, "🧧 RedPacketController 处理命令: {$command}");
            $this->log($debugFile, "参数 - ChatID: {$chatId}, FullMessage: " . ($fullMessage ?? 'null'));
            
            $this->originalMessage = $fullMessage;
            
            if (!$this->currentUser) {
                $this->log($debugFile, "❌ 用户对象未设置");
                $this->sendMessage($chatId, "❌ 用户信息错误，请重新开始", $debugFile);
                return;
            }
            
            $this->log($debugFile, "✅ 用户对象检查通过 - ID: {$this->currentUser->id}");
            
            // 🔧 修复：设置聊天上下文
            $this->setChatContext([
                'chat_id' => $chatId,
                'chat_type' => $this->getChatType($chatId),
                'message_id' => 0,
            ]);
            
            // 验证聊天类型权限
            $this->log($debugFile, "🔍 开始验证聊天类型权限");
            if (!$this->validateChatTypePermission($chatId, $command, $debugFile)) {
                $this->log($debugFile, "❌ 聊天类型权限验证失败");
                return;
            }
            $this->log($debugFile, "✅ 聊天类型权限验证通过");
            
            // 🔧 修复：根据命令类型决定处理方式
            if ($this->isCompleteRedPacketCommand($fullMessage)) {
                $this->log($debugFile, "✅ 检测到完整红包命令，直接处理");
                $this->handleCompleteRedPacketCommand($chatId, $fullMessage, $debugFile);
            } else {
                $this->log($debugFile, "🔄 委托给命令处理器");
                $this->commandHandler->handle($command, $chatId, $debugFile, $fullMessage);
            }
            
            $this->log($debugFile, "✅ 命令处理器执行完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ RedPacketController::handle 异常: " . $e->getMessage());
            $this->log($debugFile, "异常堆栈: " . $e->getTraceAsString());
            $this->handleException($e, "红包命令处理", $debugFile);
            $this->sendMessage($chatId, "❌ 处理失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 🔧 修复：处理红包相关回调
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
            
            // 🔧 修复：设置聊天上下文
            $this->setChatContext([
                'chat_id' => $chatId,
                'chat_type' => $this->getChatType($chatId),
                'message_id' => 0,
            ]);
            
            // 验证权限（特殊回调）
            if ($this->isGroupOperationCallback($callbackData)) {
                if (!$this->validateGroupOperation($chatId, $debugFile)) {
                    return;
                }
            }
            
            // 委托给回调处理器
            $this->callbackHandler->handle($callbackData, $chatId, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 回调处理异常: " . $e->getMessage());
            $this->handleException($e, "红包回调处理", $debugFile);
            $this->sendMessage($chatId, "❌ 操作失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 🔧 修复：处理文本输入（红包相关状态）
     */
    public function handleTextInput(int $chatId, string $text, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🧧 RedPacketController 处理文本输入: {$text}");
            
            if (!$this->currentUser) {
                $this->log($debugFile, "❌ 用户对象未设置");
                return;
            }
            
            // 🔧 修复：设置聊天上下文
            $this->setChatContext([
                'chat_id' => $chatId,
                'chat_type' => $this->getChatType($chatId),
                'message_id' => 0,
            ]);
            
            $userState = $this->getUserState($chatId);
            $currentState = $userState['state'] ?? 'idle';
            
            $this->log($debugFile, "当前用户状态: {$currentState}");
            
            // 🔧 修复：处理不同状态的文本输入
            switch ($currentState) {
                case 'waiting_redpacket_amount':
                    $this->handleAmountInput($chatId, $text, $debugFile);
                    break;
                    
                case 'waiting_redpacket_count':
                    $this->handleCountInput($chatId, $text, $debugFile);
                    break;
                    
                case 'waiting_redpacket_title':
                    $this->handleTitleInput($chatId, $text, $debugFile);
                    break;
                    
                default:
                    // 🔧 修复：检查是否是红包命令
                    if ($this->isCompleteRedPacketCommand($text)) {
                        $this->handleCompleteRedPacketCommand($chatId, $text, $debugFile);
                    } else {
                        $this->sendMessage($chatId, "❓ 请使用 /red <金额> <个数> [标题] 格式发红包", $debugFile);
                    }
                    break;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 文本输入处理异常: " . $e->getMessage());
            $this->handleException($e, "红包文本输入处理", $debugFile);
            $this->sendMessage($chatId, "❌ 输入处理失败，请重试", $debugFile);
        }
    }
    
    // =================== 桥接方法（供Handler调用） ===================
    
    /**
     * 桥接方法：显示红包菜单
     */
    public function bridgeShowRedPacketMenu(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🔗 桥接显示红包菜单");
            
            $balance = $this->currentUser->money_balance ?? 0;
            
            $message = "🧧 *红包中心*\n\n";
            $message .= "💰 当前余额：" . number_format($balance, 2) . " USDT\n\n";
            $message .= "📝 *发红包格式*：\n";
            $message .= "`/red <金额> <个数> [标题]`\n\n";
            $message .= "🎯 *示例*：\n";
            $message .= "• `/red 100 10` - 发100U红包，10个\n";
            $message .= "• `/red 50 5 新年快乐` - 带标题的红包";
            
            $keyboard = [
                [
                    ['text' => '🧧 发红包', 'callback_data' => 'send_red_packet'],
                    ['text' => '📊 红包记录', 'callback_data' => 'red_packet_history']
                ],
                [
                    ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示红包菜单失败: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 红包菜单加载失败", $debugFile);
        }
    }
    
    /**
     * 桥接方法：显示发红包指南
     */
    public function bridgeShowSendRedPacketGuide(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🔗 桥接显示发红包指南");
            
            $message = "🧧 *发红包指南*\n\n";
            $message .= "💡 *使用方法*：\n";
            $message .= "`/red <金额> <个数> [标题]`\n\n";
            $message .= "📋 *参数说明*：\n";
            $message .= "• 金额：红包总金额（USDT）\n";
            $message .= "• 个数：分成几个红包\n";
            $message .= "• 标题：红包祝福语（可选）\n\n";
            $message .= "🎯 *使用示例*：\n";
            $message .= "• `/red 100 10` \n";
            $message .= "• `/red 50 5 恭喜发财`\n";
            $message .= "• `/red 200 20 新年快乐`\n\n";
            $message .= "⚠️ *注意事项*：\n";
            $message .= "• 最小金额：1 USDT\n";
            $message .= "• 最大个数：100个\n";
            $message .= "• 余额充足才能发送";
            
            $keyboard = [
                [
                    ['text' => '🔙 返回红包菜单', 'callback_data' => 'redpacket']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示发红包指南失败: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 指南加载失败", $debugFile);
        }
    }
    
    /**
     * 🔧 修复：桥接方法：创建红包
     */
    public function bridgeCreateRedPacket(int $chatId, array $parsed, string $debugFile): bool
    {
        try {
            $this->log($debugFile, "🔗 桥接创建红包");
            
            // 防重复检查
            if ($this->checkRedPacketSendDuplicate($this->currentUser->id, $parsed)) {
                $this->log($debugFile, "❌ 检测到重复发送红包");
                $this->sendMessage($chatId, "❌ 请勿重复发送相同的红包", $debugFile);
                return false;
            }
            
            // 创建红包
            $result = $this->redPacketService->createRedPacket($this->currentUser, $parsed);
            
            if ($result['success']) {
                $this->log($debugFile, "✅ 红包创建成功: " . $result['packet_id']);
                
                // 发送创建成功通知
                $this->sendRedPacketCreatedNotification($result['packet_id'], $parsed, $debugFile);
                
                // 清除发送锁定
                $this->clearRedPacketSendLock($this->currentUser->id);
                $this->log($debugFile, "🎉 红包创建流程完成");
                return true;
                
            } else {
                $this->log($debugFile, "❌ 红包创建失败: " . $result['msg']);
                $this->sendMessage($chatId, "❌ 红包发送失败：" . $result['msg'], $debugFile);
                $this->clearRedPacketSendLock($this->currentUser->id);
                return false;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 创建红包异常: " . $e->getMessage());
            $this->log($debugFile, "异常堆栈: " . $e->getTraceAsString());
            $this->handleException($e, "红包创建", $debugFile);
            $this->sendMessage($chatId, "❌ 红包创建失败：" . $e->getMessage(), $debugFile);
            
            // 确保清除锁定
            if ($this->currentUser) {
                $this->clearRedPacketSendLock($this->currentUser->id);
            }
            return false;
        }
    }
    
    /**
     * 桥接方法：抢红包
     */
    public function bridgeGrabRedPacket(string $packetId, int $chatId, string $debugFile): bool
    {
        try {
            $this->log($debugFile, "🎯 桥接抢红包: {$packetId}");
            
            // 防重复检查
            if ($this->checkGrabRedPacketDuplicate($packetId, $this->currentUser->id)) {
                $this->log($debugFile, "❌ 检测到重复抢红包");
                return false; // 静默处理
            }
            
            // 抢红包
            $result = $this->redPacketService->grabRedPacket($packetId, $this->currentUser);
            
            if ($result['success']) {
                $this->log($debugFile, "✅ 抢红包成功: " . ($result['amount'] ?? 0));
                
                // 发送私聊通知（如果需要）
                if ($result['amount'] > 0) {
                    $this->sendGrabSuccessNotification($result, $debugFile);
                }
                
                $this->clearGrabRedPacketLock($packetId, $this->currentUser->id);
                return true;
                
            } else {
                $this->log($debugFile, "❌ 抢红包失败: " . $result['msg']);
                $this->clearGrabRedPacketLock($packetId, $this->currentUser->id);
                return false;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 抢红包异常: " . $e->getMessage());
            $this->clearGrabRedPacketLock($packetId, $this->currentUser->id);
            return false;
        }
    }
    
    /**
     * 桥接方法：显示红包历史
     */
    public function bridgeShowRedPacketHistory(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🔗 桥接显示红包历史");
            
            $history = $this->redPacketService->getUserRedPacketHistory($this->currentUser->id);
            $message = $this->buildRedPacketHistoryMessage($history);
            $keyboard = $this->buildRedPacketHistoryKeyboard();
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示红包历史失败: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 历史记录加载失败", $debugFile);
        }
    }
    
    // =================== 私有辅助方法 ===================
    
    /**
     * 🆕 检查是否是完整的红包命令
     */
    private function isCompleteRedPacketCommand(?string $message): bool
    {
        if (empty($message)) {
            return false;
        }
        
        $pattern = '/^\/?(red|hb|hongbao)\s+(\d+(?:\.\d+)?)\s+(\d+)(?:\s+(.+))?/i';
        return preg_match($pattern, trim($message)) > 0;
    }
    
    /**
     * 🆕 处理完整的红包命令
     */
    private function handleCompleteRedPacketCommand(int $chatId, string $message, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🎯 处理完整红包命令: {$message}");
            
            $parsed = $this->redPacketService->parseRedPacketCommand($message, $this->chatContext);
            
            if ($parsed && !isset($parsed['error'])) {
                // 验证用户权限
                $permission = $this->redPacketService->validateUserRedPacketPermission($this->currentUser, $parsed['amount']);
                if (!$permission['valid']) {
                    $this->sendMessage($chatId, "❌ " . $permission['message'], $debugFile);
                    return;
                }
                
                // 创建红包
                $this->bridgeCreateRedPacket($chatId, $parsed, $debugFile);
            } else {
                $errorMsg = $parsed['message'] ?? '命令格式错误';
                $this->sendMessage($chatId, "❌ " . $errorMsg, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理完整红包命令异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 红包命令处理失败", $debugFile);
        }
    }
    
    /**
     * 🆕 处理金额输入
     */
    private function handleAmountInput(int $chatId, string $text, string $debugFile): void
    {
        try {
            $amount = (float)$text;
            if ($amount <= 0) {
                $this->sendMessage($chatId, "❌ 请输入有效的金额", $debugFile);
                return;
            }
            
            // 保存金额，要求输入个数
            $this->setUserState($chatId, 'waiting_redpacket_count', ['amount' => $amount]);
            $this->sendMessage($chatId, "💰 金额：{$amount} USDT\n请输入红包个数：", $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理金额输入异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 金额格式错误", $debugFile);
        }
    }
    
    /**
     * 🆕 处理个数输入
     */
    private function handleCountInput(int $chatId, string $text, string $debugFile): void
    {
        try {
            $count = (int)$text;
            if ($count <= 0) {
                $this->sendMessage($chatId, "❌ 请输入有效的个数", $debugFile);
                return;
            }
            
            $userState = $this->getUserState($chatId);
            $amount = $userState['data']['amount'] ?? 0;
            
            // 保存个数，要求输入标题
            $this->setUserState($chatId, 'waiting_redpacket_title', [
                'amount' => $amount,
                'count' => $count
            ]);
            $this->sendMessage($chatId, "🎁 个数：{$count} 个\n请输入红包标题（或发送 '默认' 使用默认标题）：", $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理个数输入异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 个数格式错误", $debugFile);
        }
    }
    
    /**
     * 🆕 处理标题输入
     */
    private function handleTitleInput(int $chatId, string $text, string $debugFile): void
    {
        try {
            $userState = $this->getUserState($chatId);
            $amount = $userState['data']['amount'] ?? 0;
            $count = $userState['data']['count'] ?? 0;
            $title = $text === '默认' ? '恭喜发财，大吉大利' : $text;
            
            // 构造红包数据
            $parsed = [
                'amount' => $amount,
                'count' => $count,
                'title' => $title,
                'type' => 'random',
                'chat_context' => $this->chatContext
            ];
            
            // 创建红包
            $this->bridgeCreateRedPacket($chatId, $parsed, $debugFile);
            
            // 清除状态
            $this->clearUserState($chatId);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理标题输入异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 标题处理失败", $debugFile);
        }
    }
    
    /**
     * 🆕 发送红包创建成功通知
     */
    private function sendRedPacketCreatedNotification(string $packetId, array $parsed, string $debugFile): void
    {
        try {
            $message = "🎉 *红包发送成功！*\n\n";
            $message .= "💰 总金额：{$parsed['amount']} USDT\n";
            $message .= "📦 红包个数：{$parsed['count']} 个\n";
            $message .= "🏷️ 标题：{$parsed['title']}\n";
            $message .= "🆔 红包ID：{$packetId}\n\n";
            $message .= "🎊 红包已发送到群内，快去看看吧！";
            
            $this->sendMessage($this->currentUser->tg_id, $message, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送创建通知失败: " . $e->getMessage());
        }
    }
    
    /**
     * 🆕 发送抢红包成功通知
     */
    private function sendGrabSuccessNotification(array $result, string $debugFile): void
    {
        try {
            $amount = $result['amount'] ?? 0;
            $grabOrder = $result['grab_order'] ?? 0;
            $isBest = $result['is_best'] ?? false;
            
            $message = "🎉 *恭喜你抢到红包！*\n\n";
            $message .= "💰 抢到金额：{$amount} USDT\n";
            $message .= "📍 抢包顺序：第{$grabOrder}个\n";
            
            if ($isBest) {
                $message .= "👑 手气最佳！\n";
            }
            
            $message .= "\n💼 当前余额：" . number_format($this->currentUser->money_balance ?? 0, 2) . " USDT";
            
            $this->sendMessage($this->currentUser->tg_id, $message, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送抢包通知失败: " . $e->getMessage());
        }
    }
    
    /**
     * 验证聊天类型权限
     */
    private function validateChatTypePermission(int $chatId, string $command, string $debugFile): bool
    {
        // 获取聊天类型
        $chatType = $this->getChatType($chatId);
        
        // 红包命令只能在群组中使用
        if (in_array($command, ['red', 'hongbao', 'hb']) && $chatType === 'private') {
            $this->sendMessage($chatId, "❌ 红包功能只能在群组中使用", $debugFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证群组操作
     */
    private function validateGroupOperation(int $chatId, string $debugFile): bool
    {
        // 检查是否在群组中
        $chatType = $this->getChatType($chatId);
        if ($chatType === 'private') {
            $this->sendMessage($chatId, "❌ 此操作只能在群组中进行", $debugFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查是否是群组操作回调
     */
    private function isGroupOperationCallback(string $callbackData): bool
    {
        $groupCallbacks = ['grab_redpacket_', 'refresh_redpacket_'];
        
        foreach ($groupCallbacks as $callback) {
            if (str_starts_with($callbackData, $callback)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 获取聊天类型
     */
    private function getChatType(int $chatId): string
    {
        if ($chatId > 0) {
            return 'private';
        } else {
            return 'group'; // 简化处理，负数ID都当作群组
        }
    }
}