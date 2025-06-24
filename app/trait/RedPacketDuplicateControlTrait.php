<?php
declare(strict_types=1);

namespace app\trait;

use think\facade\Cache;

/**
 * 红包防重复控制 Trait（约50行）
 * 职责：防止重复操作，如重复发送红包、重复抢红包等
 */
trait RedPacketDuplicateControlTrait
{
    /**
     * 检查发红包操作是否重复
     */
    protected function checkRedPacketSendDuplicate(int $userId, array $redPacketData): bool
    {
        $key = "redpacket_send_lock_{$userId}";
        $lockData = [
            'amount' => $redPacketData['amount'],
            'count' => $redPacketData['count'],
            'title' => $redPacketData['title'],
            'timestamp' => time()
        ];
        
        $existing = Cache::get($key);
        
        if ($existing && $this->isSameRedPacketData($existing, $lockData)) {
            // 同样的红包数据在短时间内不允许重复发送
            return true;
        }
        
        // 设置锁定，5秒内防止重复
        Cache::set($key, $lockData, 5);
        return false;
    }
    
    /**
     * 检查抢红包操作是否重复
     */
    protected function checkGrabRedPacketDuplicate(string $packetId, int $userId): bool
    {
        $key = "grab_redpacket_lock_{$packetId}_{$userId}";
        
        if (Cache::get($key)) {
            return true; // 重复操作
        }
        
        // 设置锁定，3秒内防止重复抢红包
        Cache::set($key, true, 3);
        return false;
    }
    
    /**
     * 清除发红包锁定
     */
    protected function clearRedPacketSendLock(int $userId): void
    {
        $key = "redpacket_send_lock_{$userId}";
        Cache::delete($key);
    }
    
    /**
     * 清除抢红包锁定
     */
    protected function clearGrabRedPacketLock(string $packetId, int $userId): void
    {
        $key = "grab_redpacket_lock_{$packetId}_{$userId}";
        Cache::delete($key);
    }
    
    /**
     * 比较红包数据是否相同
     */
    private function isSameRedPacketData(array $data1, array $data2): bool
    {
        return $data1['amount'] === $data2['amount'] &&
               $data1['count'] === $data2['count'] &&
               $data1['title'] === $data2['title'] &&
               (time() - $data1['timestamp']) < 5; // 5秒内认为是重复操作
    }
}