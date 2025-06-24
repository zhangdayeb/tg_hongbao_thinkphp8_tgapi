<?php
/**
 * 通用模板配置文件 - 精简版
 * 
 * 只包含真正通用的功能：主菜单、帮助、基础消息
 * 各功能模块的键盘和消息由对应的控制器自行处理
 */

return [
    // 通用消息模板
    'messages' => [
        // 欢迎消息
        'welcome' => '[welcome]',

        // 帮助信息
        'help' => '🤖 *CG国际娱乐机器人帮助*

📋 *可用命令*：
• /start - 显示主菜单
• /help - 显示此帮助

💡 *使用提示*：
• 点击菜单按钮进行操作
• 如有问题请联系客服

🎮 祝您游戏愉快！',

        // 未知命令
        'unknown_command' => '❓ *未知命令*

请使用以下有效命令：
• /start - 主菜单
• /help - 帮助信息

💡 建议使用菜单按钮操作',

        // 功能开发中
        'under_development' => '🚧 *功能开发中*

该功能正在紧急开发中，敬请期待！

如有紧急需求，请联系客服处理。',

        // 系统错误
        'system_error' => '⚠️ *系统异常*

系统处理请求时出现异常，请稍后重试。

如问题持续出现，请联系客服处理。',
    ],

    // 通用键盘模板
    'keyboards' => [
        // 主菜单键盘 - 唯一真正通用的键盘
        'main_menu' => [
            [
                ['text' => '[button1_name]', 'url' => '[button1_url]']
            ],
            [
                ['text' => '[button2_name]', 'url' => '[button2_url]']
            ],
            [
                ['text' => '🎰唯一客服', 'url' => '{customer_service_url}'],
                ['text' => '💰唯一财务', 'url' => '{finance_service_url}']
            ],
            [
                ['text' => '👤个人中心', 'callback_data' => 'profile'],
                ['text' => '💎邀请好友', 'callback_data' => 'invite']
            ],
            [
                ['text' => '💸充值', 'callback_data' => 'recharge'],
                ['text' => '💸提现', 'callback_data' => 'withdraw']
            ],
            [
                ['text' => '[button3_name]', 'url' => '[button3_url]'],
                ['text' => '[button4_name]', 'url' => '[button4_url]'],
                ['text' => '[button5_name]', 'url' => '[button5_url]'],
                ['text' => '[button6_name]', 'url' => '[button6_url]']
            ]
        ],

        // 帮助键盘
        'help' => [
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 基础返回键盘 - 最通用的键盘
        'back_only' => [
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],
    ],

    // 通用图标
    'icons' => [
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️',
        'loading' => '⏳',
        'back' => '🔙',
        'home' => '🏠',
        'help' => '❓',
    ]
];