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
     * 处理全体会员广告私发 - 主入口方法（支持实时显示）
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
            // 输出开始信息
            echo "\n🚀 开始全体会员广告私发处理...\n";
            echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
            echo str_repeat("=", 60) . "\n";
            
            // 强制输出缓冲
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            Log::info("开始处理全体会员广告私发");
            
            // 1. 获取待私发的广告
            echo "🔍 正在查找待私发广告...\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            $advertisements = $this->getPendingAdvertisements();
            $result['summary']['ads_processed'] = count($advertisements);
            
            if (empty($advertisements)) {
                echo "ℹ️  当前没有需要私发的广告\n";
                Log::info("当前没有需要私发的广告");
                return $result;
            }
            
            echo "✅ 发现 " . count($advertisements) . " 条待私发广告\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            Log::info("发现 " . count($advertisements) . " 条待私发广告");
            
            // 2. 获取全体活跃会员
            echo "👥 正在获取活跃会员列表...\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            $members = $this->getAllActiveMembers();
            $result['summary']['total_members'] = count($members);
            
            if (empty($members)) {
                echo "⚠️  没有找到活跃会员，跳过私发\n";
                Log::warning("没有找到活跃会员，跳过私发");
                $result['errors'][] = "没有找到活跃会员";
                return $result;
            }
            
            echo "✅ 找到 " . count($members) . " 个活跃会员\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            Log::info("找到 " . count($members) . " 个活跃会员");
            
            // 3. 逐个处理广告
            echo "\n📢 开始逐个处理广告...\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            foreach ($advertisements as $adIndex => $ad) {
                $adNumber = $adIndex + 1;
                $totalAds = count($advertisements);
                
                echo "\n🎯 [{$adNumber}/{$totalAds}] 处理广告ID: {$ad->id}\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                try {
                    $adResult = $this->processAdvertisement($ad, $members);
                    $result['advertisements'][] = $adResult;
                    
                    // 累计统计
                    $result['summary']['total_messages'] += $adResult['total_sent'];
                    $result['summary']['success_count'] += $adResult['success_count'];
                    $result['summary']['failed_count'] += $adResult['failed_count'];
                    
                    echo "✅ 广告ID {$ad->id} 处理完成\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    
                    Log::info("广告ID {$ad->id} 处理完成", [
                        'total_sent' => $adResult['total_sent'],
                        'success' => $adResult['success_count'],
                        'failed' => $adResult['failed_count']
                    ]);
                    
                } catch (\Exception $e) {
                    $error = "广告ID {$ad->id} 处理失败: " . $e->getMessage();
                    $result['errors'][] = $error;
                    echo "❌ {$error}\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    Log::error($error, ['exception' => $e]);
                }
            }
            
            // 输出最终汇总
            $overallSuccessRate = $result['summary']['total_messages'] > 0 ? 
                round(($result['summary']['success_count'] / $result['summary']['total_messages']) * 100, 2) : 0;
                
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "🏁 全体会员广告私发处理完成!\n";
            echo "📊 总体统计:\n";
            echo "   处理广告数: {$result['summary']['ads_processed']}\n";
            echo "   目标会员数: {$result['summary']['total_members']}\n";
            echo "   总发送消息: {$result['summary']['total_messages']}\n";
            echo "   发送成功: {$result['summary']['success_count']}\n";
            echo "   发送失败: {$result['summary']['failed_count']}\n";
            echo "   总体成功率: {$overallSuccessRate}%\n";
            echo "   完成时间: " . date('Y-m-d H:i:s') . "\n";
            echo str_repeat("=", 60) . "\n";
            
            // 强制输出缓冲
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            Log::info("全体会员广告私发处理完成", [
                'total_ads' => $result['summary']['ads_processed'],
                'total_members' => $result['summary']['total_members'],
                'total_messages' => $result['summary']['total_messages'],
                'success_rate' => $overallSuccessRate
            ]);
            
        } catch (\Exception $e) {
            $error = "全体会员广告私发处理异常: " . $e->getMessage();
            $result['errors'][] = $error;
            echo "💥 处理异常: {$error}\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
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
        
        Log::info("查询私发广告条件", [
            'current_time' => $currentTime,
            'current_date' => $currentDate,
            'current_time_only' => $currentTimeOnly
        ]);
        
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
        
        Log::info("查询到符合基础条件的广告数量: " . count($pendingAds));
        
        // 手动过滤需要发送的广告，避免复杂SQL查询
        $filteredAds = [];
        foreach ($pendingAds as $ad) {
            $shouldSend = false;
            
            Log::info("检查广告ID {$ad->id} 发送条件", [
                'send_mode' => $ad->send_mode,
                'last_member_sent_time' => $ad->last_member_sent_time,
                'send_time' => $ad->send_time,
                'daily_times' => $ad->daily_times,
                'interval_minutes' => $ad->interval_minutes
            ]);
            
            // 模式1：一次性定时发送
            if ($ad->send_mode == 1) {
                // 私发使用独立的判断：检查是否已经私发过
                $memberSent = !empty($ad->last_member_sent_time);
                if (!$memberSent && $ad->send_time <= $currentTime) {
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
                // 启动时发送：如果从未私发过或今天未私发过
                if (empty($ad->last_member_sent_time) || date('Y-m-d', strtotime($ad->last_member_sent_time)) < $currentDate) {
                    $shouldSend = true;
                    Log::info("广告ID {$ad->id} - 模式2：启动时首次私发");
                }
            }
            // 模式3：循环间隔发送
            elseif ($ad->send_mode == 3) {
                if (empty($ad->last_member_sent_time)) {
                    $shouldSend = true;
                    Log::info("广告ID {$ad->id} - 模式3：启动时首次私发");
                } elseif (!empty($ad->interval_minutes)) {
                    $lastSentTime = strtotime($ad->last_member_sent_time);
                    $minutesPassed = (time() - $lastSentTime) / 60;
                    if ($minutesPassed >= $ad->interval_minutes) {
                        $shouldSend = true;
                        Log::info("广告ID {$ad->id} - 模式3：循环间隔私发符合条件，间隔 {$minutesPassed} 分钟");
                    }
                }
            }
            
            if ($shouldSend) {
                $filteredAds[] = $ad;
                Log::info("广告ID {$ad->id} 加入私发队列");
            } else {
                Log::info("广告ID {$ad->id} 不满足私发条件，跳过");
            }
        }
        
        Log::info("最终确定需要私发的广告数量: " . count($filteredAds));
        return $filteredAds;
    }
    
    /**
     * 获取全体活跃会员
     */
    private function getAllActiveMembers(): array
    {
        try {
            $members = User::where('status', 1)                         // 正常状态
                ->where('tg_id', '>', 0)                         // 有tg_id
                ->whereNotNull('tg_id')                          // tg_id不为空
                ->field('id,tg_id,tg_username,user_name')            // 只查询必要字段
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
     * 处理单个广告的私发 - 支持实时显示发送结果
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
        $isStartupSend = empty($advertisement->last_member_sent_time);
        
        if ($isStartupSend) {
            Log::info("广告ID {$advertisement->id} - 启动时首次私发");
        }
        
        // 输出开始信息
        echo "\n=== 开始处理广告ID: {$advertisement->id} ===\n";
        echo "广告标题: {$advertisement->title}\n";
        echo "目标用户数: " . count($members) . "\n";
        echo "开始时间: {$currentTime}\n";
        echo str_repeat("-", 50) . "\n";
        
        // 强制输出缓冲
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        // 分批处理会员，避免内存问题
        $memberBatches = array_chunk($members, $this->batchSize);
        $totalBatches = count($memberBatches);
        
        foreach ($memberBatches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            echo "\n📦 处理第 {$batchNumber}/{$totalBatches} 批，本批用户数: " . count($batch) . "\n";
            
            // 强制输出缓冲
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            
            foreach ($batch as $memberIndex => $member) {
                $memberNumber = ($batchIndex * $this->batchSize) + $memberIndex + 1;
                $totalMembers = count($members);
                
                try {
                    // 显示当前发送状态
                    echo "[{$memberNumber}/{$totalMembers}] 发送给用户 {$member['tg_id']} ";
                    
                    // 强制输出缓冲，让用户看到正在发送
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    
                    // 发送私聊消息
                    $sendResult = $this->sendToMember($member, $advertisement);
                    
                    $result['total_sent']++;
                    
                    if ($sendResult['success']) {
                        $result['success_count']++;
                        echo "✅ 成功\n";
                        Log::debug("用户 {$member['tg_id']} 发送成功");
                    } else {
                        $result['failed_count']++;
                        $result['errors'][] = "用户 {$member['tg_id']}: " . $sendResult['message'];
                        echo "❌ 失败: " . $sendResult['message'] . "\n";
                        Log::warning("用户 {$member['tg_id']} 发送失败: " . $sendResult['message']);
                    }
                    
                    // 显示当前统计
                    if ($memberNumber % 10 == 0 || $memberNumber == $totalMembers) {
                        $successRate = $result['total_sent'] > 0 ? round(($result['success_count'] / $result['total_sent']) * 100, 1) : 0;
                        echo "📊 当前进度: {$result['success_count']} 成功, {$result['failed_count']} 失败, 成功率: {$successRate}%\n";
                    }
                    
                } catch (\Exception $e) {
                    // 捕获任何异常，确保不影响其他用户
                    $result['total_sent']++;
                    $result['failed_count']++;
                    $error = "用户 {$member['tg_id']} 发送异常: " . $e->getMessage();
                    $result['errors'][] = $error;
                    echo "💥 异常: " . $e->getMessage() . "\n";
                    
                    Log::error($error, [
                        'member_id' => $member['id'] ?? 'unknown',
                        'tg_id' => $member['tg_id'] ?? 'unknown',
                        'exception' => $e
                    ]);
                }
                
                // 强制输出缓冲，确保实时显示
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                // 无论成功失败都要控制发送频率，防止触发限制
                try {
                    sleep($this->sendInterval);
                } catch (\Exception $e) {
                    // 即使sleep出错也不影响下一个用户
                    Log::warning("sleep 调用异常: " . $e->getMessage());
                }
            }
            
            // 批次完成提示
            echo "✅ 第 {$batchNumber} 批处理完成\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        
        // 输出最终统计
        $finalSuccessRate = $result['total_sent'] > 0 ? round(($result['success_count'] / $result['total_sent']) * 100, 2) : 0;
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "🎯 广告ID {$advertisement->id} 发送完成!\n";
        echo "📈 最终统计:\n";
        echo "   总发送: {$result['total_sent']}\n";
        echo "   成功: {$result['success_count']}\n"; 
        echo "   失败: {$result['failed_count']}\n";
        echo "   成功率: {$finalSuccessRate}%\n";
        echo "   完成时间: " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat("=", 50) . "\n";
        
        // 强制输出缓冲
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
        
        // 更新广告统计和状态
        $this->updateAdvertisementStatus($advertisement, $result, $currentTime);
        
        return $result;
    }
    
    /**
     * 发送消息给单个会员 - 无重试版本，直接返回结果
     */
    private function sendToMember(array $member, $advertisement): array
    {
        // 验证会员数据
        if (empty($member['tg_id']) || $member['tg_id'] <= 0) {
            return [
                'success' => false,
                'message' => '用户 tg_id 无效'
            ];
        }
        
        try {
            $telegramId = (int)$member['tg_id'];
            
            // 准备消息数据
            $messageData = $advertisement->toArray();
            $messageData['member_info'] = $member;
            
            // 发送私聊消息 - 只尝试一次
            $result = $this->telegramService->sendToUser(
                $telegramId,
                'advertisement_notify',
                $messageData,
                'advertisement_allmember',
                $advertisement->id
            );
            
            // 发送成功
            if ($result['success'] ?? false) {
                return [
                    'success' => true,
                    'message' => '发送成功'
                ];
            } else {
                // 发送失败，直接返回失败原因
                $errorMessage = $result['message'] ?? '发送失败';
                
                // 针对常见错误给出简化说明
                if (strpos($errorMessage, '403') !== false || strpos($errorMessage, 'Forbidden') !== false) {
                    $errorMessage = '用户已拒收';
                } elseif (strpos($errorMessage, '400') !== false || strpos($errorMessage, 'Bad Request') !== false) {
                    $errorMessage = '请求错误';
                } elseif (strpos($errorMessage, 'blocked') !== false) {
                    $errorMessage = '用户已屏蔽';
                } elseif (strpos($errorMessage, 'not found') !== false) {
                    $errorMessage = '用户不存在';
                }
                
                return [
                    'success' => false,
                    'message' => $errorMessage
                ];
            }
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // 简化异常消息
            if (strpos($errorMessage, 'cURL') !== false) {
                $errorMessage = '网络连接失败';
            } elseif (strpos($errorMessage, 'timeout') !== false) {
                $errorMessage = '请求超时';
            }
            
            Log::error("用户 {$member['tg_id']} 发送异常", [
                'member_id' => $member['id'] ?? 'unknown',
                'tg_id' => $member['tg_id'],
                'error' => $errorMessage,
                'exception' => $e
            ]);
            
            return [
                'success' => false,
                'message' => $errorMessage
            ];
        }
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