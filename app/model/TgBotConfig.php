<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;

/**
 * Telegram机器人配置模型
 */
class TgBotConfig extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'tg_bot_config';
    
    /**
     * 类型转换
     */
    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
        'max_file_size' => 'integer',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'created_at'];
    
    /**
     * 状态常量
     */
    public const STATUS_DISABLED = 0;     // 禁用
    public const STATUS_ENABLED = 1;      // 启用
    
    /**
     * 默认配置常量
     */
    public const DEFAULT_MAX_FILE_SIZE = 20;  // 默认最大文件大小（MB）
    
    /**
     * 获取验证规则
     */
    protected function getValidationRules(): array
    {
        return [
            'bot_token' => 'required|maxLength:200',
            'bot_username' => 'maxLength:100',
            'bot_name' => 'maxLength:100',
            'webhook_url' => 'url|maxLength:500',
            'status' => 'in:0,1',
            'max_file_size' => 'integer|min:1|max:50',
        ];
    }
    
    /**
     * Bot令牌修改器
     */
    public function setBotTokenAttr($value)
    {
        return trim($value);
    }
    
    /**
     * Bot用户名修改器
     */
    public function setBotUsernameAttr($value)
    {
        return $value ? ltrim(trim($value), '@') : '';
    }
    
    /**
     * Webhook地址修改器
     */
    public function setWebhookUrlAttr($value)
    {
        return trim($value);
    }
    
    /**
     * Bot令牌获取器（安全显示）
     */
    public function getBotTokenMaskedAttr($value, $data)
    {
        $token = $data['bot_token'] ?? '';
        if (empty($token) || strlen($token) < 10) {
            return $token;
        }
        
        return substr($token, 0, 10) . '...' . substr($token, -6);
    }
    
    /**
     * 状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用',
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 状态颜色获取器
     */
    public function getStatusColorAttr($value, $data)
    {
        $colors = [
            self::STATUS_DISABLED => 'danger',
            self::STATUS_ENABLED => 'success',
        ];
        return $colors[$data['status']] ?? 'secondary';
    }
    
    /**
     * 是否启用
     */
    public function getIsEnabledAttr($value, $data)
    {
        return ($data['status'] ?? 0) === self::STATUS_ENABLED;
    }
    
    /**
     * Bot用户名格式化
     */
    public function getBotUsernameFormattedAttr($value, $data)
    {
        $username = $data['bot_username'] ?? '';
        return $username ? '@' . $username : '';
    }
    
    /**
     * 文件大小格式化
     */
    public function getMaxFileSizeFormattedAttr($value, $data)
    {
        $size = $data['max_file_size'] ?? self::DEFAULT_MAX_FILE_SIZE;
        return $size . ' MB';
    }
    
    /**
     * Webhook状态获取器
     */
    public function getWebhookStatusAttr($value, $data)
    {
        $webhookUrl = $data['webhook_url'] ?? '';
        return !empty($webhookUrl) ? '已设置' : '未设置';
    }
    
    /**
     * Bot信息获取器
     */
    public function getBotInfoAttr($value, $data)
    {
        $botName = $data['bot_name'] ?? '';
        $botUsername = $data['bot_username'] ?? '';
        
        if (!empty($botName) && !empty($botUsername)) {
            return $botName . ' (@' . $botUsername . ')';
        } elseif (!empty($botName)) {
            return $botName;
        } elseif (!empty($botUsername)) {
            return '@' . $botUsername;
        } else {
            return '未设置';
        }
    }
    
    /**
     * 创建配置 - 修复：使用 datetime 格式
     */
    public static function createConfig(array $data): TgBotConfig
    {
        $config = new static();
        
        // 设置默认值 - 修复：使用 datetime 格式
        $data = array_merge([
            'status' => self::STATUS_DISABLED,
            'max_file_size' => self::DEFAULT_MAX_FILE_SIZE,
            'webhook_url' => '',
            'bot_username' => '',
            'bot_name' => '',
            'welcome_message' => static::getDefaultWelcomeMessage(),
            'help_message' => static::getDefaultHelpMessage(),
            'created_at' => date('Y-m-d H:i:s'),
        ], $data);
        
        $config->save($data);
        
        // 清除配置缓存
        $config->clearConfigCache();
        
        // 记录创建日志
        trace([
            'action' => 'bot_config_created',
            'config_id' => $config->id,
            'bot_username' => $config->bot_username,
            'timestamp' => time(),
        ], 'telegram_bot');
        
        return $config;
    }
    
    /**
     * 更新配置 - 修复：使用 datetime 格式
     */
    public function updateConfig(array $data): bool
    {
        $updateFields = [
            'bot_token', 'webhook_url', 'bot_username', 'bot_name', 
            'welcome_message', 'help_message', 'status', 'max_file_size'
        ];
        
        $oldData = $this->toArray();
        
        foreach ($updateFields as $field) {
            if (array_key_exists($field, $data)) {
                $this->$field = $data[$field];
            }
        }
        
        $this->updated_at = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
        
        $result = $this->save();
        
        if ($result) {
            // 清除配置缓存
            $this->clearConfigCache();
            
            // 记录更新日志
            trace([
                'action' => 'bot_config_updated',
                'config_id' => $this->id,
                'old_data' => $oldData,
                'new_data' => array_intersect_key($data, array_flip($updateFields)),
                'timestamp' => time(),
            ], 'telegram_bot');
        }
        
        return $result;
    }
    
    /**
     * 启用配置 - 修复：使用 datetime 格式
     */
    public function enable(): bool
    {
        if ($this->status === self::STATUS_ENABLED) {
            return true;
        }
        
        $this->status = self::STATUS_ENABLED;
        $this->updated_at = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
        
        $result = $this->save();
        
        if ($result) {
            $this->clearConfigCache();
            
            trace([
                'action' => 'bot_config_enabled',
                'config_id' => $this->id,
                'timestamp' => time(),
            ], 'telegram_bot');
        }
        
        return $result;
    }
    
    /**
     * 禁用配置 - 修复：使用 datetime 格式
     */
    public function disable(): bool
    {
        if ($this->status === self::STATUS_DISABLED) {
            return true;
        }
        
        $this->status = self::STATUS_DISABLED;
        $this->updated_at = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
        
        $result = $this->save();
        
        if ($result) {
            $this->clearConfigCache();
            
            trace([
                'action' => 'bot_config_disabled',
                'config_id' => $this->id,
                'timestamp' => time(),
            ], 'telegram_bot');
        }
        
        return $result;
    }
    
    /**
     * 测试Bot连接
     */
    public function testConnection(): array
    {
        if (empty($this->bot_token)) {
            return [
                'success' => false,
                'message' => 'Bot Token未设置',
            ];
        }
        
        try {
            // 这里应该调用Telegram Bot API的getMe方法测试连接
            // 暂时模拟测试结果
            $testResult = $this->simulateBotTest();
            
            if ($testResult['success']) {
                // 更新Bot信息
                $this->updateBotInfo($testResult['data']);
            }
            
            return $testResult;
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '连接测试失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 设置Webhook - 修复：使用 datetime 格式
     */
    public function setWebhook(string $webhookUrl): array
    {
        if (empty($this->bot_token)) {
            return [
                'success' => false,
                'message' => 'Bot Token未设置',
            ];
        }
        
        try {
            // 这里应该调用Telegram Bot API的setWebhook方法
            // 暂时模拟设置结果
            $result = $this->simulateWebhookSetup($webhookUrl);
            
            if ($result['success']) {
                $this->webhook_url = $webhookUrl;
                $this->updated_at = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
                $this->save();
                
                $this->clearConfigCache();
            }
            
            return $result;
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook设置失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 删除Webhook - 修复：使用 datetime 格式
     */
    public function deleteWebhook(): array
    {
        try {
            // 这里应该调用Telegram Bot API的deleteWebhook方法
            $result = $this->simulateWebhookDeletion();
            
            if ($result['success']) {
                $this->webhook_url = '';
                $this->updated_at = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
                $this->save();
                
                $this->clearConfigCache();
            }
            
            return $result;
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook删除失败: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * 获取当前配置
     */
    public static function getCurrentConfig(): ?TgBotConfig
    {
        // 先从缓存获取
        $cacheKey = CacheHelper::key('telegram', 'bot_config', 'current');
        $cachedConfig = CacheHelper::get($cacheKey);
        
        if ($cachedConfig !== null) {
            $config = new static();
            $config->data($cachedConfig);
            return $config;
        }
        
        // 从数据库获取启用的配置
        $config = static::where('status', self::STATUS_ENABLED)
                       ->order('id DESC')
                       ->find();
        
        if ($config) {
            // 更新缓存
            CacheHelper::set($cacheKey, $config->toArray(), 3600);
        }
        
        return $config;
    }
    
    /**
     * 获取所有配置
     */
    public static function getAllConfigs(): array
    {
        return static::order('id DESC')->select()->toArray();
    }
    
    /**
     * 验证配置完整性
     */
    public function validateConfig(): array
    {
        $errors = [];
        
        if (empty($this->bot_token)) {
            $errors[] = 'Bot Token不能为空';
        }
        
        if (!empty($this->webhook_url) && !filter_var($this->webhook_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Webhook URL格式不正确';
        }
        
        if ($this->max_file_size < 1 || $this->max_file_size > 50) {
            $errors[] = '最大文件大小必须在1-50MB之间';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * 获取配置统计
     */
    public static function getConfigStats(): array
    {
        return [
            'total_configs' => static::count(),
            'enabled_configs' => static::where('status', self::STATUS_ENABLED)->count(),
            'disabled_configs' => static::where('status', self::STATUS_DISABLED)->count(),
            'webhook_configs' => static::where('webhook_url', '<>', '')->count(),
        ];
    }
    
    /**
     * 更新Bot信息 - 修复：使用 datetime 格式
     */
    private function updateBotInfo(array $botData): void
    {
        if (isset($botData['username'])) {
            $this->bot_username = $botData['username'];
        }
        
        if (isset($botData['first_name'])) {
            $this->bot_name = $botData['first_name'];
        }
        
        $this->updated_at = date('Y-m-d H:i:s'); // 修复：使用 datetime 格式
        $this->save();
    }
    
    /**
     * 模拟Bot测试
     */
    private function simulateBotTest(): array
    {
        // 模拟测试结果
        return [
            'success' => true,
            'message' => '连接测试成功',
            'data' => [
                'id' => random_int(100000000, 999999999),
                'is_bot' => true,
                'first_name' => 'Test Bot',
                'username' => 'test_bot',
                'can_join_groups' => true,
                'can_read_all_group_messages' => false,
                'supports_inline_queries' => false,
            ],
        ];
    }
    
    /**
     * 模拟Webhook设置
     */
    private function simulateWebhookSetup(string $webhookUrl): array
    {
        return [
            'success' => true,
            'message' => 'Webhook设置成功',
            'webhook_url' => $webhookUrl,
        ];
    }
    
    /**
     * 模拟Webhook删除
     */
    private function simulateWebhookDeletion(): array
    {
        return [
            'success' => true,
            'message' => 'Webhook删除成功',
        ];
    }
    
    /**
     * 获取默认欢迎消息
     */
    public static function getDefaultWelcomeMessage(): string
    {
        return "🎉 欢迎使用我们的机器人！\n\n" .
               "我可以帮助您：\n" .
               "💰 充值和提现\n" .
               "🧧 发送和接收红包\n" .
               "📊 查看账户信息\n" .
               "❓ 获取帮助信息\n\n" .
               "输入 /help 查看所有可用命令。";
    }
    
    /**
     * 获取默认帮助消息
     */
    public static function getDefaultHelpMessage(): string
    {
        return "🤖 机器人命令帮助\n\n" .
               "💰 财务相关：\n" .
               "/balance - 查看余额\n" .
               "/recharge - 充值\n" .
               "/withdraw - 提现\n\n" .
               "🧧 红包功能：\n" .
               "/sendred - 发红包\n" .
               "/myreds - 我的红包\n\n" .
               "👤 账户管理：\n" .
               "/profile - 个人信息\n" .
               "/settings - 账户设置\n\n" .
               "❓ 其他：\n" .
               "/help - 显示此帮助\n" .
               "/start - 重新开始";
    }
    
    /**
     * 清除配置缓存
     */
    public function clearConfigCache(): void
    {
        $cacheKey = CacheHelper::key('telegram', 'bot_config', 'current');
        CacheHelper::delete($cacheKey);
        
        // 清除其他相关缓存
        $allConfigsKey = CacheHelper::key('telegram', 'bot_config', 'all');
        CacheHelper::delete($allConfigsKey);
    }
    
    /**
     * 获取状态文本映射
     */
    protected function getStatusTexts(): array
    {
        return [
            self::STATUS_DISABLED => '禁用',
            self::STATUS_ENABLED => '启用',
        ];
    }
    
    /**
     * 获取字段注释
     */
    public static function getFieldComments(): array
    {
        return [
            'id' => '配置ID',
            'bot_token' => 'Bot令牌',
            'webhook_url' => 'Webhook地址',
            'bot_username' => 'Bot用户名',
            'bot_name' => 'Bot显示名称',
            'welcome_message' => '欢迎消息模板',
            'help_message' => '帮助消息模板',
            'status' => '启用状态',
            'max_file_size' => '最大文件大小(MB)',
            'created_at' => '创建时间',
            'updated_at' => '更新时间',
        ];
    }
    
    /**
     * 获取表注释
     */
    public static function getTableComment(): string
    {
        return 'Telegram机器人配置表';
    }
}