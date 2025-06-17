<?php
/**
 * 监控通知系统配置文件
 * 适用于 ThinkPHP8 + PHP8.2
 */

return [
    // 监控系统总开关
    'enabled' => true,
    
    // 检查间隔(秒)
    'check_interval' => 60,
    
    // 时间重叠检查(秒) - 防止遗漏数据
    'overlap_time' => 30,
    
    // 监控规则配置
    'notify_rules' => [
        // 充值表监控
        'recharge' => [
            'enabled' => true,
            'table_name' => 'common_pay_recharge',
            'time_field' => 'create_time',
            'scope' => 'all_groups',        // 全群通知
            'conditions' => [],             // 无条件，所有新记录都通知
            'template' => 'recharge_notify',
            'user_field' => 'user_id'       // 关联用户字段
        ],
        
        // 提现表监控
        'withdraw' => [
            'enabled' => true,
            'table_name' => 'common_pay_withdraw', 
            'time_field' => 'create_time',
            'scope' => 'all_groups',        // 全群通知
            'conditions' => [],             // 无条件，所有新记录都通知
            'template' => 'withdraw_notify',
            'user_field' => 'user_id'       // 关联用户字段
        ],
        
        // 红包表监控
        'redpacket' => [
            'enabled' => true,
            'table_name' => 'tg_red_packets',
            'time_field' => 'created_at',
            'scope' => 'target_group',      // 只通知对应群
            'conditions' => [],             // 无条件，所有新红包都通知
            'template' => 'redpacket_notify',
            'user_field' => 'sender_id',    // 关联用户字段
            'target_field' => 'chat_id'     // 目标群组字段
        ],
        
        // 广告表监控
        'advertisement' => [
            'enabled' => true,
            'table_name' => 'tg_advertisements',
            'time_field' => 'send_time',
            'scope' => 'all_groups',        // 全群通知
            'conditions' => [
                'status' => 0,              // 只检查待发送的广告
            ],
            'template' => 'advertisement_notify',
            'user_field' => 'created_by'    // 关联用户字段
        ]
    ],
    
    // 消息发送配置
    'message_config' => [
        'max_retries' => 3,                 // 最大重试次数
        'retry_delay' => 5,                 // 重试延迟(秒)
        'timeout' => 30,                    // 发送超时(秒)
        'batch_size' => 10,                 // 批量发送大小
        'rate_limit' => 20,                 // 每分钟最大发送数
    ],
    
    // 缓存配置
    'cache_config' => [
        'last_check_key' => 'monitor_last_check_time',
        'cache_ttl' => 86400,               // 缓存有效期(秒)
        'use_redis' => false,               // 是否使用Redis
    ],
    
    // 日志配置
    'log_config' => [
        'enabled' => true,
        'level' => 'info',                  // 日志级别: debug, info, warning, error
        'file_path' => 'monitor',           // 日志文件路径
        'max_files' => 30,                  // 保留日志文件数量
    ],
    
    // 错误处理配置
    'error_config' => [
        'continue_on_error' => true,        // 出错时是否继续执行
        'notify_admin_on_error' => false,   // 出错时是否通知管理员
        'admin_chat_id' => '',              // 管理员聊天ID
        'max_consecutive_errors' => 5,      // 最大连续错误次数
    ],
    
    // 性能配置
    'performance_config' => [
        'memory_limit' => '128M',           // 内存限制
        'execution_time_limit' => 300,      // 执行时间限制(秒)
        'enable_debug' => false,            // 是否启用调试模式
    ]
];