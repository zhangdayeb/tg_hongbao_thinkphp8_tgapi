<?php
declare(strict_types=1);

namespace app\service;

use app\model\TgAdvertisement as Advertisement;
use app\model\User;
use think\facade\Log;

/**
 * 全体会员广告私发服务
 * 专门处理 is_all_member = 1 的广告私发业务
 */
class AdvertisementAllMemberBroadcastService
{
    private TelegramNotificationService $telegramService;
    private int $batchSize = 500;           // 每批处理用户数量
    private int $sendInterval = 1;          // 发送间隔（秒）
    private int $maxRetries = 3;            // 最大重试次数
    
    public function __construct()
    {
        $this->telegramService = new TelegramNotificationService();
    }
    
    /**
     * 处理全体会员广告私发 - 主入口方法
     */
    public function processBroadcast(): array
    {
        $result = [
            'summary' => [
                'ads_processed' => 0,
                'total_members' => 0,
                'total_messages' => 0,
                'success_count' => 0,
                'failed_count' => 0
            ],
            'advertisements' => [],
            'errors' => []
        ];
        
        try {
            Log::info("开始处理全体会员广告私发");
            
            // 1. 获取待私发的广告
            $advertisements = $this->getPendingAdvertisements();
            $result['summary']['ads_processed'] = count($advertisements);
            
            if (empty($advertisements)) {
                Log::info("当前没有需要私发的广告");
                return $result;
            }
            
            Log::info("发现 " . count($advertisements) . " 条待私发广告");
            
            // 2. 获取全体活跃会员
            $members = $this->getAllActiveMembers();
            $result['summary']['total_members'] = count($members);
            
            if (empty($members)) {
                Log::warning("没有找到活跃会员，跳过私发");
                $result['errors'][] = "没有找到活跃会员";
                return $result;
            }
            
            Log::info("找到 " . count($members) . " 个活跃会员");
            
            // 3. 逐个处理广告
            foreach ($advertisements as $ad) {
                try {
                    $adResult = $this->processAdvertisement($ad, $members);
                    $result['advertisements'][] = $adResult;
                    
                    // 累计统计
                    $result['summary']['total_messages'] += $adResult['total_sent'];
                    $result['summary']['success_count'] += $adResult['success_count'];
                    $result['summary']['failed_count'] += $adResult['failed_count'];
                    
                    Log::info("广告ID {$ad->id} 处理完成", [
                        'total_sent' => $adResult['total_sent'],
                        'success' => $adResult['success_count'],
                        'failed' => $adResult['failed_count']
                    ]);
                    
                } catch (\Exception $e) {
                    $error = "广告ID {$ad->id} 处理失败: " . $e->getMessage();
                    $result['errors'][] = $error;
                    Log::error($error, ['exception' => $e]);
                }
            }
            
            Log::info("全体会员广告私发处理完成", [
                'total_ads' => $result['summary']['ads_processed'],
                'total_members' => $result['summary']['total_members'],
                'total_messages' => $result['summary']['total_messages'],
                'success_rate' => $result['summary']['total_messages'] > 0 ? 
                    round(($result['summary']['success_count'] / $result['summary']['total_messages']) * 100, 2) : 0
            ]);
            
        } catch (\Exception $e) {
            $error = "全体会员广告私发处理异常: " . $e->getMessage();
            $result['errors'][] = $error;
            Log::error($error, ['exception' => $e]);
            throw $e;
        }
        
        return $result;
    }
    
    /**
     * 获取待私发的广告
     */
    private function getPendingAdvertisements(): array
    {
        $currentTime = date('Y-m-d H:i:s');
        $currentDate = date('Y-m-d');
        $currentTimeOnly = date('H:i');
        
        // 查询启用且is_all_member=1的广告
        $pendingAds = Advertisement::where('status', 1)
            ->where('is_all_member', 1)  // 只处理私发广告
            ->where(function($query) use ($currentDate) {
                // 检查有效期
                $query->where(function($subQuery) use ($currentDate) {
                    $subQuery->whereNull('start_date')
                            ->whereOr('start_date', '<=', $currentDate);
                })->where(function($subQuery) use ($currentDate) {
                    $subQuery->whereNull('end_date')
                            ->whereOr('end_date', '>=', $currentDate);
                });
            })
            ->order('created_at', 'asc')
            ->select();
        
        // 手动过滤需要发送的广告
        $filteredAds = [];
        foreach ($pendingAds as $ad) {
            $shouldSend = false;
            
            // 模式1：一次性定时发送
            if ($ad->send_mode == 1) {
                if ($ad->is_sent == 0 && $ad->send_time <= $currentTime) {
                    $shouldSend = true;
                    Log::info("广告ID {$ad->id} - 模式1：一次性定时私发符合条件");
                }
            }
            // 模式2：每日定时发送
            elseif ($ad->send_mode == 2) {
                if (!empty($ad->daily_times)) {
                    $dailyTimes = explode(',', $ad->daily_times);
                    if (in_array($currentTimeOnly, $dailyTimes)) {
                        $shouldSend = true;
                        Log::info("广告ID {$ad->id} - 模式2：每日定时私发符合条件");
                    }
                }
                // 启动时发送：如果从未发送过或今天未发送过
                if (empty($ad->last_sent_time) || date('Y-m-d', strtotime($ad->last_sent_time)) < $currentDate) {
                    $shouldSend = true;
                    Log::info("广告ID {$ad->id} - 模式2：启动时首次私发");
                }
            }
            // 模式3：循环间隔发送
            elseif ($ad->send_mode == 3) {
                if (empty($ad->last_sent_time)) {
                    $shouldSend = true;
                    Log::info("广告ID {$ad->id} - 模式3：启动时首次私发");
                } elseif (!empty($ad->interval_minutes)) {
                    $lastSentTime = strtotime($ad->last_sent_time);
                    $minutesPassed = (time() - $lastSentTime) / 60;
                    if ($minutesPassed >= $ad->interval_minutes) {
                        $shouldSend = true;
                        Log::info("广告ID {$ad->id} - 模式3：循环间隔私发符合条件，间隔 {$minutesPassed} 分钟");
                    }
                }
            }
            
            if ($shouldSend) {
                $filteredAds[] = $ad;
            }
        }
        
        return $filteredAds;
    }
    
    /**
     * 获取全体活跃会员
     */
    private function getAllActiveMembers(): array
    {
        try {
            $members = User::where('status', 1)                    // 正常状态
                ->where('telegram_id', '>', 0)                         // 有telegram_id
                ->whereNotNull('telegram_id')                          // telegram_id不为空
                ->field('id,telegram_id,username,nickname')            // 只查询必要字段
                ->order('id', 'asc')
                ->select()
                ->toArray();
            
            Log::info("查询到 " . count($members) . " 个活跃会员");
            return $members;
            
        } catch (\Exception $e) {
            Log::error("查询活跃会员失败: " . $e->getMessage());
            throw new \Exception("查询活跃会员失败: " . $e->getMessage());
        }
    }
    
    /**
     * 处理单个广告的私发
     */
    private function processAdvertisement($advertisement, array $members): array
    {
        $result = [
            'id' => $advertisement->id,
            'title' => $advertisement->title,
            'total_sent' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'errors' => []
        ];
        
        $currentTime = date('Y-m-d H:i:s');
        $isStartupSend = empty($advertisement->last_member_sent_time);  // 改为使用私发时间字段
        
        if ($isStartupSend) {
            Log::info("广告ID {$advertisement->id} - 启动时首次私发");
        }
        
        // 分批处理会员，避免内存问题
        $memberBatches = array_chunk($members, $this->batchSize);
        
        foreach ($memberBatches as $batchIndex => $batch) {
            Log::info("处理第 " . ($batchIndex + 1) . " 批会员，共 " . count($batch) . " 个");
            
            foreach ($batch as $member) {
                try {
                    // 发送私聊消息
                    $sendResult = $this->sendToMember($member, $advertisement);
                    
                    $result['total_sent']++;
                    
                    if ($sendResult['success']) {
                        $result['success_count']++;
                        Log::debug("用户 {$member['telegram_id']} 发送成功");
                    } else {
                        $result['failed_count']++;
                        $result['errors'][] = "用户 {$member['telegram_id']}: " . $sendResult['message'];
                        Log::warning("用户 {$member['telegram_id']} 发送失败: " . $sendResult['message']);
                    }
                    
                } catch (\Exception $e) {
                    // 捕获任何异常，确保不影响其他用户
                    $result['total_sent']++;
                    $result['failed_count']++;
                    $error = "用户 {$member['telegram_id']} 发送异常: " . $e->getMessage();
                    $result['errors'][] = $error;
                    Log::error($error, [
                        'member_id' => $member['id'] ?? 'unknown',
                        'telegram_id' => $member['telegram_id'] ?? 'unknown',
                        'exception' => $e
                    ]);
                }
                
                // 无论成功失败都要控制发送频率，防止触发限制
                try {
                    sleep($this->sendInterval);
                } catch (\Exception $e) {
                    // 即使sleep出错也不影响下一个用户
                    Log::warning("sleep 调用异常: " . $e->getMessage());
                }
            }
        }
        
        // 更新广告统计和状态
        $this->updateAdvertisementStatus($advertisement, $result, $currentTime);
        
        return $result;
    }
    
    /**
     * 发送消息给单个会员
     */
    private function sendToMember(array $member, $advertisement): array
    {
        $maxRetries = $this->maxRetries;
        $lastError = '';
        
        // 验证会员数据
        if (empty($member['telegram_id']) || $member['telegram_id'] <= 0) {
            return [
                'success' => false,
                'message' => '用户 telegram_id 无效'
            ];
        }
        
        // 重试机制：发送失败时重试，但不影响其他用户
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $telegramId = (int)$member['telegram_id'];
                
                // 准备消息数据
                $messageData = $advertisement->toArray();
                $messageData['member_info'] = $member;
                
                // 发送私聊消息
                $result = $this->telegramService->sendToUser(
                    $telegramId,
                    'advertisement_notify',
                    $messageData,
                    'advertisement_allmember',
                    $advertisement->id
                );
                
                // 发送成功，立即返回
                if ($result['success'] ?? false) {
                    if ($attempt > 1) {
                        Log::info("用户 {$telegramId} 重试第 {$attempt} 次发送成功");
                    }
                    return [
                        'success' => true,
                        'message' => '发送成功'
                    ];
                } else {
                    $lastError = $result['message'] ?? '发送失败';
                    if ($attempt < $maxRetries) {
                        Log::warning("用户 {$telegramId} 第 {$attempt} 次发送失败，准备重试: {$lastError}");
                        sleep(1); // 重试前短暂延迟
                    }
                }
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::error("用户 {$member['telegram_id']} 第 {$attempt} 次发送异常", [
                    'member_id' => $member['id'] ?? 'unknown',
                    'telegram_id' => $member['telegram_id'],
                    'attempt' => $attempt,
                    'error' => $lastError,
                    'exception' => $e
                ]);
                
                // 如果还有重试机会，短暂延迟后继续
                if ($attempt < $maxRetries) {
                    try {
                        sleep(1);
                    } catch (\Exception $sleepError) {
                        // sleep 异常也不影响重试
                        Log::warning("sleep 异常: " . $sleepError->getMessage());
                    }
                }
            }
        }
        
        // 所有重试都失败了
        Log::error("用户 {$member['telegram_id']} 发送最终失败，已重试 {$maxRetries} 次", [
            'final_error' => $lastError
        ]);
        
        return [
            'success' => false,
            'message' => "重试 {$maxRetries} 次后仍然失败: {$lastError}"
        ];
    }
    
    /**
     * 更新广告状态和统计
     */
    private function updateAdvertisementStatus($advertisement, array $result, string $currentTime): void
    {
        try {
            // 更新广告统计（保持原有逻辑）
            $advertisement->total_sent_count = ($advertisement->total_sent_count ?? 0) + 1;
            $advertisement->success_count = ($advertisement->success_count ?? 0) + $result['success_count'];
            $advertisement->failed_count = ($advertisement->failed_count ?? 0) + $result['failed_count'];
            
            // 更新私发专用时间字段
            $advertisement->last_member_sent_time = $currentTime;
            
            // 根据发送模式更新私发状态
            if ($advertisement->send_mode == 1) {
                // 一次性发送，不修改原有的 is_sent 和 status（给群发使用）
                // 私发的一次性状态通过 last_member_sent_time 来判断
                Log::info("广告ID {$advertisement->id} - 模式1：私发完成，不重复发送");
            } elseif ($advertisement->send_mode == 2) {
                // 每日定时，计算下次私发时间
                $this->calculateNextMemberDailySendTime($advertisement, $currentTime);
            } elseif ($advertisement->send_mode == 3) {
                // 循环间隔，计算下次私发时间
                $nextTime = new \DateTime($currentTime);
                $nextTime->add(new \DateInterval('PT' . $advertisement->interval_minutes . 'M'));
                $advertisement->next_member_send_time = $nextTime->format('Y-m-d H:i:s');
            }
            
            $advertisement->save();
            
            Log::info("广告私发状态更新完成", [
                'ad_id' => $advertisement->id,
                'send_mode' => $advertisement->send_mode,
                'total_sent_count' => $advertisement->total_sent_count,
                'success_count' => $advertisement->success_count,
                'failed_count' => $advertisement->failed_count,
                'last_member_sent_time' => $advertisement->last_member_sent_time
            ]);
            
        } catch (\Exception $e) {
            Log::error("更新广告私发状态失败", [
                'ad_id' => $advertisement->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 计算每日定时广告的下次私发时间
     */
    private function calculateNextMemberDailySendTime($advertisement, string $currentTime): void
    {
        if (empty($advertisement->daily_times)) {
            return;
        }
        
        $currentDateTime = new \DateTime($currentTime);
        $times = explode(',', $advertisement->daily_times);
        $currentTimeOnly = $currentDateTime->format('H:i');
        
        // 找到今天剩余的发送时间
        $remainingTimes = array_filter($times, function($time) use ($currentTimeOnly) {
            return $time > $currentTimeOnly;
        });
        
        if (!empty($remainingTimes)) {
            // 今天还有发送时间
            $nextTime = min($remainingTimes);
            $nextDateTime = new \DateTime($currentDateTime->format('Y-m-d') . ' ' . $nextTime . ':00');
        } else {
            // 今天没有了，设置为明天第一个时间
            $nextTime = min($times);
            $nextDateTime = new \DateTime($currentDateTime->format('Y-m-d') . ' ' . $nextTime . ':00');
            $nextDateTime->add(new \DateInterval('P1D'));
        }
        
        $advertisement->next_member_send_time = $nextDateTime->format('Y-m-d H:i:s');
    }
}