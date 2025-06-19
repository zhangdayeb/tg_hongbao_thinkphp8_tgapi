<?php
/**
 * 充值模板配置文件 - 适配RechargeService版本
 * 
 * 注意：现在很多消息已经在PaymentController中动态生成
 * 这里保留一些通用的模板和键盘配置
 */

return [
    // 通用消息模板（当动态获取失败时的回退）
    'messages' => [
        // 充值方式选择（回退模板）
        'methods' => '💰 *选择充值方式*

请选择您的充值方式：

🔸 *USDT充值* - 数字货币充值

🔸 *汇旺充值* - 传统银行转账

💡 请选择适合您的充值方式',

        // 等待转账确认
        'waiting_transfer' => '⏳ *等待转账确认*

您的充值订单已生成，请按照以下步骤完成充值：

1️⃣ 复制收款地址
2️⃣ 打开您的钱包APP
3️⃣ 转账到指定地址
4️⃣ 转账完成后点击"转账完成"

⚠️ 请在30分钟内完成转账，否则订单将自动取消',

        // 输入转账凭证
        'order_input' => '🔢 *输入转账凭证*

请输入您的转账订单号或交易哈希：

📝 *格式要求*：
• USDT：输入6位数字订单号
• 汇旺：输入银行转账单号

💡 订单号用于快速确认您的转账',

        // 充值失败
        'failed' => '❌ *充值失败*

很抱歉，您的充值未能成功处理。

可能原因：
• 转账金额不匹配
• 订单号输入错误
• 网络延迟

请联系客服处理或重新尝试',

        // 充值超时
        'timeout' => '⏰ *充值超时*

您的充值订单已超时自动取消。

如果您已完成转账，请联系客服处理。
或者您可以重新发起充值。',
    ],

    // 错误消息模板
    'errors' => [
        // 金额相关错误
        'amount_invalid' => '❌ *金额格式错误*

请输入正确的数字金额，例如：100 或 100.50',

        // 订单相关错误
        'order_invalid' => '❌ *订单号格式错误*

请输入正确的订单号格式：
• USDT：6位数字
• 汇旺：银行转账单号

请重新输入：',

        'order_not_found' => '❌ *订单不存在*

未找到对应的转账记录，请检查：
• 订单号是否正确
• 转账是否已完成
• 网络是否有延迟

请重新输入或联系客服',

        // 系统错误
        'processing_error' => '❌ *充值处理失败*

系统处理您的充值时出现错误，请稍后重试。

如问题持续存在，请联系客服处理。',

        'network_error' => '❌ *网络错误*

网络连接异常，请检查网络后重试。',

        'payment_service_error' => '❌ *支付服务异常*

支付服务暂时不可用，请稍后重试或联系客服。',
    ],

    // 键盘模板（大部分已在Controller中动态生成）
    'keyboards' => [
        // 充值方式选择（回退键盘）
        'methods' => [
            [
                ['text' => '💰 USDT充值', 'callback_data' => 'recharge_usdt']
            ],
            [
                ['text' => '🏦 汇旺充值', 'callback_data' => 'recharge_huiwang']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 等待转账
        'waiting' => [
            [
                ['text' => '✅ 转账完成', 'callback_data' => 'transfer_complete']
            ],
            [
                ['text' => '❌ 取消充值', 'callback_data' => 'cancel_recharge']
            ]
        ],

        // 充值失败
        'failed' => [
            [
                ['text' => '🔄 重新验证', 'callback_data' => 'retry_verify']
            ],
            [
                ['text' => '👨‍💼 联系客服', 'url' => '{customer_service_url}']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 输入取消
        'input_cancel' => [
            [
                ['text' => '❌ 取消输入', 'callback_data' => 'cancel_recharge']
            ]
        ],

        // 错误重试
        'error_retry' => [
            [
                ['text' => '🔄 重新尝试', 'callback_data' => 'retry_recharge']
            ],
            [
                ['text' => '👨‍💼 联系客服', 'url' => '{customer_service_url}']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ]
    ]
];