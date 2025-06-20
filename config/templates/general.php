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
        'welcome' => '🌟🌟 CG国际娱乐🌟🌟
🎉诚挚欢迎您的驾临🎉
💎💎  实体赌场直营   💎💎
💎💎      支        持    💎💎
💎💎  帝  豪  赌  场 💎💎
💎💎  星  际  赌  场 💎💎
💎💎  码  房  取  现 💎💎
💎💎  无  需  实  名 💎💎
💎💎注册即可游戏💎💎
💰汇旺、U钱包、ABA充提💰
👍安全可靠   大额无忧👍
🎮真人/电子/棋牌/捕鱼🎮
🔥丰厚大奖，等您来赢🔥',

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
                ['text' => '🌟开始CG国际娱乐在线游戏🔥', 'url' => '{game_url}']
            ],
            [
                ['text' => '✅官方游戏入群✅', 'url' => '{game_group_url}']
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
                ['text' => '✅官方频道', 'url' => '{official_channel_url}'],
                ['text' => '🎁包赢文化', 'url' => '{win_culture_url}'],
                ['text' => '🤝每日吃瓜', 'url' => '{daily_news_url}'],
                ['text' => '📢今日头条', 'url' => '{today_headlines_url}']
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