<?php
// 文件位置: app/service/UserService.php
// 用户服务 - 精简版，专门为 Telegram 前端服务

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\UserLog;
use app\model\UserInvitation;
use think\facade\Log;
use think\facade\Db;
use think\exception\ValidateException;

/**
 * 用户服务 - Telegram 前端专用
 * 只处理 Telegram 相关的用户功能和邀请码逻辑
 */
class UserService
{
    // =================== 核心 Telegram 用户处理 ===================
    
    /**
     * 查找或创建用户（最小化创建策略） - 支持邀请码
     * 这是统一的用户处理入口，供 CommandDispatcher 调用
     * 
     * @param array $telegramData Telegram用户数据
     * @param string $inviteCode 邀请码（可选，默认为空）
     * @return User
     */
    public function findOrCreateUser(array $telegramData, string $inviteCode = ''): User
    {
        try {
            $tgUserId = (string)($telegramData['id'] ?? '');
            
            if (empty($tgUserId)) {
                throw new ValidateException('Telegram用户ID不能为空');
            }
            
            Log::info('开始处理用户', [
                'tg_id' => $tgUserId,
                'invite_code' => $inviteCode ?: '无邀请码',
                'has_invitation' => !empty($inviteCode)
            ]);
            
            // 1. 先查找用户（无论是否有邀请码都要检查）
            $user = User::where('tg_id', $tgUserId)->find();
            
            if ($user) {
                // 2. 用户已存在，同步最新信息（不创建新用户）
                $this->syncTelegramUserInfo($tgUserId, $telegramData);
                
                // 更新最后活动时间
                $user->save(['last_activity_at' => date('Y-m-d H:i:s')]);
                
                Log::info('找到现有Telegram用户，跳过创建', [
                    'user_id' => $user->id,
                    'tg_id' => $tgUserId,
                    'user_name' => $user->user_name,
                    'invite_code' => $inviteCode ?: '无邀请码',
                    'action' => '用户已存在，仅同步信息'
                ]);
                
                return $user;
            }
            
            // 3. 用户不存在，执行最小化创建（根据是否有邀请码决定是否处理邀请关系）
            Log::info('用户不存在，开始创建新用户', [
                'tg_id' => $tgUserId,
                'invite_code' => $inviteCode ?: '无邀请码',
                'will_process_invitation' => !empty($inviteCode)
            ]);
            
            return $this->createTelegramUserMinimal($telegramData, $inviteCode);
            
        } catch (\Exception $e) {
            Log::error('查找或创建用户失败: ' . $e->getMessage(), [
                'telegram_data' => json_encode($telegramData),
                'invite_code' => $inviteCode
            ]);
            throw $e;
        }
    }
    
    /**
     * 最小化创建Telegram用户 - 支持邀请码处理 + 修正的邀请码逻辑
     * 
     * @param array $telegramData Telegram用户数据
     * @param string $inviteCode 邀请码（可选）
     * @return User
     */
    private function createTelegramUserMinimal(array $telegramData, string $inviteCode = ''): User
    {
        try {
            Db::startTrans();
            
            $tgUserId = (string)$telegramData['id'];
            
            // 生成用户自己的邀请码
            $userInvitationCode = $this->generateInvitationCode();
            
            // 最小化创建数据
            $userData = [
                // Telegram 核心字段
                'tg_id' => $tgUserId,
                'tg_username' => $telegramData['username'] ?? '',
                'tg_first_name' => $telegramData['first_name'] ?? '',
                'tg_last_name' => $telegramData['last_name'] ?? '',
                'language_code' => $telegramData['language_code'] ?? 'zh',
                
                // 系统必要字段  
                'user_name' => $this->generateGameUsername($tgUserId),
                'invitation_code' => $userInvitationCode, // 🆕 用户自己的邀请码
                'auto_created' => 1,
                'status' => 1,  // 正常状态
                'type' => 2,    // 2=会员
                'money_balance' => 0.00,
                'registration_step' => 1,
                
                // 时间字段
                'telegram_bind_time' => date('Y-m-d H:i:s'),
                'create_time' => date('Y-m-d H:i:s'),
                'last_activity_at' => date('Y-m-d H:i:s'),
            ];
            
            $user = User::create($userData);
            
            // 🔧 修正1：处理邀请码关系（新的逻辑）
            if (!empty($inviteCode)) {
                $this->processInvitationCode($user->id, $inviteCode, $tgUserId);
            } else {
                Log::info('无邀请码，跳过邀请关系处理', [
                    'user_id' => $user->id,
                    'tg_id' => $tgUserId
                ]);
            }
            
            // 记录用户创建日志
            $this->logUserAction($user->id, 'telegram_auto_create', 
                sprintf('Telegram自动创建用户: %s (%s) | 使用邀请码: %s | 生成邀请码: %s', 
                    $telegramData['username'] ?? '无用户名', 
                    $tgUserId,
                    !empty($inviteCode) ? $inviteCode : '无',
                    $userInvitationCode
                )
            );
            
            Db::commit();
            
            Log::info('Telegram用户最小化创建成功', [
                'user_id' => $user->id,
                'tg_id' => $tgUserId,
                'user_name' => $user->user_name,
                'used_invite_code' => $inviteCode ?: '无邀请码',
                'has_invitation_relationship' => !empty($inviteCode),
                'user_invitation_code' => $userInvitationCode,
                'created_fields' => array_keys($userData)
            ]);
            
            return $user;
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('Telegram用户最小化创建失败: ' . $e->getMessage(), [
                'tg_user_id' => $tgUserId ?? 'unknown',
                'invite_code' => $inviteCode,
                'error_detail' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    /**
     * 🆕 生成邀请码（确保唯一性）
     */
    private function generateInvitationCode(): string
    {
        do {
            // 格式: USER + 5位数字 + INVITE
            $code = 'USER' . str_pad((string)mt_rand(1, 99999), 5, '0', STR_PAD_LEFT) . 'INVITE';
        } while (User::where('invitation_code', $code)->find());
        
        return $code;
    }
    
    // =================== 邀请码处理逻辑（🔧 核心修正） ===================
    
    /**
     * 🔧 修正2：完全重写邀请码处理逻辑
     * 
     * @param int $newUserId 新用户ID
     * @param string $inviteCode 邀请码
     * @param string $tgUserId TG用户ID
     */
    private function processInvitationCode(int $newUserId, string $inviteCode, string $tgUserId): void
    {
        try {
            if (empty($inviteCode)) {
                Log::info('邀请码为空，跳过邀请关系处理', [
                    'new_user_id' => $newUserId,
                    'tg_user_id' => $tgUserId
                ]);
                return;
            }
            
            Log::info('开始处理邀请码关系', [
                'new_user_id' => $newUserId,
                'invite_code' => $inviteCode,
                'tg_user_id' => $tgUserId
            ]);
            
            // 🔧 修正：直接从User表查找邀请人
            $inviter = User::where('invitation_code', $inviteCode)
                          ->where('status', 1) // 确保邀请人状态正常
                          ->find();
            
            if (!$inviter) {
                Log::warning('邀请码对应的邀请人不存在或状态异常', [
                    'invite_code' => $inviteCode,
                    'new_user_id' => $newUserId,
                    'tg_user_id' => $tgUserId
                ]);
                return;
            }
            
            // 🔧 修正：防止自己邀请自己
            if ($inviter->id === $newUserId) {
                Log::warning('用户尝试使用自己的邀请码', [
                    'invite_code' => $inviteCode,
                    'user_id' => $newUserId
                ]);
                return;
            }
            
            // 🔧 修正：检查是否已经建立过邀请关系
            $existingInvitation = UserInvitation::where('inviter_id', $inviter->id)
                                              ->where('invitee_id', $newUserId)
                                              ->find();
            
            if ($existingInvitation) {
                Log::warning('邀请关系已存在', [
                    'inviter_id' => $inviter->id,
                    'invitee_id' => $newUserId,
                    'existing_invitation_id' => $existingInvitation->id
                ]);
                return;
            }
            
            // 🔧 修正：创建新的邀请关系记录
            $invitationData = [
                'inviter_id' => $inviter->id,
                'invitee_id' => $newUserId,
                'invitation_code' => $inviteCode,
                'invitee_tg_id' => $tgUserId,
                'reward_amount' => 0.00, // 待设置奖励金额
                'reward_status' => UserInvitation::REWARD_PENDING,
                'first_deposit_amount' => 0.00,
                'completed_at' => date('Y-m-d H:i:s'), // 立即标记为完成
            ];
            
            $invitation = UserInvitation::create($invitationData);
            
            if ($invitation) {
                Log::info('邀请关系建立成功', [
                    'invitation_id' => $invitation->id,
                    'invite_code' => $inviteCode,
                    'inviter_id' => $inviter->id,
                    'inviter_name' => $inviter->user_name,
                    'invitee_id' => $newUserId,
                    'tg_user_id' => $tgUserId
                ]);
                
                // 记录邀请人的日志
                $this->logUserAction($inviter->id, 'invite_success', 
                    sprintf('成功邀请新用户 (ID: %d, TG: %s, 用户名: %s)', 
                        $newUserId, 
                        $tgUserId,
                        User::find($newUserId)->user_name ?? '未知'
                    )
                );
                
                // 记录被邀请人的日志
                $this->logUserAction($newUserId, 'invited_by', 
                    sprintf('通过邀请码 %s 被用户 %s (ID: %d) 邀请', 
                        $inviteCode, 
                        $inviter->user_name,
                        $inviter->id
                    )
                );
                
            } else {
                Log::error('邀请关系创建失败', [
                    'inviter_id' => $inviter->id,
                    'invitee_id' => $newUserId,
                    'invite_code' => $inviteCode
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('处理邀请码异常: ' . $e->getMessage(), [
                'invite_code' => $inviteCode,
                'new_user_id' => $newUserId,
                'tg_user_id' => $tgUserId,
                'error' => $e->getTraceAsString()
            ]);
            
            // 邀请码处理失败不影响用户创建，只记录错误
        }
    }
    
    /**
     * 🔧 修正3：重写邀请码验证逻辑
     * 
     * @param string $inviteCode 邀请码
     * @return array ['valid' => bool, 'message' => string, 'inviter_info' => array|null]
     */
    public function validateInviteCode(string $inviteCode): array
    {
        try {
            if (empty($inviteCode)) {
                return [
                    'valid' => false,
                    'message' => '邀请码不能为空',
                    'inviter_info' => null
                ];
            }
            
            // 🔧 修正：直接从User表查找邀请人
            $inviter = User::where('invitation_code', $inviteCode)
                          ->where('status', 1)
                          ->find();
            
            if (!$inviter) {
                return [
                    'valid' => false,
                    'message' => '邀请码不存在或邀请人状态异常',
                    'inviter_info' => null
                ];
            }
            
            return [
                'valid' => true,
                'message' => '邀请码有效',
                'inviter_info' => [
                    'user_id' => $inviter->id,
                    'user_name' => $inviter->user_name,
                    'tg_username' => $inviter->tg_username,
                    'display_name' => $inviter->getFullNameAttr('', $inviter->toArray()),
                    'invitation_code' => $inviter->invitation_code
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('验证邀请码失败: ' . $e->getMessage(), [
                'invite_code' => $inviteCode
            ]);
            
            return [
                'valid' => false,
                'message' => '验证失败，请稍后重试',
                'inviter_info' => null
            ];
        }
    }
    
    // =================== 基础 Telegram 功能 ===================
    
    /**
     * 根据Telegram ID获取用户（供其他服务调用）
     */
    public function getUserByTgId(string $tgUserId): ?User
    {
        return User::where('tg_id', $tgUserId)->find();
    }
    
    /**
     * 同步Telegram用户信息
     */
    public function syncTelegramUserInfo(string $tgUserId, array $telegramData): array
    {
        try {
            $user = User::where('tg_id', $tgUserId)->find();
            if (!$user) {
                return ['code' => 404, 'msg' => '用户不存在'];
            }
            
            // 准备更新数据
            $updateData = [];
            $fieldsMap = [
                'username' => 'tg_username',
                'first_name' => 'tg_first_name', 
                'last_name' => 'tg_last_name',
                'language_code' => 'language_code'
            ];
            
            foreach ($fieldsMap as $telegramField => $userField) {
                if (isset($telegramData[$telegramField]) && 
                    $telegramData[$telegramField] !== $user->$userField) {
                    $updateData[$userField] = $telegramData[$telegramField];
                }
            }
            
            // 更新最后活动时间
            $updateData['last_activity_at'] = date('Y-m-d H:i:s');
            
            if (!empty($updateData)) {
                $user->save($updateData);
                
                Log::info('Telegram用户信息已同步', [
                    'user_id' => $user->id,
                    'tg_id' => $tgUserId,
                    'updated_fields' => array_keys($updateData)
                ]);
            }
            
            return [
                'code' => 200,
                'msg' => '用户信息同步成功',
                'data' => [
                    'user_id' => $user->id,
                    'updated' => !empty($updateData),
                    'updated_fields' => array_keys($updateData)
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('同步Telegram用户信息失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 更新用户活跃时间
     */
    public function updateUserActivity(int $userId): void
    {
        try {
            $user = User::find($userId);
            if ($user) {
                $user->save(['last_activity_at' => date('Y-m-d H:i:s')]);
            }
        } catch (\Exception $e) {
            Log::error('更新用户活跃时间失败: ' . $e->getMessage());
        }
    }
    
    // =================== 私有辅助方法 ===================
    
    /**
     * 生成游戏用户名（确保唯一性）
     */
    private function generateGameUsername(string $tgUserId): string
    {
        // 格式: TG + 后6位TG_ID + 时间戳后4位
        $suffix = substr($tgUserId, -6);
        $timestamp = substr((string)time(), -4);
        $username = "TG{$suffix}{$timestamp}";
        
        // 确保唯一性
        $counter = 1;
        $originalUsername = $username;
        while (User::where('user_name', $username)->find()) {
            $username = $originalUsername . $counter;
            $counter++;
            if ($counter > 999) { // 防止无限循环
                $username = $originalUsername . mt_rand(1000, 9999);
                break;
            }
        }
        
        return $username;
    }
    
    /**
     * 记录用户操作日志（安全版本，检查 UserLog 类是否存在）
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
}