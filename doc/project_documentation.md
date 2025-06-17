# Telegram 客户端程序说明文档

## 📋 项目概述

这是一个基于 **ThinkPHP 8** + **PHP 8.2** + **MySQL 5.7** 开发的 Telegram 机器人客户端程序。该程序专门设计为**客户端对接程序**，已删除管理端相关代码，专注于与 Telegram 机器人群交互和数据库表变化监控通知功能。

## 🏗️ 技术栈

- **后端框架**: ThinkPHP 8.0
- **开发语言**: PHP 8.2
- **数据库**: MySQL 5.7
- **数据库时间格式**: 统一使用 datetime 格式
- **消息格式**: 与 Telegram 交互不使用 markdown 格式

## 🎯 核心功能模块

### 1. 直接与 Telegram 机器人群交互的部分

这是程序的核心交互模块，包含以下主要功能：

#### 🤖 Telegram Webhook 接收器
- **入口路径**: `/webhook/telegram`
- **控制器**: `app/controller/TelegramController.php`
- **功能**: 
  - 接收 Telegram Bot API 的 webhook 数据
  - 验证数据完整性和格式
  - 转发给命令调度器处理

#### 🎮 命令处理系统
- **调度器**: `app/controller/telegram/CommandDispatcher.php`
- **基础控制器**: `app/controller/BaseTelegramController.php`
- **支持的命令**:
  - `/start` - 用户注册/启动
  - `/help` - 帮助信息
  - `/balance` - 查询余额
  - `/recharge` - 充值功能
  - `/withdraw` - 提现功能
  - 回调按钮处理

#### 💰 财务交互功能
- **充值系统**:
  - USDT 充值（TRC20/ERC20）
  - 汇旺支付充值
  - 实时到账确认
- **提现系统**:
  - USDT 钱包提现
  - 自动审核机制
  - 手续费计算

#### 🧧 红包交互功能
- **发红包**: 群组红包创建和发送
- **抢红包**: 实时抢夺机制，防重复领取
- **红包管理**: 过期处理、退款机制

#### 👥 用户管理功能
- **用户注册**: 通过 Telegram 自动注册
- **用户状态管理**: 正常、冻结等状态
- **邀请系统**: 邀请码验证和奖励

### 2. 监控表变化的群通知系统

这是程序的自动化监控模块，实现数据库表变化的实时通知：

#### 📊 数据库监控服务
- **核心服务**: `app/service/MonitorNotificationService.php`
- **监控范围**:
  - 充值表(`recharge`)监控 - 新充值记录通知
  - 提现表(`withdraw`)监控 - 新提现记录通知  
  - 红包表(`redpacket`)监控 - 新红包记录通知
  - 广告表(`advertisement`)监控 - 新广告发布通知

#### 🔔 通知发送机制
- **通知服务**: `app/service/TelegramNotificationService.php`
- **发送策略**:
  - 全群组广播（充值、提现、广告）
  - 指定群组发送（红包通知到对应群组）
  - 消息模板渲染
  - 发送失败重试机制

#### 📝 消息模板系统
- **模板服务**: `app/service/MessageTemplateService.php`
- **模板类型**:
  - 图片模板（充值、提现、广告）
  - 带按钮模板（红包）
  - 纯文本模板（系统通知）

#### 📈 监控配置
- **检查间隔**: 可配置的定时检查机制
- **时间重叠**: 防止遗漏数据的时间重叠设置
- **缓存优化**: 使用 Redis 缓存上次检查时间

## 📁 项目结构

```
项目根目录/
├── app/                                    # 应用目录
│   ├── controller/                         # 控制器目录
│   │   ├── TelegramController.php          # Telegram Webhook接收器
│   │   ├── BaseTelegramController.php      # Telegram基础控制器
│   │   └── telegram/                       # Telegram命令处理
│   │       └── CommandDispatcher.php       # 命令调度器
│   ├── service/                            # 服务层
│   │   ├── TelegramService.php             # Telegram API服务
│   │   ├── TelegramNotificationService.php # 通知发送服务
│   │   ├── MonitorNotificationService.php  # 监控通知服务
│   │   ├── MessageTemplateService.php      # 消息模板服务
│   │   └── UserService.php                 # 用户服务
│   ├── model/                              # 数据模型
│   │   ├── User.php                        # 用户模型
│   │   ├── TgCrowdList.php                 # Telegram群组模型
│   │   ├── TgMessageLog.php                # 消息发送日志模型
│   │   ├── RedPacket.php                   # 红包模型
│   │   ├── Recharge.php                    # 充值模型
│   │   └── Withdraw.php                    # 提现模型
│   ├── middleware/                         # 中间件
│   │   ├── WebhookAuth.php                 # Webhook认证中间件
│   │   └── RateLimit.php                   # 限流中间件
│   └── common/                             # 公共类库
│       └── MonitorHelper.php               # 监控助手类
├── config/                                 # 配置文件
│   ├── telegram.php                        # Telegram配置
│   ├── database.php                        # 数据库配置
│   ├── cache.php                           # 缓存配置
│   └── monitor_config.php                  # 监控配置
├── route/                                  # 路由配置
│   └── app.php                             # 应用路由
└── .env                                    # 环境配置
```

## ⚙️ 核心数据表

### 用户相关表
- `ntp_users` - 用户基础信息表
- `ntp_user_invites` - 用户邀请关系表

### Telegram相关表
- `ntp_tg_crowd_list` - Telegram群组信息表
- `ntp_tg_message_logs` - 消息发送日志表
- `ntp_tg_broadcasts` - 广播消息表

### 业务相关表
- `ntp_recharge` - 充值记录表
- `ntp_withdraw` - 提现记录表  
- `ntp_redpackets` - 红包记录表
- `ntp_advertisements` - 广告信息表

### 时间字段统一格式
所有数据表的时间字段均使用 `datetime` 格式：
```sql
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### 数据表前缀说明
**重要备注**: ThinkPHP8 配置文件中已自动添加了 `ntp_` 表前缀，因此在数据库处理代码中可以忽略 `ntp_` 表头。

例如：
- 数据库实际表名：`ntp_users`
- 模型中使用：`User::where('id', 1)->find()` （无需写 ntp_users）
- 查询时自动添加前缀：系统会自动处理为 `SELECT * FROM ntp_users`

## 🔧 配置说明

### 环境配置 (.env)
```ini
# 应用基础配置
APP_DEBUG = false

# 数据库配置
DB_TYPE = mysql
DB_HOST = 127.0.0.1
DB_NAME = ntp_dianji
DB_USER = root
DB_PASS = your_password
DB_PORT = 3306
DB_CHARSET = utf8mb4
DB_PREFIX = ntp_

# Redis配置
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
REDIS_PASSWORD = your_redis_password

# Telegram配置
TELEGRAM_BOT_TOKEN = your_bot_token
TELEGRAM_WEBHOOK_URL = https://yourdomain.com/webhook/telegram
TELEGRAM_WEBHOOK_SECRET = your_webhook_secret
```

### Telegram 配置 (config/telegram.php)
```php
return [
    // Bot基本配置
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    'bot_username' => env('TELEGRAM_BOT_USERNAME', ''),
    'api_url' => 'https://api.telegram.org/bot',
    'timeout' => 30,
    
    // Webhook配置
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL', ''),
    'webhook_secret_token' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    
    // 业务配置
    'withdraw' => [
        'min_amount' => 10.00,
        'max_amount' => 10000.00,
    ],
    
    // 外部链接
    'links' => [
        'game_url' => env('GAME_ENTRANCE_URL', ''),
        'customer_service_url' => env('CUSTOMER_SERVICE_URL', ''),
    ],
];
```

### 监控配置 (config/monitor_config.php)
```php
return [
    'enabled' => true,
    'check_interval' => 60,     // 检查间隔（秒）
    'overlap_time' => 10,       // 时间重叠（秒）
    
    // 通知规则
    'notify_rules' => [
        'recharge' => [
            'enabled' => true,
            'template' => 'recharge_notify'
        ],
        'withdraw' => [
            'enabled' => true,
            'template' => 'withdraw_notify'
        ],
        'redpacket' => [
            'enabled' => true,
            'template' => 'redpacket_notify'
        ],
        'advertisement' => [
            'enabled' => true,
            'template' => 'advertisement_notify'
        ]
    ]
];
```

## 🚀 部署说明

### 1. 环境要求
- PHP 8.2+
- MySQL 5.7+
- Redis 6.0+
- Nginx/Apache
- SSL 证书（Telegram Webhook 需要 HTTPS）

### 2. 安装步骤

```bash
# 1. 克隆项目
git clone [项目地址]
cd [项目目录]

# 2. 安装依赖
composer install

# 3. 配置环境
cp .env.example .env
# 编辑 .env 文件配置数据库等信息

# 4. 创建数据库表
php think migrate:run

# 5. 设置目录权限
chmod -R 755 runtime/
chmod -R 755 public/

# 6. 配置 Web 服务器指向 public 目录
```

### 3. Telegram Webhook 设置
```bash
# 设置 Webhook
curl -X POST "https://api.telegram.org/bot[BOT_TOKEN]/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{
    "url":"https://yourdomain.com/webhook/telegram",
    "secret_token":"your_secret_token",
    "allowed_updates":["message","callback_query","my_chat_member"]
  }'

# 检查 Webhook 状态
curl "https://api.telegram.org/bot[BOT_TOKEN]/getWebhookInfo"
```

## 🔄 监控系统运行

### 启动监控服务
```bash
# 手动运行监控检查
php think monitor:check

# 设置定时任务（推荐）
# 在 crontab 中添加：
* * * * * cd /path/to/project && php think monitor:check >> /dev/null 2>&1
```

### 监控流程说明
1. **定时检查**: 每分钟检查一次数据库表变化
2. **数据过滤**: 根据上次检查时间过滤新记录
3. **模板渲染**: 使用对应模板渲染通知消息
4. **群组发送**: 根据通知类型发送到对应群组
5. **日志记录**: 记录发送结果和错误信息
6. **状态更新**: 更新检查时间和缓存状态

## 📊 监控指标

### 发送统计
- 总发送数量
- 成功发送数量  
- 失败发送数量
- 发送成功率

### 性能指标
- 消息发送响应时间
- 数据库查询耗时
- 内存使用情况
- 错误发生频率

## 🔍 日志系统

### 日志分类
- **Telegram 日志**: `runtime/telegram/` - Telegram 相关操作
- **监控日志**: `runtime/monitor/` - 监控系统日志
- **业务日志**: `runtime/business/` - 业务操作日志
- **错误日志**: `runtime/log/` - 系统错误日志

### 日志格式
```
[2025-06-17 10:30:45] 消息内容
[2025-06-17 10:30:45] ✅ 成功操作
[2025-06-17 10:30:45] ❌ 错误操作
[2025-06-17 10:30:45] 🔄 处理中操作
```

## 🛠️ 开发规范

### 代码规范
- 遵循 PSR-4 自动加载标准
- 使用 PHP 8.2 新特性（类型声明、枚举等）
- 统一异常处理机制
- 完善的 PHPDoc 注释

### 安全规范
- 所有用户输入必须验证
- Webhook 请求验证
- IP 白名单限制
- 敏感信息加密存储

### 数据库规范
- 统一使用 datetime 时间格式
- 表名使用 `ntp_` 前缀
- 字段命名采用下划线风格
- 必须有创建和更新时间字段

## 🚨 常见问题

### 1. Webhook 接收失败
**排查步骤**:
- 检查 SSL 证书是否有效
- 验证服务器是否可外网访问
- 检查 Webhook URL 配置
- 查看日志 `runtime/telegram/telegram_debug.log`

### 2. 监控通知不发送
**排查步骤**:
- 检查监控服务是否运行
- 验证数据库连接
- 查看监控配置是否正确
- 检查 Bot Token 是否有效

### 3. 数据库连接异常
**排查步骤**:
- 检查 MySQL 服务状态
- 验证数据库配置信息
- 确认数据库用户权限
- 检查防火墙设置

### 4. Redis 连接失败
**排查步骤**:
- 检查 Redis 服务状态
- 验证 Redis 配置信息
- 确认 Redis 密码设置
- 检查网络连接

## 📈 性能优化建议

### 数据库优化
- 为监控表的时间字段建立索引
- 定期清理过期的消息日志
- 使用数据库连接池
- 优化查询语句

### 缓存优化
- 使用 Redis 缓存频繁查询的数据
- 缓存 Telegram 群组信息
- 缓存消息模板内容
- 设置合理的缓存过期时间

### 系统优化
- 使用队列处理批量消息发送
- 实现消息发送失败重试机制
- 监控系统资源使用情况
- 定时清理临时文件和日志

## 📞 技术支持

### 错误报告
提交问题时请包含以下信息：
- 错误日志内容
- 系统环境信息（PHP/MySQL/Redis 版本）
- 问题复现步骤
- 预期行为描述

### 联系方式
- **技术文档**: 参考本文档
- **日志查看**: 检查 `runtime/` 目录下的相关日志
- **配置检查**: 验证 `.env` 和 `config/` 目录下的配置文件

---

**项目版本**: v2.0.0  
**适用环境**: ThinkPHP 8 + PHP 8.2 + MySQL 5.7  
**更新时间**: 2025-06-17  
**维护状态**: 客户端版本，专注 Telegram 交互和监控通知