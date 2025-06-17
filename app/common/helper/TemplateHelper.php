<?php
declare(strict_types=1);

namespace app\common\helper;

/**
 * 模板助手类 - 极简版本
 * 
 * 专注于模板加载和变量替换，不包含业务逻辑
 */
class TemplateHelper
{
    // 模板文件目录
    private static string $templatePath = '';
    
    /**
     * 初始化模板路径
     */
    private static function initTemplatePath(): void
    {
        if (empty(self::$templatePath)) {
            self::$templatePath = config_path() . 'templates/';
        }
    }
    
    /**
     * 获取消息模板
     * 
     * @param string $module 模块名称
     * @param string $key 模板键名
     * @param array $data 替换数据
     * @return string 渲染后的模板内容
     */
    public static function getMessage(string $module, string $key, array $data = []): string
    {
        $template = self::getTemplate($module, 'messages', $key);
        
        if (empty($template)) {
            // 回退到common模块
            $template = self::getTemplate('common', 'messages', $key);
        }
        
        if (empty($template)) {
            return "❌ 模板不存在: {$module}.messages.{$key}";
        }
        
        return self::render($template, $data);
    }
    
    /**
     * 获取错误消息模板
     * 
     * @param string $module 模块名称
     * @param string $key 错误类型
     * @param array $data 替换数据
     * @return string 渲染后的错误消息
     */
    public static function getError(string $module, string $key, array $data = []): string
    {
        // 先从模块的errors中获取
        $template = self::getTemplate($module, 'errors', $key);
        
        if (empty($template)) {
            // 从common模块获取
            $template = self::getTemplate('common', 'messages', 'error_' . $key);
        }
        
        if (empty($template)) {
            return "❌ 处理失败，请稍后重试";
        }
        
        return self::render($template, $data);
    }
    
    /**
     * 获取键盘模板
     * 
     * @param string $module 模块名称
     * @param string $key 键盘键名
     * @param array $data 替换数据
     * @return array 键盘数组
     */
    public static function getKeyboard(string $module, string $key, array $data = []): array
    {
        $keyboard = self::getTemplate($module, 'keyboards', $key);
        
        if (empty($keyboard)) {
            // 回退到common模块
            $keyboard = self::getTemplate('common', 'keyboards', $key);
        }
        
        if (empty($keyboard) || !is_array($keyboard)) {
            return [];
        }
        
        return self::renderKeyboard($keyboard, $data);
    }
    
    /**
     * 渲染模板（替换变量）
     * 
     * @param string $template 模板内容
     * @param array $data 替换数据
     * @return string 渲染后的内容
     */
    public static function render(string $template, array $data = []): string
    {
        if (empty($data)) {
            return $template;
        }
        
        // 合并系统数据
        $allData = array_merge(self::getSystemData(), $data);
        
        // 替换变量
        $search = [];
        $replace = [];
        
        foreach ($allData as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = (string)$value;
        }
        
        return str_replace($search, $replace, $template);
    }
    
    /**
     * 渲染键盘
     * 
     * @param array $keyboard 键盘数组
     * @param array $data 替换数据
     * @return array 渲染后的键盘
     */
    public static function renderKeyboard(array $keyboard, array $data = []): array
    {
        if (empty($keyboard)) {
            return $keyboard;
        }
        
        $allData = array_merge(self::getSystemData(), $data);
        
        return self::processKeyboard($keyboard, $allData);
    }
    
    /**
     * 检查模块是否存在
     * 
     * @param string $module 模块名称
     * @return bool 是否存在
     */
    public static function moduleExists(string $module): bool
    {
        self::initTemplatePath();
        return file_exists(self::$templatePath . "{$module}.php");
    }
    
    /**
     * 获取所有模块列表
     * 
     * @return array 模块列表
     */
    public static function getModules(): array
    {
        self::initTemplatePath();
        
        if (!is_dir(self::$templatePath)) {
            return [];
        }
        
        $modules = [];
        $files = scandir(self::$templatePath);
        
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..' && str_ends_with($file, '.php')) {
                $modules[] = basename($file, '.php');
            }
        }
        
        return $modules;
    }
    
    // ==================== 私有方法 ====================
    
    /**
     * 获取单个模板
     */
    private static function getTemplate(string $module, string $type, string $key)
    {
        self::initTemplatePath();
        
        $file = self::$templatePath . "{$module}.php";
        
        if (!file_exists($file)) {
            return null;
        }
        
        try {
            $templates = include $file;
            return $templates[$type][$key] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取系统数据
     */
    private static function getSystemData(): array
    {
        $config = config('telegram', []);
        $data = [];
        
        // 链接配置
        if (isset($config['links'])) {
            $data = array_merge($data, $config['links']);
        }
        
        // 基础信息
        $data['bot_name'] = $config['bot_name'] ?? '';
        $data['current_time'] = date('Y-m-d H:i:s');
        $data['current_date'] = date('Y-m-d');
        
        // 业务配置
        if (isset($config['withdraw'])) {
            $w = $config['withdraw'];
            $data['min_amount'] = $w['min_amount'] ?? 10;
            $data['max_amount'] = $w['max_amount'] ?? 10000;
            $data['fee_rate'] = ($w['fee_rate'] ?? 0.02) * 100;
        }
        
        if (isset($config['payment'])) {
            $p = $config['payment'];
            $data['usdt_min'] = $p['usdt']['min_amount'] ?? 20;
            $data['usdt_max'] = $p['usdt']['max_amount'] ?? 50000;
            $data['huiwang_min'] = $p['huiwang']['min_amount'] ?? 100;
            $data['huiwang_max'] = $p['huiwang']['max_amount'] ?? 100000;
        }
        
        return $data;
    }
    
    /**
     * 处理键盘变量替换
     */
    private static function processKeyboard(array $keyboard, array $data): array
    {
        $result = [];
        
        foreach ($keyboard as $row) {
            if (!is_array($row)) continue;
            
            $processedRow = [];
            foreach ($row as $button) {
                if (!is_array($button) || !isset($button['text'])) continue;
                
                $processedButton = [];
                foreach ($button as $key => $value) {
                    $processedButton[$key] = is_string($value) ? self::render($value, $data) : $value;
                }
                
                $processedRow[] = $processedButton;
            }
            
            if (!empty($processedRow)) {
                $result[] = $processedRow;
            }
        }
        
        return $result;
    }
}