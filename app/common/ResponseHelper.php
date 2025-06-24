<?php
declare(strict_types=1);

namespace app\common;

use think\Response;

/**
 * API响应助手类
 * 
 * 统一API响应格式，提供便捷的响应方法
 */
class ResponseHelper
{
    // 成功状态码
    public const SUCCESS = 200;
    
    // 客户端错误状态码
    public const CLIENT_ERROR = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const VALIDATION_ERROR = 422;
    public const TOO_MANY_REQUESTS = 429;
    
    // 服务器错误状态码
    public const SERVER_ERROR = 500;
    public const SERVICE_UNAVAILABLE = 503;
    
    // 业务错误状态码
    public const BUSINESS_ERROR = 1000;
    public const USER_ERROR = 2000;
    public const PAYMENT_ERROR = 3000;
    public const REDPACKET_ERROR = 4000;
    public const MESSAGE_ERROR = 5000;
    public const GAME_ERROR = 6000;
    
    /**
     * 成功响应
     *
     * @param mixed $data 响应数据
     * @param string $message 响应消息
     * @param int $code 状态码
     * @return Response
     */
    public static function success($data = null, string $message = 'success', int $code = self::SUCCESS): Response
    {
        return self::json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
    }
    
    /**
     * 错误响应
     *
     * @param string $message 错误消息
     * @param int $code 错误码
     * @param mixed $data 附加数据
     * @return Response
     */
    public static function error(string $message = 'error', int $code = self::CLIENT_ERROR, $data = null): Response
    {
        return self::json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ]);
    }
    
    /**
     * 分页响应
     *
     * @param array $list 数据列表
     * @param int $total 总数
     * @param int $page 当前页
     * @param int $limit 每页数量
     * @param string $message 响应消息
     * @return Response
     */
    public static function paginate(array $list, int $total, int $page = 1, int $limit = 10, string $message = 'success'): Response
    {
        $totalPages = (int) ceil($total / $limit);
        
        return self::success([
            'list' => $list,
            'pagination' => [
                'total' => $total,
                'per_page' => $limit,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ], $message);
    }
    
    /**
     * 验证错误响应
     *
     * @param array $errors 验证错误信息
     * @param string $message 错误消息
     * @return Response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): Response
    {
        return self::error($message, self::VALIDATION_ERROR, ['errors' => $errors]);
    }
    
    /**
     * 未授权响应
     *
     * @param string $message 错误消息
     * @return Response
     */
    public static function unauthorized(string $message = 'Unauthorized'): Response
    {
        return self::error($message, self::UNAUTHORIZED);
    }
    
    /**
     * 禁止访问响应
     *
     * @param string $message 错误消息
     * @return Response
     */
    public static function forbidden(string $message = 'Forbidden'): Response
    {
        return self::error($message, self::FORBIDDEN);
    }
    
    /**
     * 资源未找到响应
     *
     * @param string $message 错误消息
     * @return Response
     */
    public static function notFound(string $message = 'Not Found'): Response
    {
        return self::error($message, self::NOT_FOUND);
    }
    
    /**
     * 服务器错误响应
     *
     * @param string $message 错误消息
     * @return Response
     */
    public static function serverError(string $message = 'Internal Server Error'): Response
    {
        return self::error($message, self::SERVER_ERROR);
    }
    
    /**
     * 频率限制响应
     *
     * @param string $message 错误消息
     * @return Response
     */
    public static function tooManyRequests(string $message = 'Too Many Requests'): Response
    {
        return self::error($message, self::TOO_MANY_REQUESTS);
    }
    
    /**
     * 用户相关错误响应
     *
     * @param string $message 错误消息
     * @param int $subCode 子错误码
     * @return Response
     */
    public static function userError(string $message, int $subCode = 1): Response
    {
        return self::error($message, self::USER_ERROR + $subCode);
    }
    
    /**
     * 支付相关错误响应
     *
     * @param string $message 错误消息
     * @param int $subCode 子错误码
     * @return Response
     */
    public static function paymentError(string $message, int $subCode = 1): Response
    {
        return self::error($message, self::PAYMENT_ERROR + $subCode);
    }
    
    /**
     * 红包相关错误响应
     *
     * @param string $message 错误消息
     * @param int $subCode 子错误码
     * @return Response
     */
    public static function redpacketError(string $message, int $subCode = 1): Response
    {
        return self::error($message, self::REDPACKET_ERROR + $subCode);
    }
    
    /**
     * 消息相关错误响应
     *
     * @param string $message 错误消息
     * @param int $subCode 子错误码
     * @return Response
     */
    public static function messageError(string $message, int $subCode = 1): Response
    {
        return self::error($message, self::MESSAGE_ERROR + $subCode);
    }
    
    /**
     * 游戏相关错误响应
     *
     * @param string $message 错误消息
     * @param int $subCode 子错误码
     * @return Response
     */
    public static function gameError(string $message, int $subCode = 1): Response
    {
        return self::error($message, self::GAME_ERROR + $subCode);
    }
    
    /**
     * 业务逻辑错误响应
     *
     * @param string $message 错误消息
     * @param int $subCode 子错误码
     * @return Response
     */
    public static function businessError(string $message, int $subCode = 1): Response
    {
        return self::error($message, self::BUSINESS_ERROR + $subCode);
    }
    
    /**
     * 创建JSON响应
     *
     * @param array $data 响应数据
     * @param int $httpCode HTTP状态码
     * @return Response
     */
    private static function json(array $data, int $httpCode = 200): Response
    {
        return response($data, $httpCode)->header([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'X-Powered-By' => 'ThinkPHP'
        ]);
    }
    
    /**
     * 获取错误消息映射
     *
     * @return array
     */
    public static function getErrorMessages(): array
    {
        return [
            // 系统错误
            self::CLIENT_ERROR => '请求参数错误',
            self::UNAUTHORIZED => '未授权访问',
            self::FORBIDDEN => '禁止访问',
            self::NOT_FOUND => '资源不存在',
            self::METHOD_NOT_ALLOWED => '请求方法不允许',
            self::VALIDATION_ERROR => '数据验证失败',
            self::TOO_MANY_REQUESTS => '请求过于频繁',
            self::SERVER_ERROR => '服务器内部错误',
            self::SERVICE_UNAVAILABLE => '服务不可用',
            
            // 业务错误
            self::BUSINESS_ERROR => '业务处理失败',
            
            // 用户错误 2000-2999
            2001 => '用户不存在',
            2002 => '用户已存在',
            2003 => '用户状态异常',
            2004 => '密码错误',
            2005 => '用户被禁用',
            2006 => '用户信息不完整',
            2007 => '邀请码无效',
            2008 => '手机号已绑定',
            2009 => '提现密码错误',
            2010 => '用户余额不足',
            
            // 支付错误 3000-3999
            3001 => '支付方式不支持',
            3002 => '充值金额不合法',
            3003 => '提现金额不合法',
            3004 => '余额不足',
            3005 => '超出充值限额',
            3006 => '超出提现限额',
            3007 => '订单不存在',
            3008 => '订单状态异常',
            3009 => '支付凭证无效',
            3010 => '提现地址无效',
            3011 => '手续费不足',
            3012 => '风控检查未通过',
            
            // 红包错误 4000-4999
            4001 => '红包金额不合法',
            4002 => '红包个数不合法',
            4003 => '红包不存在',
            4004 => '红包已过期',
            4005 => '红包已被抢完',
            4006 => '不能抢自己的红包',
            4007 => '已经抢过这个红包',
            4008 => '红包功能已关闭',
            4009 => '超出每日红包限制',
            4010 => '群成员数量不足',
            
            // 消息错误 5000-5999
            5001 => '消息发送失败',
            5002 => '消息格式错误',
            5003 => '消息过长',
            5004 => '消息模板不存在',
            5005 => '接收者不存在',
            5006 => '消息被拒收',
            5007 => '群发消息失败',
            5008 => '消息已过期',
            
            // 游戏错误 6000-6999
            6001 => '游戏接口错误',
            6002 => '游戏账号创建失败',
            6003 => '余额转账失败',
            6004 => '游戏不存在',
            6005 => '游戏维护中',
            6006 => '游戏登录失败',
            6007 => '投注金额不合法',
            6008 => '游戏记录同步失败',
        ];
    }
    
    /**
     * 根据错误码获取错误消息
     *
     * @param int $code 错误码
     * @return string
     */
    public static function getErrorMessage(int $code): string
    {
        $messages = self::getErrorMessages();
        return $messages[$code] ?? '未知错误';
    }
    
    /**
     * 创建标准化的异常响应
     *
     * @param \Throwable $e 异常对象
     * @param bool $debug 是否显示调试信息
     * @return Response
     */
    public static function exception(\Throwable $e, bool $debug = false): Response
    {
        $data = [
            'code' => $e->getCode() ?: self::SERVER_ERROR,
            'message' => $e->getMessage() ?: '系统异常',
            'data' => null,
            'timestamp' => time()
        ];
        
        if ($debug) {
            $data['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        }
        
        return self::json($data, 200);
    }
}