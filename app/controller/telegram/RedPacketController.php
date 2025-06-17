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

/**
 * 红包控制器 - 调试版本（添加详细日志）
 * 职责：协调各个组件，处理到数据库写入完成，不负责群内消息发送
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
            
            // 添加调试日志
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
    
    /**
     * 设置当前用户（由CommandDispatcher调用）
     */
    public function setUser(User $user): void
    {
        try {
            $this->log('debug', "🔧 设置当前用户: ID={$user->id}, TG_ID={$user->user_id}");
            
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
     * 处理红包相关命令 - 主入口
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
            
            // 验证聊天类型权限
            $this->log($debugFile, "🔍 开始验证聊天类型权限");
            if (!$this->validateChatTypePermission($chatId, $command, $debugFile)) {
                $this->log($debugFile, "❌ 聊天类型权限验证失败");
                return;
            }
            $this->log($debugFile, "✅ 聊天类型权限验证通过");
            
            // 委托给命令处理器
            $this->log($debugFile, "🔄 委托给命令处理器");
            $this->commandHandler->handle($command, $chatId, $debugFile, $fullMessage);
            $this->log($debugFile, "✅ 命令处理器执行完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ RedPacketController::handle 异常: " . $e->getMessage());
            $this->log($debugFile, "异常堆栈: " . $e->getTraceAsString());
            $this->handleException($e, "红包命令处理", $debugFile);
            $this->sendMessage($chatId, "❌ 处理失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 处理红包相关回调
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
     * 处理文本输入（红包相关状态）
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
            
            $this->log($debugFile, "当前用户状态: {$currentState}");
            
            switch ($currentState) {
                case 'waiting_red_packet_command':
                    $this->commandHandler->handleRedPacketCommand($chatId, $text, $debugFile);
                    break;
                    
                case 'waiting_red_packet_amount':
                    $this->commandHandler->handleRedPacketAmount($chatId, $text, $debugFile);
                    break;
                    
                case 'waiting_red_packet_count':
                    $this->commandHandler->handleRedPacketCount($chatId, $text, $debugFile);
                    break;
                    
                case 'waiting_red_packet_title':
                    $this->commandHandler->handleRedPacketTitle($chatId, $text, $debugFile);
                    break;
                    
                case 'confirming_red_packet':
                    $this->commandHandler->handleRedPacketConfirmation($chatId, $text, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "无法处理的状态: {$currentState}");
                    break;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 文本输入处理异常: " . $e->getMessage());
            $this->handleException($e, "红包文本输入处理", $debugFile);
        }
    }
    
    // =================== 桥接方法（供Handler调用）===================
    
    /**
     * 桥接方法：显示红包主菜单
     */
    public function bridgeShowRedPacketMenu(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🔧 桥接显示红包主菜单");
            
            $message = $this->buildRedPacketMenuMessage();
            $keyboard = $this->buildRedPacketMenuKeyboard($chatId);
            $this->messageSender->sendWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
            $this->log($debugFile, "✅ 红包主菜单显示完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示红包菜单异常: " . $e->getMessage());
            $this->handleException($e, "显示红包菜单", $debugFile);
            $this->sendMessage($chatId, "❌ 菜单加载失败", $debugFile);
        }
    }
    
    /**
     * 桥接方法：显示发红包指南
     */
    public function bridgeShowSendRedPacketGuide(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "🔧 桥接显示发红包指南");
            
            // 验证群组红包权限
            if (!$this->validateGroupRedPacketPermission($chatId, $debugFile)) {
                return;
            }
            
            $message = $this->buildSendRedPacketGuideMessage();
            $keyboard = $this->buildSendRedPacketGuideKeyboard();
            $this->messageSender->sendWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
            $this->log($debugFile, "✅ 发红包指南显示完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示发红包指南异常: " . $e->getMessage());
            $this->handleException($e, "显示发红包指南", $debugFile);
            $this->sendMessage($chatId, "❌ 指南加载失败", $debugFile);
        }
    }
    
    /**
     * 桥接方法：创建红包（简化版 - 只到数据库写入）
     */
    public function bridgeCreateRedPacket(int $chatId, array $parsed, string $debugFile): bool
    {
        try {
            $this->log($debugFile, "🎯 桥接创建红包（仅数据库操作）");
            $this->log($debugFile, "红包参数: " . json_encode($parsed));
            
            // 防重复检查
            if ($this->checkRedPacketSendDuplicate($this->currentUser->id, $parsed)) {
                $this->log($debugFile, "❌ 检测到重复发送");
                $this->sendMessage($chatId, "❌ 请不要重复发送相同的红包", $debugFile);
                return false;
            }
            $this->log($debugFile, "✅ 重复检查通过");
            
            // 创建红包（仅数据库操作，不发送群内消息）
            $this->log($debugFile, "🔄 开始调用 redPacketService->createRedPacket");
            $result = $this->redPacketService->createRedPacket(
                $this->currentUser,
                $parsed['amount'],
                $parsed['count'],
                $parsed['title'],
                $this->chatContext
            );
            $this->log($debugFile, "✅ redPacketService->createRedPacket 调用完成");
            $this->log($debugFile, "创建结果: " . json_encode($result));
            
            if ($result['success']) {
                $this->log($debugFile, "✅ 红包数据库创建成功: " . $result['packet_id']);
                
                // 发送友好的等待提示给用户（私聊）
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
            $this->handleException($e, "抢红包处理", $debugFile);
            $this->clearGrabRedPacketLock($packetId, $this->currentUser->id);
            return false;
        }
    }
    
    /**
     * 桥接方法：获取红包历史
     */
    public function bridgeShowRedPacketHistory(int $chatId, string $debugFile): void
    {
        try {
            $history = $this->redPacketService->getUserRedPacketHistory($this->currentUser->id);
            $message = $this->buildRedPacketHistoryMessage($history);
            $keyboard = $this->buildRedPacketHistoryKeyboard();
            
            $this->messageSender->sendWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "显示红包历史", $debugFile);
            $this->sendMessage($chatId, "❌ 历史记录加载失败", $debugFile);
        }
    }
    
    /**
     * 桥接方法：发送简单消息
     */
    public function bridgeSendMessage(int $chatId, string $message, string $debugFile): void
    {
        $this->messageSender->send($chatId, $message, $debugFile);
    }
    
    // =================== 内部方法 ===================
    
    /**
     * 发送红包创建成功通知（群内提示）
     * 
     * @param string|int $packetId 红包ID（支持int和string类型）
     * @param array $parsed 解析后的红包数据
     * @param string $debugFile 调试日志文件
     */
    private function sendRedPacketCreatedNotification($packetId, array $parsed, string $debugFile): void
    {
        try {
            // 确保 packetId 是字符串类型，修正类型错误
            $packetIdStr = (string)$packetId;
            
            $this->log($debugFile, "📤 发送红包创建群内提示: {$packetIdStr}");
            
            // 获取当前聊天ID（应该是群组）
            $chatId = $this->chatContext['chat_id'] ?? 0;
            if ($chatId == 0) {
                $this->log($debugFile, "❌ 无效的聊天ID，跳过群内通知");
                return;
            }
            
            // 构建群内提示消息
            $message = "✅ *红包创建成功*\n\n" .
                    "🧧 标题：{$parsed['title']}\n" .
                    "💰 金额：{$parsed['amount']} USDT\n" .
                    "📦 个数：{$parsed['count']} 个\n" .
                    "🆔 红包ID：`{$packetIdStr}`\n\n" .
                    "⏳ *红包正在准备中，即将由系统发出...*\n" .
                    "💡 请稍候，红包消息即将出现在群内";
            
            // 发送到当前群组（不添加键盘，保持简洁）
            $result = $this->messageSender->send($chatId, $message, $debugFile);
            
            if ($result) {
                $this->log($debugFile, "✅ 群内红包创建提示发送成功");
            } else {
                $this->log($debugFile, "⚠️ 群内红包创建提示发送失败");
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 发送红包创建提示异常: " . $e->getMessage());
            $this->logError($debugFile, "红包创建提示发送失败", $e);
        }
    }
    
    /**
     * 判断是否是群组操作回调
     */
    private function isGroupOperationCallback(string $callbackData): bool
    {
        return strpos($callbackData, 'grab_redpacket_') === 0;
    }
    
    /**
     * 验证群组操作权限
     */
    private function validateGroupOperation(int $chatId, string $debugFile): bool
    {
        $chatType = $this->getChatType($chatId);
        
        if ($chatType === 'private') {
            $this->sendMessage($chatId, "❌ 该操作只能在群组中进行", $debugFile);
            return false;
        }
        
        return $this->validateGroupPermission($chatId, $debugFile);
    }
    
    /**
     * 发送抢红包成功通知
     */
    private function sendGrabSuccessNotification(array $result, string $debugFile): void
    {
        try {
            if ($this->currentUser && isset($result['amount'])) {
                $amount = $result['amount'];
                $isBest = $result['is_best_luck'] ?? false;
                $bestText = $isBest ? '👑 手气最佳！' : '';
                
                $message = "🎉 恭喜您抢到了 {$amount} USDT 红包！{$bestText}";
                $this->sendMessage($this->currentUser->user_id, $message, $debugFile);
            }
        } catch (\Exception $e) {
            $this->log($debugFile, "发送抢红包通知失败: " . $e->getMessage());
        }
    }
    
    // =================== Getter方法（供Handler使用）===================
    
    public function getCurrentUser(): ?User
    {
        return $this->currentUser;
    }
    
    public function getChatContext(): ?array
    {
        return $this->chatContext;
    }
    
    public function getOriginalMessage(): ?string
    {
        return $this->originalMessage;
    }
    
    public function getRedPacketService(): TelegramRedPacketService
    {
        return $this->redPacketService;
    }
}