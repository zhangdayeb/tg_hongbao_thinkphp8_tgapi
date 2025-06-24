<?php
declare(strict_types=1);

namespace app\common;

/**
 * 安全助手类
 * 
 * 提供数据加密解密、密码哈希、签名验证、输入过滤等安全功能
 */
class SecurityHelper
{
    /**
     * 加密密钥
     */
    private static string $encryptKey = '';
    
    /**
     * 签名密钥
     */
    private static string $signKey = '';
    
    /**
     * 初始化密钥
     */
    private static function initKeys(): void
    {
        if (empty(self::$encryptKey)) {
            self::$encryptKey = config('app.app_key', 'default_encrypt_key');
        }
        if (empty(self::$signKey)) {
            self::$signKey = config('app.sign_key', 'default_sign_key');
        }
    }
    
    /**
     * 加密数据
     *
     * @param string $data 要加密的数据
     * @param string $key 自定义密钥
     * @return string
     */
    public static function encrypt(string $data, string $key = ''): string
    {
        self::initKeys();
        $key = $key ?: self::$encryptKey;
        
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * 解密数据
     *
     * @param string $encryptedData 加密的数据
     * @param string $key 自定义密钥
     * @return string|false
     */
    public static function decrypt(string $encryptedData, string $key = '')
    {
        self::initKeys();
        $key = $key ?: self::$encryptKey;
        
        $data = base64_decode($encryptedData);
        if (strlen($data) < 16) {
            return false;
        }
        
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * 生成密码哈希
     *
     * @param string $password 原始密码
     * @param string $salt 盐值
     * @return string
     */
    public static function hashPassword(string $password, string $salt = ''): string
    {
        if (empty($salt)) {
            $salt = self::generateSalt();
        }
        
        return hash('sha256', $password . $salt);
    }
    
    /**
     * 验证密码
     *
     * @param string $password 原始密码
     * @param string $hash 存储的哈希值
     * @param string $salt 盐值
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash, string $salt = ''): bool
    {
        return hash_equals($hash, self::hashPassword($password, $salt));
    }
    
    /**
     * 生成随机盐值
     *
     * @param int $length 盐值长度
     * @return string
     */
    public static function generateSalt(int $length = 16): string
    {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * 生成随机字符串
     *
     * @param int $length 字符串长度
     * @param string $chars 字符集
     * @return string
     */
    public static function generateRandomString(int $length = 32, string $chars = ''): string
    {
        if (empty($chars)) {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        }
        
        $result = '';
        $charLength = strlen($chars);
        
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $charLength - 1)];
        }
        
        return $result;
    }
    
    /**
     * 生成数字验证码
     *
     * @param int $length 验证码长度
     * @return string
     */
    public static function generateNumericCode(int $length = 6): string
    {
        return self::generateRandomString($length, '0123456789');
    }
    
    /**
     * 生成邀请码
     *
     * @param int $length 邀请码长度
     * @return string
     */
    public static function generateInviteCode(int $length = 8): string
    {
        return strtoupper(self::generateRandomString($length, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'));
    }
    
    /**
     * 生成订单号
     *
     * @param string $prefix 前缀
     * @param int $length 总长度
     * @return string
     */
    public static function generateOrderNumber(string $prefix = '', int $length = 16): string
    {
        $timestamp = time();
        $random = random_int(1000, 9999);
        
        $orderNumber = $prefix . date('YmdHis', $timestamp) . $random;
        
        // 如果长度不够，补充随机数字
        if (strlen($orderNumber) < $length) {
            $orderNumber .= self::generateNumericCode($length - strlen($orderNumber));
        }
        
        return substr($orderNumber, 0, $length);
    }
    
    /**
     * 数据签名
     *
     * @param array $data 要签名的数据
     * @param string $key 签名密钥
     * @return string
     */
    public static function sign(array $data, string $key = ''): string
    {
        self::initKeys();
        $key = $key ?: self::$signKey;
        
        // 排序数据
        ksort($data);
        
        // 构建签名字符串
        $signString = '';
        foreach ($data as $k => $v) {
            if ($k !== 'sign' && $v !== '' && $v !== null) {
                $signString .= $k . '=' . $v . '&';
            }
        }
        $signString = rtrim($signString, '&');
        
        return hash_hmac('sha256', $signString, $key);
    }
    
    /**
     * 验证签名
     *
     * @param array $data 数据
     * @param string $signature 签名
     * @param string $key 签名密钥
     * @return bool
     */
    public static function verifySign(array $data, string $signature, string $key = ''): bool
    {
        $computedSign = self::sign($data, $key);
        return hash_equals($signature, $computedSign);
    }
    
    /**
     * 过滤输入数据
     *
     * @param mixed $input 输入数据
     * @param array $options 过滤选项
     * @return mixed
     */
    public static function filterInput($input, array $options = [])
    {
        $defaultOptions = [
            'trim' => true,
            'strip_tags' => true,
            'htmlspecialchars' => true,
            'remove_null_byte' => true,
            'max_length' => 0
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        if (is_string($input)) {
            // 移除空字节
            if ($options['remove_null_byte']) {
                $input = str_replace("\0", '', $input);
            }
            
            // 去除首尾空格
            if ($options['trim']) {
                $input = trim($input);
            }
            
            // 移除HTML标签
            if ($options['strip_tags']) {
                $input = strip_tags($input);
            }
            
            // HTML特殊字符转义
            if ($options['htmlspecialchars']) {
                $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            }
            
            // 长度限制
            if ($options['max_length'] > 0 && mb_strlen($input) > $options['max_length']) {
                $input = mb_substr($input, 0, $options['max_length']);
            }
        } elseif (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = self::filterInput($value, $options);
            }
        }
        
        return $input;
    }
    
    /**
     * 验证USDT地址格式
     *
     * @param string $address USDT地址
     * @param string $network 网络类型
     * @return bool
     */
    public static function validateUsdtAddress(string $address, string $network = 'TRC20'): bool
    {
        switch (strtoupper($network)) {
            case 'TRC20':
                // TRC20地址格式：T开头，34位长度
                return preg_match('/^T[A-Za-z1-9]{33}$/', $address) === 1;
            case 'ERC20':
                // ERC20地址格式：0x开头，42位长度
                return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
            case 'OMNI':
                // OMNI地址格式：1或3开头
                return preg_match('/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/', $address) === 1;
            default:
                return false;
        }
    }
    
    /**
     * 验证手机号格式
     *
     * @param string $phone 手机号
     * @param string $region 地区
     * @return bool
     */
    public static function validatePhone(string $phone, string $region = 'CN'): bool
    {
        switch (strtoupper($region)) {
            case 'CN':
                // 中国手机号
                return preg_match('/^1[3-9]\d{9}$/', $phone) === 1;
            case 'KH':
                // 柬埔寨手机号
                return preg_match('/^(\+855|855|0)?[1-9]\d{7,8}$/', $phone) === 1;
            default:
                // 通用手机号验证
                return preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === 1;
        }
    }
    
    /**
     * 验证邮箱格式
     *
     * @param string $email 邮箱地址
     * @return bool
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * 验证IP地址
     *
     * @param string $ip IP地址
     * @param array $allowedRanges 允许的IP段
     * @return bool
     */
    public static function validateIp(string $ip, array $allowedRanges = []): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        
        if (empty($allowedRanges)) {
            return true;
        }
        
        foreach ($allowedRanges as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查IP是否在指定范围内
     *
     * @param string $ip IP地址
     * @param string $range IP段 (例如: 192.168.1.0/24)
     * @return bool
     */
    public static function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $bits) = explode('/', $range);
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        
        return ($ip & $mask) === ($subnet & $mask);
    }
    
    /**
     * 生成CSRF Token
     *
     * @return string
     */
    public static function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * 验证CSRF Token
     *
     * @param string $token 提交的token
     * @param string $sessionToken 会话中的token
     * @return bool
     */
    public static function verifyCsrfToken(string $token, string $sessionToken): bool
    {
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * 获取安全的随机数
     *
     * @param int $min 最小值
     * @param int $max 最大值
     * @return int
     */
    public static function secureRandom(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return random_int($min, $max);
    }
    
    /**
     * 时间安全比较
     *
     * @param string $expected 期望值
     * @param string $actual 实际值
     * @return bool
     */
    public static function timingSafeEquals(string $expected, string $actual): bool
    {
        return hash_equals($expected, $actual);
    }
    
    /**
     * 检测恶意输入
     *
     * @param string $input 输入内容
     * @return bool
     */
    public static function detectMaliciousInput(string $input): bool
    {
        $patterns = [
            '/(<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>)/mi', // Script标签
            '/javascript:/i', // JavaScript协议
            '/on\w+\s*=/i', // 事件处理器
            '/expression\s*\(/i', // CSS表达式
            '/vbscript:/i', // VBScript协议
            '/data:text\/html/i', // Data URL
            '/<iframe\b/i', // iframe标签
            '/<object\b/i', // object标签
            '/<embed\b/i', // embed标签
            '/sql/i', // SQL关键字
            '/union\s+select/i', // SQL注入
            '/drop\s+table/i', // 删除表
            '/delete\s+from/i', // 删除数据
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 敏感信息掩码 - 修复版本，避免 Markdown 解析问题
     *
     * @param string $data 原始数据
     * @param string $type 数据类型
     * @return string
     */
    public static function maskSensitiveData(string $data, string $type = 'default'): string
    {
        switch ($type) {
            case 'phone':
                // 原来：$1****$2 → 改为：$1····$2 (避免 Markdown 解析星号)
                return preg_replace('/(\d{3})\d{4}(\d{4})/', '$1····$2', $data);
                
            case 'email':
                // 原来：$1***$2@ → 改为：$1···$2@
                return preg_replace('/(.)[^@]*(.{2})@/', '$1···$2@', $data);
                
            case 'id_card':
                // 原来：$1**********$2 → 改为：$1··········$2
                return preg_replace('/(\d{4})\d{10}(\d{4})/', '$1··········$2', $data);
                
            case 'bank_card':
                // 原来：$1****$2 → 改为：$1····$2
                return preg_replace('/(\d{4})\d+(\d{4})/', '$1····$2', $data);
                
            case 'usdt_address':
                // 原来：'****' → 改为：'····'
                return substr($data, 0, 6) . '····' . substr($data, -6);
                
            default:
                $length = strlen($data);
                if ($length <= 6) {
                    // 原来：str_repeat('*', $length) → 改为：str_repeat('·', $length)
                    return str_repeat('·', $length);
                }
                // 原来：str_repeat('*', $length - 6) → 改为：str_repeat('·', $length - 6)
                return substr($data, 0, 3) . str_repeat('·', $length - 6) . substr($data, -3);
        }
    }
}