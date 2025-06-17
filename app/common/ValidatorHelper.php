<?php
declare(strict_types=1);

namespace app\common;

/**
 * 验证助手类
 * 
 * 提供数据验证规则、自定义验证器、错误信息处理等功能
 */
class ValidatorHelper
{
    /**
     * 验证规则
     */
    private array $rules = [];
    
    /**
     * 验证数据
     */
    private array $data = [];
    
    /**
     * 错误信息
     */
    private array $errors = [];
    
    /**
     * 自定义错误消息
     */
    private array $messages = [];
    
    /**
     * 字段别名
     */
    private array $aliases = [];
    
    /**
     * 构造函数
     *
     * @param array $data 要验证的数据
     * @param array $rules 验证规则
     * @param array $messages 自定义错误消息
     */
    public function __construct(array $data = [], array $rules = [], array $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
    }
    
    /**
     * 创建验证器实例
     *
     * @param array $data 要验证的数据
     * @param array $rules 验证规则
     * @param array $messages 自定义错误消息
     * @return static
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new static($data, $rules, $messages);
    }
    
    /**
     * 设置字段别名
     *
     * @param array $aliases 字段别名
     * @return $this
     */
    public function setAliases(array $aliases): self
    {
        $this->aliases = $aliases;
        return $this;
    }
    
    /**
     * 执行验证
     *
     * @return bool
     */
    public function validate(): bool
    {
        $this->errors = [];
        
        foreach ($this->rules as $field => $rules) {
            $this->validateField($field, $rules);
        }
        
        return empty($this->errors);
    }
    
    /**
     * 验证单个字段
     *
     * @param string $field 字段名
     * @param string|array $rules 验证规则
     */
    private function validateField(string $field, $rules): void
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }
        
        $value = $this->data[$field] ?? null;
        
        foreach ($rules as $rule) {
            if (!$this->validateRule($field, $value, $rule)) {
                break; // 一个字段的第一个验证失败后停止后续验证
            }
        }
    }
    
    /**
     * 验证单个规则
     *
     * @param string $field 字段名
     * @param mixed $value 字段值
     * @param string $rule 规则
     * @return bool
     */
    private function validateRule(string $field, $value, string $rule): bool
    {
        // 解析规则参数
        $params = [];
        if (strpos($rule, ':') !== false) {
            list($rule, $paramString) = explode(':', $rule, 2);
            $params = explode(',', $paramString);
        }
        
        $method = 'validate' . ucfirst($rule);
        
        if (method_exists($this, $method)) {
            $result = $this->$method($field, $value, $params);
        } else {
            // 自定义规则
            $result = $this->validateCustomRule($field, $value, $rule, $params);
        }
        
        if (!$result) {
            $this->addError($field, $rule, $params);
            return false;
        }
        
        return true;
    }
    
    /**
     * 添加错误信息
     *
     * @param string $field 字段名
     * @param string $rule 规则
     * @param array $params 参数
     */
    private function addError(string $field, string $rule, array $params = []): void
    {
        $key = "{$field}.{$rule}";
        $fieldName = $this->aliases[$field] ?? $field;
        
        if (isset($this->messages[$key])) {
            $message = $this->messages[$key];
        } else {
            $message = $this->getDefaultMessage($rule, $fieldName, $params);
        }
        
        $this->errors[$field][] = $message;
    }
    
    /**
     * 获取默认错误消息
     *
     * @param string $rule 规则
     * @param string $field 字段名
     * @param array $params 参数
     * @return string
     */
    private function getDefaultMessage(string $rule, string $field, array $params = []): string
    {
        $messages = [
            'required' => "{$field}不能为空",
            'string' => "{$field}必须是字符串",
            'numeric' => "{$field}必须是数字",
            'integer' => "{$field}必须是整数",
            'float' => "{$field}必须是浮点数",
            'boolean' => "{$field}必须是布尔值",
            'array' => "{$field}必须是数组",
            'email' => "{$field}格式不正确",
            'url' => "{$field}必须是有效的URL",
            'ip' => "{$field}必须是有效的IP地址",
            'date' => "{$field}必须是有效的日期",
            'min' => "{$field}最小值为{$params[0]}",
            'max' => "{$field}最大值为{$params[0]}",
            'between' => "{$field}必须在{$params[0]}到{$params[1]}之间",
            'length' => "{$field}长度必须是{$params[0]}",
            'minLength' => "{$field}最小长度为{$params[0]}",
            'maxLength' => "{$field}最大长度为{$params[0]}",
            'in' => "{$field}必须是: " . implode(', ', $params),
            'notIn' => "{$field}不能是: " . implode(', ', $params),
            'regex' => "{$field}格式不正确",
            'unique' => "{$field}已经存在",
            'exists' => "{$field}不存在",
            'same' => "{$field}必须与{$params[0]}相同",
            'different' => "{$field}必须与{$params[0]}不同",
            'phone' => "{$field}手机号格式不正确",
            'usdtAddress' => "{$field}USDT地址格式不正确",
            'password' => "{$field}密码格式不正确",
            'amount' => "{$field}金额格式不正确",
            'positive' => "{$field}必须是正数",
            'alphaNum' => "{$field}只能包含字母和数字",
            'alpha' => "{$field}只能包含字母",
            'chinese' => "{$field}只能包含中文字符",
        ];
        
        return $messages[$rule] ?? "{$field}验证失败";
    }
    
    /**
     * 获取错误信息
     *
     * @return array
     */
    public function errors(): array
    {
        return $this->errors;
    }
    
    /**
     * 获取第一个错误信息
     *
     * @return string
     */
    public function firstError(): string
    {
        foreach ($this->errors as $fieldErrors) {
            return $fieldErrors[0] ?? '';
        }
        return '';
    }
    
    /**
     * 验证必填
     */
    private function validateRequired(string $field, $value, array $params): bool
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return false;
        }
        return true;
    }
    
    /**
     * 验证字符串
     */
    private function validateString(string $field, $value, array $params): bool
    {
        return is_string($value);
    }
    
    /**
     * 验证数字
     */
    private function validateNumeric(string $field, $value, array $params): bool
    {
        return is_numeric($value);
    }
    
    /**
     * 验证整数
     */
    private function validateInteger(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
    
    /**
     * 验证浮点数
     */
    private function validateFloat(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }
    
    /**
     * 验证布尔值
     */
    private function validateBoolean(string $field, $value, array $params): bool
    {
        return is_bool($value) || in_array($value, ['0', '1', 'true', 'false', true, false, 0, 1], true);
    }
    
    /**
     * 验证数组
     */
    private function validateArray(string $field, $value, array $params): bool
    {
        return is_array($value);
    }
    
    /**
     * 验证邮箱
     */
    private function validateEmail(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * 验证URL
     */
    private function validateUrl(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * 验证IP地址
     */
    private function validateIp(string $field, $value, array $params): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * 验证日期
     */
    private function validateDate(string $field, $value, array $params): bool
    {
        if (!is_string($value)) return false;
        
        $format = $params[0] ?? 'Y-m-d H:i:s';
        $date = \DateTime::createFromFormat($format, $value);
        
        return $date && $date->format($format) === $value;
    }
    
    /**
     * 验证最小值
     */
    private function validateMin(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $min = (float) $params[0];
        
        if (is_numeric($value)) {
            return (float) $value >= $min;
        }
        
        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }
        
        if (is_array($value)) {
            return count($value) >= $min;
        }
        
        return false;
    }
    
    /**
     * 验证最大值
     */
    private function validateMax(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $max = (float) $params[0];
        
        if (is_numeric($value)) {
            return (float) $value <= $max;
        }
        
        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }
        
        if (is_array($value)) {
            return count($value) <= $max;
        }
        
        return false;
    }
    
    /**
     * 验证范围
     */
    private function validateBetween(string $field, $value, array $params): bool
    {
        if (count($params) < 2) return false;
        
        $min = (float) $params[0];
        $max = (float) $params[1];
        
        if (is_numeric($value)) {
            $value = (float) $value;
            return $value >= $min && $value <= $max;
        }
        
        if (is_string($value)) {
            $length = mb_strlen($value);
            return $length >= $min && $length <= $max;
        }
        
        return false;
    }
    
    /**
     * 验证固定长度
     */
    private function validateLength(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $length = (int) $params[0];
        
        if (is_string($value)) {
            return mb_strlen($value) === $length;
        }
        
        if (is_array($value)) {
            return count($value) === $length;
        }
        
        return false;
    }
    
    /**
     * 验证最小长度
     */
    private function validateMinLength(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $minLength = (int) $params[0];
        
        if (is_string($value)) {
            return mb_strlen($value) >= $minLength;
        }
        
        return false;
    }
    
    /**
     * 验证最大长度
     */
    private function validateMaxLength(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $maxLength = (int) $params[0];
        
        if (is_string($value)) {
            return mb_strlen($value) <= $maxLength;
        }
        
        return false;
    }
    
    /**
     * 验证在指定值中
     */
    private function validateIn(string $field, $value, array $params): bool
    {
        return in_array($value, $params, true);
    }
    
    /**
     * 验证不在指定值中
     */
    private function validateNotIn(string $field, $value, array $params): bool
    {
        return !in_array($value, $params, true);
    }
    
    /**
     * 验证正则表达式
     */
    private function validateRegex(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        return preg_match($params[0], $value) === 1;
    }
    
    /**
     * 验证手机号
     */
    private function validatePhone(string $field, $value, array $params): bool
    {
        $region = $params[0] ?? 'CN';
        return SecurityHelper::validatePhone($value, $region);
    }
    
    /**
     * 验证USDT地址
     */
    private function validateUsdtAddress(string $field, $value, array $params): bool
    {
        $network = $params[0] ?? 'TRC20';
        return SecurityHelper::validateUsdtAddress($value, $network);
    }
    
    /**
     * 验证密码强度
     */
    private function validatePassword(string $field, $value, array $params): bool
    {
        if (!is_string($value)) return false;
        
        $minLength = (int) ($params[0] ?? 6);
        $requireMixed = isset($params[1]) ? (bool) $params[1] : false;
        
        // 长度检查
        if (strlen($value) < $minLength) {
            return false;
        }
        
        // 混合字符检查
        if ($requireMixed) {
            $hasLower = preg_match('/[a-z]/', $value);
            $hasUpper = preg_match('/[A-Z]/', $value);
            $hasDigit = preg_match('/\d/', $value);
            
            return $hasLower && $hasUpper && $hasDigit;
        }
        
        return true;
    }
    
    /**
     * 验证金额格式
     */
    private function validateAmount(string $field, $value, array $params): bool
    {
        if (!is_numeric($value)) return false;
        
        $amount = (float) $value;
        $precision = (int) ($params[0] ?? 2);
        
        // 检查小数位数
        $decimalPlaces = strlen(substr(strrchr($value, '.'), 1));
        if ($decimalPlaces > $precision) {
            return false;
        }
        
        return $amount >= 0;
    }
    
    /**
     * 验证正数
     */
    private function validatePositive(string $field, $value, array $params): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
    
    /**
     * 验证字母数字
     */
    private function validateAlphaNum(string $field, $value, array $params): bool
    {
        return ctype_alnum($value);
    }
    
    /**
     * 验证纯字母
     */
    private function validateAlpha(string $field, $value, array $params): bool
    {
        return ctype_alpha($value);
    }
    
    /**
     * 验证中文字符
     */
    private function validateChinese(string $field, $value, array $params): bool
    {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $value) === 1;
    }
    
    /**
     * 验证相同
     */
    private function validateSame(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $otherField = $params[0];
        $otherValue = $this->data[$otherField] ?? null;
        
        return $value === $otherValue;
    }
    
    /**
     * 验证不同
     */
    private function validateDifferent(string $field, $value, array $params): bool
    {
        if (!isset($params[0])) return false;
        
        $otherField = $params[0];
        $otherValue = $this->data[$otherField] ?? null;
        
        return $value !== $otherValue;
    }
    
    /**
     * 自定义规则验证
     */
    private function validateCustomRule(string $field, $value, string $rule, array $params): bool
    {
        // 这里可以扩展自定义验证规则
        return true;
    }
    
    /**
     * 静态验证方法
     *
     * @param array $data 数据
     * @param array $rules 规则
     * @param array $messages 消息
     * @return array
     */
    public static function validate(array $data, array $rules, array $messages = []): array
    {
        $validator = new static($data, $rules, $messages);
        $result = $validator->validate();
        
        return [
            'valid' => $result,
            'errors' => $validator->errors(),
            'first_error' => $validator->firstError()
        ];
    }
}