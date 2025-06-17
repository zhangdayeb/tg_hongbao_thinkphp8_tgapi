<?php
/**
 * Telegram Bot 系统配置文件
 * 
 * 仅包含系统级配置，不包含模板内容
 * 模板内容已拆分到 config/templates/ 目录下
 */

return [
    // Bot 基本信息
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
    'bot_name' => env('TELEGRAM_BOT_NAME', '盛邦娱乐机器人'),
    
    // API 配置
    'api_url' => 'https://api.telegram.org/bot',
    'file_api_url' => 'https://api.telegram.org/file/bot',
    'timeout' => 30, // API请求超时时间(秒)
    'retry_count' => 3, // 重试次数
    'retry_delay' => 1, // 重试延迟(秒)
    
    // Webhook 配置
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),
    'webhook_secret_token' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    'webhook_max_connections' => 40,
    'webhook_allowed_updates' => [
        'message',
        'callback_query',
        'inline_query',
        'chosen_inline_result'
    ],
    
    // 外部链接配置
    'links' => [
        // 游戏相关
        'game_url' => env('GAME_ENTRANCE_URL', 'https://game.tgapi.oyim.top'),
        'game_group_url' => env('GAME_GROUP_URL', 'https://t.me/+IN8NfevhJuI4NTJl'),
        
        // 客服相关
        'customer_service_url' => env('CUSTOMER_SERVICE_URL', 'https://t.me/xg_soft_bot'),
        'finance_service_url' => env('FINANCE_SERVICE_URL', 'https://t.me/xiaoxiaoxiaomama'),
        
        // 其他链接
        'official_channel_url' => env('OFFICIAL_CHANNEL_URL', 'https://t.me/your_official_channel'),
        'win_culture_url' => env('WIN_CULTURE_URL', 'https://www.google.com'),
        'daily_news_url' => env('DAILY_NEWS_URL', 'https://www.google.com'), 
        'today_headlines_url' => env('TODAY_HEADLINES_URL', 'https://www.google.com'),
    ],
    
    // 业务配置
    'withdraw' => [
        'min_amount' => 10.00,           // 最小提现金额
        'max_amount' => 10000.00,        // 最大提现金额
        'fee_rate' => 0.02,              // 手续费率 2%
        'fee_min' => 1.00,               // 最小手续费
        'fee_max' => 100.00,             // 最大手续费
        'password_length' => 6,          // 密码长度
        'session_timeout' => 1800,       // 会话超时时间(秒) 30分钟
        'daily_limit' => 50000.00,       // 每日提现限额
        'monthly_limit' => 500000.00,    // 每月提现限额
    ],
    
    'payment' => [
        'usdt' => [
            'min_amount' => 20,
            'max_amount' => 50000,
            'fee_rate' => 0.00,
            'timeout' => 1800, // 30分钟
        ],
        'huiwang' => [
            'min_amount' => 100,
            'max_amount' => 100000,
            'fee_rate' => 0.02,
            'timeout' => 1800,
        ],
    ],
    
    // 功能开关
    'features' => [
        'user_registration' => true,     // 用户注册
        'payment_system' => true,        // 支付系统
        'withdraw_system' => true,       // 提现系统
        'redpacket_system' => true,      // 红包系统
        'game_integration' => true,      // 游戏集成
        'invitation_system' => true,     // 邀请系统
        'admin_panel' => true,           // 管理面板
        'statistics' => true,            // 统计功能
        'broadcast' => true,             // 广播功能
        'group_management' => true,      // 群组管理
        'file_upload' => false,          // 文件上传
        'inline_mode' => false,          // 内联模式
        'payment_flow' => true,          // 充值流程
        'withdraw_flow' => true,         // 提现流程
        'template_cache' => true,        // 模板缓存
    ],
    
    // 文件上传限制
    'max_file_size' => 20 * 1024 * 1024, // 20MB
    'allowed_file_types' => [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'document' => ['pdf', 'doc', 'docx', 'txt'],
        'video' => ['mp4', 'avi', 'mov'],
        'audio' => ['mp3', 'wav', 'ogg']
    ],
    
    // 消息限制
    'message_max_length' => 4096,        // 单条消息最大长度
    'caption_max_length' => 1024,        // 图片说明最大长度
    'inline_keyboard_max_buttons' => 100, // 内联键盘最大按钮数
    
    // 安全设置
    'rate_limit' => [
        'messages_per_second' => 30,     // 每秒最大发送消息数
        'messages_per_minute' => 20,     // 每分钟向同一聊天发送消息数
        'bulk_messages_per_second' => 30, // 群发时每秒消息数
    ],
    
    // IP白名单 (Telegram服务器IP段)
    'allowed_ips' => [
        '149.154.160.0/20',
        '91.108.4.0/22',
        '91.108.56.0/22',
        '109.239.140.0/24',
        '149.154.164.0/22',
        '149.154.168.0/22',
        '149.154.172.0/22',
        '134.122.197.44/32', // 您的服务器IP
    ],
    
    // 调试配置
    'debug' => [
        'enabled' => env('APP_DEBUG', false),
        'skip_ip_check' => env('APP_DEBUG', false),
        'skip_secret_check' => env('APP_DEBUG', false),
        'log_all_requests' => env('APP_DEBUG', false),
        'detailed_errors' => env('APP_DEBUG', false),
    ],
    
    // 命令配置
    'commands' => [
        'start' => '开始使用机器人',
        'help' => '获取帮助信息',
        'profile' => '个人中心',
        'balance' => '查看余额',
        'recharge' => '充值',
        'withdraw' => '提现',
        'invite' => '邀请好友',
        'service' => '联系客服',
        // ===== 新增红包相关命令 =====
        'red' => '发红包 - 格式: /red 金额 个数 [标题]',
        'myreds' => '查看我的红包记录',
        // ===== 红包命令新增结束 =====
    ],
    
    // 模板配置
    'template' => [
        'cache_enabled' => true,         // 是否启用模板缓存
        'cache_ttl' => 3600,            // 缓存时间（秒）
        'auto_reload' => env('APP_DEBUG', false), // 调试模式自动重载
        // ===== 新增模板路径配置 =====
        'path' => config_path() . 'templates/', // 模板文件路径
        // ===== 模板路径配置新增结束 =====
    ]
];