<?php
/**
 * 公共模板配置文件
 * 
 * 包含各模块共用的通用消息模板和键盘配置
 * 如错误提示、成功提示、确认对话框等
 */

return [
    // 通用消息模板
    'messages' => [
        // 成功类型消息
        'success_general' => '✅ *操作成功*

您的操作已成功完成！',

        'success_saved' => '✅ *保存成功*

您的信息已成功保存。',

        'success_updated' => '✅ *更新成功*

信息已成功更新。',

        'success_deleted' => '✅ *删除成功*

信息已成功删除。',

        // 错误类型消息
        'error_general' => '❌ *操作失败*

操作执行失败，请稍后重试。',

        'error_network' => '❌ *网络错误*

网络连接异常，请检查网络后重试。',

        'error_timeout' => '⏰ *操作超时*

您的操作已超时，请重新开始。',

        'error_permission' => '🚫 *权限不足*

您没有权限执行此操作。',

        'error_not_found' => '❓ *信息不存在*

未找到相关信息，请检查后重试。',

        'error_invalid_input' => '❌ *输入无效*

您输入的信息格式不正确，请重新输入。',

        'error_system' => '🔧 *系统错误*

系统出现异常，我们正在紧急修复。

请稍后重试或联系客服。',

        // 确认类型消息
        'confirm_general' => '❓ *确认操作*

您确定要执行此操作吗？

⚠️ 此操作无法撤销',

        'confirm_delete' => '❓ *确认删除*

您确定要删除此信息吗？

⚠️ 删除后无法恢复',

        'confirm_cancel' => '❓ *确认取消*

您确定要取消当前操作吗？

⚠️ 已输入的信息将丢失',

        // 警告类型消息
        'warning_general' => '⚠️ *注意*

请仔细确认您的操作。',

        'warning_important' => '🚨 *重要提醒*

此操作很重要，请谨慎处理！',

        'warning_risk' => '⚠️ *风险提示*

此操作存在风险，请确认无误后继续。',

        // 信息类型消息
        'info_processing' => '⏳ *正在处理*

系统正在处理您的请求，请稍候...',

        'info_waiting' => '⏳ *请等待*

操作正在进行中，请耐心等待。',

        'info_maintenance' => '🔧 *系统维护*

系统正在维护中，预计 {time} 后恢复。',

        'info_upgrade' => '🚀 *系统升级*

系统正在升级中，新功能即将上线！',

        // 状态消息
        'status_online' => '🟢 *在线*

系统运行正常',

        'status_offline' => '🔴 *离线*

系统暂时不可用',

        'status_busy' => '🟡 *繁忙*

系统负载较高，响应可能较慢',

        // 帮助信息
        'help_contact' => '需要帮助？请联系客服：{customer_service_url}',

        'help_format' => '📝 *格式说明*

请按照以下格式输入：{format}',

        'help_example' => '💡 *示例*

正确格式：{example}',

        // 验证消息
        'verification_code_sent' => '📱 *验证码已发送*

验证码已发送到您的手机，请查收。',

        'verification_code_invalid' => '❌ *验证码错误*

请输入正确的验证码。',

        'verification_success' => '✅ *验证成功*

身份验证已通过。',

        // 操作限制消息
        'limit_daily' => '⚠️ *达到每日限额*

您今日的操作次数已达上限。',

        'limit_hourly' => '⚠️ *操作过于频繁*

请稍后再试（{minutes}分钟后）。',

        'limit_amount' => '⚠️ *金额超限*

单次操作金额不能超过 {limit}。',
    ],

    // 通用键盘模板
    'keyboards' => [
        // 确认对话框
        'confirm' => [
            [
                ['text' => '✅ 确认', 'callback_data' => 'confirm_yes'],
                ['text' => '❌ 取消', 'callback_data' => 'confirm_no']
            ]
        ],

        // 确认删除
        'confirm_delete' => [
            [
                ['text' => '🗑️ 确认删除', 'callback_data' => 'delete_confirm']
            ],
            [
                ['text' => '❌ 取消', 'callback_data' => 'delete_cancel']
            ]
        ],

        // 重试操作
        'retry' => [
            [
                ['text' => '🔄 重试', 'callback_data' => 'retry_operation']
            ],
            [
                ['text' => '❌ 取消', 'callback_data' => 'cancel_operation']
            ]
        ],

        // 返回菜单
        'back_only' => [
            [
                ['text' => '🔙 返回', 'callback_data' => 'back']
            ]
        ],

        'back_to_main_only' => [
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 联系客服
        'contact_service' => [
            [
                ['text' => '👨‍💼 联系客服', 'url' => '{customer_service_url}']
            ],
            [
                ['text' => '🔙 返回', 'callback_data' => 'back']
            ]
        ],

        // 操作选择
        'operation_choice' => [
            [
                ['text' => '✅ 继续', 'callback_data' => 'continue_operation'],
                ['text' => '❌ 取消', 'callback_data' => 'cancel_operation']
            ]
        ],

        // 错误处理
        'error_handle' => [
            [
                ['text' => '🔄 重新尝试', 'callback_data' => 'retry_operation']
            ],
            [
                ['text' => '👨‍💼 联系客服', 'url' => '{customer_service_url}']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 成功操作后选择
        'success_choice' => [
            [
                ['text' => '🔄 继续操作', 'callback_data' => 'continue_same']
            ],
            [
                ['text' => '🏠 返回主菜单', 'callback_data' => 'back_to_main']
            ]
        ],

        // 是否选择
        'yes_no' => [
            [
                ['text' => '✅ 是', 'callback_data' => 'choice_yes'],
                ['text' => '❌ 否', 'callback_data' => 'choice_no']
            ]
        ],

        // 关闭对话框
        'close' => [
            [
                ['text' => '❌ 关闭', 'callback_data' => 'close_dialog']
            ]
        ],

        // 刷新状态
        'refresh' => [
            [
                ['text' => '🔄 刷新', 'callback_data' => 'refresh_status']
            ],
            [
                ['text' => '🔙 返回', 'callback_data' => 'back']
            ]
        ],

        // 维护中选项
        'maintenance' => [
            [
                ['text' => '🔄 重新检查', 'callback_data' => 'check_maintenance']
            ],
            [
                ['text' => '👨‍💼 联系客服', 'url' => '{customer_service_url}']
            ]
        ],

        // 空键盘（仅显示消息）
        'empty' => [],
    ],

    // 状态图标映射
    'status_icons' => [
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️',
        'processing' => '⏳',
        'online' => '🟢',
        'offline' => '🔴',
        'busy' => '🟡',
        'maintenance' => '🔧',
        'upgrade' => '🚀',
    ],

    // 操作类型映射
    'action_icons' => [
        'save' => '💾',
        'edit' => '✏️',
        'delete' => '🗑️',
        'copy' => '📋',
        'share' => '📤',
        'download' => '⬇️',
        'upload' => '⬆️',
        'refresh' => '🔄',
        'search' => '🔍',
        'filter' => '🔽',
        'sort' => '🔀',
        'settings' => '⚙️',
    ]
];