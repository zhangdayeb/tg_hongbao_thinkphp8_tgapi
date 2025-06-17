# Telegram机器人系统项目说明文档

## 📋 项目概述

这是一个基于ThinkPHP 6.0开发的Telegram机器人系统，主要功能包括用户管理、充值提现、红包功能、群组管理等。系统采用模块化设计，支持多种支付方式和完善的管理后台。

## 🏗️ 技术架构

### 核心技术栈
- **后端框架**: ThinkPHP 6.0
- **数据库**: MySQL 8.0
- **缓存系统**: Redis
- **消息队列**: Workerman WebSocket
- **API通信**: Telegram Bot API
- **开发语言**: PHP 7.4+

### 系统特性
- ✅ RESTful API设计
- ✅ 模块化架构
- ✅ 中间件支持（限流、安全验证）
- ✅ 多环境配置
- ✅ 完善的日志系统
- ✅ 缓存优化
- ✅ 安全防护机制

## 📁 项目结构

```
项目根目录/
├── app/                          # 应用目录
│   ├── common/                   # 公共类库
│   │   ├── SecurityHelper.php    # 安全助手类
│   │   ├── ValidatorHelper.php   # 验证助手类
│   │   └── helper/               # 助手类目录
│   │       └── TemplateHelper.php # 模板助手类
│   ├── controller/               # 控制器目录
│   │   ├── BaseTelegramController.php # Telegram基础控制器
│   │   ├── admin/               # 管理后台控制器
│   │   │   └── TelegramController.php # Telegram管理控制器
│   │   └── common/              # 公共控制器
│   │       └── LogHelper.php     # 日志助手
│   ├── middleware/              # 中间件目录
│   │   └── RateLimit.php        # 限流中间件
│   ├── model/                   # 模型目录
│   │   ├── User.php             # 用户模型
│   │   ├── Admin.php            # 管理员模型
│   │   ├── TgCrowdList.php      # Telegram群组模型
│   │   └── TgBotConfig.php      # 机器人配置模型
│   ├── service/                 # 服务层目录
│   │   └── TelegramBotService.php # Telegram机器人服务
│   └── utils/                   # 工具类目录
│       ├── TelegramKeyboard.php # Telegram键盘工具
│       └── TelegramMessage.php  # Telegram消息工具
├── config/                      # 配置文件目录
│   ├── database.php             # 数据库配置
│   ├── log.php                  # 日志配置
│   ├── view.php                 # 视图配置
│   ├── telegram.php             # Telegram配置
│   └── templates/               # 消息模板目录
│       ├── redpacket.php        # 红包模板
│       └── payment.php          # 支付模板
├── .env                         # 环境配置文件
└── app/common.php               # 公共函数文件
```

## ⚙️ 核心功能模块

### 1. 用户管理系统
- **用户注册/登录**: 通过Telegram自动注册
- **用户状态管理**: 支持正常、冻结等状态
- **实名认证**: 支持身份证验证
- **权限管理**: 区分普通用户、代理等角色

### 2. 财务系统
- **充值功能**: 
  - USDT充值（支持TRC20/ERC20）
  - 汇旺支付充值
  - 实时到账确认
- **提现功能**:
  - USDT钱包提现
  - 自动审核机制
  - 手续费计算
- **资金流水**: 完整的交易记录

### 3. 红包系统
- **发红包**: 
  - 拼手气红包
  - 普通红包
  - 群组红包功能
- **抢红包**: 
  - 实时抢夺
  - 手气最佳奖励
  - 防重复领取
- **红包管理**: 
  - 过期处理
  - 退款机制
  - 统计分析

### 4. Telegram机器人
- **命令处理**: 支持/start、/help、/balance等命令
- **交互界面**: 内联键盘菜单
- **消息模板**: 可配置的消息模板系统
- **多语言支持**: 模板化多语言方案

### 5. 群组管理
- **群组监控**: 实时监控群组状态
- **广播功能**: 批量消息推送
- **权限控制**: 机器人权限管理
- **成员统计**: 群组数据分析

### 6. 管理后台
- **用户管理**: 用户信息查看、编辑
- **财务管理**: 充值提现审核
- **系统设置**: 参数配置
- **数据统计**: 运营数据分析

## 🔧 安全机制

### 数据安全
- **密码加密**: Base64编码存储
- **签名验证**: HMAC-SHA256签名
- **输入过滤**: 防XSS、SQL注入
- **USDT地址验证**: 支持多种网络格式

### 访问控制
- **IP白名单**: Telegram服务器IP验证
- **Webhook验证**: Secret Token验证
- **限流保护**: 多级限流机制
- **异常检测**: 自动黑名单机制

### 防重复处理
- **回调防重**: 缓存机制防重复处理
- **交易防重**: 订单号唯一性检查
- **消息防重**: 防止重复发送

## 📊 数据库设计

### 核心数据表
- **ntp_user**: 用户信息表
- **ntp_common_admin**: 管理员表
- **ntp_tg_crowd_list**: Telegram群组表
- **ntp_tg_bot_config**: 机器人配置表
- **ntp_telegram_user_state**: 用户状态表

### 数据库配置
- 字符集: UTF8MB4
- 时区: Asia/Shanghai
- 引擎: InnoDB
- 表前缀: ntp_

## 🚀 部署说明

### 环境要求
- PHP >= 7.4
- MySQL >= 8.0
- Redis >= 5.0
- Composer
- 支持URL重写的Web服务器

### 配置步骤

1. **克隆项目代码**
```bash
git clone [项目地址]
cd [项目目录]
```

2. **安装依赖**
```bash
composer install
```

3. **配置环境文件**
```bash
cp .env.example .env
# 编辑.env文件，配置数据库、Redis等信息
```

4. **数据库迁移**
```bash
php think migrate:run
```

5. **配置Telegram Webhook**
```bash
# 设置Webhook URL
curl -X POST "https://api.telegram.org/bot[BOT_TOKEN]/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://yourdomain.com/webhook/telegram"}'
```

### 重要配置项

#### .env 配置
```ini
# 应用调试
APP_DEBUG = false
LOG_LEVEL = error,warning

# 数据库配置
[DATABASE]
TYPE = mysql
HOSTNAME = 127.0.0.1
DATABASE = ntp_dianji
USERNAME = root
PASSWORD = your_password
HOSTPORT = 3306
CHARSET = utf8mb4
PREFIX = ntp_

# Redis配置
[REDIS]
HOST = 127.0.0.1
PORT = 6379
PASSWORD = your_redis_password

# Workerman配置
[WORKER]
one = websocket://0.0.0.0:2009
two = text://0.0.0.0:3009
two_tcp = tcp://127.0.0.1:3009
```

#### Telegram配置
```php
// config/telegram.php
return [
    'bot_token' => 'your_bot_token',
    'webhook_secret' => 'your_webhook_secret',
    'webhook_url' => 'https://yourdomain.com/webhook/telegram',
    'allowed_ips' => [
        '149.154.160.0/20',
        '91.108.4.0/22',
        // ... Telegram服务器IP段
    ],
];
```

## 📝 API接口

### Webhook接口
- **POST** `/webhook/telegram` - 接收Telegram更新
- **POST** `/api/payment/notify` - 支付回调通知

### 管理后台API
- **GET** `/admin/telegram/groups` - 获取群组列表
- **POST** `/admin/telegram/broadcast` - 发送广播消息
- **GET** `/admin/users` - 获取用户列表
- **POST** `/admin/users/status` - 修改用户状态

## 🔍 监控与日志

### 日志分类
- **业务日志**: `runtime/log/business/` - 重要业务操作
- **调试日志**: `runtime/log/debug/` - 开发调试信息
- **错误日志**: `runtime/log/` - 系统错误信息
- **Telegram日志**: 专门的Telegram操作日志

### 监控指标
- 消息发送成功率
- API响应时间
- 用户活跃度
- 系统资源使用率

## 🛠️ 开发规范

### 代码规范
- 遵循PSR-4自动加载标准
- 使用PHPDoc注释
- 异常处理机制
- 统一的返回格式

### 安全规范
- 所有用户输入必须验证
- 敏感信息加密存储
- API接口签名验证
- SQL注入防护

### 性能优化
- 数据库查询优化
- Redis缓存使用
- 消息队列异步处理
- 静态资源CDN

## 🚨 常见问题

### 1. Webhook不能正常接收
- 检查服务器是否支持HTTPS
- 验证IP白名单配置
- 确认Webhook URL可访问

### 2. Redis连接失败
- 检查Redis服务状态
- 验证连接配置信息
- 确认防火墙设置

### 3. 数据库连接异常
- 检查数据库服务状态
- 验证用户名密码
- 确认数据库存在

### 4. 消息发送失败
- 检查Bot Token有效性
- 验证用户权限
- 确认消息格式正确

## 📞 技术支持

如遇到技术问题，请提供以下信息：
- 错误日志内容
- 系统环境信息
- 问题复现步骤
- 预期行为描述

---

**版本**: v1.0.0  
**更新时间**: 2025-06-17  
**维护状态**: 积极维护中