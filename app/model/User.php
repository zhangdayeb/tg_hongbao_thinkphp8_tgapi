<?php
declare(strict_types=1);

namespace app\model;

use app\common\SecurityHelper;
use app\common\CacheHelper;
use app\common\ValidatorHelper;
use think\Model;


/**
 * 用户模型 - 包含游戏ID功能的完整版本
 */
class User extends Model
{
    /**
     * 数据表名
     */
    protected $name = 'common_user';
    
    /**
     * 隐藏字段
     */
    protected $hidden = ['pwd', 'withdraw_pwd', 'delete_time'];
    
    /**
     * 类型转换 - 包含游戏ID字段
     */
    protected $type = [
        'id' => 'integer',
        'money_balance' => 'float',
        'money_freeze' => 'float',
        'money_total_recharge' => 'float',
        'money_total_withdraw' => 'float',
        'rebate_balance' => 'float',
        'rebate_total' => 'float',
        'type' => 'integer',
        'status' => 'integer',
        'state' => 'integer',
        'is_real_name' => 'integer',
        'is_fictitious' => 'integer',
        'market_level' => 'integer',
        'login_count' => 'integer',
        // Telegram 相关字段类型转换
        'tg_first_name' => 'string',
        'tg_last_name' => 'string',
        'tg_username' => 'string',
        'language_code' => 'string',
        'withdraw_password_set' => 'integer',
        'auto_created' => 'integer',
        'registration_step' => 'integer',
        // 游戏ID字段
        'game_id' => 'string',
    ];
    
    /**
     * 只读字段
     */
    protected $readonly = ['id', 'create_time'];
    
    /**
     * 用户类型常量
     */
    public const TYPE_MEMBER = 0;         // 普通会员
    public const TYPE_AGENT = 1;          // 代理
    
    /**
     * 用户状态常量
     */
    public const STATUS_DISABLED = 0;     // 禁用/冻结
    public const STATUS_NORMAL = 1;       // 正常
    
    /**
     * 在线状态常量
     */
    public const STATE_OFFLINE = 0;       // 离线
    public const STATE_ONLINE = 1;        // 在线
    
    /**
     * 实名状态常量
     */
    public const REAL_NAME_NO = 0;        // 未实名
    public const REAL_NAME_YES = 1;       // 已实名
    
    /**
     * 虚拟账号类型常量
     */
    public const FICTITIOUS_NORMAL = 0;   // 正常账号
    public const FICTITIOUS_VIRTUAL = 1;  // 虚拟账号
    public const FICTITIOUS_TRIAL = 2;    // 试玩账号
    
    /**
     * 密码修改器
     */
    public function setPwdAttr($value)
    {
        return $value ? base64_encode($value) : '';
    }
    
    /**
     * 提现密码修改器
     */
    public function setWithdrawPwdAttr($value)
    {
        return $value ? base64_encode($value) : '';
    }
    
    /**
     * 密码获取器（仅用于验证，不返回实际密码）
     */
    public function getPwdAttr($value)
    {
        return $value ? base64_decode($value) : '';
    }
    
    /**
     * 提现密码获取器
     */
    public function getWithdrawPwdAttr($value)
    {
        return $value ? base64_decode($value) : '';
    }
    
    /**
     * 用户名修改器
     */
    public function setUserNameAttr($value)
    {
        return trim($value);
    }
    
    /**
     * Telegram用户名修改器
     */
    public function setTgUsernameAttr($value)
    {
        return $value ? ltrim(trim($value), '@') : '';
    }
    
    /**
     * Telegram 名字修改器
     */
    public function setTgFirstNameAttr($value)
    {
        return trim($value);
    }
    
    /**
     * Telegram 姓氏修改器
     */
    public function setTgLastNameAttr($value)
    {
        return trim($value);
    }
    
    /**
     * 游戏ID修改器 - 清理和验证
     */
    public function setGameIdAttr($value)
    {
        // 如果为空，返回空字符串
        if (empty($value)) {
            return '';
        }
        
        // 清理游戏ID：移除前后空格
        $gameId = trim($value);
        
        // 基础验证（这里只做格式清理，具体验证在 Service 层）
        if (preg_match('/^[a-zA-Z0-9_]{3,20}$/', $gameId)) {
            return $gameId;
        }
        
        // 如果格式不正确，返回空字符串
        return '';
    }
    
    /**
     * 修复：最后活动时间修改器
     */
    public function setLastActivityAtAttr($value)
    {
        // 如果是时间戳，转换为 datetime 格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果已经是正确格式，直接返回
        if (is_string($value) && strlen($value) === 19) {
            return $value;
        }
        
        // 默认返回当前时间
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 修复：Telegram绑定时间修改器
     */
    public function setTelegramBindTimeAttr($value)
    {
        // 如果是时间戳，转换为 datetime 格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果已经是正确格式，直接返回
        if (is_string($value) && strlen($value) === 19) {
            return $value;
        }
        
        // 默认返回当前时间
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 修复：创建时间修改器
     */
    public function setCreateTimeAttr($value)
    {
        // 如果是时间戳，转换为 datetime 格式
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', $value);
        }
        
        // 如果已经是正确格式，直接返回
        if (is_string($value) && strlen($value) === 19) {
            return $value;
        }
        
        // 默认返回当前时间
        return date('Y-m-d H:i:s');
    }
    
    /**
     * 金额字段修改器
     */
    public function setMoneyBalanceAttr($value)
    {
        return round((float)$value, 2);
    }
    
    public function setMoneyFreezeAttr($value)
    {
        return round((float)$value, 2);
    }
    
    public function setMoneyTotalRechargeAttr($value)
    {
        return round((float)$value, 2);
    }
    
    public function setMoneyTotalWithdrawAttr($value)
    {
        return round((float)$value, 2);
    }
    
    public function setRebateBalanceAttr($value)
    {
        return round((float)$value, 2);
    }
    
    public function setRebateTotalAttr($value)
    {
        return round((float)$value, 2);
    }
    
    /**
     * 游戏ID获取器
     */
    public function getGameIdAttr($value)
    {
        return $value ?: '';
    }
    
    /**
     * 游戏ID状态获取器
     */
    public function getGameIdStatusAttr($value, $data)
    {
        $gameId = $data['game_id'] ?? '';
        return empty($gameId) ? '未设置' : '已设置';
    }
    
    /**
     * 游戏ID状态图标获取器
     */
    public function getGameIdIconAttr($value, $data)
    {
        $gameId = $data['game_id'] ?? '';
        return empty($gameId) ? '❌' : '✅';
    }
    
    /**
     * 用户类型获取器
     */
    public function getTypeTextAttr($value, $data)
    {
        $types = [
            self::TYPE_MEMBER => '会员',
            self::TYPE_AGENT => '代理',
        ];
        return $types[$data['type']] ?? '未知';
    }
    
    /**
     * 状态获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $statuses = [
            self::STATUS_DISABLED => '冻结',
            self::STATUS_NORMAL => '正常',
        ];
        return $statuses[$data['status']] ?? '未知';
    }
    
    /**
     * 在线状态获取器
     */
    public function getStateTextAttr($value, $data)
    {
        $states = [
            self::STATE_OFFLINE => '离线',
            self::STATE_ONLINE => '在线',
        ];
        return $states[$data['state']] ?? '未知';
    }
    
    /**
     * 实名状态获取器
     */
    public function getIsRealNameTextAttr($value, $data)
    {
        $realNames = [
            self::REAL_NAME_NO => '未实名',
            self::REAL_NAME_YES => '已实名',
        ];
        return $realNames[$data['is_real_name']] ?? '未知';
    }
    
    /**
     * 虚拟账号类型获取器
     */
    public function getIsFictitiousTextAttr($value, $data)
    {
        $fictitious = [
            self::FICTITIOUS_NORMAL => '正常账号',
            self::FICTITIOUS_VIRTUAL => '虚拟账号',
            self::FICTITIOUS_TRIAL => '试玩账号',
        ];
        return $fictitious[$data['is_fictitious']] ?? '未知';
    }
    
    /**
     * 最后活动时间获取器
     */
    public function getLastActivityAtTextAttr($value, $data)
    {
        $lastActivity = $data['last_activity_at'] ?? '';
        if (empty($lastActivity)) {
            return '从未活动';
        }
        
        // 如果是时间戳，转换为日期时间
        if (is_numeric($lastActivity)) {
            return date('Y-m-d H:i:s', $lastActivity);
        }
        
        return $lastActivity;
    }
    
    /**
     * 注册时间格式化获取器
     */
    public function getRegisterTimeAttr($value, $data)
    {
        $createTime = $data['create_time'] ?? '';
        if (empty($createTime)) {
            return '';
        }
        
        if (is_numeric($createTime)) {
            return date('Y-m-d', $createTime);
        } else {
            return date('Y-m-d', strtotime($createTime));
        }
    }
    
    /**
     * 手机号掩码获取器 - 修改为 138....9001 格式
     */
    public function getPhoneMaskedAttr($value, $data)
    {
        $phone = $data['phone'] ?? '';
        if (empty($phone)) {
            return '未绑定';
        }
        
        // 确保手机号长度足够（一般是11位）
        if (strlen($phone) >= 11) {
            $prefix = substr($phone, 0, 3);    // 前3位: 138
            $suffix = substr($phone, -4);      // 后4位: 9001
            return "{$prefix}....{$suffix}";   // 使用4个点号隐藏中间部分
        }
        
        // 如果手机号长度不够11位，但大于等于7位
        if (strlen($phone) >= 7) {
            $prefix = substr($phone, 0, 3);
            $suffix = substr($phone, -4);
            return "{$prefix}....{$suffix}";
        }
        
        // 如果手机号太短，直接返回（可能是特殊号码）
        return $phone;
    }
    
    /**
     * 邮箱掩码获取器
     */
    public function getEmailMaskedAttr($value, $data)
    {
        $email = $data['email'] ?? '';
        if (empty($email)) {
            return '';
        }
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }
        return substr($parts[0], 0, 2) . '***@' . $parts[1];
    }
    
    /**
     * Telegram 全名获取器
     */
    public function getFullNameAttr($value, $data)
    {
        $firstName = trim($data['tg_first_name'] ?? '');
        $lastName = trim($data['tg_last_name'] ?? '');
        
        if (empty($firstName) && empty($lastName)) {
            return $data['user_name'] ?? 'Unknown';
        }
        
        return trim($firstName . ' ' . $lastName);
    }
    
    /**
     * 姓名掩码获取器（用于个人中心）
     */
    public function getNameMaskedAttr($value, $data)
    {
        $fullName = $this->getFullNameAttr($value, $data);
        
        if (empty($fullName) || $fullName === 'Unknown') {
            return "X···············";
        }
        
        $length = mb_strlen($fullName);
        if ($length <= 1) {
            return $fullName . "···············";
        }
        
        // 保留第一个字符，其余用15个中点
        return mb_substr($fullName, 0, 1) . str_repeat('·', 15);
    }
    
    /**
     * 格式化余额获取器（用于个人中心）
     */
    public function getFormattedBalanceAttr($value, $data)
    {
        $balance = $data['money_balance'] ?? 0;
        return number_format((float)$balance, 2);
    }
    
    /**
     * 可用余额获取器
     */
    public function getAvailableBalanceAttr($value, $data)
    {
        $balance = $data['money_balance'] ?? 0;
        $freeze = $data['money_freeze'] ?? 0;
        return round($balance - $freeze, 2);
    }
    
    /**
     * 总资产获取器
     */
    public function getTotalAssetsAttr($value, $data)
    {
        $balance = $data['money_balance'] ?? 0;
        $rebate = $data['rebate_balance'] ?? 0;
        return round($balance + $rebate, 2);
    }
    
    /**
     * 验证密码
     */
    public function verifyPassword(string $password): bool
    {
        return $this->pwd === $password;
    }
    
    /**
     * 验证提现密码
     */
    public function verifyWithdrawPassword(string $password): bool
    {
        return $this->withdraw_pwd === $password;
    }
    
    /**
     * 是否可以提现
     */
    public function canWithdraw(): bool
    {
        return $this->status === self::STATUS_NORMAL && 
               !empty($this->withdraw_pwd) && 
               $this->money_balance > 0;
    }
    
    /**
     * 是否为代理
     */
    public function isAgent(): bool
    {
        return $this->type === self::TYPE_AGENT;
    }
    
    /**
     * 是否已实名
     */
    public function isRealName(): bool
    {
        return $this->is_real_name === self::REAL_NAME_YES;
    }
    
    /**
     * 是否为虚拟账号
     */
    public function isVirtual(): bool
    {
        return $this->is_fictitious !== self::FICTITIOUS_NORMAL;
    }
    
    /**
     * 是否为新 Telegram 用户
     */
    public function isNewTelegramUser(): bool
    {
        return empty($this->tg_id) || $this->auto_created === 1;
    }
    
    /**
     * 是否已设置游戏ID
     */
    public function hasGameId(): bool
    {
        return !empty($this->game_id);
    }
    
    /**
     * 是否可以设置游戏ID
     */
    public function canSetGameId(): bool
    {
        return $this->status === self::STATUS_NORMAL;
    }
    
    /**
     * 是否可以发红包 - 新增方法
     */
    public function canSendRedPacket(): bool
    {
        return $this->status === self::STATUS_NORMAL && 
               $this->money_balance > 0;
    }
    
    /**
     * 设置游戏ID
     */
    public function setGameId(string $gameId): bool
    {
        // 基础验证
        if (!$this->canSetGameId()) {
            return false;
        }
        
        // 验证游戏ID格式
        if (!$this->validateGameIdFormat($gameId)) {
            return false;
        }
        
        return $this->save([
            'game_id' => $gameId,
            'last_activity_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * 清除游戏ID
     */
    public function clearGameId(): bool
    {
        return $this->save([
            'game_id' => '',
            'last_activity_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * 验证游戏ID格式
     */
    private function validateGameIdFormat(string $gameId): bool
    {
        // 长度检查：3-20个字符
        if (strlen($gameId) < 3 || strlen($gameId) > 20) {
            return false;
        }
        
        // 格式检查：只允许字母、数字和下划线
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $gameId)) {
            return false;
        }
        
        // 不能全是数字
        if (is_numeric($gameId)) {
            return false;
        }
        
        // 不能以下划线开头或结尾
        if (str_starts_with($gameId, '_') || str_ends_with($gameId, '_')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 修复：更新最后活动时间
     */
    public function updateLastActivity(): bool
    {
        return $this->save([
            'last_activity_at' => date('Y-m-d H:i:s'),  // 直接使用字符串格式
            'state' => self::STATE_ONLINE
        ]);
    }
    
    /**
     * 更新 Telegram 信息
     */
    public function updateTelegramInfo(array $telegramData): bool
    {
        $updateData = [];
        
        if (isset($telegramData['first_name'])) {
            $updateData['tg_first_name'] = $telegramData['first_name'];
        }
        
        if (isset($telegramData['last_name'])) {
            $updateData['tg_last_name'] = $telegramData['last_name'];
        }
        
        if (isset($telegramData['username'])) {
            $updateData['tg_username'] = $telegramData['username'];
        }
        
        if (isset($telegramData['language_code'])) {
            $updateData['language_code'] = $telegramData['language_code'];
        }
        
        if (!empty($updateData)) {
            $updateData['last_activity_at'] = date('Y-m-d H:i:s');  // 直接使用字符串格式
            return $this->save($updateData);
        }
        
        return true;
    }
    
    /**
     * 增加余额
     */
    public function addBalance(float $amount, string $remark = ''): bool
    {
        if ($amount <= 0) {
            return false;
        }
        
        return $this->save([
            'money_balance' => $this->money_balance + $amount
        ]);
    }
    
    /**
     * 减少余额
     */
    public function subBalance(float $amount, string $remark = ''): bool
    {
        if ($amount <= 0 || $this->money_balance < $amount) {
            return false;
        }
        
        return $this->save([
            'money_balance' => $this->money_balance - $amount
        ]);
    }
    
    /**
     * 发红包扣款 - 新增方法
     */
    public function sendRedPacket(float $amount, string $packetId): bool
    {
        if ($amount <= 0 || $this->money_balance < $amount) {
            return false;
        }
        
        $beforeBalance = $this->money_balance;
        
        // 扣除余额
        $result = $this->save([
            'money_balance' => $this->money_balance - $amount
        ]);
        
        if ($result) {
            // 记录发红包流水
            MoneyLog::addRedPacketSendLog($this->id, $amount, $packetId, $beforeBalance);
        }
        
        return $result;
    }
    
    /**
     * 收红包加款 - 新增方法
     */
    public function receiveRedPacket(float $amount, string $packetId): bool
    {
        if ($amount <= 0) {
            return false;
        }
        
        $beforeBalance = $this->money_balance;
        
        // 增加余额
        $result = $this->save([
            'money_balance' => $this->money_balance + $amount
        ]);
        
        if ($result) {
            // 记录收红包流水
            MoneyLog::addRedPacketReceiveLog($this->id, $amount, $packetId, $beforeBalance);
        }
        
        return $result;
    }
    
    /**
     * 冻结余额
     */
    public function freezeBalance(float $amount): bool
    {
        if ($amount <= 0 || $this->money_balance < $amount) {
            return false;
        }
        
        return $this->save([
            'money_balance' => $this->money_balance - $amount,
            'money_freeze' => $this->money_freeze + $amount
        ]);
    }
    
    /**
     * 解冻余额
     */
    public function unfreezeBalance(float $amount): bool
    {
        if ($amount <= 0 || $this->money_freeze < $amount) {
            return false;
        }
        
        return $this->save([
            'money_balance' => $this->money_balance + $amount,
            'money_freeze' => $this->money_freeze - $amount
        ]);
    }
    
    /**
     * 获取游戏相关信息
     */
    public function getGameInfo(): array
    {
        return [
            'user_id' => $this->id,
            'user_name' => $this->user_name,
            'game_id' => $this->game_id ?: '',
            'has_game_id' => $this->hasGameId(),
            'can_set_game_id' => $this->canSetGameId(),
            'game_id_status' => $this->game_id_status,
            'game_id_icon' => $this->game_id_icon,
        ];
    }
    
    /**
     * 获取红包统计 - 新增方法
     */
    public function getRedPacketStats(): array
    {
        $redpacketStats = MoneyLog::getRedPacketStats($this->id);
        
        return [
            'send_count' => $redpacketStats['send_count'],
            'send_amount' => $redpacketStats['send_amount'],
            'receive_count' => $redpacketStats['receive_count'],
            'receive_amount' => $redpacketStats['receive_amount'],
            'net_amount' => $redpacketStats['net_amount'],
        ];
    }
    
    /**
     * 获取用户统计信息（增强版 - 包含游戏ID信息）
     */
    public function getStats(): array
    {
        return [
            'total_recharge' => $this->money_total_recharge,
            'total_withdraw' => $this->money_total_withdraw,
            'total_rebate' => $this->rebate_total,
            'login_count' => $this->login_count,
            'available_balance' => $this->available_balance,
            'total_assets' => $this->total_assets,
            // 新增游戏ID相关统计
            'has_game_id' => $this->hasGameId(),
            'game_id' => $this->game_id ?: '',
            'can_login_game' => $this->hasGameId() && $this->status === self::STATUS_NORMAL,
            // 新增红包相关统计
            'can_send_redpacket' => $this->canSendRedPacket(),
        ];
    }
    
    /**
     * 根据 Telegram ID 获取个人信息（增强版 - 包含游戏ID）
     */
    public static function getProfileByTgId(string $tgId): ?User
    {
        return self::where('tg_id', $tgId)
                   ->field('id,tg_id,tg_first_name,tg_last_name,tg_username,user_name,money_balance,phone,create_time,status,withdraw_password_set,auto_created,last_activity_at,game_id')  // 添加 game_id 字段
                   ->find();
    }
    
    /**
     * 根据游戏ID查找用户（静态方法）
     */
    public static function findByGameId(string $gameId): ?User
    {
        if (empty($gameId)) {
            return null;
        }
        
        return self::where('game_id', $gameId)->find();
    }
    
    /**
     * 检查游戏ID是否已存在（静态方法）
     */
    public static function gameIdExists(string $gameId, ?int $excludeUserId = null): bool
    {
        if (empty($gameId)) {
            return false;
        }
        
        $query = self::where('game_id', $gameId);
        
        if ($excludeUserId) {
            $query->where('id', '<>', $excludeUserId);
        }
        
        return $query->count() > 0;
    }
    
    /**
     * 获取游戏ID使用统计（静态方法）
     */
    public static function getGameIdStats(): array
    {
        $total = self::count();
        $withGameId = self::where('game_id', '<>', '')->count();
        $withoutGameId = $total - $withGameId;
        
        return [
            'total_users' => $total,
            'with_game_id' => $withGameId,
            'without_game_id' => $withoutGameId,
            'game_id_rate' => $total > 0 ? round(($withGameId / $total) * 100, 2) : 0
        ];
    }
    
    /**
     * 创建 Telegram 用户（静态方法，用于兼容）
     */
    public static function createTelegramUser(string $tgId, array $telegramData = []): ?User
    {
        $userData = [
            'tg_id' => $tgId,
            'tg_first_name' => $telegramData['first_name'] ?? '',
            'tg_last_name' => $telegramData['last_name'] ?? '',
            'tg_username' => $telegramData['username'] ?? '',
            'language_code' => $telegramData['language_code'] ?? 'zh',
            'auto_created' => 1,
            'status' => self::STATUS_NORMAL,
            'type' => self::TYPE_MEMBER,
            'money_balance' => 0.00,
            'registration_step' => 0,
            'game_id' => '',  // 初始化游戏ID为空
            'telegram_bind_time' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];
        
        // 生成唯一用户名
        $userData['user_name'] = 'TG' . substr($tgId, -6) . substr((string)time(), -4);
        
        return self::create($userData);
    }
    
    /**
     * 绑定 Telegram 账号
     */
    public function bindTelegram(string $tgId, array $telegramData = []): bool
    {
        $bindData = [
            'tg_id' => $tgId,
            'telegram_bind_time' => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ];
        
        if (!empty($telegramData)) {
            if (isset($telegramData['first_name'])) {
                $bindData['tg_first_name'] = $telegramData['first_name'];
            }
            if (isset($telegramData['last_name'])) {
                $bindData['tg_last_name'] = $telegramData['last_name'];
            }
            if (isset($telegramData['username'])) {
                $bindData['tg_username'] = $telegramData['username'];
            }
            if (isset($telegramData['language_code'])) {
                $bindData['language_code'] = $telegramData['language_code'];
            }
        }
        
        return $this->save($bindData);
    }
    
    /**
     * 关联发送的红包 - 新增关联方法
     */
    public function sentRedPackets()
    {
        return $this->hasMany(RedPacket::class, 'sender_id');
    }
    
    /**
     * 关联抢红包记录 - 新增关联方法
     */
    public function redPacketRecords()
    {
        return $this->hasMany(RedPacketRecord::class, 'user_id');
    }
}