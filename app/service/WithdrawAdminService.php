<?php
// 文件位置: app/service/WithdrawAdminService.php
// 提现管理服务 - 处理管理员提现审核和管理功能

declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\WithdrawOrder;
use app\model\MoneyLog;
use think\facade\Db;
use think\facade\Log;
use think\exception\ValidateException;

class WithdrawAdminService
{
    // 提现状态常量
    const STATUS_PENDING = 0;    // 待审核
    const STATUS_SUCCESS = 1;    // 成功
    const STATUS_FAILED = 2;     // 失败
    const STATUS_CANCELLED = 3;  // 已取消
    
    private TelegramService $telegramService;
    private TelegramBroadcastService $telegramBroadcastService;
    
    public function __construct()
    {
        $this->telegramService = new TelegramService();
        $this->telegramBroadcastService = new TelegramBroadcastService();
    }
    
    // =================== 1. 提现审核功能 ===================
    
    /**
     * 审核提现订单 - 移除所有通知
     */
    public function processWithdraw(string $orderNo, int $status, string $remark = ''): array
    {
        try {
            Db::startTrans();
            
            // 查找订单
            $order = WithdrawOrder::where('order_number', $orderNo)->find();
            if (!$order) {
                throw new ValidateException('提现订单不存在');
            }
            
            if ($order->status != self::STATUS_PENDING) {
                throw new ValidateException('订单状态异常');
            }
            
            // 获取用户信息
            $user = User::find($order->user_id);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }
            
            if ($status == self::STATUS_SUCCESS) {
                // 提现成功
                $order->save([
                    'status' => self::STATUS_SUCCESS,
                    'success_time' => date('Y-m-d H:i:s'),
                    'msg' => $remark
                ]);
                
                // 🔥 删除所有通知相关代码
                /*
                // 发送个人提现成功通知
                $this->telegramService->sendWithdrawSuccessNotify($order->user_id, [...]);
                
                // 提现成功群组广播
                $this->telegramBroadcastService->broadcastWithdrawSuccess([...]);
                */
                
                $logMsg = "提现审核通过: {$orderNo}";
                
            } else {
                // 提现失败，退还余额
                $order->save([
                    'status' => self::STATUS_FAILED,
                    'msg' => $remark
                ]);
                
                // 退还用户余额
                $refundAmount = $order->money + $order->money_fee;
                $oldBalance = $user->money_balance;
                $newBalance = $oldBalance + $refundAmount;
                
                $user->save(['money_balance' => $newBalance]);
                
                // 记录资金流水
                $this->createMoneyLog($order->user_id, 1, 401, $oldBalance, $newBalance, $refundAmount, $order->id, "提现失败退款 - 订单号{$orderNo}");
                
                // 🔥 删除所有通知相关代码
                /*
                // 发送提现失败通知
                $this->telegramService->sendWithdrawFailedNotify($order->user_id, [...]);
                */
                
                $logMsg = "提现审核拒绝: {$orderNo}，已退还余额";
            }
            
            Db::commit();
            
            // 记录日志
            Log::info($logMsg, [
                'order_no' => $orderNo,
                'user_id' => $order->user_id,
                'status' => $status,
                'remark' => $remark
            ]);
            
            return [
                'code' => 200,
                'msg' => '审核完成',
                'data' => []
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('审核提现订单失败: ' . $e->getMessage());
            throw $e;
        }
    }
    
    // =================== 私有方法 ===================
    
    /**
     * 创建资金流水记录
     */
    private function createMoneyLog(int $userId, int $type, int $status, float $moneyBefore, float $moneyEnd, float $money, int $sourceId, string $mark): void
    {
        MoneyLog::create([
            'uid' => $userId,
            'type' => $type,
            'status' => $status,
            'money_before' => $moneyBefore,
            'money_end' => $moneyEnd,
            'money' => $money,
            'source_id' => $sourceId,
            'mark' => $mark,
            'create_time' => date('Y-m-d H:i:s')
        ]);
    }
}