<?php
/**
 * Telegram 红包系统统一路由配置
 * 使用 Route::rule 格式配置所有路由
 */
use think\facade\Route;

// ====================================================================
// 基础路由
// ====================================================================
Route::rule('/$', 'Index/index');                    // 首页
Route::rule('hello/:name$', 'Index/hello');          // Hello页面

// ====================================================================
// Telegram核心路由 (最重要)
// ====================================================================
Route::rule('telegram/test$', 'TelegramController/test');           // Telegram测试
Route::rule('webhook/telegram$', 'TelegramController/webhook');     // Telegram Webhook

// ====================================================================
// 管理后台路由
// ====================================================================
// 认证相关
Route::rule('admin/login$', 'admin.Auth/login');                    // 管理员登录
Route::rule('admin/captcha$', 'admin.Auth/captcha');               // 验证码

// 仪表板
Route::rule('admin/dashboard$', 'admin.Dashboard/index');           // 管理仪表板

// 用户管理
Route::rule('admin/users$', 'admin.UserController/index');          // 用户列表
Route::rule('admin/users/:id$', 'admin.UserController/show');       // 用户详情
Route::rule('admin/users/:id/enable$', 'admin.UserController/enable');   // 启用用户
Route::rule('admin/users/:id/disable$', 'admin.UserController/disable'); // 禁用用户

// 红包管理
Route::rule('admin/redpackets$', 'admin.RedPacketController/packetList');              // 红包列表
Route::rule('admin/redpackets/system$', 'admin.RedPacketController/createSystemPacket'); // 创建系统红包
Route::rule('admin/redpackets/:id/send-telegram$', 'admin.RedPacketController/sendPacketToTelegram'); // 发送红包到Telegram

// Telegram 管理
Route::rule('admin/telegram/groups$', 'admin.TelegramController/groupList');           // 群组列表
Route::rule('admin/telegram/groups/add$', 'admin.TelegramController/addGroup');        // 添加群组
Route::rule('admin/telegram/users$', 'admin.TelegramController/telegramUserList');     // Telegram用户列表
Route::rule('admin/telegram/broadcasts$', 'admin.TelegramController/createBroadcast'); // 创建广播

// 支付管理
Route::rule('admin/payment/recharge$', 'admin.PaymentController/rechargeList');        // 充值列表
Route::rule('admin/payment/recharge/:id/confirm$', 'admin.PaymentController/confirmRecharge'); // 确认充值
Route::rule('admin/payment/withdraw$', 'admin.PaymentController/withdrawList');        // 提现列表
Route::rule('admin/payment/withdraw/:id/approve$', 'admin.PaymentController/approveWithdraw'); // 批准提现

// 统计报表
Route::rule('admin/stats/dashboard$', 'admin.StatController/dashboard');               // 统计仪表板
Route::rule('admin/stats/users$', 'admin.StatController/userStat');                   // 用户统计
Route::rule('admin/stats/revenue$', 'admin.StatController/revenueStat');              // 收入统计

// ====================================================================
// Webhook回调路由
// ====================================================================
// 支付回调
Route::rule('webhook/payment/usdt/callback$', 'webhook.Payment/usdtCallback');         // USDT支付回调
Route::rule('webhook/payment/huiwang/callback$', 'webhook.Payment/huiwangCallback');   // 汇旺支付回调
Route::rule('webhook/payment/callback/:method$', 'webhook.Payment/callback');          // 通用支付回调

// Webhook管理
Route::rule('webhook/admin/logs$', 'webhook.Admin/getLogs');                          // 获取Webhook日志
Route::rule('webhook/admin/statistics$', 'webhook.Admin/getStatistics');             // 获取Webhook统计
Route::rule('webhook/admin/retry/:id$', 'webhook.Admin/retry');                      // 重试Webhook

// ====================================================================
// 用户前端路由
// ====================================================================
// 用户认证
Route::rule('user/auth/telegram/bind$', 'user.Auth/telegramBind');                   // Telegram绑定
Route::rule('user/auth/telegram/verify$', 'user.Auth/telegramVerify');               // Telegram验证

// 用户功能
Route::rule('user/profile$', 'user.User/profile');                                   // 用户资料
Route::rule('user/redpackets$', 'user.RedPacket/index');                            // 用户红包
Route::rule('user/balance$', 'user.Finance/balance');                               // 用户余额
Route::rule('user/invite$', 'user.Invite/index');                                   // 邀请页面

// ====================================================================
// 公开页面路由
// ====================================================================
Route::rule('help$', 'Public/help');                                                // 帮助页面
Route::rule('rules$', 'Public/rules');                                              // 规则页面
Route::rule('invite/:code$', 'Public/inviteInfo');                                  // 邀请信息页面

// ====================================================================
// 权限控制模块 (示例格式)
// ====================================================================
Route::rule('action/list$', 'auth.Action/index');                                   // 权限控制列表
Route::rule('action/add$', 'auth.Action/add');                                      // 添加权限控制
Route::rule('action/edit$', 'auth.Action/edit');                                    // 编辑权限控制
Route::rule('action/del$', 'auth.Action/del');                                      // 删除权限控制
Route::rule('action/status$', 'auth.Action/status');                                // 权限状态管理

// ====================================================================
// 全局路由配置
// ====================================================================
Route::pattern([
    'id' => '\d+',
    'token' => '[a-zA-Z0-9:_-]+',
    'code' => '[A-Z0-9]{6,20}',
    'method' => '[a-z]+',
]);

// ====================================================================
// 错误处理
// ====================================================================
Route::miss(function() {
    $request = request();
    return json([
        'code' => 404, 
        'msg' => '页面不存在', 
        'path' => $request->pathinfo(),
        'method' => $request->method()
    ]);
});