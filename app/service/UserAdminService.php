<?php
// 文件位置: app/service/UserAdminService.php
// 后台管理和API服务 - 从 UserService 拆分出来

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\UserLog;
use think\facade\Cache;
use think\facade\Log;
use think\facade\Db;
use think\exception\ValidateException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * 用户后台管理服务
 * 专门为后台管理和API接口提供服务
 */
class UserAdminService
{
    // JWT 配置
    private const JWT_SECRET = 'your_jwt_secret_key';
    private const JWT_EXPIRE = 86400 * 7; // 7天
    
    private UserService $userService;
    
    public function __construct()
    {
        $this->userService = new UserService();
    }
    
    // =================== 1. 基础用户功能（API相关） ===================
    
    /**
     * 用户注册
     */
    public function register(array $data): array
    {
        try {
            // 数据验证
            $this->validateRegisterData($data);
            
            // 检查用户是否已存在
            if ($this->checkUserExists($data['username'], $data['email'])) {
                throw new ValidateException('用户名或邮箱已存在');
            }
            
            // 开启事务
            Db::startTrans();
            
            // 创建用户
            $userData = [
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => $this->hashPassword($data['password']),
                'telegram_id' => $data['telegram_id'] ?? null,
                'telegram_username' => $data['telegram_username'] ?? null,
                'balance' => 0.00,
                'status' => 1, // 激活状态
                'register_time' => time(),
                'register_ip' => request()->ip(),
            ];
            
            $user = User::create($userData);
            
            // 记录注册日志
            $this->logUserAction($user->id, 'register', '用户注册');
            
            Db::commit();
            
            // 生成token
            $token = $this->generateToken($user);
            
            return [
                'code' => 200,
                'msg' => '注册成功',
                'data' => [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'token' => $token,
                    'expire_time' => time() + self::JWT_EXPIRE
                ]
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('用户注册失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 用户登录
     */
    public function login(string $username, string $password): array
    {
        try {
            // 查找用户
            $user = User::where('username', $username)
                       ->whereOr('email', $username)
                       ->find();
            
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            // 检查用户状态
            if ($user->status != 1) {
                throw new ValidateException('账户已被禁用');
            }
            
            // 验证密码
            if (!$this->verifyPassword($password, $user->password)) {
                // 记录登录失败日志
                $this->logUserAction($user->id, 'login_failed', '密码错误');
                throw new ValidateException('密码错误');
            }
            
            // 更新最后登录信息
            $user->save([
                'last_login_time' => time(),
                'last_login_ip' => request()->ip(),
                'last_activity_at' => date('Y-m-d H:i:s')
            ]);
            
            // 生成token
            $token = $this->generateToken($user);
            
            // 缓存用户信息
            $this->cacheUserInfo($user);
            
            // 记录登录成功日志
            $this->logUserAction($user->id, 'login', '用户登录');
            
            return [
                'code' => 200,
                'msg' => '登录成功',
                'data' => [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'balance' => $user->balance,
                    'token' => $token,
                    'expire_time' => time() + self::JWT_EXPIRE
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('用户登录失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 验证Token
     */
    public function verifyToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key(self::JWT_SECRET, 'HS256'));
            $userId = $decoded->user_id;
            
            // 从缓存获取用户信息
            $userInfo = $this->getUserFromCache($userId);
            if (!$userInfo) {
                // 缓存不存在，从数据库获取
                $user = User::find($userId);
                if (!$user || $user->status != 1) {
                    throw new ValidateException('用户不存在或已被禁用');
                }
                $userInfo = $user->toArray();
                $this->cacheUserInfo($user);
            }
            
            return [
                'code' => 200,
                'msg' => 'Token有效',
                'data' => $userInfo
            ];
            
        } catch (\Exception $e) {
            throw new ValidateException('Token无效或已过期');
        }
    }
    
    /**
     * 获取用户信息
     */
    public function getUserInfo(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) {
            throw new ValidateException('用户不存在');
        }
        
        return [
            'code' => 200,
            'msg' => '获取成功',
            'data' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'telegram_id' => $user->telegram_id,
                'telegram_username' => $user->telegram_username,
                'telegram_bound' => !empty($user->telegram_id),
                'balance' => $user->balance,
                'status' => $user->status,
                'register_time' => $user->register_time,
                'last_login_time' => $user->last_login_time,
                'last_activity_at' => $user->last_activity_at
            ]
        ];
    }
    
    /**
     * 更新用户余额
     */
    public function updateBalance(int $userId, float $amount, string $type = 'add', string $remark = ''): array
    {
        try {
            Db::startTrans();
            
            $user = User::find($userId);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            $oldBalance = $user->balance;
            
            if ($type === 'add') {
                $newBalance = $oldBalance + $amount;
            } elseif ($type === 'sub') {
                if ($oldBalance < $amount) {
                    throw new ValidateException('余额不足');
                }
                $newBalance = $oldBalance - $amount;
            } else {
                throw new ValidateException('操作类型错误');
            }
            
            // 更新余额
            $user->save(['balance' => $newBalance]);
            
            // 记录余额变动日志
            $this->logBalanceChange($userId, $type, $amount, $oldBalance, $newBalance, $remark);
            
            // 清除缓存
            $this->clearUserCache($userId);
            
            Db::commit();
            
            return [
                'code' => 200,
                'msg' => '余额更新成功',
                'data' => [
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                    'change_amount' => $amount
                ]
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('更新用户余额失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 2. 兼容性方法（保持向后兼容） ===================
    
    /**
     * 通过Telegram ID获取用户（兼容性方法）
     */
    public function getUserByTelegramId(int $telegramId): ?User
    {
        return User::where('telegram_id', $telegramId)->find();
    }
    
    /**
     * 创建Telegram用户（兼容性方法，调用核心服务）
     */
    public function createTelegramUser(int $telegramId, string $telegramUsername = '', string $firstName = '', string $lastName = ''): array
    {
        try {
            // 检查是否已存在
            $existUser = $this->getUserByTelegramId($telegramId);
            if ($existUser) {
                return [
                    'code' => 200,
                    'msg' => '用户已存在',
                    'data' => [
                        'user_id' => $existUser->id,
                        'username' => $existUser->user_name,
                        'is_new' => false
                    ]
                ];
            }
            
            // 构造 telegram 数据
            $telegramData = [
                'id' => $telegramId,
                'username' => $telegramUsername,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'language_code' => 'zh'
            ];
            
            // 调用核心服务创建
            $user = $this->userService->findOrCreateUser($telegramData);
            
            return [
                'code' => 200,
                'msg' => '账号创建成功',
                'data' => [
                    'user_id' => $user->id,
                    'username' => $user->user_name,
                    'display_name' => $user->getFullNameAttr('', $user->toArray()),
                    'is_new' => true
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('创建Telegram用户失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 3. 游戏ID相关方法 ===================
    
    /**
     * 根据游戏ID查找用户
     */
    public function getUserByGameId(string $gameId): ?User
    {
        try {
            if (empty($gameId)) {
                return null;
            }
            
            $user = User::where('game_id', $gameId)->find();
            
            if ($user) {
                Log::info('根据游戏ID找到用户', [
                    'game_id' => $gameId,
                    'user_id' => $user->id,
                    'user_name' => $user->user_name
                ]);
            }
            
            return $user;
            
        } catch (\Exception $e) {
            Log::error('根据游戏ID查找用户失败: ' . $e->getMessage(), [
                'game_id' => $gameId
            ]);
            return null;
        }
    }
    
    /**
     * 更新用户的游戏ID
     */
    public function updateUserGameId(int $userId, string $gameId): bool
    {
        try {
            Db::startTrans();
            
            // 验证用户是否存在
            $user = User::find($userId);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            // 验证游戏ID格式
            if (!$this->validateGameIdFormat($gameId)) {
                throw new ValidateException('游戏ID格式不正确');
            }
            
            // 检查游戏ID是否已被其他用户使用
            $existingUser = $this->getUserByGameId($gameId);
            if ($existingUser && $existingUser->id !== $userId) {
                throw new ValidateException('该游戏ID已被其他用户使用');
            }
            
            $oldGameId = $user->game_id;
            
            // 更新游戏ID
            $result = $user->save(['game_id' => $gameId]);
            
            if ($result) {
                // 清除用户缓存
                $this->clearUserCache($userId);
                
                // 记录操作日志
                $this->logUserAction($userId, 'update_game_id', sprintf(
                    '更新游戏ID: %s -> %s',
                    $oldGameId ?: '未设置',
                    $gameId
                ));
                
                Db::commit();
                
                Log::info('用户游戏ID更新成功', [
                    'user_id' => $userId,
                    'old_game_id' => $oldGameId,
                    'new_game_id' => $gameId
                ]);
                
                return true;
            }
            
            throw new \Exception('保存失败');
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('更新用户游戏ID失败: ' . $e->getMessage(), [
                'user_id' => $userId,
                'game_id' => $gameId
            ]);
            return false;
        }
    }
    
    /**
     * 检查游戏ID是否可用
     */
    public function checkGameIdAvailability(string $gameId, ?int $excludeUserId = null): array
    {
        try {
            // 格式验证
            if (!$this->validateGameIdFormat($gameId)) {
                return [
                    'available' => false,
                    'message' => '游戏ID格式不正确：只能包含字母、数字和下划线，长度1-20字符'
                ];
            }
            
            // 检查是否已被使用
            $existingUser = $this->getUserByGameId($gameId);
            if ($existingUser && (!$excludeUserId || $existingUser->id !== $excludeUserId)) {
                return [
                    'available' => false,
                    'message' => '该游戏ID已被其他用户使用'
                ];
            }
            
            return [
                'available' => true,
                'message' => '游戏ID可用'
            ];
            
        } catch (\Exception $e) {
            Log::error('检查游戏ID可用性失败: ' . $e->getMessage(), [
                'game_id' => $gameId,
                'exclude_user_id' => $excludeUserId
            ]);
            
            return [
                'available' => false,
                'message' => '检查失败，请稍后重试'
            ];
        }
    }
    
    /**
     * 获取用户的游戏绑定信息
     */
    public function getUserGameInfo(int $userId): ?array
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return null;
            }
            
            return [
                'user_id' => $user->id,
                'user_name' => $user->user_name,
                'game_id' => $user->game_id ?? '',
                'game_id_set' => !empty($user->game_id),
                'game_id_set_time' => $user->game_id_update_time ?? null,
                'can_login_game' => !empty($user->game_id) && $user->status == 1
            ];
            
        } catch (\Exception $e) {
            Log::error('获取用户游戏信息失败: ' . $e->getMessage(), [
                'user_id' => $userId
            ]);
            return null;
        }
    }
    
    /**
     * 批量获取用户游戏ID信息（用于管理后台）
     */
    public function batchGetUserGameIds(array $userIds): array
    {
        try {
            if (empty($userIds)) {
                return [];
            }
            
            $users = User::whereIn('id', $userIds)
                         ->field('id,user_name,game_id')
                         ->select();
            
            $result = [];
            foreach ($users as $user) {
                $result[$user->id] = [
                    'user_id' => $user->id,
                    'user_name' => $user->user_name,
                    'game_id' => $user->game_id ?? '',
                    'has_game_id' => !empty($user->game_id)
                ];
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('批量获取用户游戏ID失败: ' . $e->getMessage(), [
                'user_ids' => $userIds
            ]);
            return [];
        }
    }
    
    /**
     * 清除用户的游戏ID（用于管理员操作）
     */
    public function clearUserGameId(int $userId, string $reason = ''): bool
    {
        try {
            Db::startTrans();
            
            $user = User::find($userId);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            $oldGameId = $user->game_id;
            
            // 清除游戏ID
            $result = $user->save(['game_id' => '']);
            
            if ($result) {
                // 清除用户缓存
                $this->clearUserCache($userId);
                
                // 记录操作日志
                $this->logUserAction($userId, 'clear_game_id', sprintf(
                    '清除游戏ID: %s, 原因: %s',
                    $oldGameId ?: '无',
                    $reason ?: '无'
                ));
                
                Db::commit();
                
                Log::info('用户游戏ID清除成功', [
                    'user_id' => $userId,
                    'old_game_id' => $oldGameId,
                    'reason' => $reason
                ]);
                
                return true;
            }
            
            throw new \Exception('保存失败');
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('清除用户游戏ID失败: ' . $e->getMessage(), [
                'user_id' => $userId,
                'reason' => $reason
            ]);
            return false;
        }
    }
    
    /**
     * 生成建议的游戏ID（基于用户名）
     */
    public function generateSuggestedGameIds(string $userName): array
    {
        try {
            $suggestions = [];
            $baseId = preg_replace('/[^a-zA-Z0-9]/', '', $userName); // 移除特殊字符
            
            if (strlen($baseId) >= 3) {
                // 基于用户名的建议
                $suggestions[] = $baseId;
                $suggestions[] = $baseId . '888';
                $suggestions[] = $baseId . '666';
                $suggestions[] = 'game_' . $baseId;
                $suggestions[] = $baseId . '_player';
            }
            
            // 通用建议
            $suggestions[] = 'player_' . rand(1000, 9999);
            $suggestions[] = 'game_' . rand(100, 999);
            $suggestions[] = 'user_' . rand(1000, 9999);
            
            // 过滤掉已存在的游戏ID
            $availableSuggestions = [];
            foreach ($suggestions as $suggestion) {
                if ($this->checkGameIdAvailability($suggestion)['available']) {
                    $availableSuggestions[] = $suggestion;
                }
                
                // 最多返回5个建议
                if (count($availableSuggestions) >= 5) {
                    break;
                }
            }
            
            return $availableSuggestions;
            
        } catch (\Exception $e) {
            Log::error('生成建议游戏ID失败: ' . $e->getMessage(), [
                'user_name' => $userName
            ]);
            return ['player_' . rand(1000, 9999)]; // 返回一个随机建议
        }
    }
    
    // =================== 4. 私有工具方法 ===================
    
    /**
     * 验证注册数据
     */
    private function validateRegisterData(array $data): void
    {
        if (empty($data['username']) || strlen($data['username']) < 3) {
            throw new ValidateException('用户名长度不能少于3位');
        }
        
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidateException('邮箱格式不正确');
        }
        
        if (empty($data['password']) || strlen($data['password']) < 6) {
            throw new ValidateException('密码长度不能少于6位');
        }
    }
    
    /**
     * 检查用户是否已存在
     */
    private function checkUserExists(string $username, string $email): bool
    {
        return User::where('username', $username)
                  ->whereOr('email', $email)
                  ->count() > 0;
    }
    
    /**
     * 加密密码
     */
    private function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * 验证密码
     */
    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * 生成JWT Token
     */
    private function generateToken(User $user): string
    {
        $payload = [
            'user_id' => $user->id,
            'username' => $user->username,
            'iat' => time(),
            'exp' => time() + self::JWT_EXPIRE
        ];
        
        return JWT::encode($payload, self::JWT_SECRET, 'HS256');
    }
    
    /**
     * 缓存用户信息
     */
    private function cacheUserInfo(User $user): void
    {
        $cacheKey = 'user_info_' . $user->id;
        $userData = $user->toArray();
        unset($userData['password']); // 移除密码字段
        
        Cache::set($cacheKey, $userData, 3600); // 缓存1小时
    }
    
    /**
     * 从缓存获取用户信息
     */
    private function getUserFromCache(int $userId): ?array
    {
        $cacheKey = 'user_info_' . $userId;
        return Cache::get($cacheKey);
    }
    
    /**
     * 清除用户缓存
     */
    private function clearUserCache(int $userId): void
    {
        $cacheKey = 'user_info_' . $userId;
        Cache::delete($cacheKey);
    }
    
    /**
     * 验证游戏ID格式
     */
    private function validateGameIdFormat(string $gameId): bool
    {
        // 长度检查
        if (strlen($gameId) < 1 || strlen($gameId) > 20) {
            return false;
        }
        
        // 格式检查：只允许字母、数字和下划线
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $gameId)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 记录用户操作日志
     */
    private function logUserAction(int $userId, string $action, string $description): void
    {
        try {
            if (class_exists('app\model\UserLog')) {
                UserLog::create([
                    'user_id' => $userId,
                    'action' => $action,
                    'description' => $description,
                    'ip' => request()->ip(),
                    'user_agent' => request()->header('User-Agent'),
                    'create_time' => time()
                ]);
            } else {
                // 如果 UserLog 不存在，使用日志记录
                Log::info('用户操作日志', [
                    'user_id' => $userId,
                    'action' => $action,
                    'description' => $description,
                    'ip' => request()->ip(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('记录用户操作日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 记录余额变动日志
     */
    private function logBalanceChange(int $userId, string $type, float $amount, float $oldBalance, float $newBalance, string $remark): void
    {
        $this->logUserAction($userId, 'balance_' . $type, sprintf(
            '余额变动: %s %.2f, 余额: %.2f -> %.2f, 备注: %s',
            $type === 'add' ? '+' : '-',
            $amount,
            $oldBalance,
            $newBalance,
            $remark
        ));
    }
}