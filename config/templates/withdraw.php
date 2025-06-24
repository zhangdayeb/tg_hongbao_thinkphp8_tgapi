<?php
/**
 * 提现模板配置文件
 * 
 * 包含提现功能相关的所有消息模板和键盘配置
 */

return [
    // 消息模板
    'messages' => [
        // 提现主界面
        'main' => '💸 *提现中心*

💰 *当前余额*: {balance} USDT
📍 *提现地址*: {address_status}

💡 *提现说明*：
• 最小提现：{min_amount} USDT
• 最大提现：{max_amount} USDT  
• 手续费率：{fee_rate}%
• 处理时间：人工审核，1-24小时

{status_message}',

        // 需要设置密码
        'need_password' => '🔐 *未设置提现密码*

为了您的资金安全，请先设置6位数字提现密码。

⚠️ *重要提醒*：
• 提现密码用于验证提现操作
• 请设置容易记住但不易被猜到的密码
• 密码设置后可以修改，需联系客服

请设置您的提现密码：',

        // 需要绑定地址
        'need_address' => '📍 *未绑定提现地址*

请先绑定您的USDT提现地址（TRC20网络）。

⚠️ *重要提醒*：
• 仅支持TRC20网络的USDT地址
• 地址格式：以T开头，34位字符
• 地址一旦绑定，修改需联系客服审核

请输入您的USDT地址：',

        // 设置密码界面
        'set_password' => '🔐 *设置提现密码*

请输入6位数字密码：

💡 *密码要求*：
• 必须是6位数字
• 不能是连续数字（如123456）
• 不能是重复数字（如111111）

请输入密码：',

        // 确认密码
        'confirm_password' => '🔐 *确认提现密码*

请再次输入刚才设置的6位数字密码：',

        // 密码设置成功
        'password_success' => '✅ *提现密码设置成功*

🎉 恭喜！您的提现密码已设置成功。

现在可以进行提现操作了。',

        // 绑定地址界面
        'bind_address' => '📍 *绑定USDT地址*

请输入您的USDT提现地址（TRC20网络）：

📝 *地址格式*：
• 以字母T开头
• 总共34位字符
• 示例：TQn9Y5nKpUJbaw...（省略）

⚠️ 请确保地址正确，转账后无法撤销',

        // 地址绑定成功
        'address_success' => '✅ *USDT地址绑定成功*

📍 *绑定地址*: `{address}`

🎉 地址已成功绑定，现在可以进行提现操作了。',

        // 输入提现金额
        'enter_amount' => '💸 *申请提现*

💰 *当前余额*: {balance} USDT
📍 *提现地址*: `{address}`

💡 *提现信息*：
• 最小金额：{min_amount} USDT
• 最大金额：{max_amount} USDT
• 手续费率：{fee_rate}%

请输入提现金额：',

        // 确认提现信息
        'confirm' => '💸 *确认提现信息*

💰 *提现金额*: {amount} USDT
💳 *手续费*: {fee} USDT  
💎 *实际到账*: {actual_amount} USDT
📍 *提现地址*: `{address}`

⚠️ *请确认信息无误，提交后需要人工审核*

请输入提现密码确认：',

        // 提现申请成功
        'success' => '✅ *提现申请成功*

🎉 您的提现申请已提交成功！

📄 *申请详情*：
• 订单号：{order_no}
• 提现金额：{amount} USDT
• 手续费：{fee} USDT
• 实际到账：{actual_amount} USDT
• 申请时间：{apply_time}

⏰ 您的申请正在人工审核中，请耐心等待。
📱 审核结果将通过消息通知您。',

        // 提现记录
        'history' => '📋 *提现记录*

{records}

💡 如有疑问请联系客服处理',

        // 修改地址
        'modify_address' => '📍 *修改USDT地址*

当前地址：`{current_address}`

请输入新的USDT地址（TRC20网络）：

📝 *地址格式*：
• 以字母T开头
• 总共34位字符

⚠️ 地址修改需要客服审核',

        // 地址修改成功
        'modify_success' => '✅ *地址修改成功*

📍 *新地址*: `{address}`

🎉 地址已成功修改！',
    ],

    // 错误消息模板
    'errors' => [
        // 密码相关错误
        'password_invalid' => '❌ *提现密码错误*

请输入正确的6位数字提现密码。

💡 如忘记密码请联系客服重置',

        'password_format_error' => '❌ *密码格式错误*

提现密码必须是6位数字，请重新输入：',

        'password_weak' => '❌ *密码强度不够*

不能使用连续数字或重复数字，请重新设置：',

        'password_mismatch' => '❌ *密码不一致*

两次输入的密码不一致，请重新确认：',

        // 地址相关错误
        'address_invalid' => '❌ *地址格式错误*

请输入正确的USDT地址（TRC20网络）：
• 以字母T开头
• 总共34位字符

请重新输入：',

        // 金额相关错误
        'amount_invalid' => '❌ *金额格式错误*

请输入正确的数字金额，例如：100 或 100.50',

        'amount_too_small' => '❌ *提现金额过小*

最小提现金额为 {min_amount} USDT，请重新输入：',

        'amount_too_large' => '❌ *提现金额过大*

最大提现金额为 {max_amount} USDT，请重新输入：',

        'insufficient_balance' => '❌ *余额不足*

您的余额为 {balance} USDT，需要 {required} USDT（含手续费 {fee} USDT）

请重新输入金额：',

        // 系统错误
        'processing_error' => '❌ *处理失败*

系统处理您的请求时出现错误，请稍后重试。

如问题持续存在，请联系客服处理。',

        'timeout' => '⏰ *操作超时*

您的当前操作已超时，请重新选择功能。',
    ],

    // 键盘模板
    'keyboards' => [
        // 提现主界面（条件满足时）
        'main' => [
            [
                ['text' => '💸 开始提现', 'callback_data' => 'start_withdraw']
            ],
            [
                ['text' => '📋 提现记录', 'callback_data' => 'withdraw_history'],
                ['text' => '🔧 修改地址', 'callback_data' => 'modify_address']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 需要设置密码
        'need_password' => [
            [
                ['text' => '🔐 设置提现密码', 'callback_data' => 'set_withdraw_password']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 需要绑定地址
        'need_address' => [
            [
                ['text' => '📍 绑定USDT地址', 'callback_data' => 'bind_usdt_address']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 设置密码操作
        'set_password' => [
            [
                ['text' => '❌ 取消设置', 'callback_data' => 'cancel_withdraw']
            ]
        ],

        // 绑定地址操作
        'bind_address' => [
            [
                ['text' => '❌ 取消绑定', 'callback_data' => 'cancel_withdraw']
            ]
        ],

        // 提现确认
        'confirm' => [
            [
                ['text' => '✅ 确认提现', 'callback_data' => 'confirm_withdraw']
            ],
            [
                ['text' => '❌ 取消提现', 'callback_data' => 'cancel_withdraw']
            ]
        ],

        // 提现成功
        'success' => [
            [
                ['text' => '📋 查看记录', 'callback_data' => 'withdraw_history']
            ],
            [
                ['text' => '🎮 开始游戏', 'url' => '{game_url}']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 提现记录
        'history' => [
            [
                ['text' => '💸 申请提现', 'callback_data' => 'start_withdraw']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 取消操作
        'cancel' => [
            [
                ['text' => '🔙 返回提现中心', 'callback_data' => 'withdraw']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 错误重试
        'error_retry' => [
            [
                ['text' => '🔄 重新尝试', 'callback_data' => 'retry_withdraw']
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