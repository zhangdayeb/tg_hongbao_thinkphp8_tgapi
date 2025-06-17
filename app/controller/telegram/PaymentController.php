<?php
declare(strict_types=1);

namespace app\controller\telegram;

use app\controller\BaseTelegramController;
use app\common\helper\TemplateHelper;
use app\service\RechargeService;
use app\service\UserService;
use app\model\User;

/**
 * 充值控制器 - 完整版
 * 支持汇旺支付银行账号显示、二维码展示、订单号输入处理
 * 优化版：增强订单创建成功反馈
 */
class PaymentController extends BaseTelegramController
{
    private RechargeService $rechargeService;
    private UserService $userService;
    private ?User $currentUser = null;
    
    public function __construct()
    {
        parent::__construct();
        $this->rechargeService = new RechargeService();
        $this->userService = new UserService();
    }
    
    /**
     * 设置当前用户（供CommandDispatcher调用）
     */
    public function setUser(User $user): void
    {
        $this->currentUser = $user;
    }
    
    /**
     * 获取当前用户
     */
    protected function getCurrentUser(): ?User
    {
        return $this->currentUser;
    }
    
    /**
     * 处理充值相关命令
     */
    public function handle(string $command, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "PaymentController 处理命令: {$command}");
        
        try {
            switch ($command) {
                case 'recharge':
                    $this->showRechargeOptions($chatId, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "❌ PaymentController 未知命令: {$command}");
                    break;
            }
        } catch (\Exception $e) {
            $this->handleException($e, "处理充值命令: {$command}", $debugFile);
            $errorMsg = "❌ 系统繁忙，请稍后重试";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 处理回调查询
     */
    public function handleCallback(string $callbackData, int $chatId, string $debugFile): void
    {
        $this->log($debugFile, "PaymentController 处理回调: {$callbackData}");
        
        try {
            switch ($callbackData) {
                case 'recharge':
                    $this->showRechargeOptions($chatId, $debugFile);
                    break;
                    
                case 'recharge_usdt':
                    $this->requestAmountInput($chatId, 'usdt', $debugFile);
                    break;
                    
                case 'recharge_huiwang':
                    $this->requestAmountInput($chatId, 'huiwang', $debugFile);
                    break;
                    
                case 'confirm_amount':
                    $this->confirmAmountAndShowPayment($chatId, $debugFile);
                    break;
                    
                case 'reenter_amount':
                    $this->reenterAmount($chatId, $debugFile);
                    break;
                    
                case 'copy_address':
                    $this->handleCopyAddress($chatId, $debugFile);
                    break;
                    
                case 'copy_account':
                    $this->handleCopyAccount($chatId, $debugFile);
                    break;
                    
                case 'transfer_complete':
                    $this->handleTransferComplete($chatId, $debugFile);
                    break;
                    
                case 'cancel_recharge':
                    $this->cancelRecharge($chatId, $debugFile);
                    break;
                    
                case 'manual_amount':
                    $this->manualAmountInput($chatId, $debugFile);
                    break;
                    
                default:
                    // 处理快捷金额选择
                    if (strpos($callbackData, 'quick_amount_') === 0) {
                        $this->handleQuickAmount($chatId, $callbackData, $debugFile);
                    } else {
                        $this->log($debugFile, "❌ PaymentController 未知回调: {$callbackData}");
                    }
                    break;
            }
        } catch (\Exception $e) {
            $this->handleException($e, "处理充值回调: {$callbackData}", $debugFile);
            $errorMsg = "❌ 操作失败，请稍后重试";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 处理文本输入（金额输入和订单号输入）
     */
    public function handleTextInput(int $chatId, string $text, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $this->log($debugFile, "PaymentController 处理文本输入: '{$text}', 当前状态: '{$userState['state']}', 数据: " . json_encode($userState['data']));
        
        try {
            switch ($userState['state']) {
                case 'entering_amount':
                    $this->log($debugFile, "→ 处理金额输入");
                    $this->processAmountInput($chatId, $text, $debugFile);
                    break;
                    
                case 'entering_order_id':
                    $this->log($debugFile, "→ 处理订单号输入");
                    $this->processOrderIdInput($chatId, $text, $debugFile);
                    break;
                    
                default:
                    $this->log($debugFile, "❌ 用户不在需要输入的状态: {$userState['state']}");
                    
                    // 如果用户发送了看起来像订单号的文本，尝试帮助处理
                    if (preg_match('/^[a-zA-Z0-9\-_]{6,}$/', trim($text))) {
                        $this->log($debugFile, "💡 检测到可能的订单号，尝试恢复状态");
                        
                        // 检查是否有充值相关的状态残留
                        if (isset($userState['data']['method']) && isset($userState['data']['amount'])) {
                            $this->log($debugFile, "💡 发现充值数据，恢复到订单号输入状态");
                            $this->setUserState($chatId, 'entering_order_id', $userState['data']);
                            $this->processOrderIdInput($chatId, $text, $debugFile);
                            return;
                        }
                    }
                    
                    $message = "❓ *需要帮助吗？*\n\n";
                    $message .= "请使用下方菜单进行操作：\n\n";
                    $message .= "• /start - 返回主菜单\n";
                    $message .= "• /help - 查看帮助\n\n";
                    $message .= "💡 如需充值、提现等操作，请使用主菜单按钮";
                    
                    $keyboard = [
                        [['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']]
                    ];
                    $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
                    break;
            }
        } catch (\Exception $e) {
            $this->handleException($e, "处理充值文本输入", $debugFile);
            $errorMsg = "❌ 输入处理失败，请重试";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 显示充值选项
     */
    private function showRechargeOptions(int $chatId, string $debugFile): void
    {
        // 清除之前的状态
        $this->clearUserState($chatId);
        
        try {
            // 使用RechargeService获取动态充值方式
            $methodsResult = $this->rechargeService->getDepositMethods(true);
            
            if ($methodsResult['code'] !== 200) {
                throw new \Exception($methodsResult['msg']);
            }
            
            $methods = $methodsResult['data'];
            
            // 检查是否有可用的充值方式
            $availableMethods = array_filter($methods, fn($method) => $method['is_available']);
            
            if (empty($availableMethods)) {
                $message = "❌ *暂无可用的充值方式*\n\n系统维护中，请稍后再试或联系客服";
                $keyboard = [
                    [['text' => '👨‍💼 联系客服', 'url' => config('telegram.links.customer_service_url')]],
                    [['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']]
                ];
                $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
                return;
            }
            
            // 动态生成充值方式消息
            $message = "💰 *选择充值方式*\n\n请选择您的充值方式：\n\n";
            
            foreach ($availableMethods as $method) {
                $message .= "🔸 *{$method['method_name']}*\n";
                $message .= "  • 到账时间：{$method['arrive_time']}\n";
                $message .= "  • 手续费：{$method['fee_info']}\n";
                $message .= "  • 单笔限额：{$method['amount_range']}\n\n";
            }
            
            $message .= "💡 请选择适合您的充值方式";
            
            // 动态生成充值方式键盘
            $keyboard = [];
            foreach ($availableMethods as $method) {
                $buttonText = $method['icon'] . ' ' . $method['method_name'];
                $keyboard[] = [['text' => $buttonText, 'callback_data' => 'recharge_' . $method['method_code']]];
            }
            $keyboard[] = [['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示充值选项完成 - 可用方式: " . count($availableMethods));
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取充值方式失败: " . $e->getMessage());
            
            // 回退到静态模板
            $message = "💰 *选择充值方式*\n\n请选择您的充值方式：";
            $keyboard = [
                [['text' => '⚡ 汇旺转账', 'callback_data' => 'recharge_huiwang']],
                [['text' => '₿ USDT转账', 'callback_data' => 'recharge_usdt']],
                [['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']]
            ];
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        }
    }
    
    /**
     * 请求用户输入金额
     */
    private function requestAmountInput(int $chatId, string $method, string $debugFile): void
    {
        // 设置用户状态
        $this->setUserState($chatId, 'entering_amount', [
            'method' => $method,
            'start_time' => time()
        ]);
        
        try {
            // 获取方式配置
            $configResult = $this->rechargeService->getPaymentConfig($method);
            if ($configResult['code'] !== 200) {
                throw new \Exception($configResult['msg']);
            }
            
            $config = $configResult['data'];
            
            // 获取快捷金额
            $quickAmountsResult = $this->rechargeService->getQuickAmounts($method);
            $quickAmounts = $quickAmountsResult['code'] === 200 ? $quickAmountsResult['data'] : [];
            
            // 动态生成输入金额消息
            $message = "💰 *{$config['display_name']}充值*\n\n";
            $message .= "请选择或输入充值金额：\n\n";
            $message .= "💰 *金额范围*: {$config['amount_range']}\n";
            $message .= "📊 *手续费*: {$config['fee_info']}\n";
            $message .= "⏰ *到账时间*: {$config['arrive_time']}\n";
            $message .= "🌐 *网络类型*: {$config['network_type']}\n\n";
            
            if (!empty($quickAmounts)) {
                $message .= "💡 *快捷选择*：点击下方按钮快速选择金额";
            } else {
                $message .= "💡 请直接输入充值金额，例如：100";
            }
            
            // 动态生成快捷金额键盘
            $keyboard = [];
            
            if (!empty($quickAmounts)) {
                // 每行最多3个按钮
                $row = [];
                foreach ($quickAmounts as $i => $amount) {
                    $row[] = ['text' => $amount['display'], 'callback_data' => $amount['callback_data']];
                    
                    if (count($row) === 3 || $i === count($quickAmounts) - 1) {
                        $keyboard[] = $row;
                        $row = [];
                    }
                }
            }
            
            // 添加手动输入和取消按钮
            $keyboard[] = [['text' => '✏️ 手动输入', 'callback_data' => 'manual_amount']];
            $keyboard[] = [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 请求{$config['display_name']}金额输入完成");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取支付配置失败: " . $e->getMessage());
            
            // 回退到静态配置
            $methodName = $method === 'usdt' ? 'USDT' : '汇旺';
            $message = "💰 *{$methodName}充值*\n\n请输入充值金额：";
            $keyboard = [
                [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
            ];
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        }
    }
    
    /**
     * 处理金额输入
     */
    private function processAmountInput(int $chatId, string $input, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $method = $userState['data']['method'] ?? '';
        
        $this->log($debugFile, "处理金额输入: {$input}, 方式: {$method}");
        
        // 验证金额格式
        $amount = $this->parseAmountInput($input);
        if ($amount === false) {
            $errorMsg = "❌ *金额格式错误*\n\n请输入正确的数字，例如：100 或 100.50";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
            return;
        }
        
        try {
            // 获取用户ID
            $userId = $this->getUserIdFromChatId($chatId);
            $this->log($debugFile, "获取到用户ID: {$userId}");
            
            // 使用RechargeService验证金额
            $validation = $this->rechargeService->validateAmount($method, $amount, $userId);
            
            if (!$validation['valid']) {
                $errorMsg = "❌ *金额验证失败*\n\n" . implode("\n", $validation['errors']) . "\n\n请重新输入正确的充值金额：";
                $this->sendMessage($chatId, $errorMsg, $debugFile);
                return;
            }
            
            // 金额验证通过，显示确认页面
            $this->showAmountConfirmation($chatId, $method, $amount, $validation['fee_info'], $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 金额验证异常: " . $e->getMessage());
            $errorMsg = "❌ *金额验证失败*\n\n" . $e->getMessage() . "\n\n请重新输入充值金额：";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 显示金额确认页面
     */
    private function showAmountConfirmation(int $chatId, string $method, float $amount, array $feeInfo, string $debugFile): void
    {
        // 更新状态，保存金额和费用信息
        $userState = $this->getUserState($chatId);
        $userState['data']['amount'] = $amount;
        $userState['data']['fee_info'] = $feeInfo;
        $this->setUserState($chatId, 'confirming_amount', $userState['data']);
        
        try {
            // 获取方式配置
            $configResult = $this->rechargeService->getPaymentConfig($method);
            $config = $configResult['code'] === 200 ? $configResult['data'] : ['display_name' => $method];
            
            // 动态生成确认消息
            $message = "💰 *确认充值金额*\n\n";
            $message .= "*充值方式*: {$config['display_name']}\n";
            $message .= "*充值金额*: " . number_format($amount, 2) . " USDT\n";
            
            if ($feeInfo['fee_amount'] > 0) {
                $message .= "*手续费*: {$feeInfo['formatted_fee']}\n";
                $message .= "*实际到账*: {$feeInfo['formatted_actual']}\n";
            } else {
                $message .= "*手续费*: 免费\n";
                $message .= "*实际到账*: " . number_format($amount, 2) . " USDT\n";
            }
            
            $message .= "\n请确认金额无误后继续";
            
            $keyboard = [
                [['text' => '✅ 确认金额', 'callback_data' => 'confirm_amount']],
                [['text' => '✏️ 重新输入', 'callback_data' => 'reenter_amount']],
                [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示金额确认页面: {$amount} USDT");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示确认页面失败: " . $e->getMessage());
            // 回退处理
            $this->reenterAmount($chatId, $debugFile);
        }
    }
    
    /**
     * 处理快捷金额选择
     */
    private function handleQuickAmount(int $chatId, string $callbackData, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        
        if ($userState['state'] !== 'entering_amount') {
            $this->log($debugFile, "❌ 用户状态错误，不在输入金额状态");
            return;
        }
        
        $amount = (float)str_replace('quick_amount_', '', $callbackData);
        $method = $userState['data']['method'] ?? '';
        
        $this->log($debugFile, "处理快捷金额选择: {$amount}, 方式: {$method}");
        
        try {
            // 获取用户ID
            $userId = $this->getUserIdFromChatId($chatId);
            $this->log($debugFile, "获取到用户ID: {$userId}");
            
            // 验证快捷金额
            $validation = $this->rechargeService->validateAmount($method, $amount, $userId);
            
            if (!$validation['valid']) {
                $errorMsg = "❌ 金额验证失败：" . implode(', ', $validation['errors']);
                $this->sendMessage($chatId, $errorMsg, $debugFile);
                return;
            }
            
            // 直接显示确认页面
            $this->showAmountConfirmation($chatId, $method, $amount, $validation['fee_info'], $debugFile);
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 快捷金额验证失败: " . $e->getMessage());
            $errorMsg = "❌ *金额验证失败*\n\n" . $e->getMessage() . "\n\n请重新选择金额：";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 确认金额并显示支付信息
     */
    private function confirmAmountAndShowPayment(int $chatId, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        
        if ($userState['state'] !== 'confirming_amount') {
            $this->log($debugFile, "❌ 用户状态错误，不在确认金额状态");
            return;
        }
        
        $method = $userState['data']['method'] ?? '';
        $amount = $userState['data']['amount'] ?? 0;
        
        try {
            // 使用RechargeService获取最优账户
            $accountResult = $this->rechargeService->getDepositAccounts($method, true);
            
            if ($accountResult['code'] !== 200) {
                throw new \Exception($accountResult['msg']);
            }
            
            $account = $accountResult['data'];
            
            if ($method === 'usdt') {
                $this->showUSDTPayment($chatId, $amount, $account, $debugFile);
            } else {
                $this->showHuiwangPayment($chatId, $amount, $account, $debugFile);
            }
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 获取支付账户失败: " . $e->getMessage());
            $errorMsg = "❌ 暂无可用的收款账户，请稍后重试或联系客服";
            
            $keyboard = [
                [['text' => '🔄 重新尝试', 'callback_data' => 'confirm_amount']],
                [['text' => '👨‍💼 联系客服', 'url' => config('telegram.links.customer_service_url')]],
                [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $errorMsg, $keyboard, $debugFile);
        }
    }
    
    /**
     * 显示USDT支付页面
     */
    private function showUSDTPayment(int $chatId, float $amount, array $account, string $debugFile): void
    {
        // 设置状态为等待支付
        $userState = $this->getUserState($chatId);
        $userState['data']['account'] = $account;
        $this->setUserState($chatId, 'waiting_payment', $userState['data']);
        
        try {
            // 如果有二维码，先发送二维码图片
            if (!empty($account['qr_code_url'])) {
                $this->sendPhoto($chatId, $account['qr_code_url'], '💰 USDT充值二维码\n请使用支持TRC20的钱包扫码转账', $debugFile);
            }
            
            // 动态生成支付信息消息
            $message = "💰 *USDT充值信息*\n\n";
            $message .= "*充值金额*: " . number_format($amount, 2) . " USDT\n";
            $message .= "*网络类型*: {$account['network_type']}\n";
            $message .= "*收款地址*: `{$account['payment_address']}`\n\n";
            
            $message .= "⚠️ *重要提醒*：\n";
            $message .= "1. 请确保转账金额准确无误\n";
            $message .= "2. 请使用{$account['network_type']}网络转账\n";
            $message .= "3. 转账完成后请点击\"转账完成\"按钮\n";
            $message .= "4. 请保存好转账凭证\n\n";
            $message .= "💡 点击地址可自动复制";
            
            $keyboard = [
                [['text' => '📋 复制地址', 'callback_data' => 'copy_address']],
                [['text' => '✅ 转账完成', 'callback_data' => 'transfer_complete']],
                [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示USDT支付页面: {$amount} USDT, 地址: {$account['payment_address']}");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示USDT支付页面失败: " . $e->getMessage());
            $errorMsg = "❌ 支付信息加载失败，请重试";
            $this->sendMessage($chatId, $errorMsg, $debugFile);
        }
    }
    
    /**
     * 显示汇旺支付页面 - 支持银行账号信息和二维码
     */
    private function showHuiwangPayment(int $chatId, float $amount, array $account, string $debugFile): void
    {
        // 设置状态为等待支付
        $userState = $this->getUserState($chatId);
        $userState['data']['account'] = $account;
        $this->setUserState($chatId, 'waiting_payment', $userState['data']);
        
        $feeInfo = $userState['data']['fee_info'] ?? ['fee_amount' => 0, 'actual_amount' => $amount];
        
        try {
            // 如果有二维码，先发送二维码图片
            if (!empty($account['qr_code_url'])) {
                $this->sendPhoto($chatId, $account['qr_code_url'], '💳 汇旺充值二维码\n请使用银行APP扫码转账', $debugFile);
            }
            
            // 显示银行账号信息
            $message = "💳 *汇旺充值 - 银行转账*\n\n";
            
            // 充值金额信息
            $message .= "💰 *充值信息*\n";
            $message .= "• 充值金额：" . number_format($amount, 2) . " USDT\n";
            
            if ($feeInfo['fee_amount'] > 0) {
                $message .= "• 手续费：" . number_format($feeInfo['fee_amount'], 2) . " USDT\n";
                $message .= "• 实际到账：" . number_format($feeInfo['actual_amount'], 2) . " USDT\n";
            } else {
                $message .= "• 手续费：免费\n";
                $message .= "• 实际到账：" . number_format($amount, 2) . " USDT\n";
            }
            
            $message .= "• 到账时间：5-10分钟\n\n";
            
            // 银行账号信息
            $message .= "🏦 *收款账户信息*\n";
            $message .= "• 户名：{$account['account_name']}\n";
            $message .= "• 账号：`{$account['account_number']}`\n";
            $message .= "• 开户行：{$account['bank_name']}\n\n";
            
            $message .= "📝 *转账步骤*\n";
            
            if (!empty($account['qr_code_url'])) {
                $message .= "1. 扫描上方二维码 或 复制账号信息\n";
                $message .= "2. 通过银行APP/网银转账\n";
                $message .= "3. 转账金额：" . number_format($amount, 2) . " 元\n";
                $message .= "4. 转账完成后点击\"转账完成\"\n";
                $message .= "5. 输入银行转账订单号\n\n";
            } else {
                $message .= "1. 复制上方账号信息\n";
                $message .= "2. 通过银行APP/网银转账\n";
                $message .= "3. 转账金额：" . number_format($amount, 2) . " 元\n";
                $message .= "4. 转账完成后点击\"转账完成\"\n";
                $message .= "5. 输入银行转账订单号\n\n";
            }
            
            $message .= "⚠️ *重要*：请确保转账金额准确无误";
            
            $keyboard = [
                [['text' => '📋 复制账号', 'callback_data' => 'copy_account']],
                [['text' => '✅ 转账完成', 'callback_data' => 'transfer_complete']],
                [['text' => '💰 联系财务', 'url' => config('telegram.links.finance_service_url')]],
                [['text' => '❌ 取消充值', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 显示汇旺支付页面: {$amount} USDT, 账号: {$account['account_number']}, 二维码: " . (!empty($account['qr_code_url']) ? '有' : '无'));
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 显示汇旺支付页面失败: " . $e->getMessage());
            
            // 回退到联系财务模式
            $message = "💳 *汇旺充值 - 联系财务*\n\n";
            $message .= "• 充值金额：" . number_format($amount, 2) . " USDT\n";
            $message .= "• 手续费：免费\n";
            $message .= "• 实际到账：" . number_format($amount, 2) . " USDT\n";
            $message .= "• 到账时间：5-10分钟\n\n";
            $message .= "📞 *操作步骤*\n";
            $message .= "1. 联系财务客服\n";
            $message .= "2. 提供充值金额\n";
            $message .= "3. 获取转账信息\n";
            $message .= "4. 完成银行转账\n\n";
            $message .= "💡 汇旺支付需要人工处理";
            
            $keyboard = [
                [['text' => '💰 联系财务', 'url' => config('telegram.links.finance_service_url')]],
                [['text' => '❌ 取消充值', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 回退显示汇旺联系财务页面: {$amount} USDT");
        }
    }
    
    /**
     * 处理复制地址（USDT）
     */
    private function handleCopyAddress(int $chatId, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $account = $userState['data']['account'] ?? null;
        
        if (!$account || empty($account['payment_address'])) {
            $this->log($debugFile, "❌ 未找到支付地址信息");
            return;
        }
        
        $address = $account['payment_address'];
        $message = "📋 *地址已准备复制*\n\n`{$address}`\n\n💡 长按上方地址进行复制";
        
        $this->sendMessage($chatId, $message, $debugFile);
        $this->log($debugFile, "处理复制地址请求: {$address}");
    }
    
    /**
     * 处理复制账号（汇旺支付）
     */
    private function handleCopyAccount(int $chatId, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $account = $userState['data']['account'] ?? null;
        
        if (!$account || empty($account['account_number'])) {
            $this->log($debugFile, "❌ 未找到银行账号信息");
            return;
        }
        
        $accountNumber = $account['account_number'];
        $accountName = $account['account_name'] ?? '';
        $bankName = $account['bank_name'] ?? '';
        
        $message = "📋 *银行账号已准备复制*\n\n";
        $message .= "户名：{$accountName}\n";
        $message .= "账号：`{$accountNumber}`\n";
        $message .= "开户行：{$bankName}\n\n";
        $message .= "💡 长按账号进行复制";
        
        $this->sendMessage($chatId, $message, $debugFile);
        $this->log($debugFile, "处理复制银行账号请求: {$accountNumber}");
    }
    
    /**
     * 处理转账完成
     */
    private function handleTransferComplete(int $chatId, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        
        if ($userState['state'] !== 'waiting_payment') {
            $this->log($debugFile, "❌ 用户状态错误，不在等待支付状态: {$userState['state']}");
            return;
        }
        
        // 设置状态为等待订单号输入，保持数据完整性
        $userData = $userState['data'] ?? [];
        $this->setUserState($chatId, 'entering_order_id', $userData);
        $this->log($debugFile, "✅ 用户状态已设置为 entering_order_id");
        
        $method = $userData['method'] ?? '';
        
        if ($method === 'usdt') {
            $message = "✅ *转账完成确认*\n\n";
            $message .= "请输入您的转账哈希值（TxID）：\n\n";
            $message .= "💡 可在钱包的转账记录中找到\n";
            $message .= "💡 通常以0x开头的长字符串\n\n";
            $message .= "请直接发送订单号，无需点击任何按钮：";
        } else {
            $message = "✅ *转账完成确认*\n\n";
            $message .= "请输入银行转账的订单号或流水号：\n\n";
            $message .= "💡 可在银行APP转账记录中找到\n";
            $message .= "💡 一般为数字或字母数字组合\n\n";
            $message .= "请直接发送订单号，无需点击任何按钮：";
        }
        
        $keyboard = [
            [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 请求输入转账订单号 - 方式: {$method}");
    }
    
    /**
     * 处理订单号输入 - 增强版：立即反馈处理状态
     */
    private function processOrderIdInput(int $chatId, string $input, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $orderId = trim($input);
        
        $this->log($debugFile, "处理订单号输入: {$orderId}");
        
        // 🔧 检查是否已经在处理中，避免重复处理
        if (isset($userState['data']['processing']) && $userState['data']['processing'] === true) {
            $this->log($debugFile, "⚠️ 订单正在处理中，忽略重复输入");
            
            // 🚀 优化：告知用户正在处理，避免重复提交
            $waitingMsg = "⏳ *订单正在处理中*\n\n";
            $waitingMsg .= "您的充值订单正在创建中，请耐心等待...\n";
            $waitingMsg .= "请勿重复提交订单号";
            
            $this->sendMessage($chatId, $waitingMsg, $debugFile);
            return;
        }
        
        // 增强订单号验证
        if (strlen($orderId) < 6) {
            $errorMsg = "❌ *订单号格式错误*\n\n";
            $errorMsg .= "订单号长度至少6位，您输入的是：{$orderId}\n\n";
            $errorMsg .= "请重新输入正确的订单号：";
            
            $keyboard = [
                [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $errorMsg, $keyboard, $debugFile);
            return;
        }
        
        // 订单号格式验证 - 允许数字、字母、连字符
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $orderId)) {
            $errorMsg = "❌ *订单号格式错误*\n\n";
            $errorMsg .= "订单号只能包含字母、数字、连字符\n";
            $errorMsg .= "您输入的是：{$orderId}\n\n";
            $errorMsg .= "请重新输入正确的订单号：";
            
            $keyboard = [
                [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $errorMsg, $keyboard, $debugFile);
            return;
        }
        
        // 🔧 设置处理标志，防止重复处理
        $userState['data']['processing'] = true;
        $this->setUserState($chatId, 'entering_order_id', $userState['data']);
        
        // 🚀 优化：立即给用户反馈，表示系统正在处理
        $processingMsg = "⏳ *正在处理您的充值订单...*\n\n";
        $processingMsg .= "📋 订单号：{$orderId}\n";
        $processingMsg .= "💰 充值金额：" . number_format($userState['data']['amount'] ?? 0, 2) . " USDT\n";
        $processingMsg .= "🔄 正在创建充值订单，请稍候...";
        
        $this->sendMessage($chatId, $processingMsg, $debugFile);
        $this->log($debugFile, "✅ 发送处理中消息，开始创建订单");
        
        // 完成充值流程
        $this->completeRecharge($chatId, $orderId, $userState['data'], $debugFile);
    }
    
    /**
     * 完成充值流程 - 优化版：增强成功反馈和错误处理
     */
    private function completeRecharge(int $chatId, string $orderId, array $paymentData, string $debugFile): void
    {
        $method = $paymentData['method'];
        $amount = $paymentData['amount'];
        
        try {
            // 获取用户ID
            $userId = $this->getUserIdFromChatId($chatId);
            $this->log($debugFile, "获取到用户ID: {$userId}");
            
            // 🚀 优化：先发送订单创建开始的消息
            $creatingMsg = "🔄 *正在创建充值订单...*\n\n";
            $creatingMsg .= "系统正在为您创建订单，请稍等片刻...";
            
            $this->sendMessage($chatId, $creatingMsg, $debugFile);
            
            // 使用RechargeService创建充值订单
            $orderResult = $this->rechargeService->createRechargeOrder($userId, $amount, $method);
            
            if ($orderResult['code'] !== 200) {
                throw new \Exception($orderResult['msg']);
            }
            
            $rechargeOrderNo = $orderResult['data']['order_no'];
            $this->log($debugFile, "✅ 充值订单创建成功: {$rechargeOrderNo}");
            
            // 🚀 优化：发送订单创建成功的中间反馈
            $orderCreatedMsg = "✅ *订单创建成功*\n\n";
            $orderCreatedMsg .= "📋 系统订单号：`{$rechargeOrderNo}`\n";
            $orderCreatedMsg .= "🔄 正在提交支付凭证...";
            
            $this->sendMessage($chatId, $orderCreatedMsg, $debugFile);
            
            // 🔧 优化：简化提交支付凭证，移除广播逻辑
            $proofResult = $this->rechargeService->submitPaymentProof($rechargeOrderNo, [
                'transaction_id' => $orderId,
                'tg_message_id' => null,
                'payment_proof' => null
            ]);
            
            if ($proofResult['code'] !== 200) {
                $this->log($debugFile, "⚠️ 提交凭证失败: " . $proofResult['msg']);
                // 即使提交凭证失败，也继续显示成功信息，因为订单已创建
            } else {
                $this->log($debugFile, "✅ 支付凭证提交成功");
            }
            
            // 🔧 立即清除用户状态，防止重复处理
            $this->clearUserState($chatId);
            $this->log($debugFile, "✅ 用户状态已清除");
            
            // 获取配置信息用于显示
            $configResult = $this->rechargeService->getPaymentConfig($method);
            $config = $configResult['code'] === 200 ? $configResult['data'] : ['display_name' => $method, 'arrive_time' => '1-3分钟'];
            
            // 🚀 优化：更加详细和友好的成功消息
            $message = "🎉 *充值订单提交成功！*\n\n";
            $message .= "✅ 您的充值申请已成功提交并正在处理中\n\n";
            
            $message .= "📋 *订单详情*\n";
            $message .= "• 充值方式：{$config['display_name']}\n";
            $message .= "• 充值金额：" . number_format($amount, 2) . " USDT\n";
            $message .= "• 系统订单号：`{$rechargeOrderNo}`\n";
            $message .= "• 交易订单号：{$orderId}\n";
            $message .= "• 提交时间：" . date('Y-m-d H:i:s') . "\n\n";
            
            $message .= "⏰ *处理进度*\n";
            $message .= "• 当前状态：已提交，等待确认\n";
            $message .= "• 预计到账：{$config['arrive_time']}\n";
            $message .= "• 处理方式：系统自动审核\n\n";
            
            $message .= "💡 *温馨提示*\n";
            $message .= "• 订单信息已自动通知相关人员\n";
            $message .= "• 到账后余额将自动更新\n";
            $message .= "• 如有疑问请联系客服";
            
            $keyboard = [
                [['text' => '💰 继续充值', 'callback_data' => 'recharge']],
                [['text' => '📊 查看余额', 'callback_data' => 'check_balance']],
                [['text' => '👨‍💼 联系客服', 'url' => config('telegram.links.customer_service_url')]],
                [['text' => '🏠 返回主页', 'callback_data' => 'back_to_main']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
            $this->log($debugFile, "✅ 充值流程完成 - 订单号: {$rechargeOrderNo}, 方式: {$method}, 金额: {$amount}");
            $this->log($debugFile, "💡 订单广播将由后台定时任务处理");
            
        } catch (\Exception $e) {
            $this->log($debugFile, "❌ 创建充值订单失败: " . $e->getMessage());
            
            // 🔧 发生错误时也要清除状态
            $this->clearUserState($chatId);
            
            // 🚀 优化：更详细的错误信息
            $message = "❌ *充值订单创建失败*\n\n";
            $message .= "很抱歉，创建充值订单时遇到问题：\n\n";
            $message .= "🔸 错误信息：{$e->getMessage()}\n";
            $message .= "🔸 您输入的订单号：{$orderId}\n";
            $message .= "🔸 充值金额：" . number_format($amount, 2) . " USDT\n\n";
            $message .= "💡 请尝试以下解决方案：\n";
            $message .= "• 检查订单号是否正确\n";
            $message .= "• 稍后重新尝试\n";
            $message .= "• 联系客服获得帮助";
            
            $keyboard = [
                [['text' => '🔄 重新尝试', 'callback_data' => 'recharge']],
                [['text' => '👨‍💼 联系客服', 'url' => config('telegram.links.customer_service_url')]],
                [['text' => '🏠 返回主页', 'callback_data' => 'back_to_main']]
            ];
            
            $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        }
    }
    
    /**
     * 手动输入金额
     */
    private function manualAmountInput(int $chatId, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $method = $userState['data']['method'] ?? '';
        
        if (empty($method)) {
            $this->showRechargeOptions($chatId, $debugFile);
            return;
        }
        
        try {
            $configResult = $this->rechargeService->getPaymentConfig($method);
            $config = $configResult['code'] === 200 ? $configResult['data'] : ['display_name' => $method];
            
            $message = "💰 *{$config['display_name']}充值 - 手动输入*\n\n";
            $message .= "请直接发送您要充值的金额数字：\n\n";
            $message .= "💡 例如：100 或 100.50";
            
        } catch (\Exception $e) {
            $methodName = $method === 'usdt' ? 'USDT' : '汇旺';
            $message = "💰 *{$methodName}充值 - 手动输入*\n\n请直接发送您要充值的金额数字：";
        }
        
        $keyboard = [
            [['text' => '❌ 取消', 'callback_data' => 'cancel_recharge']]
        ];
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 显示手动输入金额界面");
    }
    
    /**
     * 重新输入金额
     */
    private function reenterAmount(int $chatId, string $debugFile): void
    {
        $userState = $this->getUserState($chatId);
        $method = $userState['data']['method'] ?? '';
        
        if (empty($method)) {
            $this->showRechargeOptions($chatId, $debugFile);
            return;
        }
        
        // 重置到输入金额状态
        $this->setUserState($chatId, 'entering_amount', ['method' => $method]);
        $this->requestAmountInput($chatId, $method, $debugFile);
    }
    
    /**
     * 取消充值
     */
    private function cancelRecharge(int $chatId, string $debugFile): void
    {
        $this->clearUserState($chatId);
        
        $message = "❌ *充值已取消*\n\n您的充值流程已取消，如需充值请重新开始。";
        
        $keyboard = [
            [['text' => '💰 重新充值', 'callback_data' => 'recharge']],
            [['text' => '🏠 返回主页', 'callback_data' => 'back_to_main']]
        ];
        
        $this->sendMessageWithKeyboard($chatId, $message, $keyboard, $debugFile);
        $this->log($debugFile, "✅ 取消充值完成");
    }
    
    // ==================== 工具方法 ====================
    
    /**
     * 解析金额输入
     */
    private function parseAmountInput(string $input): float|false
    {
        $input = trim($input);
        $input = preg_replace('/[^\d.]/', '', $input);
        
        if (empty($input) || !is_numeric($input)) {
            return false;
        }
        
        $amount = (float)$input;
        
        if ($amount <= 0) {
            return false;
        }
        
        // 检查小数位数
        $decimalPlaces = strlen(substr(strrchr($input, '.'), 1));
        if ($decimalPlaces > 2) {
            return false;
        }
        
        return $amount;
    }
    
    /**
     * 从ChatID获取系统用户ID
     */
    private function getUserIdFromChatId(int $chatId): int
    {
        try {
            // 方法1: 通过当前用户对象获取（优先使用）
            if ($this->currentUser instanceof User) {
                $this->log('debug', "通过currentUser获取用户ID: {$this->currentUser->id}");
                return $this->currentUser->id;
            }
            
            // 方法2: 通过UserService查找用户
            $user = $this->userService->getUserByTgId((string)$chatId);
            if ($user) {
                $this->log('debug', "通过UserService获取用户ID: {$user->id}");
                return $user->id;
            }
            
            // 方法3: 直接查询数据库
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
}