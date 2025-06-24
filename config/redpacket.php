<?php
/**
 * 红包系统配置文件
 * 
 * 包含红包规则、限额设置、算法参数等
 */

return [
    // 红包基本配置
    'basic' => [
        'enabled' => true, // 红包功能总开关
        'min_amount' => 1.00, // 红包最小总金额
        'max_amount' => 10000.00, // 红包最大总金额
        'min_count' => 1, // 红包最小个数
        'max_count' => 100, // 红包最大个数
        'min_single_amount' => 0.01, // 单个红包最小金额
        'max_single_amount' => 1000.00, // 单个红包最大金额
        'expire_hours' => 24, // 红包过期时间(小时)
        'precision' => 2, // 金额精度(小数位)
    ],
    
    // 红包类型配置
    'types' => [
        'random' => [
            'name' => '拼手气红包',
            'code' => 'random',
            'enabled' => true,
            'description' => '随机金额，拼人品',
            'algorithm' => 'random_split',
            'icon' => '🎲',
            'sort_order' => 1
        ],
        'average' => [
            'name' => '普通红包',
            'code' => 'average',
            'enabled' => true,
            'description' => '平均分配，人人有份',
            'algorithm' => 'average_split',
            'icon' => '📦',
            'sort_order' => 2
        ],
        'custom' => [
            'name' => '定制红包',
            'code' => 'custom',
            'enabled' => false,
            'description' => '自定义每个红包金额',
            'algorithm' => 'custom_split',
            'icon' => '🎨',
            'sort_order' => 3
        ]
    ],
    
    // 用户限制配置
    'user_limits' => [
        'daily_send_count' => 10, // 每日发红包次数限制
        'daily_send_amount' => 1000.00, // 每日发红包金额限制
        'daily_receive_count' => 50, // 每日抢红包次数限制
        'hourly_send_count' => 3, // 每小时发红包次数限制
        'min_balance_required' => 0.01, // 发红包最小余额要求
        'new_user_limit_days' => 3, // 新用户限制天数
        'new_user_max_amount' => 100.00, // 新用户最大红包金额
        'vip_multiplier' => 2.0, // VIP用户限制倍数
    ],
    
    // 群组限制配置
    'group_limits' => [
        'max_concurrent_packets' => 5, // 群内最大并发红包数
        'min_members_required' => 3, // 发红包最少群成员数
        'admin_only' => false, // 是否仅管理员可发红包
        'silence_period' => 300, // 红包间隔时间(秒)
        'max_daily_packets' => 20, // 群内每日最大红包数
    ],
    
    // 算法配置
    'algorithms' => [
        'random_split' => [
            'name' => '拼手气算法',
            'variance_factor' => 0.8, // 方差因子，控制随机程度
            'min_ratio' => 0.01, // 单个红包最小比例
            'max_ratio' => 0.90, // 单个红包最大比例
            'balance_threshold' => 0.3, // 平衡阈值
        ],
        'average_split' => [
            'name' => '平均分配算法',
            'allow_variance' => false, // 是否允许微小差异
            'variance_range' => 0.01, // 差异范围
        ],
        'custom_split' => [
            'name' => '自定义算法',
            'validate_total' => true, // 验证总金额
            'auto_adjust' => true, // 自动调整
        ]
    ],
    
    // 抢红包规则
    'grab_rules' => [
        'allow_self_grab' => false, // 是否允许自己抢自己的红包
        'require_group_member' => true, // 是否需要群成员身份
        'min_join_time' => 0, // 入群最少时间(秒)
        'cooldown_seconds' => 1, // 抢红包冷却时间
        'max_attempts' => 3, // 最大尝试次数
        'concurrent_limit' => 1, // 并发抢红包限制
    ],
    
    // 红包状态配置
    'status' => [
        'active' => 1, // 进行中
        'completed' => 2, // 已抢完
        'expired' => 3, // 已过期
        'revoked' => 4, // 已撤回
        'canceled' => 5, // 已取消
    ],
    
    // 红包奖励配置
    'rewards' => [
        'best_luck' => [
            'enabled' => true, // 是否启用手气最佳
            'bonus_rate' => 0.05, // 额外奖励比例
            'min_participants' => 3, // 最少参与人数
            'announcement' => true, // 是否公告
        ],
        'participation' => [
            'enabled' => false, // 参与奖励
            'amount' => 0.01, // 参与奖励金额
            'source' => 'system', // 奖励来源
        ]
    ],
    
    // 红包显示配置
    'display' => [
        'show_grabber_info' => true, // 显示抢红包用户信息
        'show_amounts' => true, // 显示具体金额
        'show_remaining' => true, // 显示剩余信息
        'show_best_luck' => true, // 显示手气最佳
        'animate_grab' => true, // 抢红包动画
        'sound_effects' => false, // 音效
    ],
    
    // 消息模板配置
    'messages' => [
        'send_success' => "🎁 红包发送成功！\n\n💰 总金额：{total_amount} USDT\n📦 红包个数：{count} 个\n⏰ 有效期：{expire_time}\n\n快来抢红包吧！",
        'grab_success' => "🎉 恭喜你抢到红包！\n\n💰 金额：{amount} USDT\n🎲 运气：{luck_rank}\n💼 当前余额：{balance} USDT",
        'grab_failed' => "😔 很遗憾，红包已被抢完了！\n\n下次要手快一点哦~",
        'expired' => "⏰ 红包已过期\n\n未领取的金额已退回发送者账户",
        'best_luck' => "🏆 恭喜 {username} 获得手气最佳！\n\n💰 金额：{amount} USDT",
        'insufficient_balance' => "💰 余额不足，无法发送红包\n\n当前余额：{balance} USDT\n需要金额：{required} USDT",
        'limit_exceeded' => "⚠️ 已达到发红包限制\n\n每日限制：{daily_limit} 个\n已发送：{sent_count} 个",
    ],
    
    // 统计配置
    'statistics' => [
        'enabled' => true,
        'real_time' => true, // 实时统计
        'daily_stats' => true, // 每日统计
        'user_ranking' => true, // 用户排行
        'group_ranking' => true, // 群组排行
        'cache_ttl' => 300, // 统计缓存时间(秒)
    ],
    
    // 安全配置
    'security' => [
        'enable_captcha' => false, // 启用验证码
        'rate_limit' => [
            'grab_per_minute' => 10, // 每分钟抢红包次数
            'send_per_hour' => 5, // 每小时发红包次数
        ],
        'fraud_detection' => [
            'enabled' => true,
            'suspicious_patterns' => [
                'same_ip_multiple_grabs' => 5, // 同IP多次抢红包
                'rapid_grab_sequence' => 3, // 快速连续抢红包
                'unusual_timing' => true, // 异常时间模式
            ]
        ],
        'blacklist' => [
            'enabled' => true,
            'auto_add' => true, // 自动添加可疑用户
            'check_interval' => 3600, // 检查间隔(秒)
        ]
    ],
    
    // 通知配置
    'notifications' => [
        'send_notification' => [
            'enabled' => true,
            'channels' => ['telegram'],
            'template' => 'redpacket_sent'
        ],
        'grab_notification' => [
            'enabled' => true,
            'channels' => ['telegram'],
            'template' => 'redpacket_grabbed'
        ],
        'expire_notification' => [
            'enabled' => true,
            'channels' => ['telegram', 'database'],
            'template' => 'redpacket_expired'
        ],
        'best_luck_notification' => [
            'enabled' => true,
            'channels' => ['telegram'],
            'template' => 'redpacket_best_luck'
        ]
    ],
    
    // 定时任务配置
    'scheduled_tasks' => [
        'expire_check' => [
            'enabled' => true,
            'interval' => 300, // 检查间隔(秒)
            'batch_size' => 100, // 每次处理数量
        ],
        'statistics_update' => [
            'enabled' => true,
            'interval' => 3600, // 更新间隔(秒)
        ],
        'cleanup_expired' => [
            'enabled' => true,
            'interval' => 86400, // 清理间隔(秒)
            'keep_days' => 30, // 保留天数
        ]
    ],
    'command_restrictions' => [
        'allow_in_private' => false,        // 禁止私聊发红包
        'allow_in_groups' => true,          // 允许群组发红包
        'require_bot_admin' => true,        // 要求机器人是管理员
        'single_group_only' => true,        // 只发送到当前群组
    ],
    // 测试模式配置
    'test_mode' => [
        'enabled' => env('REDPACKET_TEST_MODE', false),
        'virtual_money' => true, // 使用虚拟货币
        'skip_balance_check' => true, // 跳过余额检查
        'fast_expire' => 60, // 快速过期时间(秒)
        'mock_users' => true, // 模拟用户
    ]
];