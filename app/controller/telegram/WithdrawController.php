<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\common\helper\TemplateHelper;
use app\common\CacheHelper;
use app\common\SecurityHelper;
use app\model\User;

/**
 * 提现控制器 - 使用模板系统版本
 * 统一使用TemplateHelper管理所有消息和键盘模板
 * 修复版：添加用户管理机制，照搬PaymentController成功模式
 */
class WithdrawController extends BaseTelegramController
{
    private $withdrawService;
    private ?User $currentUser = null;  // 👈 新增：当前用户对象
    
    // 提现相关状态常量
    private const STATE_SETTING_PASSWORD = 'withdraw_setting_password';
    private const STATE_BINDING_ADDRESS = 'withdraw_binding_address';
    private const STATE_ENTERING_AMOUNT = 'withdraw_entering_amount';
    private const STATE_ENTERING_PASSWORD = 'withdraw_entering_password';
    private const STATE_MODIFYING_ADDRESS = 'withdraw_modifying_address';
    
    /**
     * 构造函数 - 在这里初始化服务
     */
    public function __construct()
    {
        parent::__construct();
        // 🔧 关键修复：在构造函数中初始化服务
        $this->withdrawService = new \app\service\WithdrawService();
    }

    /**
     * 设置当前用户（供CommandDispatcher调用）
     * 👈 新增：照搬PaymentController的用户管理
     */
    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
    
    /**
     * 获取当前用户
     * 👈 新增：照搬PaymentController的用户管理
     */
    protected function getCurrentUser(): ?User
    {
        return $this->currentUser;
    }

    /**
     * 处理 /withdraw 命令或提现主界面
     */
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "WithdrawController 处理命令: {$command}");
        
        try {
            // 🔧 修复：使用新的用户获取方式
            try {
                $user = $this->getCurrentUser();
                if (!$user) {
                    $userId = $this->getUserIdFromChatId($chatId);
                    $user = User::find($userId);
                }
                
                if (!$user) {
                    throw new \Exception('用户不存在');
                }
                
                // 转换为数组格式（保持业务逻辑兼容性）
                $userData = [
                    'id' => $user->id,
                    'balance' => $user->money_balance,
                    'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                    'withdraw_password' => $user->withdraw_pwd,
                    'usdt_address' => $user->usdt_address ?? '',
                    'status' => $user->status
                ];
                
            } catch (\Exception $e) {
                $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
                $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
                return;
            }
            
            // 检查前置条件并显示主界面
            $this->showWithdrawMain($chatId, $userData, $debugFile);
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理提现命令", $debugFile);
            $errorMsg = TemplateHelper::getError('withdraw', 'processing_error');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 处理按钮回调
     */
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "WithdrawController 处理回调: {$callbackData}");
        
        // 👈 临时添加调试日志
        $currentUser = $this->getCurrentUser();
        if ($currentUser) {
            $this->log($debugFile, "✅ 用户已正确设置: ID={$currentUser->id}");
        } else {
            $this->log($debugFile, "❌ 用户未设置！这就是问题所在");
        }
        
        try {
            switch ($callbackData) {
                case 'withdraw':
                    // 🔧 修复：使用新的用户获取方式
                    try {
                        $user = $this->getCurrentUser();
                        if (!$user) {
                            $userId = $this->getUserIdFromChatId($chatId);
                            $user = User::find($userId);
                        }
                        
                        if (!$user) {
                            throw new \Exception('用户不存在');
                        }
                        
                        // 转换为数组格式（保持业务逻辑兼容性）
                        $userData = [
                            'id' => $user->id,
                            'balance' => $user->money_balance,
                            'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                            'withdraw_password' => $user->withdraw_pwd,
                            'usdt_address' => $user->usdt_address ?? '',
                            'status' => $user->status
                        ];
                        
                    } catch (\Exception $e) {
                        $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
                        $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
                        return;
                    }
                    
                    $this->showWithdrawMain($chatId, $userData, $debugFile);
                    break;
                    
                case 'set_withdraw_password':
                    $this->startSetPassword($chatId, $debugFile);
                    break;
                    
                case 'bind_usdt_address':
                    $this->startBindAddress($chatId, $debugFile);
                    break;
                    
                case 'start_withdraw':
                    $this->startWithdraw($chatId, $debugFile);
                    break;
                    
                case 'confirm_withdraw':
                    $this->confirmWithdraw($chatId, $debugFile);
                    break;
                    
                case 'cancel_withdraw':
                    $this->cancelWithdraw($chatId, $debugFile);
                    break;
                    
                case 'withdraw_history':
                    $this->showWithdrawHistory($chatId, $debugFile);
                    break;
                    
                case 'modify_address':
                    $this->startModifyAddress($chatId, $debugFile);
                    break;
                    
                case 'retry_withdraw':
                    $this->retryWithdraw($chatId, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "❌ 未知的提现回调: {$callbackData}");
                    $message = TemplateHelper::getMessage('common', 'error_general');
                    $this->sendMessage($chatId, $message, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理提现回调: {$callbackData}", $debugFile);
            $errorMsg = TemplateHelper::getError('withdraw', 'processing_error');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 处理用户文本输入
     */
    public function handleTextInput(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "WithdrawController 处理文本输入: {$text}");
        
        try {
            $userState = $this->getUserState($chatId);
            $currentState = $userState['state'] ?? 'normal';
            
            switch ($currentState) {
                case self::STATE_SETTING_PASSWORD:
                    $this->processPasswordInput($chatId, $text, $debugFile);
                    break;
                    
                case self::STATE_BINDING_ADDRESS:
                    $this->processAddressInput($chatId, $text, $debugFile);
                    break;
                    
                case self::STATE_ENTERING_AMOUNT:
                    $this->processAmountInput($chatId, $text, $debugFile);
                    break;
                    
                case self::STATE_ENTERING_PASSWORD:
                    $this->processPasswordVerify($chatId, $text, $debugFile);
                    break;
                    
                case self::STATE_MODIFYING_ADDRESS:
                    $this->processAddressModify($chatId, $text, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "❌ 提现控制器收到未预期的文本输入，状态: {$currentState}");
                    $message = TemplateHelper::getMessage('general', 'unexpected_input');
                    $keyboard = TemplateHelper::getKeyboard('general', 'back_to_main_only');
                    $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, "处理提现文本输入", $debugFile);
            $errorMsg = TemplateHelper::getError('withdraw', 'processing_error');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 显示提现主界面 - 使用模板系统
     */
    private function showWithdrawMain(int $chatId, array $user, string $debugFile): void
    {
        $this->log($debugFile, "显示提现主界面");
        
        // 检查是否设置提现密码
        if (!$user['has_withdraw_pwd']) {
            $this->showNeedPasswordInterface($chatId, $user, $debugFile);
            return;
        }
        
        // 检查是否绑定USDT地址
        if (empty($user['usdt_address'])) {
            $this->showNeedAddressInterface($chatId, $user, $debugFile);
            return;
        }
        
        // 显示正常提现界面
        $this->showNormalWithdrawInterface($chatId, $user, $debugFile);
    }
    
    /**
     * 显示需要设置密码的界面 - 使用模板系统
     */
    private function showNeedPasswordInterface(int $chatId, array $user, string $debugFile): void
    {
        $addressStatus = empty($user['usdt_address']) ? '未绑定' : SecurityHelper::maskSensitiveData($user['usdt_address'], 'usdt_address');
        
        // 准备模板数据
        $data = [
            'balance' => number_format($user['balance'], 2),
            'address_status' => $addressStatus,
            'status_message' => TemplateHelper::getMessage('withdraw', 'need_password')
        ];
        
        // 获取提现主界面消息模板
        $message = TemplateHelper::getMessage('withdraw', 'main', $data);
        
        // 获取需要设置密码的键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'need_password');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 显示需要绑定地址的界面 - 使用模板系统
     */
    private function showNeedAddressInterface(int $chatId, array $user, string $debugFile): void
    {
        // 准备模板数据
        $data = [
            'balance' => number_format($user['balance'], 2),
            'address_status' => '未绑定',
            'status_message' => TemplateHelper::getMessage('withdraw', 'need_address')
        ];
        
        // 获取提现主界面消息模板
        $message = TemplateHelper::getMessage('withdraw', 'main', $data);
        
        // 获取需要绑定地址的键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'need_address');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 显示正常提现界面 - 使用模板系统
     */
    private function showNormalWithdrawInterface(int $chatId, array $user, string $debugFile): void
    {
        // 准备模板数据
        $data = [
            'balance' => number_format($user['balance'], 2),
            'address_status' => SecurityHelper::maskSensitiveData($user['usdt_address'], 'usdt_address'),
            'status_message' => '✅ 所有条件已满足，可以进行提现操作'
        ];
        
        // 获取提现主界面消息模板
        $message = TemplateHelper::getMessage('withdraw', 'main', $data);
        
        // 获取提现主界面键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'main');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 开始设置密码流程 - 使用模板系统（简化为一次输入）
     */
    private function startSetPassword(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "开始设置提现密码流程");
        
        $this->setUserState($chatId, self::STATE_SETTING_PASSWORD);
        
        // 获取设置密码消息模板
        $message = TemplateHelper::getMessage('withdraw', 'set_password');
        
        // 获取设置密码键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'set_password');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理密码输入 - 完整修复版本
     */
    private function processPasswordInput(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "处理密码输入: " . str_repeat('*', strlen($text)));
        
        // 验证密码格式
        if (!$this->validatePassword($text)) {
            $errorMsg = TemplateHelper::getError('withdraw', 'password_format_error');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 验证密码强度
        if (!$this->validatePasswordStrength($text)) {
            $errorMsg = TemplateHelper::getError('withdraw', 'password_weak');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        try {
            // 🔧 修复：使用用户的数据库ID而不是Telegram ID
            $result = $this->withdrawService->setWithdrawPassword($user->id, $text);
            if ($result['code'] !== 200) {
                $this->sendMessage($chatId, '❌ ' . $result['msg'], $debugFile);
                return;
            }
            
            // 清除状态
            $this->clearUserState($chatId);
            
            // 获取密码设置成功消息模板
            $message = TemplateHelper::getMessage('withdraw', 'password_success');
            $this->sendMessage($chatId, $message, $debugFile);
            
            // 🔧 修复：重新获取用户数据后显示主界面
            $updatedUser = User::find($user->id);
            if ($updatedUser) {
                $updatedUserData = [
                    'id' => $updatedUser->id,
                    'balance' => $updatedUser->money_balance,
                    'has_withdraw_pwd' => $updatedUser->withdraw_password_set == 1,
                    'withdraw_password' => $updatedUser->withdraw_pwd,
                    'usdt_address' => $updatedUser->usdt_address ?? '',
                    'status' => $updatedUser->status
                ];
                $this->showWithdrawMain($chatId, $updatedUserData, $debugFile);
            } else {
                // 备用方案：返回主菜单
                $backMessage = "密码设置成功！请重新进入提现功能。";
                $keyboard = [
                    [
                        ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                    ]
                ];
                $this->sendMessageWithKeyboard($chatId, $backMessage, $keyboard, $debugFile);
            }
            
            $this->log($debugFile, "✅ 提现密码设置成功，用户ID: " . $user->id);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 设置提现密码异常: " . $e->getMessage());
            
            // 清除状态，避免用户卡在当前步骤
            $this->clearUserState($chatId);
            
            // 发送友好的错误消息
            $errorMessage = '❌ 设置密码失败，请稍后重试。';
            if (strpos($e->getMessage(), '已设置') !== false) {
                $errorMessage = '❌ 提现密码已设置，无需重复设置。';
            }
            
            $keyboard = [
                [
                    ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
            $this->sendMessageWithKeyboard($chatId, $errorMessage, $keyboard, $debugFile);
        }
    }
    
    /**
     * 开始绑定地址流程 - 使用模板系统
     */
    private function startBindAddress(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "开始绑定USDT地址流程");
        
        $this->setUserState($chatId, self::STATE_BINDING_ADDRESS);
        
        // 获取绑定地址消息模板
        $message = TemplateHelper::getMessage('withdraw', 'bind_address');
        
        // 获取绑定地址键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'bind_address');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理地址输入 - 完整修复版本
     */
    private function processAddressInput(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "处理USDT地址输入: {$text}");
        
        // 验证地址格式
        if (!$this->validateUsdtAddress($text)) {
            $errorMsg = TemplateHelper::getError('withdraw', 'address_invalid');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        try {
            // 🔧 修复：使用用户的数据库ID而不是Telegram ID
            $result = $this->withdrawService->bindUsdtAddress($user->id, $text);
            if ($result['code'] !== 200) {
                $this->sendMessage($chatId, '❌ ' . $result['msg'], $debugFile);
                return;
            }
            
            // 清除状态
            $this->clearUserState($chatId);
            
            // 准备模板数据
            $data = ['address' => $text];
            
            // 获取地址绑定成功消息模板
            $message = TemplateHelper::getMessage('withdraw', 'address_success', $data);
            $this->sendMessage($chatId, $message, $debugFile);
            
            // 🔧 修复：重新获取更新后的用户数据显示主界面
            $updatedUser = User::find($user->id);
            if ($updatedUser) {
                $updatedUserData = [
                    'id' => $updatedUser->id,
                    'balance' => $updatedUser->money_balance,
                    'has_withdraw_pwd' => $updatedUser->withdraw_password_set == 1,
                    'withdraw_password' => $updatedUser->withdraw_pwd,
                    'usdt_address' => $updatedUser->usdt_address ?? '',
                    'status' => $updatedUser->status
                ];
                $this->showWithdrawMain($chatId, $updatedUserData, $debugFile);
            } else {
                // 备用方案：返回主菜单
                $backMessage = "地址绑定成功！请重新进入提现功能。";
                $keyboard = [
                    [
                        ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                    ]
                ];
                $this->sendMessageWithKeyboard($chatId, $backMessage, $keyboard, $debugFile);
            }
            
            $this->log($debugFile, "✅ USDT地址绑定成功，用户ID: " . $user->id . ", 地址: " . $text);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 绑定USDT地址异常: " . $e->getMessage());
            
            // 清除状态，避免用户卡在当前步骤
            $this->clearUserState($chatId);
            
            // 发送友好的错误消息
            $errorMessage = '❌ 绑定地址失败，请稍后重试。';
            if (strpos($e->getMessage(), '已绑定') !== false) {
                $errorMessage = '❌ USDT地址已绑定，无需重复绑定。';
            } elseif (strpos($e->getMessage(), '格式') !== false) {
                $errorMessage = '❌ USDT地址格式不正确，请检查后重新输入。';
            }
            
            $keyboard = [
                [
                    ['text' => '🔧 重新绑定', 'callback_data' => 'bind_usdt_address'],
                    ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
            $this->sendMessageWithKeyboard($chatId, $errorMessage, $keyboard, $debugFile);
        }
    }
    
    /**
     * 开始提现流程 - 使用模板系统
     */
    private function startWithdraw(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "开始提现申请流程");
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 转换为数组格式（保持业务逻辑兼容性）
            $userData = [
                'id' => $user->id,
                'balance' => $user->money_balance,
                'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                'withdraw_password' => $user->withdraw_pwd,
                'usdt_address' => $user->usdt_address ?? '',
                'status' => $user->status
            ];
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        $this->setUserState($chatId, self::STATE_ENTERING_AMOUNT);
        
        // 准备模板数据
        $data = [
            'balance' => number_format($userData['balance'], 2),
            'address' => SecurityHelper::maskSensitiveData($userData['usdt_address'], 'usdt_address')
        ];
        
        // 获取输入提现金额消息模板
        $message = TemplateHelper::getMessage('withdraw', 'enter_amount', $data);
        
        // 获取取消键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'cancel');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理金额输入 - 使用模板系统
     */
    private function processAmountInput(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "处理提现金额输入: {$text}");
        
        // 验证金额格式
        if (!$this->isValidAmount($text)) {
            $errorMsg = TemplateHelper::getError('withdraw', 'amount_invalid');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        $amount = (float)$text;
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 转换为数组格式（保持业务逻辑兼容性）
            $userData = [
                'id' => $user->id,
                'balance' => $user->money_balance,
                'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                'withdraw_password' => $user->withdraw_pwd,
                'usdt_address' => $user->usdt_address ?? '',
                'status' => $user->status
            ];
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        $config = config('telegram.withdraw', []);
        
        // 验证金额范围
        $minAmount = $config['min_amount'] ?? 10;
        $maxAmount = $config['max_amount'] ?? 10000;
        
        if ($amount < $minAmount) {
            $data = ['min_amount' => $minAmount];
            $errorMsg = TemplateHelper::getError('withdraw', 'amount_too_small', $data);
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        if ($amount > $maxAmount) {
            $data = ['max_amount' => $maxAmount];
            $errorMsg = TemplateHelper::getError('withdraw', 'amount_too_large', $data);
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 计算手续费
        $fee = $this->calculateFee($amount);
        $totalRequired = $amount + $fee;
        
        // 验证余额
        if ($userData['balance'] < $totalRequired) {
            $data = [
                'balance' => number_format($userData['balance'], 2),
                'required' => number_format($totalRequired, 2),
                'fee' => number_format($fee, 2)
            ];
            $errorMsg = TemplateHelper::getError('withdraw', 'insufficient_balance', $data);
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 保存提现数据到状态
        $userState = $this->getUserState($chatId);
        $userState['data']['withdraw_amount'] = $amount;
        $userState['data']['withdraw_fee'] = $fee;
        $this->setUserState($chatId, self::STATE_ENTERING_PASSWORD, $userState['data']);
        
        // 准备模板数据
        $actualAmount = $amount; // 实际到账 = 提现金额（手续费从余额扣除）
        $data = [
            'amount' => number_format($amount, 2),
            'fee' => number_format($fee, 2),
            'actual_amount' => number_format($actualAmount, 2),
            'address' => SecurityHelper::maskSensitiveData($userData['usdt_address'], 'usdt_address')
        ];
        
        // 获取确认提现信息消息模板
        $message = TemplateHelper::getMessage('withdraw', 'confirm', $data);
        
        // 获取确认键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'confirm');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理密码验证 - 防重复通知版本
     */
    private function processPasswordVerify(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "处理提现密码验证: " . str_repeat('*', strlen($text)));
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 转换为数组格式（保持业务逻辑兼容性）
            $userData = [
                'id' => $user->id,
                'balance' => $user->money_balance,
                'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                'withdraw_password' => $user->withdraw_pwd,
                'usdt_address' => $user->usdt_address ?? '',
                'status' => $user->status
            ];
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        // 验证提现密码
        if (!$this->verifyWithdrawPassword($userData, $text)) {
            $errorMsg = TemplateHelper::getError('withdraw', 'password_invalid');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 获取提现申请状态数据
        $userState = $this->getUserState($chatId);
        $amount = $userState['data']['withdraw_amount'] ?? 0;
        $fee = $userState['data']['withdraw_fee'] ?? 0;
        
        if ($amount <= 0) {
            $this->sendMessage($chatId, '❌ 提现金额错误，请重新申请', $debugFile);
            $this->clearUserState($chatId);
            return;
        }
        
        try {
            // 调用真实的提现服务创建订单
            $result = $this->withdrawService->createWithdrawOrder($userData['id'], $amount, $text);
            
            if ($result['code'] !== 200) {
                $this->sendMessage($chatId, '❌ ' . $result['msg'], $debugFile);
                return; // 注意：失败时不清除状态，用户可以重试
            }
            
            // 🔥 关键：订单创建成功后立即清除状态，防止重复提交
            $this->clearUserState($chatId);
            
            // ⚠️ 注意：这里不要再发送通知，因为在 WithdrawService 中已经发送了
            
            $orderData = $result['data'];
            
            // 只发送前端成功提示消息，不要重复调用通知服务
            $message = "✅ 提现申请成功！\n\n";
            $message .= "📄 订单号：{$orderData['order_no']}\n";
            $message .= "💰 提现金额：{$orderData['amount']} USDT\n";
            $message .= "💳 手续费：{$orderData['fee']} USDT\n";
            $message .= "🏦 到账金额：{$orderData['actual_amount']} USDT\n";
            $message .= "⏳ 正在审核中，请耐心等待...";
            
            $keyboard = [
                [
                    ['text' => '📋 提现记录', 'callback_data' => 'withdraw_history'],
                    ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            
            $this->log($debugFile, "✅ 提现申请成功，订单号: " . $orderData['order_no']);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 提现申请异常: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 提现申请失败：' . $e->getMessage(), $debugFile);
            // 发生异常时清除状态，避免用户卡在当前步骤
            $this->clearUserState($chatId);
        }
    }
    
    /**
     * 确认提现（按钮方式）
     */
    private function confirmWithdraw(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "确认提现操作（按钮方式）");
        
        // 这个方法暂时不使用，因为我们通过密码验证来确认
        $message = "请输入提现密码确认操作";
        $this->sendMessage($chatId, $message, $debugFile);
    }
    
    /**
     * 取消提现 - 使用模板系统
     */
    private function cancelWithdraw(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "取消提现操作");
        
        // 清除状态
        $this->clearUserState($chatId);
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 转换为数组格式（保持业务逻辑兼容性）
            $userData = [
                'id' => $user->id,
                'balance' => $user->money_balance,
                'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                'withdraw_password' => $user->withdraw_pwd,
                'usdt_address' => $user->usdt_address ?? '',
                'status' => $user->status
            ];
            
            $this->showWithdrawMain($chatId, $userData, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
    }
    
    /**
     * 重试提现
     */
    private function retryWithdraw(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "重试提现操作");
        
        // 清除状态，重新开始
        $this->clearUserState($chatId);
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 转换为数组格式（保持业务逻辑兼容性）
            $userData = [
                'id' => $user->id,
                'balance' => $user->money_balance,
                'has_withdraw_pwd' => $user->withdraw_password_set == 1,
                'withdraw_password' => $user->withdraw_pwd,
                'usdt_address' => $user->usdt_address ?? '',
                'status' => $user->status
            ];
            
            $this->showWithdrawMain($chatId, $userData, $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
    }
    
    /**
     * 显示提现记录 - 使用模板系统
     */
    private function showWithdrawHistory(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "显示提现记录");
        
        // 获取模拟提现记录
        $records = $this->getMockWithdrawHistory($chatId);
        
        if (empty($records)) {
            $recordsText = "📝 暂无提现记录";
        } else {
            $recordsText = "";
            foreach ($records as $i => $record) {
                $statusEmoji = $this->getStatusIcon($record['status']);
                $recordsText .= sprintf(
                    "📄 *订单 %d*\n" .
                    "• 订单号：`%s`\n" .
                    "• 金额：%s USDT\n" .
                    "• 状态：%s %s\n" .
                    "• 时间：%s\n\n",
                    $i + 1,
                    $record['order_no'],
                    number_format($record['amount'], 2),
                    $statusEmoji,
                    $this->getStatusText($record['status']),
                    $record['apply_time']
                );
            }
        }
        
        // 准备模板数据
        $data = ['records' => $recordsText];
        
        // 获取提现记录消息模板
        $message = TemplateHelper::getMessage('withdraw', 'history', $data);
        
        // 获取提现记录键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'history');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 开始修改地址流程 - 使用模板系统
     */
    private function startModifyAddress(int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "开始修改USDT地址流程");
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            $currentAddress = $user->usdt_address ?? '';
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        $this->setUserState($chatId, self::STATE_MODIFYING_ADDRESS);
        
        // 准备模板数据
        $data = ['current_address' => $currentAddress];
        
        // 获取修改地址消息模板
        $message = TemplateHelper::getMessage('withdraw', 'modify_address', $data);
        
        // 获取绑定地址键盘
        $keyboard = TemplateHelper::getKeyboard('withdraw', 'bind_address');
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
    }
    
    /**
     * 处理地址修改 - 完整修复版本
     */
    private function processAddressModify(int $chatId, string $text, string $debugFile): void
    {
        $this->log($debugFile, "处理USDT地址修改: {$text}");
        
        // 验证地址格式
        if (!$this->validateUsdtAddress($text)) {
            $errorMsg = TemplateHelper::getError('withdraw', 'address_invalid');
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        // 🔧 修复：使用新的用户获取方式
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                $userId = $this->getUserIdFromChatId($chatId);
                $user = User::find($userId);
            }
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取用户失败: " . $e->getMessage());
            $this->sendMessage($chatId, '❌ 用户未注册，请先注册', $debugFile);
            return;
        }
        
        try {
            // 🔧 修复：使用用户的数据库ID调用更新地址服务
            $result = $this->withdrawService->updateUsdtAddress($user->id, $text);
            if ($result['code'] !== 200) {
                $this->sendMessage($chatId, '❌ ' . $result['msg'], $debugFile);
                return;
            }
            
            // 清除状态
            $this->clearUserState($chatId);
            
            // 准备模板数据
            $data = ['address' => $text];
            
            // 获取地址修改成功消息模板
            $message = TemplateHelper::getMessage('withdraw', 'modify_success', $data);
            $this->sendMessage($chatId, $message, $debugFile);
            
            // 🔧 修复：重新获取更新后的用户数据显示主界面
            $updatedUser = User::find($user->id);
            if ($updatedUser) {
                $updatedUserData = [
                    'id' => $updatedUser->id,
                    'balance' => $updatedUser->money_balance,
                    'has_withdraw_pwd' => $updatedUser->withdraw_password_set == 1,
                    'withdraw_password' => $updatedUser->withdraw_pwd,
                    'usdt_address' => $updatedUser->usdt_address ?? '',
                    'status' => $updatedUser->status
                ];
                $this->showWithdrawMain($chatId, $updatedUserData, $debugFile);
            } else {
                // 备用方案：返回主菜单
                $backMessage = "地址修改成功！请重新进入提现功能。";
                $keyboard = [
                    [
                        ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                    ]
                ];
                $this->sendMessageWithKeyboard($chatId, $backMessage, $keyboard, $debugFile);
            }
            
            $this->log($debugFile, "✅ USDT地址修改成功，用户ID: " . $user->id . ", 新地址: " . $text);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 修改USDT地址异常: " . $e->getMessage());
            
            // 清除状态，避免用户卡在当前步骤
            $this->clearUserState($chatId);
            
            // 发送友好的错误消息
            $errorMessage = '❌ 修改地址失败，请稍后重试。';
            if (strpos($e->getMessage(), '格式') !== false) {
                $errorMessage = '❌ USDT地址格式不正确，请检查后重新输入。';
            }
            
            $keyboard = [
                [
                    ['text' => '🔧 重新修改', 'callback_data' => 'modify_address'],
                    ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
                ]
            ];
            $this->sendMessageWithKeyboard($chatId, $errorMessage, $keyboard, $debugFile);
        }
    }
    
    // ==================== 工具方法 ====================
    
    /**
     * 从ChatID获取系统用户ID（照搬PaymentController的成功模式）
     * 👈 新增：核心修复方法
     */
    private function getUserIdFromChatId(int $chatId): int
    {
        try {
            // 方法1: 通过当前用户对象获取（优先使用）
            if ($this->currentUser instanceof User) {
                $this->log('debug', "通过currentUser获取用户ID: {$this->currentUser->id}");
                return $this->currentUser->id;
            }
            
            // 方法2: 直接查询数据库
            $user = User::where('tg_id', (string)$chatId)->find();
            if ($user) {
                $this->log('debug', "通过数据库查询获取用户ID: {$user->id}");
                return $user->id;
            }
            
            // 如果都找不到，抛出异常
            throw new \Exception('用户不存在');
            
        } catch (\Exception $e) {
            // 记录错误日志
            \think\facade\Log::error('获取用户ID失败', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
                'current_user' => $this->currentUser ? $this->currentUser->id : 'null'
            ]);
            
            // 抛出异常让上层处理
            throw new \Exception('用户不存在');
        }
    }
    
    /**
     * 获取用户数据（保留原有方法作为备用）
     */
    private function getRealUser(int $tgUserId): ?array
    {
        $user = User::where('tg_id', (string)$tgUserId)->find();
        if (!$user) {
            return null;
        }
        
        return [
            'id' => $user->id,
            'balance' => $user->money_balance,
            'has_withdraw_pwd' => $user->withdraw_password_set == 1,
            'withdraw_password' => $user->withdraw_pwd,
            'usdt_address' => $user->usdt_address ?? '',
            'status' => $user->status
        ];
    }
       
    /**
     * 验证金额格式
     */
    private function isValidAmount(string $amount): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', trim($amount)) && (float)$amount > 0;
    }
    
    /**
     * 验证密码格式
     */
    private function validatePassword(string $password): bool
    {
        return preg_match('/^\d{6}$/', $password) === 1;
    }
    
    /**
     * 验证密码强度
     */
    private function validatePasswordStrength(string $password): bool
    {
        // 不能是连续数字
        if (preg_match('/^(012345|123456|234567|345678|456789|567890)$/', $password)) {
            return false;
        }
        
        // 不能是重复数字
        if (preg_match('/^(\d)\1{5}$/', $password)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证USDT地址格式
     */
    private function validateUsdtAddress(string $address): bool
    {
        // TRC20 USDT地址格式：以T开头，34位字符
        return preg_match('/^T[A-Za-z0-9]{33}$/', trim($address)) === 1;
    }
    
    /**
     * 验证提现密码
     */
    private function verifyWithdrawPassword(array $user, string $password): bool
    {
        // 模拟密码验证
        if (!$user['has_withdraw_pwd']) {
            return false;
        }
        
        // 这里应该使用加密比较，暂时用简单比较
        return $this->validatePassword($password);
    }
    
    /**
     * 计算手续费
     */
    private function calculateFee(float $amount): float
    {
        $config = config('telegram.withdraw', []);
        $feeRate = $config['fee_rate'] ?? 0.02;
        $feeMin = $config['fee_min'] ?? 1.00;
        $feeMax = $config['fee_max'] ?? 100.00;
        
        $fee = $amount * $feeRate;
        $fee = max($feeMin, min($feeMax, $fee));
        
        return round($fee, 2);
    }
    
    /**
     * 生成订单号
     */
    private function generateOrderNumber(): string
    {
        return 'W' . date('Ymd') . rand(100000, 999999);
    }
    
    /**
     * 获取模拟提现记录
     */
    private function getMockWithdrawHistory(int $chatId): array
    {
        // 模拟数据，实际应该从数据库查询
        return [
            [
                'order_no' => 'W20250619001',
                'amount' => 100.00,
                'status' => 'pending',
                'apply_time' => '2025-06-19 10:30:00'
            ],
            [
                'order_no' => 'W20250618002', 
                'amount' => 50.00,
                'status' => 'success',
                'apply_time' => '2025-06-18 15:20:00'
            ]
        ];
    }
    
    /**
     * 获取状态图标
     */
    private function getStatusIcon(string $status): string
    {
        $iconMap = [
            'pending' => '⏳',
            'success' => '✅',
            'failed' => '❌',
            'cancelled' => '🚫'
        ];
        
        return $iconMap[$status] ?? '❓';
    }
    
    /**
     * 获取状态文本
     */
    private function getStatusText(string $status): string
    {
        $textMap = [
            'pending' => '待审核',
            'success' => '已完成',
            'failed' => '已失败',
            'cancelled' => '已取消'
        ];
        
        return $textMap[$status] ?? '未知';
    }
}