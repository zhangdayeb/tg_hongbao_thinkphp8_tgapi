<?php
/**
 * 游戏系统配置文件
 * 
 * 包含游戏接口配置、游戏列表、跳转参数等
 */

return [
    // 游戏平台基本配置
    'platform' => [
        'name' => '盛邦国际娱乐城',
        'code' => 'SHENGHANG',
        'api_url' => env('GAME_API_URL', ''),
        'api_key' => env('GAME_API_KEY', ''),
        'api_secret' => env('GAME_API_SECRET', ''),
        'merchant_code' => env('GAME_MERCHANT_CODE', ''),
        'currency' => 'USDT',
        'language' => 'zh-CN',
        'timezone' => 'Asia/Shanghai',
    ],
    
    // API接口配置
    'api' => [
        'timeout' => 30, // 请求超时时间(秒)
        'retry_count' => 3, // 重试次数
        'retry_delay' => 2, // 重试延迟(秒)
        'log_requests' => true, // 记录请求日志
        'log_responses' => true, // 记录响应日志
        'encrypt_data' => true, // 数据加密
        'sign_requests' => true, // 请求签名
    ],
    
    // 用户相关配置
    'user' => [
        'auto_create' => true, // 自动创建游戏账号
        'username_prefix' => 'SB', // 用户名前缀
        'password_length' => 8, // 默认密码长度
        'default_password' => 'a123456', // 默认密码
        'sync_balance' => true, // 同步余额
        'auto_transfer' => true, // 自动转账
    ],
    
    // 余额配置
    'balance' => [
        'sync_interval' => 300, // 余额同步间隔(秒)
        'auto_sync' => true, // 自动同步
        'min_transfer' => 1.00, // 最小转账金额
        'max_transfer' => 100000.00, // 最大转账金额
        'transfer_fee' => 0, // 转账手续费
        'precision' => 2, // 金额精度
    ],
    
    // 游戏分类配置
    'categories' => [
        'live' => [
            'name' => '真人视讯',
            'code' => 'live',
            'icon' => '🎭',
            'description' => '真人荷官，实时对战',
            'enabled' => true,
            'sort_order' => 1,
            'featured' => true,
        ],
        'slot' => [
            'name' => '电子游戏',
            'code' => 'slot',
            'icon' => '🎰',
            'description' => '经典老虎机，海量奖池',
            'enabled' => true,
            'sort_order' => 2,
            'featured' => true,
        ],
        'sport' => [
            'name' => '体育竞猜',
            'code' => 'sport',
            'icon' => '⚽',
            'description' => '体育赛事，实时竞猜',
            'enabled' => true,
            'sort_order' => 3,
            'featured' => true,
        ],
        'lottery' => [
            'name' => '彩票游戏',
            'code' => 'lottery',
            'icon' => '🎱',
            'description' => '数字彩票，中奖率高',
            'enabled' => true,
            'sort_order' => 4,
            'featured' => false,
        ],
        'card' => [
            'name' => '棋牌游戏',
            'code' => 'card',
            'icon' => '🃏',
            'description' => '经典棋牌，策略对战',
            'enabled' => true,
            'sort_order' => 5,
            'featured' => false,
        ]
    ],
    
    // 游戏提供商配置
    'providers' => [
        'evolution' => [
            'name' => 'Evolution Gaming',
            'code' => 'EVO',
            'type' => 'live',
            'enabled' => true,
            'api_url' => '',
            'game_prefix' => 'EVO_',
            'featured' => true,
        ],
        'pragmatic' => [
            'name' => 'Pragmatic Play',
            'code' => 'PP',
            'type' => 'slot',
            'enabled' => true,
            'api_url' => '',
            'game_prefix' => 'PP_',
            'featured' => true,
        ],
        'netent' => [
            'name' => 'NetEnt',
            'code' => 'NET',
            'type' => 'slot',
            'enabled' => true,
            'api_url' => '',
            'game_prefix' => 'NET_',
            'featured' => false,
        ]
    ],
    
    // 热门游戏配置
    'featured_games' => [
        [
            'name' => '百家乐',
            'code' => 'EVO_BACCARAT',
            'category' => 'live',
            'provider' => 'evolution',
            'image' => '/images/games/baccarat.jpg',
            'description' => '经典百家乐，简单易上手',
            'min_bet' => 1.00,
            'max_bet' => 10000.00,
            'rtp' => 98.94,
            'featured' => true,
            'sort_order' => 1,
        ],
        [
            'name' => '龙虎斗',
            'code' => 'EVO_DRAGON_TIGER',
            'category' => 'live',
            'provider' => 'evolution',
            'image' => '/images/games/dragon_tiger.jpg',
            'description' => '刺激龙虎斗，50%胜率',
            'min_bet' => 1.00,
            'max_bet' => 5000.00,
            'rtp' => 96.27,
            'featured' => true,
            'sort_order' => 2,
        ],
        [
            'name' => '疯狂老虎机',
            'code' => 'PP_SWEET_BONANZA',
            'category' => 'slot',
            'provider' => 'pragmatic',
            'image' => '/images/games/sweet_bonanza.jpg',
            'description' => '甜蜜爆分，最高21000倍',
            'min_bet' => 0.20,
            'max_bet' => 100.00,
            'rtp' => 96.48,
            'featured' => true,
            'sort_order' => 3,
        ]
    ],
    
    // 游戏链接配置
    'game_url' => [
        'base_url' => env('GAME_BASE_URL', ''),
        'login_url' => env('GAME_LOGIN_URL', ''),
        'logout_url' => env('GAME_LOGOUT_URL', ''),
        'demo_url' => env('GAME_DEMO_URL', ''),
        'mobile_url' => env('GAME_MOBILE_URL', ''),
        'token_expire' => 3600, // Token过期时间(秒)
        'session_timeout' => 7200, // 会话超时时间(秒)
    ],
    
    // 游戏大厅配置
    'lobby' => [
        'default_category' => 'live', // 默认分类
        'games_per_page' => 20, // 每页游戏数
        'cache_duration' => 1800, // 缓存时间(秒)
        'show_jackpot' => true, // 显示奖池
        'show_hot_games' => true, // 显示热门游戏
        'show_new_games' => true, // 显示新游戏
        'enable_search' => true, // 启用搜索
        'enable_filter' => true, // 启用筛选
    ],
    
    // 试玩配置
    'demo' => [
        'enabled' => true, // 启用试玩
        'balance' => 1000.00, // 试玩金额
        'reset_interval' => 3600, // 重置间隔(秒)
        'max_duration' => 1800, // 最大试玩时间(秒)
        'available_games' => ['slot', 'card'], // 可试玩游戏类型
    ],
    
    // 投注限制配置
    'betting_limits' => [
        'min_bet' => 0.10, // 全局最小投注
        'max_bet' => 50000.00, // 全局最大投注
        'daily_loss_limit' => 100000.00, // 每日亏损限制
        'session_loss_limit' => 10000.00, // 单次游戏亏损限制
        'cooling_off_period' => 86400, // 冷却期(秒)
    ],
    
    // 游戏记录配置
    'game_records' => [
        'sync_enabled' => true, // 启用记录同步
        'sync_interval' => 300, // 同步间隔(秒)
        'record_details' => true, // 记录详细信息
        'keep_days' => 90, // 记录保存天数
        'real_time_update' => true, // 实时更新
    ],
    
    // 奖池配置
    'jackpot' => [
        'enabled' => true, // 启用奖池
        'contribution_rate' => 0.01, // 贡献率
        'min_trigger' => 1000.00, // 最小触发金额
        'max_prize' => 1000000.00, // 最大奖金
        'display_format' => 'USDT {amount}', // 显示格式
        'update_interval' => 60, // 更新间隔(秒)
    ],
    
    // 活动配置
    'promotions' => [
        'welcome_bonus' => [
            'enabled' => true,
            'amount' => 100.00, // 欢迎奖金
            'min_deposit' => 50.00, // 最小充值
            'wagering_requirement' => 30, // 流水要求倍数
        ],
        'daily_bonus' => [
            'enabled' => true,
            'amount' => 10.00, // 每日奖金
            'min_play_time' => 1800, // 最小游戏时间(秒)
        ],
        'cashback' => [
            'enabled' => true,
            'rate' => 0.05, // 返水比例
            'min_loss' => 100.00, // 最小亏损
            'max_cashback' => 1000.00, // 最大返水
        ]
    ],
    
    // 安全配置
    'security' => [
        'enable_fraud_detection' => true, // 启用欺诈检测
        'max_sessions_per_user' => 3, // 每用户最大会话数
        'ip_whitelist' => [], // IP白名单
        'block_vpn' => false, // 阻止VPN
        'require_verification' => false, // 需要身份验证
        'audit_logs' => true, // 审计日志
    ],
    
    // 移动端配置
    'mobile' => [
        'responsive_design' => true, // 响应式设计
        'touch_optimized' => true, // 触控优化
        'app_download_url' => '', // APP下载地址
        'force_landscape' => false, // 强制横屏
        'show_fullscreen' => true, // 显示全屏按钮
    ],
    
    // 客服配置
    'customer_service' => [
        'enabled' => true,
        'chat_url' => env('GAME_CHAT_URL', ''),
        'support_email' => env('GAME_SUPPORT_EMAIL', ''),
        'support_phone' => env('GAME_SUPPORT_PHONE', ''),
        'working_hours' => '24/7',
        'languages' => ['zh', 'en', 'km'],
    ],
    
    // 统计配置
    'statistics' => [
        'track_gameplay' => true, // 跟踪游戏过程
        'track_preferences' => true, // 跟踪偏好
        'generate_reports' => true, // 生成报告
        'real_time_analytics' => true, // 实时分析
    ],
    
    // 测试模式配置
    'test_mode' => [
        'enabled' => env('GAME_TEST_MODE', false),
        'fake_balance' => 10000.00, // 虚假余额
        'mock_api' => true, // 模拟API
        'skip_authentication' => false, // 跳过认证
        'debug_mode' => true, // 调试模式
    ]
];