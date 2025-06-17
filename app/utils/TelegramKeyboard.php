<?php
declare(strict_types=1);

namespace app\utils;

/**
 * Telegram键盘工具类
 * 用于生成内联键盘和自定义键盘
 */
class TelegramKeyboard
{
    /**
     * 创建内联键盘
     */
    public static function inline(array $buttons): array
    {
        return [
            'inline_keyboard' => $buttons
        ];
    }
    
    /**
     * 创建自定义键盘
     */
    public static function custom(array $buttons, array $options = []): array
    {
        $keyboard = [
            'keyboard' => $buttons,
            'resize_keyboard' => $options['resize'] ?? true,
            'one_time_keyboard' => $options['one_time'] ?? false,
            'selective' => $options['selective'] ?? false,
        ];
        
        if (isset($options['input_field_placeholder'])) {
            $keyboard['input_field_placeholder'] = $options['input_field_placeholder'];
        }
        
        return $keyboard;
    }
    
    /**
     * 移除键盘
     */
    public static function remove(bool $selective = false): array
    {
        return [
            'remove_keyboard' => true,
            'selective' => $selective
        ];
    }
    
    /**
     * 强制回复键盘
     */
    public static function forceReply(array $options = []): array
    {
        return [
            'force_reply' => true,
            'selective' => $options['selective'] ?? false,
            'input_field_placeholder' => $options['placeholder'] ?? ''
        ];
    }
    
    /**
     * 创建内联按钮
     */
    public static function inlineButton(string $text, array $options = []): array
    {
        $button = ['text' => $text];
        
        if (isset($options['callback_data'])) {
            $button['callback_data'] = $options['callback_data'];
        } elseif (isset($options['url'])) {
            $button['url'] = $options['url'];
        } elseif (isset($options['switch_inline_query'])) {
            $button['switch_inline_query'] = $options['switch_inline_query'];
        } elseif (isset($options['switch_inline_query_current_chat'])) {
            $button['switch_inline_query_current_chat'] = $options['switch_inline_query_current_chat'];
        } elseif (isset($options['pay'])) {
            $button['pay'] = $options['pay'];
        }
        
        return $button;
    }
    
    /**
     * 创建自定义按钮
     */
    public static function customButton(string $text, array $options = []): array
    {
        $button = ['text' => $text];
        
        if (isset($options['request_contact'])) {
            $button['request_contact'] = $options['request_contact'];
        } elseif (isset($options['request_location'])) {
            $button['request_location'] = $options['request_location'];
        } elseif (isset($options['request_poll'])) {
            $button['request_poll'] = $options['request_poll'];
        }
        
        return $button;
    }
    
    /**
     * 主菜单键盘
     */
    public static function mainMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('💰 我的钱包', ['callback_data' => 'wallet']),
                self::inlineButton('🧧 红包', ['callback_data' => 'redpacket'])
            ],
            [
                self::inlineButton('💳 充值', ['callback_data' => 'recharge']),
                self::inlineButton('💸 提现', ['callback_data' => 'withdraw'])
            ],
            [
                self::inlineButton('👥 邀请好友', ['callback_data' => 'invite']),
                self::inlineButton('📊 我的数据', ['callback_data' => 'stats'])
            ],
            [
                self::inlineButton('⚙️ 设置', ['callback_data' => 'settings']),
                self::inlineButton('❓ 帮助', ['callback_data' => 'help'])
            ]
        ]);
    }
    
    /**
     * 钱包菜单键盘
     */
    public static function walletMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('💳 充值', ['callback_data' => 'recharge']),
                self::inlineButton('💸 提现', ['callback_data' => 'withdraw'])
            ],
            [
                self::inlineButton('📋 交易记录', ['callback_data' => 'transactions']),
                self::inlineButton('📊 资金流水', ['callback_data' => 'money_logs'])
            ],
            [
                self::inlineButton('🔙 返回主菜单', ['callback_data' => 'main_menu'])
            ]
        ]);
    }
    
    /**
     * 充值方式选择键盘
     */
    public static function rechargeMethodMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('💎 USDT充值', ['callback_data' => 'recharge_usdt']),
                self::inlineButton('🏦 汇旺充值', ['callback_data' => 'recharge_huiwang'])
            ],
            [
                self::inlineButton('🔙 返回', ['callback_data' => 'wallet'])
            ]
        ]);
    }
    
    /**
     * 红包菜单键盘
     */
    public static function redpacketMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('🎁 发红包', ['callback_data' => 'send_redpacket']),
                self::inlineButton('📦 我的红包', ['callback_data' => 'my_redpackets'])
            ],
            [
                self::inlineButton('🏆 红包排行', ['callback_data' => 'redpacket_ranking']),
                self::inlineButton('📊 红包统计', ['callback_data' => 'redpacket_stats'])
            ],
            [
                self::inlineButton('🔙 返回主菜单', ['callback_data' => 'main_menu'])
            ]
        ]);
    }
    
    /**
     * 红包类型选择键盘
     */
    public static function redpacketTypeMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('🎲 拼手气红包', ['callback_data' => 'redpacket_random'])
            ],
            [
                self::inlineButton('💰 普通红包', ['callback_data' => 'redpacket_average'])
            ],
            [
                self::inlineButton('🔙 返回', ['callback_data' => 'redpacket'])
            ]
        ]);
    }
    
    /**
     * 确认/取消键盘
     */
    public static function confirmMenu(string $confirmData, string $cancelData = 'cancel'): array
    {
        return self::inline([
            [
                self::inlineButton('✅ 确认', ['callback_data' => $confirmData]),
                self::inlineButton('❌ 取消', ['callback_data' => $cancelData])
            ]
        ]);
    }
    
    /**
     * 数字键盘
     */
    public static function numberPad(string $prefix = 'num_'): array
    {
        $buttons = [];
        
        // 数字按钮 1-9
        for ($i = 1; $i <= 9; $i += 3) {
            $row = [];
            for ($j = 0; $j < 3; $j++) {
                $num = $i + $j;
                if ($num <= 9) {
                    $row[] = self::inlineButton((string)$num, ['callback_data' => $prefix . $num]);
                }
            }
            $buttons[] = $row;
        }
        
        // 最后一行：删除、0、确认
        $buttons[] = [
            self::inlineButton('⬅️', ['callback_data' => $prefix . 'del']),
            self::inlineButton('0', ['callback_data' => $prefix . '0']),
            self::inlineButton('✅', ['callback_data' => $prefix . 'ok'])
        ];
        
        return self::inline($buttons);
    }
    
    /**
     * 金额快捷选择键盘
     */
    public static function amountQuickSelect(array $amounts, string $prefix = 'amount_'): array
    {
        $buttons = [];
        $row = [];
        
        foreach ($amounts as $index => $amount) {
            $row[] = self::inlineButton($amount . ' USDT', ['callback_data' => $prefix . $amount]);
            
            // 每行最多3个按钮
            if (($index + 1) % 3 === 0) {
                $buttons[] = $row;
                $row = [];
            }
        }
        
        // 添加剩余按钮
        if (!empty($row)) {
            $buttons[] = $row;
        }
        
        // 添加自定义金额按钮
        $buttons[] = [
            self::inlineButton('💰 自定义金额', ['callback_data' => $prefix . 'custom'])
        ];
        
        return self::inline($buttons);
    }
    
    /**
     * 分页键盘
     */
    public static function pagination(int $currentPage, int $totalPages, string $prefix = 'page_'): array
    {
        $buttons = [];
        
        if ($totalPages <= 1) {
            return self::inline($buttons);
        }
        
        $row = [];
        
        // 上一页
        if ($currentPage > 1) {
            $row[] = self::inlineButton('⬅️ 上一页', ['callback_data' => $prefix . ($currentPage - 1)]);
        }
        
        // 页码信息
        $row[] = self::inlineButton("{$currentPage}/{$totalPages}", ['callback_data' => 'noop']);
        
        // 下一页
        if ($currentPage < $totalPages) {
            $row[] = self::inlineButton('下一页 ➡️', ['callback_data' => $prefix . ($currentPage + 1)]);
        }
        
        $buttons[] = $row;
        
        return self::inline($buttons);
    }
    
    /**
     * 语言选择键盘
     */
    public static function languageMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('🇨🇳 中文', ['callback_data' => 'lang_zh']),
                self::inlineButton('🇺🇸 English', ['callback_data' => 'lang_en'])
            ],
            [
                self::inlineButton('🇯🇵 日本語', ['callback_data' => 'lang_ja']),
                self::inlineButton('🇰🇷 한국어', ['callback_data' => 'lang_ko'])
            ],
            [
                self::inlineButton('🔙 返回', ['callback_data' => 'settings'])
            ]
        ]);
    }
    
    /**
     * 设置菜单键盘
     */
    public static function settingsMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('🌍 语言设置', ['callback_data' => 'settings_language']),
                self::inlineButton('🔒 修改密码', ['callback_data' => 'settings_password'])
            ],
            [
                self::inlineButton('📱 绑定手机', ['callback_data' => 'settings_phone']),
                self::inlineButton('💳 USDT地址', ['callback_data' => 'settings_usdt'])
            ],
            [
                self::inlineButton('🔔 通知设置', ['callback_data' => 'settings_notification']),
                self::inlineButton('🛡️ 安全设置', ['callback_data' => 'settings_security'])
            ],
            [
                self::inlineButton('🔙 返回主菜单', ['callback_data' => 'main_menu'])
            ]
        ]);
    }
    
    /**
     * 联系客服键盘
     */
    public static function contactSupport(): array
    {
        return self::inline([
            [
                self::inlineButton('👨‍💼 在线客服', ['url' => 'https://t.me/support_bot']),
                self::inlineButton('📞 客服电话', ['callback_data' => 'support_phone'])
            ],
            [
                self::inlineButton('📧 发送邮件', ['callback_data' => 'support_email']),
                self::inlineButton('❓ 常见问题', ['callback_data' => 'faq'])
            ]
        ]);
    }
    
    /**
     * 共享联系人键盘
     */
    public static function shareContact(): array
    {
        return self::custom([
            [
                self::customButton('📱 分享联系人', ['request_contact' => true])
            ],
            [
                ['text' => '❌ 取消']
            ]
        ], ['one_time' => true, 'resize' => true]);
    }
    
    /**
     * 共享位置键盘
     */
    public static function shareLocation(): array
    {
        return self::custom([
            [
                self::customButton('📍 分享位置', ['request_location' => true])
            ],
            [
                ['text' => '❌ 取消']
            ]
        ], ['one_time' => true, 'resize' => true]);
    }
    
    /**
     * 管理员菜单键盘
     */
    public static function adminMenu(): array
    {
        return self::inline([
            [
                self::inlineButton('👥 用户管理', ['callback_data' => 'admin_users']),
                self::inlineButton('💰 财务管理', ['callback_data' => 'admin_finance'])
            ],
            [
                self::inlineButton('🧧 红包管理', ['callback_data' => 'admin_redpackets']),
                self::inlineButton('📢 广播消息', ['callback_data' => 'admin_broadcast'])
            ],
            [
                self::inlineButton('📊 数据统计', ['callback_data' => 'admin_stats']),
                self::inlineButton('⚙️ 系统设置', ['callback_data' => 'admin_settings'])
            ]
        ]);
    }
    
    /**
     * 生成键盘JSON
     */
    public static function toJson(array $keyboard): string
    {
        return json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 从配置文件生成键盘
     */
    public static function fromConfig(string $configKey): array
    {
        $config = config("telegram.keyboards.{$configKey}");
        
        if (!$config) {
            return self::inline([]);
        }
        
        $buttons = [];
        foreach ($config as $row) {
            $buttonRow = [];
            foreach ($row as $button) {
                $buttonRow[] = self::inlineButton($button['text'], $button['data']);
            }
            $buttons[] = $buttonRow;
        }
        
        return self::inline($buttons);
    }
    
    /**
     * 添加返回按钮
     */
    public static function addBackButton(array $keyboard, string $backData = 'back'): array
    {
        $keyboard['inline_keyboard'][] = [
            self::inlineButton('🔙 返回', ['callback_data' => $backData])
        ];
        
        return $keyboard;
    }
    
    /**
     * 添加关闭按钮
     */
    public static function addCloseButton(array $keyboard): array
    {
        $keyboard['inline_keyboard'][] = [
            self::inlineButton('❌ 关闭', ['callback_data' => 'close'])
        ];
        
        return $keyboard;
    }
    
    /**
     * 合并键盘
     */
    public static function merge(array ...$keyboards): array
    {
        $merged = ['inline_keyboard' => []];
        
        foreach ($keyboards as $keyboard) {
            if (isset($keyboard['inline_keyboard'])) {
                $merged['inline_keyboard'] = array_merge(
                    $merged['inline_keyboard'], 
                    $keyboard['inline_keyboard']
                );
            }
        }
        
        return $merged;
    }
    
    /**
     * 创建网格布局键盘
     */
    public static function grid(array $buttons, int $columns = 2): array
    {
        $grid = [];
        $row = [];
        
        foreach ($buttons as $index => $button) {
            $row[] = $button;
            
            if (($index + 1) % $columns === 0 || $index === count($buttons) - 1) {
                $grid[] = $row;
                $row = [];
            }
        }
        
        return self::inline($grid);
    }
}