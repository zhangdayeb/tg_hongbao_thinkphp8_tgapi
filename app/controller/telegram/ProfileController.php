<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\service\UserService;
use app\model\User;

/**
 * 个人中心控制器 - 增强版（包含完整的绑定游戏ID功能）
 */
class ProfileController extends BaseTelegramController
{
    private UserService $userService;
    private ?User $currentUser = null;
    
    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }
    
    /**
     * 设置当前用户（由 CommandDispatcher 调用）
     */
    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
    
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "ProfileController 处理命令: {$command}");
        $this->showProfile($chatId, $debugFile);
    }
    
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "ProfileController 处理回调: {$callbackData}");
        
        switch ($callbackData) {
            case 'profile':
                $this->showProfile($chatId, $debugFile);
                break;
                
            case 'bind_game_id':
                $this->handleBindGameId($chatId, $debugFile);
                break;
                
            case 'start_bind_game_id':
                $this->startBindGameId($chatId, $debugFile);
                break;
                
            case 'cancel_bind_game_id':
                $this->cancelBindGameId($chatId, $debugFile);
                break;
                
            case 'view_current_game_id':
                $this->viewCurrentGameId($chatId, $debugFile);
                break;
                
            default:
                $this->showProfile($chatId, $debugFile);
                break;
        }
    }
    
    /**
     * 处理文本输入（绑定游戏ID流程）
     */
    public function handleTextInput(int $chatId, string $text, string $debugFile): void
    {
        try {
            // 获取用户状态
            $userState = $this->getUserState($chatId);
            $currentState = $userState['state'] ?? 'idle';
            
            $this->log($debugFile, "ProfileController 处理文本输入 - 状态: {$currentState}, 输入: {$text}");
            
            switch ($currentState) {
                case 'waiting_game_id_input':
                    $this->processGameIdInput($chatId, trim($text), $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "ProfileController 收到非预期状态的文本输入: {$currentState}");
                    break;
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ ProfileController 处理文本输入异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 处理输入失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 显示个人中心 - 使用真实用户数据
     */
    private function showProfile(int $chatId, string $debugFile): void
    {
        try {
            // 获取用户数据
            $userData = $this->getUserData($chatId, $debugFile);
            
            if (!$userData) {
                $this->log($debugFile, "❌ 无法获取用户数据");
                $this->sendMessage($chatId, "❌ 获取用户信息失败，请稍后重试", $debugFile);
                return;
            }
            
            // 构建个人信息文本 - 避免 Markdown 特殊字符
            $text = $this->buildSafeProfileText($userData);
            
            // 键盘布局 - 增强版
            $keyboard = $this->buildEnhancedKeyboard($userData);
            
            // 使用标准发送方法
            $this->sendMessageWithKeyboard($chatId, $text, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示个人中心完成 - 用户ID: {$userData['id']}");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示个人中心异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 系统异常，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 处理绑定游戏ID - 显示选项菜单
     */
    private function handleBindGameId(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "显示绑定游戏ID选项菜单");
            
            // 获取用户数据
            $userData = $this->getUserData($chatId, $debugFile);
            if (!$userData) {
                $this->sendMessage($chatId, "❌ 获取用户信息失败", $debugFile);
                return;
            }
            
            // 检查是否已有游戏ID
            $currentGameId = $userData['game_id'] ?? '';
            
            $message = "🎮 游戏ID管理\n\n";
            $message .= "🆔 当前用户ID: " . $userData['id'] . "\n";
            $message .= "👤 用户名: " . $userData['user_name'] . "\n";
            
            if (!empty($currentGameId)) {
                $message .= "🎯 当前游戏ID: " . $currentGameId . "\n\n";
                $message .= "您可以选择以下操作：\n";
                $message .= "• 查看当前游戏ID\n";
                $message .= "• 修改游戏ID\n";
            } else {
                $message .= "🎯 游戏ID: 未设置\n\n";
                $message .= "请设置您的游戏ID以便游戏登录。\n";
            }
            
            // 键盘布局
            $keyboard = [];
            
            if (!empty($currentGameId)) {
                $keyboard[] = [
                    ['text' => '👁️ 查看当前游戏ID', 'callback_data' => 'view_current_game_id']
                ];
                $keyboard[] = [
                    ['text' => '✏️ 修改游戏ID', 'callback_data' => 'start_bind_game_id']
                ];
            } else {
                $keyboard[] = [
                    ['text' => '🆔 设置游戏ID', 'callback_data' => 'start_bind_game_id']
                ];
            }
            
            $keyboard[] = [
                ['text' => '🔙 返回个人中心', 'callback_data' => 'profile']
            ];
            $keyboard[] = [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示绑定游戏ID选项菜单完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理绑定游戏ID异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 处理失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 开始绑定游戏ID流程 - 修复Markdown问题
     */
    private function startBindGameId(int $chatId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "开始绑定游戏ID流程");
            
            // 设置用户状态为等待游戏ID输入
            $this->setUserState($chatId, 'waiting_game_id_input', [
                'action' => 'bind_game_id',
                'start_time' => time()
            ], 300); // 5分钟超时
            
            // 🔧 修复：避免Markdown解析问题的消息内容
            $message = "🆔 请输入您的游戏ID\n\n";
            $message .= "📝 输入要求：\n";
            $message .= "• 支持字母、数字和下划线\n";
            $message .= "• 长度1-20个字符\n";
            $message .= "• 不能包含特殊符号\n\n";
            $message .= "💡 示例：1、abc、player123、user_001、_test_、666\n\n";
            $message .= "请直接输入您的游戏ID：";
            
            // 取消按钮
            $keyboard = [
                [
                    ['text' => '❌ 取消设置', 'callback_data' => 'cancel_bind_game_id']
                ]
            ];
            
            // 🔧 使用不解析Markdown的安全发送方法
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 发送游戏ID输入提示");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 开始绑定游戏ID异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 启动绑定流程失败", $debugFile);
        }
    }
    
    /**
     * 处理游戏ID输入
     */
    private function processGameIdInput(int $chatId, string $gameId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "处理游戏ID输入: {$gameId}");
            
            // 验证游戏ID格式
            $validation = $this->validateGameId($gameId);
            if (!$validation['valid']) {
                $this->sendMessage($chatId, "❌ " . $validation['message'], $debugFile);
                return;
            }
            
            // 检查游戏ID是否已被使用
            $existingUser = $this->userService->getUserByGameId($gameId);
            if ($existingUser && $existingUser->id !== $this->currentUser->id) {
                $this->sendMessage($chatId, "❌ 该游戏ID已被其他用户使用，请换一个", $debugFile);
                return;
            }
            
            // 显示确认信息
            $this->showGameIdConfirmation($chatId, $gameId, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理游戏ID输入异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 处理失败，请重试", $debugFile);
        }
    }
    
    /**
     * 显示游戏ID确认
     */
    private function showGameIdConfirmation(int $chatId, string $gameId, string $debugFile): void
    {
        try {
            // 更新状态为等待确认
            $this->setUserState($chatId, 'waiting_game_id_confirm', [
                'action' => 'bind_game_id',
                'game_id' => $gameId,
                'start_time' => time()
            ], 300);
            
            $message = "🆔 确认游戏ID设置\n\n";
            $message .= "您输入的游戏ID是：\n";
            $message .= "🎯 " . $gameId . "\n\n";
            $message .= "请确认此游戏ID是否正确？\n";
            $message .= "设置后可以使用此ID登录游戏系统。";
            
            $keyboard = [
                [
                    ['text' => '✅ 确认设置', 'callback_data' => 'confirm_game_id_' . $gameId]
                ],
                [
                    ['text' => '❌ 重新输入', 'callback_data' => 'start_bind_game_id']
                ],
                [
                    ['text' => '🔙 取消设置', 'callback_data' => 'cancel_bind_game_id']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示游戏ID确认信息");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示确认信息异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 显示确认信息失败", $debugFile);
        }
    }
    
    /**
     * 确认设置游戏ID（通过特殊回调处理）
     */
    public function handleGameIdConfirmation(string $callbackData, int $chatId, string $debugFile): void
    {
        try {
            // 从回调数据中提取游戏ID
            if (strpos($callbackData, 'confirm_game_id_') === 0) {
                $gameId = substr($callbackData, strlen('confirm_game_id_'));
                $this->confirmGameIdBinding($chatId, $gameId, $debugFile);
            }
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 处理游戏ID确认异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 确认处理失败", $debugFile);
        }
    }
    
    /**
     * 确认绑定游戏ID
     */
    private function confirmGameIdBinding(int $chatId, string $gameId, string $debugFile): void
    {
        try {
            $this->log($debugFile, "确认绑定游戏ID: {$gameId}");
            
            // 更新用户的游戏ID
            $result = $this->userService->updateUserGameId($this->currentUser->id, $gameId);
            
            if ($result) {
                // 清除用户状态
                $this->clearUserState($chatId);
                
                // 更新当前用户对象
                $this->currentUser->game_id = $gameId;
                
                $message = "🎉 游戏ID设置成功！\n\n";
                $message .= "🆔 您的游戏ID：" . $gameId . "\n";
                $message .= "🎮 现在可以使用此ID登录游戏了\n\n";
                $message .= "✅ 设置已保存";
                
                $keyboard = [
                    [
                        ['text' => '🎮 进入游戏', 'url' => config('telegram.links.game_url')]
                    ],
                    [
                        ['text' => '🔙 返回个人中心', 'callback_data' => 'profile']
                    ],
                    [
                        ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
                    ]
                ];
                
                $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
                $this->log($debugFile, "✅ 游戏ID绑定成功");
                
            } else {
                $this->sendMessage($chatId, "❌ 保存失败，请稍后重试", $debugFile);
                $this->log($debugFile, "❌ 更新游戏ID到数据库失败");
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 确认绑定游戏ID异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 绑定失败，请稍后重试", $debugFile);
        }
    }
    
    /**
     * 查看当前游戏ID
     */
    private function viewCurrentGameId(int $chatId, string $debugFile): void
    {
        try {
            $userData = $this->getUserData($chatId, $debugFile);
            if (!$userData) {
                $this->sendMessage($chatId, "❌ 获取用户信息失败", $debugFile);
                return;
            }
            
            $gameId = $userData['game_id'] ?? '';
            
            $message = "🎮 当前游戏ID信息\n\n";
            $message .= "🆔 用户ID: " . $userData['id'] . "\n";
            $message .= "👤 用户名: " . $userData['user_name'] . "\n";
            
            if (!empty($gameId)) {
                $message .= "🎯 游戏ID: " . $gameId . "\n\n";
                $message .= "✅ 您可以使用此ID登录游戏";
            } else {
                $message .= "🎯 游戏ID: 未设置\n\n";
                $message .= "❌ 请先设置游戏ID";
            }
            
            $keyboard = [
                [
                    ['text' => '✏️ 修改游戏ID', 'callback_data' => 'start_bind_game_id']
                ],
                [
                    ['text' => '🔙 返回', 'callback_data' => 'bind_game_id']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示当前游戏ID信息");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 查看游戏ID异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 查看失败", $debugFile);
        }
    }
    
    /**
     * 取消绑定游戏ID
     */
    private function cancelBindGameId(int $chatId, string $debugFile): void
    {
        try {
            // 清除用户状态
            $this->clearUserState($chatId);
            
            $message = "❌ 已取消游戏ID设置";
            
            $keyboard = [
                [
                    ['text' => '🔙 返回个人中心', 'callback_data' => 'profile']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 取消绑定游戏ID完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 取消绑定异常: " . $e->getMessage());
            $this->sendMessage($chatId, "❌ 取消操作失败", $debugFile);
        }
    }
    
    /**
     * 验证游戏ID格式 - 宽松版本
     */
    private function validateGameId(string $gameId): array
    {
        // ✅ 修正1: 最小长度改为1位
        if (strlen($gameId) < 1) {
            return ['valid' => false, 'message' => '游戏ID不能为空'];
        }
        
        // 保持最大长度限制
        if (strlen($gameId) > 20) {
            return ['valid' => false, 'message' => '游戏ID不能超过20个字符'];
        }
        
        // 格式检查：只允许字母、数字和下划线
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $gameId)) {
            return ['valid' => false, 'message' => '游戏ID只能包含字母、数字和下划线'];
        }
        
        // ✅ 修正2: 移除全数字限制，允许纯数字
        // 原代码删除：if (is_numeric($gameId)) { ... }
        
        // ✅ 修正3: 移除下划线位置限制，允许任意位置使用下划线
        // 原代码删除：if (str_starts_with($gameId, '_') || str_ends_with($gameId, '_')) { ... }
        
        return ['valid' => true, 'message' => '格式正确'];
    }
    
    /**
     * 构建增强的键盘布局
     */
    private function buildEnhancedKeyboard(array $userData): array
    {
        $gameId = $userData['game_id'] ?? '';
        $buttonText = empty($gameId) ? '🆔 设置游戏ID' : '🎮 管理游戏ID';
        
        return [
            [
                ['text' => $buttonText, 'callback_data' => 'bind_game_id']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ];
    }
    
    // ... 其他原有方法保持不变 ...
    
    /**
     * 获取用户数据 - 使用真实数据库查询
     */
    private function getUserData(int $chatId, string $debugFile): ?array
    {
        try {
            // 优先使用当前设置的用户
            if ($this->currentUser) {
                $this->log($debugFile, "使用当前设置的用户数据 - ID: {$this->currentUser->id}");
                return $this->formatUserData($this->currentUser);
            }
            
            // 回退：根据 chatId 查找用户（chatId 通常就是 tg_id）
            $tgUserId = (string)$chatId;
            $user = $this->userService->getUserByTgId($tgUserId);
            
            if (!$user) {
                $this->log($debugFile, "❌ 未找到用户 - TG_ID: {$tgUserId}");
                return null;
            }
            
            $this->log($debugFile, "通过TG_ID查找到用户 - ID: {$user->id}");
            return $this->formatUserData($user);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户数据异常: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 格式化用户数据
     */
    private function formatUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'tg_id' => $user->tg_id,
            'user_name' => $user->user_name,
            'game_id' => $user->game_id ?? '', // 添加游戏ID字段
            'name' => $user->getFullNameAttr('', $user->toArray()),
            'balance' => $user->money_balance,
            'phone' => $user->phone ?? null,
            'status' => $user->status,
            'auto_created' => $user->auto_created ?? 0,
            'create_time' => $user->create_time,
            'last_activity' => $user->last_activity_at
        ];
    }
    
    /**
     * 构建安全的个人信息文本
     */
    private function buildSafeProfileText(array $userData): string
    {
        $maskedName = $this->maskUserNameSafe($userData['name']);
        $phoneStatus = $this->formatPhoneStatusNew($userData['phone']);
        $balance = number_format($userData['balance'], 2);
        
        // 用户类型标识
        $userTypeIcon = $userData['auto_created'] ? '🤖' : '👤';
        $userTypeText = $userData['auto_created'] ? '(自动创建)' : '';
        
        // 状态标识
        $statusIcon = $userData['status'] == 1 ? '✅' : '❌';
        $statusText = $userData['status'] == 1 ? '正常' : '冻结';
        
        // 游戏ID状态
        $gameId = $userData['game_id'] ?? '';
        $gameIdStatus = empty($gameId) ? '未设置' : $gameId;
        $gameIdIcon = empty($gameId) ? '❌' : '✅';
        
        $text = "📱 个人中心信息\n\n";
        $text .= "🆔 用户ID: {$userData['id']}\n";
        $text .= "🔗 Telegram ID: {$userData['tg_id']}\n";
        $text .= "{$userTypeIcon} 用户名: {$userData['user_name']} {$userTypeText}\n";
        $text .= "📝 姓名: {$maskedName}\n";
        $text .= "💰 账户余额: {$balance} USDT\n";
        $text .= "📱 {$phoneStatus}\n";
        $text .= "{$gameIdIcon} 游戏ID: {$gameIdStatus}\n";
        $text .= "{$statusIcon} 账户状态: {$statusText}\n\n";
        
        // 注册信息
        if (!empty($userData['create_time'])) {
            $createDate = is_numeric($userData['create_time']) 
                ? date('Y-m-d', $userData['create_time'])
                : date('Y-m-d', strtotime($userData['create_time']));
            $text .= "📅 注册时间: {$createDate}\n";
        }
        
        // 最后活动
        if (!empty($userData['last_activity'])) {
            $lastActivity = is_numeric($userData['last_activity'])
                ? date('m-d H:i', $userData['last_activity'])
                : date('m-d H:i', strtotime($userData['last_activity']));
            $text .= "⏰ 最后活动: {$lastActivity}\n";
        }
        
        $text .= "\n------------------------";
        
        return $text;
    }
    
    /**
     * 手机号格式化方法
     */
    private function formatPhoneStatusNew(?string $phone): string
    {
        if (empty($phone)) {
            return "手机: 未绑定";
        }
        
        if (strlen($phone) >= 11) {
            $prefix = substr($phone, 0, 3);
            $suffix = substr($phone, -4);
            return "手机: {$prefix}....{$suffix}";
        }
        
        if (strlen($phone) >= 7) {
            $prefix = substr($phone, 0, 3);
            $suffix = substr($phone, -4);
            return "手机: {$prefix}....{$suffix}";
        }
        
        return "手机: {$phone}";
    }
    
    /**
     * 安全的用户名隐藏
     */
    private function maskUserNameSafe(?string $name): string
    {
        if (empty($name)) {
            return "X...............";
        }
        
        $length = mb_strlen($name);
        if ($length <= 1) {
            return $name . "...............";
        }
        
        return mb_substr($name, 0, 1) . str_repeat('.', 15);
    }
}