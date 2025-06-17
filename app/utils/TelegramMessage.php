<?php
declare(strict_types=1);

namespace app\utils;

/**
 * Telegram消息工具类
 * 用于消息格式化、模板渲染和文本处理
 */
class TelegramMessage
{
    /**
     * Telegram消息最大长度
     */
    public const MAX_MESSAGE_LENGTH = 4096;
    public const MAX_CAPTION_LENGTH = 1024;
    
    /**
     * Markdown特殊字符
     */
    private static array $markdownChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    
    /**
     * HTML特殊字符
     */
    private static array $htmlChars = ['<', '>', '&'];
    
    /**
     * 格式化欢迎消息
     */
    public static function welcome(string $username = '', string $firstName = ''): string
    {
        $name = $firstName ?: $username ?: '朋友';
        
        return "🎉 欢迎 {$name}！\n\n" .
               "我是您的专属助手机器人，可以帮助您：\n\n" .
               "💰 查看钱包余额\n" .
               "💳 充值和提现\n" .
               "🧧 发送和接收红包\n" .
               "👥 邀请好友获得奖励\n" .
               "📊 查看交易记录\n\n" .
               "点击下方按钮开始使用，或输入 /help 查看帮助信息。";
    }
    
    /**
     * 格式化钱包信息
     */
    public static function walletInfo(array $userInfo): string
    {
        $balance = number_format($userInfo['money_balance'] ?? 0, 2);
        $totalRecharge = number_format($userInfo['total_recharge'] ?? 0, 2);
        $totalWithdraw = number_format($userInfo['total_withdraw'] ?? 0, 2);
        
        return "💰 *我的钱包*\n\n" .
               "📊 *账户信息*\n" .
               "└ 当前余额：`{$balance} USDT`\n" .
               "└ 累计充值：`{$totalRecharge} USDT`\n" .
               "└ 累计提现：`{$totalWithdraw} USDT`\n\n" .
               "💳 *快捷操作*\n" .
               "点击下方按钮进行充值、提现等操作";
    }
    
    /**
     * 格式化充值信息
     */
    public static function rechargeInfo(array $order): string
    {
        $amount = number_format($order['money'], 2);
        $method = $order['payment_method_text'] ?? '未知';
        $status = $order['status_text'] ?? '未知';
        $orderNumber = $order['order_number'] ?? '';
        $createTime = $order['create_time'] ?? '';
        
        return "💳 *充值订单详情*\n\n" .
               "📋 订单号：`{$orderNumber}`\n" .
               "💰 充值金额：`{$amount} USDT`\n" .
               "💎 支付方式：{$method}\n" .
               "📊 订单状态：{$status}\n" .
               "🕐 创建时间：{$createTime}\n\n" .
               ($order['status'] == 0 ? "⏳ 订单正在处理中，请耐心等待..." : "");
    }
    
    /**
     * 格式化提现信息
     */
    public static function withdrawInfo(array $order): string
    {
        $amount = number_format($order['money'], 2);
        $fee = number_format($order['money_fee'] ?? 0, 2);
        $actual = number_format($order['money_actual'] ?? 0, 2);
        $status = $order['status_text'] ?? '未知';
        $orderNumber = $order['order_number'] ?? '';
        $address = self::maskAddress($order['withdraw_address'] ?? '');
        
        return "💸 *提现订单详情*\n\n" .
               "📋 订单号：`{$orderNumber}`\n" .
               "💰 提现金额：`{$amount} USDT`\n" .
               "💸 手续费：`{$fee} USDT`\n" .
               "💵 实际到账：`{$actual} USDT`\n" .
               "🏦 提现地址：`{$address}`\n" .
               "📊 订单状态：{$status}\n\n" .
               ($order['status'] == 0 ? "⏳ 订单正在审核中，请耐心等待..." : "");
    }
    
    /**
     * 格式化红包信息
     */
    public static function redpacketInfo(array $packet): string
    {
        $totalAmount = number_format($packet['total_amount'], 2);
        $remainAmount = number_format($packet['remain_amount'], 2);
        $totalCount = $packet['total_count'];
        $remainCount = $packet['remain_count'];
        $type = $packet['type_text'] ?? '未知';
        $status = $packet['status_text'] ?? '未知';
        $progress = $packet['progress'] ?? 0;
        
        $emoji = $packet['status'] == 1 ? '🎁' : '📦';
        
        return "{$emoji} *红包详情*\n\n" .
               "💰 红包金额：`{$totalAmount} USDT`\n" .
               "🧧 红包类型：{$type}\n" .
               "📊 红包状态：{$status}\n" .
               "🎯 红包个数：{$totalCount} 个\n" .
               "⚡ 剩余个数：{$remainCount} 个\n" .
               "💵 剩余金额：`{$remainAmount} USDT`\n" .
               "📈 领取进度：{$progress}%\n\n" .
               ($packet['status'] == 1 ? "🔥 快来抢红包吧！" : "");
    }
    
    /**
     * 格式化邀请信息
     */
    public static function inviteInfo(array $stats): string
    {
        $totalInvites = $stats['total_invitations'] ?? 0;
        $completedInvites = $stats['completed_invitations'] ?? 0;
        $totalRewards = number_format($stats['total_rewards'] ?? 0, 2);
        $inviteCode = $stats['invitation_code'] ?? '';
        
        return "👥 *邀请好友*\n\n" .
               "🎯 您的邀请码：`{$inviteCode}`\n\n" .
               "📊 *邀请统计*\n" .
               "└ 总邀请人数：{$totalInvites} 人\n" .
               "└ 成功注册：{$completedInvites} 人\n" .
               "└ 累计奖励：`{$totalRewards} USDT`\n\n" .
               "💡 *邀请奖励规则*\n" .
               "• 好友注册并首次充值≥50 USDT\n" .
               "• 您可获得 10 USDT 奖励\n" .
               "• 好友越多，奖励越多！\n\n" .
               "📲 分享您的邀请码给好友吧！";
    }
    
    /**
     * 格式化用户统计
     */
    public static function userStats(array $stats): string
    {
        $balance = number_format($stats['balance'] ?? 0, 2);
        $totalRecharge = number_format($stats['total_recharge'] ?? 0, 2);
        $totalWithdraw = number_format($stats['total_withdraw'] ?? 0, 2);
        $sentPackets = $stats['sent_redpackets'] ?? 0;
        $receivedPackets = $stats['received_redpackets'] ?? 0;
        $bestLuckCount = $stats['best_luck_count'] ?? 0;
        $registerTime = $stats['register_time'] ?? '';
        
        return "📊 *我的数据*\n\n" .
               "💰 *资金统计*\n" .
               "└ 当前余额：`{$balance} USDT`\n" .
               "└ 累计充值：`{$totalRecharge} USDT`\n" .
               "└ 累计提现：`{$totalWithdraw} USDT`\n\n" .
               "🧧 *红包统计*\n" .
               "└ 发出红包：{$sentPackets} 个\n" .
               "└ 领取红包：{$receivedPackets} 个\n" .
               "└ 手气最佳：{$bestLuckCount} 次\n\n" .
               "📅 注册时间：{$registerTime}";
    }
    
    /**
     * 格式化错误消息
     */
    public static function error(string $message, string $code = ''): string
    {
        $codeText = $code ? " (错误码: {$code})" : '';
        return "❌ *操作失败*\n\n{$message}{$codeText}";
    }
    
    /**
     * 格式化成功消息
     */
    public static function success(string $message): string
    {
        return "✅ *操作成功*\n\n{$message}";
    }
    
    /**
     * 格式化警告消息
     */
    public static function warning(string $message): string
    {
        return "⚠️ *注意*\n\n{$message}";
    }
    
    /**
     * 格式化信息消息
     */
    public static function info(string $message): string
    {
        return "ℹ️ *提示*\n\n{$message}";
    }
    
    /**
     * 格式化加载消息
     */
    public static function loading(string $message = '处理中'): string
    {
        return "⏳ {$message}...";
    }
    
    /**
     * 格式化帮助消息
     */
    public static function help(): string
    {
        return "❓ *帮助信息*\n\n" .
               "🤖 *基本命令*\n" .
               "/start - 开始使用\n" .
               "/help - 查看帮助\n" .
               "/menu - 主菜单\n" .
               "/wallet - 我的钱包\n" .
               "/redpacket - 红包功能\n\n" .
               "💰 *钱包功能*\n" .
               "• 查看余额和交易记录\n" .
               "• 充值：支持USDT、汇旺等\n" .
               "• 提现：实时到账，低手续费\n\n" .
               "🧧 *红包功能*\n" .
               "• 发红包：支持拼手气、普通红包\n" .
               "• 抢红包：快速领取，手气最佳有奖励\n" .
               "• 排行榜：查看红包达人\n\n" .
               "👥 *邀请功能*\n" .
               "• 邀请好友注册获得奖励\n" .
               "• 好友充值您也有收益\n\n" .
               "❓ 如有问题，请联系客服获取帮助。";
    }
    
    /**
     * 转义Markdown字符
     */
    public static function escapeMarkdown(string $text): string
    {
        return str_replace(self::$markdownChars, array_map(fn($char) => "\\{$char}", self::$markdownChars), $text);
    }
    
    /**
     * 转义HTML字符
     */
    public static function escapeHtml(string $text): string
    {
        return str_replace(self::$htmlChars, ['&lt;', '&gt;', '&amp;'], $text);
    }
    
    /**
     * 检查消息长度
     */
    public static function checkLength(string $text, bool $isCaption = false): array
    {
        $maxLength = $isCaption ? self::MAX_CAPTION_LENGTH : self::MAX_MESSAGE_LENGTH;
        $length = mb_strlen($text);
        
        return [
            'valid' => $length <= $maxLength,
            'length' => $length,
            'max_length' => $maxLength,
            'exceeded' => max(0, $length - $maxLength)
        ];
    }
    
    /**
     * 截断消息
     */
    public static function truncate(string $text, int $maxLength = null, string $suffix = '...'): string
    {
        $maxLength = $maxLength ?? self::MAX_MESSAGE_LENGTH;
        
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        
        return mb_substr($text, 0, $maxLength - mb_strlen($suffix)) . $suffix;
    }
    
    /**
     * 分割长消息
     */
    public static function split(string $text, int $maxLength = null): array
    {
        $maxLength = $maxLength ?? self::MAX_MESSAGE_LENGTH;
        $parts = [];
        
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }
        
        $lines = explode("\n", $text);
        $currentPart = '';
        
        foreach ($lines as $line) {
            if (mb_strlen($currentPart . "\n" . $line) > $maxLength) {
                if (!empty($currentPart)) {
                    $parts[] = trim($currentPart);
                    $currentPart = $line;
                } else {
                    // 单行太长，强制分割
                    $parts = array_merge($parts, self::splitLine($line, $maxLength));
                }
            } else {
                $currentPart .= ($currentPart ? "\n" : '') . $line;
            }
        }
        
        if (!empty($currentPart)) {
            $parts[] = trim($currentPart);
        }
        
        return $parts;
    }
    
    /**
     * 分割单行文本
     */
    private static function splitLine(string $line, int $maxLength): array
    {
        $parts = [];
        $remaining = $line;
        
        while (mb_strlen($remaining) > $maxLength) {
            $parts[] = mb_substr($remaining, 0, $maxLength);
            $remaining = mb_substr($remaining, $maxLength);
        }
        
        if (!empty($remaining)) {
            $parts[] = $remaining;
        }
        
        return $parts;
    }
    
    /**
     * 格式化金额
     */
    public static function formatAmount(float $amount, string $currency = 'USDT'): string
    {
        return number_format($amount, 2) . ' ' . $currency;
    }
    
    /**
     * 掩码地址
     */
    public static function maskAddress(string $address, int $prefixLength = 6, int $suffixLength = 6): string
    {
        if (empty($address) || strlen($address) <= $prefixLength + $suffixLength) {
            return $address;
        }
        
        return substr($address, 0, $prefixLength) . '...' . substr($address, -$suffixLength);
    }
    
    /**
     * 掩码手机号
     */
    public static function maskPhone(string $phone): string
    {
        if (empty($phone) || strlen($phone) < 7) {
            return $phone;
        }
        
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
    
    /**
     * 格式化时间
     */
    public static function formatTime(int $timestamp, string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, $timestamp);
    }
    
    /**
     * 格式化相对时间
     */
    public static function timeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return '刚刚';
        } elseif ($diff < 3600) {
            return round($diff / 60) . '分钟前';
        } elseif ($diff < 86400) {
            return round($diff / 3600) . '小时前';
        } elseif ($diff < 86400 * 7) {
            return round($diff / 86400) . '天前';
        } else {
            return date('Y-m-d', $timestamp);
        }
    }
    
    /**
     * 渲染模板
     */
    public static function render(string $template, array $variables = []): string
    {
        $text = $template;
        
        foreach ($variables as $key => $value) {
            $placeholder = '{' . $key . '}';
            $text = str_replace($placeholder, (string)$value, $text);
        }
        
        return $text;
    }
    
    /**
     * 加载模板文件
     */
    public static function loadTemplate(string $name, array $variables = []): string
    {
        $templatePath = app()->getAppPath() . 'view/telegram/' . $name . '.txt';
        
        if (!file_exists($templatePath)) {
            return '';
        }
        
        $template = file_get_contents($templatePath);
        return self::render($template, $variables);
    }
    
    /**
     * 创建进度条
     */
    public static function progressBar(float $progress, int $length = 10, string $filled = '█', string $empty = '░'): string
    {
        $progress = max(0, min(1, $progress / 100));
        $filledLength = (int)round($progress * $length);
        $emptyLength = $length - $filledLength;
        
        return str_repeat($filled, $filledLength) . str_repeat($empty, $emptyLength);
    }
    
    /**
     * 创建表格
     */
    public static function table(array $data, array $headers = []): string
    {
        if (empty($data)) {
            return '';
        }
        
        $table = '';
        
        // 添加表头
        if (!empty($headers)) {
            $table .= '```' . "\n";
            $table .= implode(' | ', $headers) . "\n";
            $table .= str_repeat('-', mb_strlen(implode(' | ', $headers))) . "\n";
        } else {
            $table .= '```' . "\n";
        }
        
        // 添加数据行
        foreach ($data as $row) {
            $table .= implode(' | ', array_map('strval', $row)) . "\n";
        }
        
        $table .= '```';
        
        return $table;
    }
    
    /**
     * 创建列表
     */
    public static function list(array $items, string $prefix = '• '): string
    {
        return implode("\n", array_map(fn($item) => $prefix . $item, $items));
    }
    
    /**
     * 格式化状态指示器
     */
    public static function statusIndicator(string $status): string
    {
        $indicators = [
            'success' => '✅',
            'pending' => '⏳',
            'failed' => '❌',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            'processing' => '🔄',
            'active' => '🟢',
            'inactive' => '🔴',
        ];
        
        return $indicators[$status] ?? '❓';
    }
    
    /**
     * 创建引用文本
     */
    public static function quote(string $text): string
    {
        $lines = explode("\n", $text);
        return implode("\n", array_map(fn($line) => '> ' . $line, $lines));
    }
    
    /**
     * 创建代码块
     */
    public static function code(string $code, string $language = ''): string
    {
        return "```{$language}\n{$code}\n```";
    }
    
    /**
     * 创建内联代码
     */
    public static function inlineCode(string $code): string
    {
        return "`{$code}`";
    }
    
    /**
     * 创建链接
     */
    public static function link(string $text, string $url): string
    {
        return "[{$text}]({$url})";
    }
    
    /**
     * 创建粗体文本
     */
    public static function bold(string $text): string
    {
        return "*{$text}*";
    }
    
    /**
     * 创建斜体文本
     */
    public static function italic(string $text): string
    {
        return "_{$text}_";
    }
    
    /**
     * 创建删除线文本
     */
    public static function strikethrough(string $text): string
    {
        return "~{$text}~";
    }
    
    /**
     * 验证消息格式
     */
    public static function validate(string $text, string $parseMode = 'Markdown'): array
    {
        $errors = [];
        
        // 检查长度
        $lengthCheck = self::checkLength($text);
        if (!$lengthCheck['valid']) {
            $errors[] = "消息长度超限，当前 {$lengthCheck['length']} 字符，最大 {$lengthCheck['max_length']} 字符";
        }
        
        // 检查格式
        if ($parseMode === 'Markdown') {
            $errors = array_merge($errors, self::validateMarkdown($text));
        } elseif ($parseMode === 'HTML') {
            $errors = array_merge($errors, self::validateHtml($text));
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * 验证Markdown格式
     */
    private static function validateMarkdown(string $text): array
    {
        $errors = [];
        
        // 检查未闭合的标记
        $markdownPairs = [
            '*' => '粗体',
            '_' => '斜体',
            '`' => '内联代码',
            '~' => '删除线'
        ];
        
        foreach ($markdownPairs as $char => $name) {
            if (substr_count($text, $char) % 2 !== 0) {
                $errors[] = "未闭合的{$name}标记 ({$char})";
            }
        }
        
        return $errors;
    }
    
    /**
     * 验证HTML格式
     */
    private static function validateHtml(string $text): array
    {
        $errors = [];
        
        // 简单的HTML标签检查
        $allowedTags = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'code', 'pre', 'a'];
        
        preg_match_all('/<(\/?[a-z]+)(?:\s[^>]*)?>/', $text, $matches);
        
        $openTags = [];
        
        foreach ($matches[1] as $tag) {
            if (strpos($tag, '/') === 0) {
                // 闭合标签
                $tagName = substr($tag, 1);
                if (empty($openTags) || array_pop($openTags) !== $tagName) {
                    $errors[] = "未匹配的闭合标签: <{$tag}>";
                }
            } else {
                // 开放标签
                if (!in_array($tag, $allowedTags)) {
                    $errors[] = "不支持的HTML标签: <{$tag}>";
                } else {
                    $openTags[] = $tag;
                }
            }
        }
        
        if (!empty($openTags)) {
            $errors[] = "未闭合的HTML标签: " . implode(', ', array_map(fn($tag) => "<{$tag}>", $openTags));
        }
        
        return $errors;
    }
}