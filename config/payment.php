<?php
/**
 * 支付系统配置文件
 * 
 * 包含充值提现配置、手续费设置、风控参数等
 */

return [
    // 充值配置
    'recharge' => [
        // 充值方式
        'methods' => [
            'usdt' => [
                'name' => 'USDT充值',
                'code' => 'usdt',
                'enabled' => true,
                'icon' => '₿',
                'description' => '数字货币钱包转账',
                'network' => 'TRC20',
                'min_amount' => 10.00,
                'max_amount' => 100000.00,
                'processing_time' => '确认后即时到账',
                'fee_rate' => 0, // 手续费率 0%
                'fee_fixed' => 0, // 固定手续费
                'sort_order' => 1
            ],
            'huiwang' => [
                'name' => '汇旺转账',
                'code' => 'huiwang',
                'enabled' => true,
                'icon' => '⚡',
                'description' => '本地银行快速转账',
                'network' => null,
                'min_amount' => 10.00,
                'max_amount' => 20000.00,
                'processing_time' => '30分钟-2小时',
                'fee_rate' => 0,
                'fee_fixed' => 0,
                'sort_order' => 2
            ]
        ],
        
        // 充值限制
        'limits' => [
            'daily_amount' => 500000.00, // 每日充值限额
            'daily_count' => 50, // 每日充值次数限制
            'monthly_amount' => 10000000.00, // 每月充值限额
            'single_min' => 10.00, // 单笔最小金额
            'single_max' => 100000.00, // 单笔最大金额
        ],
        
        // 风控配置
        'risk_control' => [
            'auto_approve_amount' => 1000.00, // 自动审核通过金额
            'manual_review_amount' => 10000.00, // 人工审核金额
            'suspicious_amount' => 50000.00, // 可疑金额
            'same_ip_daily_limit' => 10, // 同IP每日充值次数限制
            'same_device_daily_limit' => 20, // 同设备每日充值次数
            'verification_required_amount' => 5000.00, // 需要身份验证的金额
        ],
        
        // 订单配置
        'order' => [
            'prefix' => 'R', // 订单号前缀
            'length' => 14, // 订单号总长度
            'expire_time' => 3600, // 订单过期时间(秒)
            'auto_cancel_time' => 7200, // 自动取消时间(秒)
        ]
    ],
    
    // 提现配置
    'withdraw' => [
        // 提现方式
        'methods' => [
            'usdt' => [
                'name' => 'USDT提现',
                'code' => 'usdt',
                'enabled' => true,
                'icon' => '₿',
                'network' => 'TRC20',
                'min_amount' => 20.00,
                'max_amount' => 50000.00,
                'processing_time' => '1-24小时',
                'fee_rate' => 0.01, // 手续费率 1%
                'fee_fixed' => 2.00, // 固定手续费 2 USDT
                'fee_min' => 2.00, // 最小手续费
                'fee_max' => 100.00, // 最大手续费
            ]
        ],
        
        // 提现限制
        'limits' => [
            'daily_amount' => 100000.00, // 每日提现限额
            'daily_count' => 10, // 每日提现次数限制
            'weekly_amount' => 500000.00, // 每周提现限额
            'monthly_amount' => 2000000.00, // 每月提现限额
            'single_min' => 20.00, // 单笔最小金额
            'single_max' => 50000.00, // 单笔最大金额
            'balance_reserve' => 0.01, // 余额保留(最少保留金额)
        ],
        
        // 风控配置
        'risk_control' => [
            'auto_approve_amount' => 500.00, // 自动审核通过金额
            'manual_review_amount' => 5000.00, // 人工审核金额
            'suspicious_amount' => 20000.00, // 可疑金额
            'new_user_limit_days' => 7, // 新用户限制天数
            'new_user_max_amount' => 1000.00, // 新用户最大提现金额
            'minimum_deposit_required' => true, // 是否需要先充值
            'deposit_withdraw_ratio' => 0.8, // 充值提现比例要求
        ],
        
        // 地址验证
        'address_validation' => [
            'usdt_trc20_pattern' => '/^T[A-Za-z1-9]{33}$/', // USDT TRC20地址格式
            'required_confirmations' => 1, // 需要确认数
            'blacklist_check' => true, // 黑名单检查
        ],
        
        // 订单配置
        'order' => [
            'prefix' => 'W', // 订单号前缀
            'length' => 14, // 订单号总长度
            'expire_time' => 86400, // 订单过期时间(秒) 24小时
            'auto_cancel_time' => 172800, // 自动取消时间(秒) 48小时
        ]
    ],
    
    // 手续费配置
    'fees' => [
        'recharge' => [
            'usdt' => ['rate' => 0, 'fixed' => 0],
            'huiwang' => ['rate' => 0, 'fixed' => 0],
        ],
        'withdraw' => [
            'usdt' => [
                'rate' => 0.01, // 1%
                'fixed' => 2.00, // 2 USDT
                'min' => 2.00,
                'max' => 100.00
            ],
        ],
        'transfer' => [ // 内部转账手续费
            'rate' => 0,
            'fixed' => 0,
            'min' => 0,
            'max' => 0
        ]
    ],
    
    // 余额配置
    'balance' => [
        'min_balance' => 0.01, // 最小余额
        'precision' => 2, // 小数位精度
        'display_currency' => 'USDT', // 显示货币单位
        'allow_negative' => false, // 是否允许负余额
    ],
    
    // 审核配置
    'approval' => [
        'recharge' => [
            'auto_approve' => true, // 是否自动审核
            'auto_approve_amount' => 1000.00, // 自动审核金额
            'require_proof' => false, // 是否需要凭证
            'expire_hours' => 72, // 审核超时时间(小时)
        ],
        'withdraw' => [
            'auto_approve' => false, // 是否自动审核
            'auto_approve_amount' => 100.00, // 自动审核金额
            'require_password' => true, // 是否需要提现密码
            'expire_hours' => 24, // 审核超时时间(小时)
            'working_hours' => [ // 工作时间审核
                'enabled' => false,
                'start' => '09:00',
                'end' => '18:00',
                'timezone' => 'Asia/Shanghai'
            ]
        ]
    ],
    
    // 通知配置
    'notifications' => [
        'recharge_success' => [
            'enabled' => true,
            'template' => 'recharge_success',
            'channels' => ['telegram', 'database']
        ],
        'withdraw_success' => [
            'enabled' => true,
            'template' => 'withdraw_success',
            'channels' => ['telegram', 'database']
        ],
        'withdraw_failed' => [
            'enabled' => true,
            'template' => 'withdraw_failed',
            'channels' => ['telegram', 'database']
        ],
        'balance_low' => [
            'enabled' => true,
            'threshold' => 10.00,
            'template' => 'balance_low',
            'channels' => ['telegram']
        ]
    ],
    
    // 统计配置
    'statistics' => [
        'enabled' => true,
        'real_time' => true, // 实时统计
        'cache_ttl' => 300, // 统计缓存时间(秒)
        'daily_reset_time' => '00:00', // 每日重置时间
    ],
    
    // 安全配置
    'security' => [
        'encrypt_sensitive_data' => true, // 加密敏感数据
        'log_all_transactions' => true, // 记录所有交易
        'require_2fa' => false, // 是否需要双因子认证
        'ip_whitelist' => [], // IP白名单
        'max_failed_attempts' => 5, // 最大失败尝试次数
        'lockout_duration' => 1800, // 锁定时间(秒)
    ],
    
    // 第三方接口配置
    'gateways' => [
        'usdt' => [
            'enabled' => true,
            'api_url' => env('USDT_API_URL', ''),
            'api_key' => env('USDT_API_KEY', ''),
            'api_secret' => env('USDT_API_SECRET', ''),
            'timeout' => 30,
            'retry_count' => 3,
        ],
        'huiwang' => [
            'enabled' => true,
            'api_url' => env('HUIWANG_API_URL', ''),
            'api_key' => env('HUIWANG_API_KEY', ''),
            'timeout' => 30,
            'retry_count' => 3,
        ]
    ],
    
    // 测试模式配置
    'test_mode' => [
        'enabled' => env('PAYMENT_TEST_MODE', false),
        'auto_approve' => true, // 测试模式自动审核
        'mock_responses' => true, // 模拟响应
        'fake_transactions' => true, // 虚假交易
    ]
];