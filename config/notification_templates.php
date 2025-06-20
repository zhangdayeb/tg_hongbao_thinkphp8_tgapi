<?php
/**
 * 通知消息模板配置文件
 * 适用于 ThinkPHP8 + PHP8.2
 * 注意：不使用 Markdown 格式，避免 * 等特殊字符
 */

return [
    // 系统启动通知模板 - 简单版本
    'system_startup_notify' => [
        'type' => 'text',
        'text' => "🤖 服务上线\n\n⏰ {time}"
    ],

    // 充值通知模板 - 改为GIF图片 + 简单文字
    'recharge_notify' => [
        'type' => 'animation', // 从photo改为animation
        'image_url' => 'https://tgapi.oyim.top/static/recharge_success.gif',  // 充值成功GIF
        'caption' => "🎉 恭喜 {user_name} 成功充值 {money} USDT\n⏰ {create_time}"
    ],

    // 提现通知模板 - 改为GIF图片 + 简单文字
    'withdraw_notify' => [
        'type' => 'animation', // 从photo改为animation
        'image_url' => 'https://tgapi.oyim.top/static/withdraw_success2.gif',  // 提现成功GIF
        'caption' => "💰 恭喜 {user_name} 成功提现 {money} USDT\n⏰ {create_time}"
    ],

    // 红包通知模板 - 先发图片，再发文字和按钮（保持PNG）
    'redpacket_notify' => [
        'type' => 'photo_then_button',  // 🔧 只改这里：photo_and_text_with_button → photo_then_button
        'image_url' => 'https://tgapi.oyim.top/static/redpacket_header.png',  // 红包顶部图片
        'text' => "🧧 {sender_name} 发了一个红包\n\n" .
                 "💵 总金额：{total_amount} USDT\n" .
                 "🎁 个数：{total_count}个\n" .
                 "💝 {title}",
        'button' => [
            'text' => '🎁 抢红包',
            'callback_data' => 'grab_redpacket_{packet_id}'
        ]
    ],

    // 广告通知模板 - 图片 + 底部文字
    'advertisement_notify' => [
        'type' => 'photo',
        'image_url' => '{image_url}', // 从广告记录中获取
        'caption' => "{content}"
    ],

    // 变量映射配置 - 用于数据转换
    'variable_mapping' => [
        // 充值相关
        'payment_method' => [
            'huiwang' => '汇旺转账',
            'usdt' => 'USDT转账'
        ],
        
        // 红包类型
        'packet_type' => [
            1 => '拼手气红包',
            2 => '平均红包'
        ]
    ],

    // 默认值配置
    'default_values' => [
        'user_name' => '匿名用户',
        'title' => '恭喜发财，大吉大利',
        'image_url' => 'https://tgapi.oyim.top/static/default.png'
    ],

    // 时间格式配置
    'time_format' => [
        'datetime' => 'Y-m-d H:i:s',
        'date' => 'Y-m-d',
        'time' => 'H:i:s'
    ],

    // 金额格式配置
    'amount_format' => [
        'decimals' => 2,
        'decimal_separator' => '.',
        'thousands_separator' => ''
    ],

    // 默认媒体文件配置
    'default_media' => [
        // 默认GIF动图（充值提现改为GIF）
        'recharge_gif' => 'https://tgapi.oyim.top/static/recharge_success.gif',
        'withdraw_gif' => 'https://tgapi.oyim.top/static/withdraw_success2.gif',
        
        // 默认图片（红包等保持PNG）
        'redpacket_header' => 'https://tgapi.oyim.top/static/redpacket_header.png',
        'advertisement' => 'https://tgapi.oyim.top/static/default.png'
    ],

    // 默认图片配置（保留兼容性）
    'default_images' => [
        'recharge' => 'https://tgapi.oyim.top/static/default.png',
        'withdraw' => 'https://tgapi.oyim.top/static/default.png',
        'advertisement' => 'https://tgapi.oyim.top/static/default.png'
    ]
];