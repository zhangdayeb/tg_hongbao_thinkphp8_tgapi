<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Log;

/**
 * 消息模板处理服务
 * 适用于 ThinkPHP8 + PHP8.2
 */
class MessageTemplateService
{
    /**
     * 格式化模板
     */
    public function formatTemplate(array $template, array $data): array
    {
        try {
            // 预处理数据
            $processedData = $this->preprocessData($data);
            
            // 根据模板类型处理
            return match($template['type']) {
                'photo' => $this->formatPhotoTemplate($template, $processedData),
                'text_with_button' => $this->formatButtonTemplate($template, $processedData),
                default => $this->formatTextTemplate($template, $processedData)
            };
            
        } catch (\Exception $e) {
            Log::error("模板格式化失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 格式化图片模板（充值、提现、广告）
     */
    private function formatPhotoTemplate(array $template, array $data): array
    {
        return [
            'type' => 'photo',
            'image_url' => $this->replaceVariables($template['image_url'], $data),
            'caption' => $this->replaceVariables($template['caption'], $data)
        ];
    }
    
    /**
     * 格式化带按钮模板（红包）
     */
    private function formatButtonTemplate(array $template, array $data): array
    {
        $result = [
            'type' => 'text_with_button',
            'text' => $this->replaceVariables($template['text'], $data)
        ];
        
        // 处理按钮
        if (isset($template['button'])) {
            $result['button'] = [
                'text' => $this->replaceVariables($template['button']['text'], $data),
                'callback_data' => $this->replaceVariables($template['button']['callback_data'], $data)
            ];
        }
        
        return $result;
    }
    
    /**
     * 格式化普通文本模板
     */
    private function formatTextTemplate(array $template, array $data): array
    {
        $text = $template['text'] ?? $template['caption'] ?? '';
        
        return [
            'type' => 'text',
            'text' => $this->replaceVariables($text, $data)
        ];
    }
    
    /**
     * 预处理数据
     */
    private function preprocessData(array $data): array
    {
        // 获取用户信息
        $data = $this->enrichUserData($data);
        
        // 格式化时间
        $data = $this->formatTimeFields($data);
        
        // 格式化金额
        $data = $this->formatAmountFields($data);
        
        // 映射枚举值
        $data = $this->mapEnumValues($data);
        
        // 设置默认值
        $data = $this->setDefaultValues($data);
        
        return $data;
    }
    
    /**
     * 丰富用户数据
     */
    private function enrichUserData(array $data): array
    {
        // 充值、提现表的用户信息
        if (isset($data['user_id']) && !isset($data['user_name'])) {
            $user = User::find($data['user_id']);
            if ($user) {
                $data['user_name'] = $this->getUserDisplayName($user);
            }
        }
        
        // 红包表的发送者信息
        if (isset($data['sender_id']) && !isset($data['sender_name'])) {
            $user = User::find($data['sender_id']);
            if ($user) {
                $data['sender_name'] = $this->getUserDisplayName($user);
            }
        }
        
        return $data;
    }
    
    /**
     * 获取用户显示名称
     */
    private function getUserDisplayName(User $user): string
    {
        // 优先使用 TG 名字
        if (!empty($user->tg_first_name)) {
            $name = $user->tg_first_name;
            if (!empty($user->tg_last_name)) {
                $name .= ' ' . $user->tg_last_name;
            }
            return $name;
        }
        
        // 其次使用 TG 用户名
        if (!empty($user->tg_username)) {
            return '@' . $user->tg_username;
        }
        
        // 最后使用系统用户名
        if (!empty($user->user_name)) {
            return $user->user_name;
        }
        
        // 默认显示
        return '用户' . substr($user->tg_id ?? $user->id, -6);
    }
    
    /**
     * 格式化时间字段
     */
    private function formatTimeFields(array $data): array
    {
        $timeFormat = config('notification_templates.time_format.datetime', 'Y-m-d H:i:s');
        
        $timeFields = ['create_time', 'created_at', 'success_time', 'expire_time', 'send_time'];
        
        foreach ($timeFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                if (is_string($data[$field])) {
                    // 如果已经是字符串格式，尝试重新格式化
                    $timestamp = strtotime($data[$field]);
                    if ($timestamp !== false) {
                        $data[$field] = date($timeFormat, $timestamp);
                    }
                } elseif (is_numeric($data[$field])) {
                    // 如果是时间戳
                    $data[$field] = date($timeFormat, (int)$data[$field]);
                }
            }
        }
        
        return $data;
    }
    
    /**
     * 格式化金额字段
     */
    private function formatAmountFields(array $data): array
    {
        $amountConfig = config('notification_templates.amount_format', [
            'decimals' => 2,
            'decimal_separator' => '.',
            'thousands_separator' => ''
        ]);
        
        $amountFields = ['money', 'total_amount', 'money_fee', 'money_actual', 'money_balance'];
        
        foreach ($amountFields as $field) {
            if (isset($data[$field])) {
                $amount = (float)$data[$field];
                $data[$field] = number_format(
                    $amount,
                    $amountConfig['decimals'],
                    $amountConfig['decimal_separator'],
                    $amountConfig['thousands_separator']
                );
            }
        }
        
        return $data;
    }
    
    /**
     * 映射枚举值
     */
    private function mapEnumValues(array $data): array
    {
        $mapping = config('notification_templates.variable_mapping', []);
        
        // 支付方式映射
        if (isset($data['payment_method']) && isset($mapping['payment_method'])) {
            $data['payment_method_text'] = $mapping['payment_method'][$data['payment_method']] 
                ?? $data['payment_method'];
        }
        
        // 红包类型映射
        if (isset($data['packet_type']) && isset($mapping['packet_type'])) {
            $data['packet_type_text'] = $mapping['packet_type'][$data['packet_type']] 
                ?? '普通红包';
        }
        
        return $data;
    }
    
    /**
     * 设置默认值
     */
    private function setDefaultValues(array $data): array
    {
        $defaults = config('notification_templates.default_values', []);
        
        foreach ($defaults as $key => $value) {
            if (!isset($data[$key]) || empty($data[$key])) {
                $data[$key] = $value;
            }
        }
        
        // 特殊处理：如果没有图片URL，使用默认图片
        if (!isset($data['image_url']) || empty($data['image_url'])) {
            $defaultImages = config('notification_templates.default_images', []);
            
            // 根据数据类型判断使用哪个默认图片
            if (isset($data['payment_method'])) {
                $data['image_url'] = $defaultImages['recharge'] ?? '';
            } elseif (isset($data['withdraw_address'])) {
                $data['image_url'] = $defaultImages['withdraw'] ?? '';
            } else {
                $data['image_url'] = $defaultImages['advertisement'] ?? '';
            }
        }
        
        return $data;
    }
    
    /**
     * 替换模板变量
     */
    private function replaceVariables(string $template, array $data): string
    {
        // 使用正则表达式匹配 {variable_name} 格式的变量
        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($data) {
            $key = $matches[1];
            
            if (array_key_exists($key, $data)) {
                return (string)$data[$key];
            }
            
            // 如果找不到变量，记录警告但不中断处理
            Log::warning("模板变量 {{$key}} 未找到对应数据");
            return $matches[0]; // 返回原始变量格式
        }, $template);
    }
    
    /**
     * 验证模板数据完整性
     */
    public function validateTemplateData(string $templateName, array $data): array
    {
        $errors = [];
        
        // 根据模板类型检查必需字段
        switch ($templateName) {
            case 'recharge_notify':
                $required = ['user_name', 'money', 'create_time'];
                break;
            case 'withdraw_notify':
                $required = ['user_name', 'money', 'create_time'];
                break;
            case 'redpacket_notify':
                $required = ['sender_name', 'total_amount', 'total_count', 'packet_id'];
                break;
            case 'advertisement_notify':
                $required = ['content'];
                break;
            default:
                $required = [];
        }
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "缺少必需字段: {$field}";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * 获取模板预览
     */
    public function getTemplatePreview(string $templateName): array
    {
        $template = config("notification_templates.{$templateName}");
        if (!$template) {
            throw new \Exception("模板 {$templateName} 不存在");
        }
        
        // 生成示例数据
        $sampleData = $this->generateSampleData($templateName);
        
        // 格式化模板
        return $this->formatTemplate($template, $sampleData);
    }
    
    /**
     * 生成示例数据
     */
    private function generateSampleData(string $templateName): array
    {
        $baseData = [
            'user_name' => '张三',
            'sender_name' => '李四',
            'money' => '100.00',
            'total_amount' => '500.00',
            'total_count' => 10,
            'title' => '恭喜发财，大吉大利',
            'content' => '这是一个系统公告的示例内容',
            'create_time' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'packet_id' => 'HB123456789',
            'payment_method' => 'usdt',
            'packet_type' => 1
        ];
        
        return $baseData;
    }
}