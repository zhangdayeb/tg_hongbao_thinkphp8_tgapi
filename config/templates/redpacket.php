<?php
/**
 * 红包模板配置文件
 * 
 * 包含红包功能相关的所有消息模板和键盘配置
 */

return [
    // 消息模板
    'messages' => [
        // 发红包引导说明
        'help' => '🧧 *红包功能说明*

💡 *发红包格式*：
`/red 金额 个数 [标题]`

📝 *使用示例*：
• `/red 100 10` - 发100USDT拼手气红包，10个
• `/red 50 5 恭喜发财` - 发50USDT红包，标题"恭喜发财"

⚠️ *发红包规则*：
• 最小金额：{min_amount} USDT
• 最大金额：{max_amount} USDT
• 最小个数：{min_count} 个
• 最大个数：{max_count} 个
• 单个最小：{min_single_amount} USDT

💰 当前余额：{balance} USDT',

        // 群内红包卡片
        'packet_card' => '🧧 *{title}*

💰 总金额：`{total_amount} USDT`
📦 红包个数：{total_count} 个
🎲 红包类型：拼手气红包
👤 发送者：{sender_name}
⏰ 有效期：{expire_time}

🔥 剩余：{remain_count}个 | {remain_amount} USDT

点击下方按钮抢红包！',

        // 发红包成功通知
        'send_success' => '🎁 *红包发送成功！*

💰 总金额：{total_amount} USDT
📦 红包个数：{count} 个
⏰ 有效期：{expire_time}

💸 已从余额扣除：{total_amount} USDT
💼 当前余额：{balance} USDT

🎉 红包已发送到群内，快去看看吧！',

        // 抢红包成功通知
        'grab_success' => '🎉 *恭喜你抢到红包！*

💰 抢到金额：`{amount} USDT`
🎲 运气等级：{luck_rank}
📍 抢红包顺序：第{grab_order}个
🏆 {best_luck_text}

💼 当前余额：{balance} USDT

🎊 恭喜发财，大吉大利！',

        // 红包完成通知
        'packet_completed' => '🎉 *红包已抢完！*

💰 总金额：`{total_amount} USDT`
👥 参与人数：{participant_count} 人
⏱️ 耗时：{duration}

🏆 *手气最佳*：{best_user}
💎 最佳金额：`{best_amount} USDT`

🎊 感谢大家的参与！',

        // 红包过期通知
        'packet_expired' => '⏰ *红包已过期*

💰 剩余金额：`{remain_amount} USDT`
📦 剩余个数：{remain_count} 个

💸 未领取的金额已退回发送者账户
👤 发送者：{sender_name}

⌛ 红包有效期为24小时',

        // 我的红包记录
        'my_records' => '📋 *我的红包记录*

📊 *统计信息*：
• 发送红包：{sent_count} 个，总计 {sent_amount} USDT
• 抢到红包：{received_count} 个，总计 {received_amount} USDT
• 手气最佳：{best_luck_count} 次

📝 *最近记录*：
{recent_records}

💡 更多记录请在个人中心查看',

        // 红包详情信息
        'packet_detail' => '📊 *红包详情*

🧧 *基本信息*：
• 红包标题：{title}
• 总金额：`{total_amount} USDT`
• 红包个数：{total_count} 个
• 发送者：{sender_name}
• 创建时间：{create_time}

📈 *进度信息*：
• 已抢个数：{grabbed_count} 个
• 已抢金额：`{grabbed_amount} USDT`
• 剩余个数：{remain_count} 个
• 剩余金额：`{remain_amount} USDT`
• 完成进度：{progress}%

🏆 *抢红包记录*：
{grab_records}',

        // 红包记录条目
        'record_item' => '{emoji} {username} - `{amount} USDT` {time_ago}',
    ],

    // 错误消息模板
    'errors' => [
        // 余额不足
        'insufficient_balance' => '❌ *余额不足*

💰 当前余额：{balance} USDT
💸 需要金额：{required} USDT

请先充值后再发红包',

        // 命令格式错误
        'invalid_format' => '❌ *命令格式错误*

📝 *正确格式*：
`/red 金额 个数 [标题]`

💡 *示例*：
• `/red 100 10` - 发100USDT，10个红包
• `/red 50 5 恭喜发财` - 发50USDT，标题"恭喜发财"

请重新输入正确的命令',

        // 金额参数错误
        'invalid_amount' => '❌ *金额错误*

💰 金额范围：{min_amount} - {max_amount} USDT
📝 您输入的金额：{input_amount}

请输入正确的金额范围',

        // 个数参数错误
        'invalid_count' => '❌ *红包个数错误*

📦 个数范围：{min_count} - {max_count} 个
📝 您输入的个数：{input_count}

请输入正确的红包个数',

        // 单个红包金额过小
        'amount_too_small' => '❌ *单个红包金额过小*

💰 总金额：{total_amount} USDT
📦 红包个数：{count} 个
💎 平均金额：{average_amount} USDT
⚠️ 单个最小：{min_single_amount} USDT

请调整金额或个数',

        // 重复抢红包
        'already_grabbed' => '❌ *您已抢过这个红包*

💰 您抢到的金额：`{amount} USDT`
🎲 运气等级：{luck_rank}
⏰ 抢红包时间：{grab_time}

一个红包只能抢一次哦',

        // 红包不存在
        'packet_not_found' => '❌ *红包不存在*

可能的原因：
• 红包已过期
• 红包已被抢完
• 红包ID无效

请检查后重试',

        // 红包已抢完
        'packet_completed_error' => '❌ *很遗憾，红包已被抢完*

🏃‍♂️ 下次要手快一点哦！

💡 关注群内消息，及时参与抢红包',

        // 红包已过期
        'packet_expired_error' => '❌ *红包已过期*

⏰ 红包有效期为24小时
🕐 此红包已超过有效期

💡 关注最新红包，及时参与',

        // 不能抢自己的红包
        'cannot_grab_own' => '❌ *不能抢自己的红包*

🎁 您发的红包是给大家的礼物
💰 自己发的红包不能自己抢

感谢您的慷慨分享！',

        // 系统错误
        'system_error' => '❌ *系统处理失败*

系统处理红包时出现异常，请稍后重试。

如问题持续存在，请联系客服处理。',

        // 网络错误
        'network_error' => '❌ *网络连接异常*

网络连接不稳定，请检查网络后重试。',

        // 群组权限错误
        'group_permission_error' => '❌ *群组权限不足*

当前群组不支持红包功能，或机器人权限不足。

请联系群主或管理员处理。',

        // 用户限制错误
        'user_limit_exceeded' => '❌ *达到发红包限制*

📊 每日限制：{daily_limit} 个
📈 已发送：{sent_today} 个
⏰ 限制重置时间：明日0点

请明天再发红包',

        // 操作频繁错误
        'rate_limit_exceeded' => '❌ *操作过于频繁*

⏰ 请等待 {wait_seconds} 秒后再试

为了系统稳定，请不要频繁操作',
    ],

    // 键盘模板
    'keyboards' => [
        // 抢红包按钮
        'grab_packet' => [
            [
                ['text' => '🧧 抢红包', 'callback_data' => 'grab_red_packet:{packet_id}']
            ],
            [
                ['text' => '📊 红包详情', 'callback_data' => 'red_packet_detail:{packet_id}']
            ]
        ],

        // 红包详情键盘
        'packet_detail' => [
            [
                ['text' => '🧧 抢红包', 'callback_data' => 'grab_red_packet:{packet_id}']
            ],
            [
                ['text' => '🔙 返回', 'callback_data' => 'close_detail']
            ]
        ],

        // 红包已完成
        'packet_completed' => [
            [
                ['text' => '📊 查看详情', 'callback_data' => 'red_packet_detail:{packet_id}']
            ]
        ],

        // 我的红包记录
        'my_records' => [
            [
                ['text' => '🧧 发个红包', 'callback_data' => 'send_redpacket_guide']
            ],
            [
                ['text' => '👤 个人中心', 'callback_data' => 'profile'],
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 发红包成功
        'send_success' => [
            [
                ['text' => '📊 查看红包', 'callback_data' => 'red_packet_detail:{packet_id}']
            ],
            [
                ['text' => '🧧 再发一个', 'callback_data' => 'send_redpacket_guide']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 错误重试
        'error_retry' => [
            [
                ['text' => '🔄 重新尝试', 'callback_data' => 'retry_redpacket']
            ],
            [
                ['text' => '❓ 查看帮助', 'callback_data' => 'redpacket_help']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 红包帮助
        'help' => [
            [
                ['text' => '🧧 发个红包', 'callback_data' => 'send_redpacket_guide']
            ],
            [
                ['text' => '📋 我的记录', 'callback_data' => 'my_redpacket_records']
            ],
            [
                ['text' => '🔙 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 抢红包成功
        'grab_success' => [
            [
                ['text' => '💰 查看余额', 'callback_data' => 'check_balance']
            ],
            [
                ['text' => '🧧 发个红包', 'callback_data' => 'send_redpacket_guide']
            ],
            [
                ['text' => '🎮 开始游戏', 'url' => '{game_url}']
            ]
        ],

        // 网络错误重试
        'network_retry' => [
            [
                ['text' => '🔄 重新加载', 'callback_data' => 'reload_redpacket']
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