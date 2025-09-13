# 邮件查看系统

一个专为宝塔面板设计的邮件查看项目，支持IMAP/POP3协议，便于查看验证码邮件等内容。

## 🆕 重要更新：Python邮件处理器

本系统现已升级支持Python邮件处理器，提供更强大的代理支持和更好的稳定性：

### 新特性
- ✅ **Python邮件处理器**：使用Python的imaplib/poplib替代php-imap扩展
- ✅ **增强代理支持**：完整支持HTTP和SOCKS5代理连接
- ✅ **无缝兼容**：自动回退到原有php-imap实现，确保系统稳定
- ✅ **更好的错误处理**：详细的连接诊断和错误信息
- ✅ **跨平台兼容**：Python实现提供更好的跨平台支持

### 安装Python依赖（可选）
```bash
# 进入项目目录
cd backend/python

# 运行依赖安装脚本
chmod +x setup_dependencies.sh
./setup_dependencies.sh

# 或手动安装
pip3 install PySocks requests --user
```

### 测试系统
```bash
# 运行系统测试
cd backend/test
php test_mail_system.php
```

## 项目特性

- 🌐 **前端界面**：简洁的邮件查看界面，用户只需输入邮箱即可查看最新邮件
- 🔧 **后台管理**：完整的管理员控制面板，支持邮箱账号的增删改查
- 📧 **多协议支持**：支持IMAP和POP3协议，可选择SSL安全连接
- 🗄️ **SQLite数据库**：轻量级数据库，无需复杂配置
- 🛡️ **安全性**：管理员登录验证，密码加密存储
- 📱 **响应式设计**：支持PC和移动端访问

## 邮件处理器架构

系统采用双重架构设计，自动选择最佳的邮件处理方式：

### 1. Python邮件处理器（推荐）
- **位置**: `backend/python/mail_handler.py`
- **优势**: 完整代理支持、更好的稳定性、跨平台兼容
- **支持**: HTTP代理、SOCKS5代理、SSL/TLS、IMAP/POP3
- **依赖**: Python 3.x（系统自带），可选：PySocks、requests

### 2. PHP-IMAP处理器（备用）
- **位置**: `backend/utils/mail_fetcher_original.php`
- **优势**: 传统php-imap扩展，无需额外配置
- **限制**: 代理支持有限
- **依赖**: PHP IMAP扩展

### 自动选择逻辑
1. 系统首先尝试使用Python邮件处理器
2. 如果Python不可用或脚本执行失败，自动回退到PHP-IMAP
3. 确保系统在任何环境下都能正常工作

## 代理功能增强

### 支持的代理类型
- **HTTP代理**: 标准HTTP/HTTPS代理
- **SOCKS5代理**: 高级SOCKS5代理（需要PySocks库）
- **认证代理**: 支持用户名/密码认证

### 代理配置
代理设置存储在数据库中，通过管理后台配置：
- 启用/禁用代理
- 选择代理类型（HTTP/SOCKS5）
- 配置代理服务器和端口
- 设置认证信息

## 目录结构

```
邮件查看系统/
├── frontend/                 # 前端文件
│   └── index.html            # 用户邮件查看页面
├── backend/                  # 后端文件
│   ├── api/                  # API接口
│   │   └── get_mail.php      # 邮件获取API
│   ├── utils/                # 工具类
│   │   ├── mail_fetcher.php          # 统一邮件获取接口
│   │   ├── mail_fetcher_original.php # 原始PHP-IMAP实现
│   │   └── python_mail_fetcher.php   # Python桥接类
│   ├── python/               # Python邮件处理器 🆕
│   │   ├── mail_handler.py           # Python邮件处理脚本
│   │   └── setup_dependencies.sh     # 依赖安装脚本
│   └── test/                 # 测试脚本 🆕
│       └── test_mail_system.php      # 系统功能测试
├── admin/                    # 管理后台
│   ├── login.php            # 管理员登录页面
│   ├── dashboard.php        # 管理员控制面板
│   ├── init_admin.php       # 管理员初始化脚本
│   ├── api/                 # API接口
│   │   └── get_mail.php     # 邮件获取API
│   └── utils/               # 工具类
│       ├── mail_fetcher.php          # 统一邮件获取接口
│       └── mail_fetcher_original.php # 原始实现备份
├── db/                      # 数据库文件（自动创建）
│   ├── init.sql             # 数据库初始化脚本
│   ├── admin.sqlite         # 管理员数据库
│   └── mail.sqlite          # 邮箱账号数据库
└── README.md               # 项目说明文档
```

## 宝塔面板部署指南

### 1. 环境要求

- **PHP版本**：7.4 或以上
- **PHP扩展**：必须开启 `IMAP` 扩展
- **数据库**：SQLite（PHP内置支持）
- **Web服务器**：Nginx 或 Apache

### 2. 安装IMAP扩展

在宝塔面板中安装IMAP扩展：

1. 进入宝塔面板 → 软件商店 → PHP → 设置
2. 找到"安装扩展"选项
3. 安装 `IMAP` 扩展
4. 重启PHP服务

### 3. 上传项目文件

1. 将所有项目文件上传到网站根目录
2. 确保文件权限正确（推荐755）
3. 确保 `db/` 目录有写入权限（推荐777）

### 4. 初始化管理员账号

访问 `http://你的域名/admin/init_admin.php` 进行初始化

初始化成功后会显示：
```
管理员账号初始化成功！
默认账号: admin
默认密码: admin
请访问 login.php 进行登录
```

### 5. 配置网站设置

在宝塔面板中设置：

1. **网站设置** → **默认文档**：添加 `index.html`
2. **网站设置** → **伪静态**：如需要可设置
3. **网站设置** → **SSL**：建议开启HTTPS

### 6. 测试访问

- 前端页面：`http://你的域名/`
- 管理后台：`http://你的域名/admin`

## 使用说明

### 管理员操作

1. **登录后台**
   - 访问 `http://你的域名/admin`
   - 使用账号 `admin` 密码 `admin` 登录
   - 登录后建议修改密码

2. **添加邮箱账号**
   - 在控制面板中填写邮箱信息
   - 支持IMAP和POP3协议
   - 可选择是否启用SSL

3. **常用邮箱配置参考**
   
   **QQ邮箱**（推荐）
   - 服务器：imap.qq.com
   - 端口：993
   - 协议：IMAP
   - SSL：开启
   - 密码：授权码（非QQ密码）

   **163邮箱**
   - 服务器：imap.163.com
   - 端口：993
   - 协议：IMAP
   - SSL：开启
   - 密码：授权码

   **Gmail**
   - 服务器：imap.gmail.com
   - 端口：993
   - 协议：IMAP
   - SSL：开启
   - 密码：应用专用密码

### 用户操作

1. **查看邮件**
   - 访问前端页面 `http://你的域名/`
   - 输入已添加的邮箱地址
   - 点击"获取邮件"查看最新邮件

## 数据库说明

### admin.sqlite（管理员数据库）

```sql
CREATE TABLE admins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,           -- 加密后的密码
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### mail.sqlite（邮箱数据库）

```sql
CREATE TABLE mail_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,              -- 邮箱地址
    username TEXT NOT NULL,           -- 登录用户名
    password TEXT NOT NULL,           -- 邮箱密码/授权码
    server TEXT NOT NULL,             -- 邮件服务器地址
    port INTEGER NOT NULL,            -- 端口号
    protocol TEXT NOT NULL,           -- imap 或 pop3
    ssl BOOLEAN DEFAULT 0,            -- 是否启用SSL
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## 常见问题

### 1. Python邮件处理器问题

#### Python未找到
**错误信息**：`找不到Python解释器`

**解决方法**：
1. 确保系统已安装Python 3.x
2. 检查Python是否在PATH中：`which python3`
3. 系统会自动回退到PHP-IMAP实现

#### 代理连接失败
**错误信息**：包含代理相关的错误信息

**解决方法**：
1. 检查代理服务器是否正常运行
2. 验证代理配置（地址、端口、认证）
3. 对于SOCKS5代理，确保已安装PySocks：`pip3 install PySocks`
4. 可以暂时禁用代理进行测试

#### 依赖库缺失
**错误信息**：`ModuleNotFoundError` 或类似导入错误

**解决方法**：
```bash
# 安装可选依赖
pip3 install PySocks requests --user

# 或运行安装脚本
cd backend/python
./setup_dependencies.sh
```

### 2. IMAP扩展未安装

**错误信息**：`IMAP扩展功能不完整` 或 `PHP IMAP 扩展未安装`

**新增诊断功能**：系统现在会自动检测：
- 扩展是否已加载
- 所有9个核心IMAP函数是否可用
- CLI和Web环境配置差异
- 具体的错误类型和解决方案

**解决方法**：
1. 宝塔面板 → PHP → 安装扩展 → IMAP
2. 重启PHP服务
3. 使用新增的IMAP诊断页面验证安装状态
4. **新增**: 如果IMAP扩展安装困难，系统会自动使用Python邮件处理器

**诊断工具**：访问 `admin/imap_diagnostic.php` 获取详细的扩展状态报告

### 3. 数据库文件权限问题

**错误信息**：`数据库连接失败`

**解决方法**：
1. 设置 `db/` 目录权限为 777
2. 确保 `db/` 目录存在且可写

### 4. 邮箱连接失败

**常见原因**：
1. 未开启IMAP/POP3服务
2. 密码错误（需要使用授权码）
3. 服务器地址或端口错误
4. SSL设置不正确

**新增功能 - 智能诊断**：
- 自动检测具体失败原因（SSL证书、认证失败、服务器拒绝等）
- 提供针对性的解决建议
- 显示详细的连接参数和错误分类

**解决方法**：
1. 登录邮箱开启IMAP/POP3服务
2. 获取并使用授权码（不是登录密码）
3. 检查服务器配置信息
4. 根据邮箱服务商要求设置SSL
5. **新增**：使用邮箱管理页面的"测试连接"功能获取详细诊断信息
6. **新增**：如果使用代理，检查代理配置是否正确

### 5. 前端无法访问API

**可能原因**：
1. 跨域问题
2. 文件路径不正确

**解决方法**：
1. 确保API文件路径正确
2. 检查服务器配置

## 系统测试和诊断

### 运行系统测试
```bash
cd backend/test
php test_mail_system.php
```

该测试会检查：
- Python脚本是否存在和可执行
- PHP桥接类是否正常工作
- 数据库连接和表结构
- 代理配置状态

### 查看日志
系统会记录详细的运行日志，包括：
- 使用的邮件处理器类型（Python/PHP-IMAP）
- 代理连接状态
- 错误详情和建议

日志位置：
- PHP错误日志：根据服务器配置
- Python输出：通过PHP error_log记录

## 迁移说明

### 从旧版本升级
1. **自动兼容**：新版本完全兼容旧版本，无需额外配置
2. **增强功能**：自动启用Python邮件处理器和代理支持
3. **零停机**：如果Python不可用，自动使用原有实现

### 强制使用特定实现
如需强制使用特定实现，可以：

#### 强制使用Python实现
```php
require_once 'backend/utils/python_mail_fetcher.php';
$fetcher = new PythonMailFetcher($server, $port, $username, $password, $protocol, $ssl);
```

#### 强制使用PHP-IMAP实现
```php
require_once 'backend/utils/mail_fetcher_original.php';
$fetcher = new OriginalMailFetcher($server, $port, $username, $password, $protocol, $ssl);
```

## 安全建议

1. **修改默认密码**：登录后立即修改管理员密码
2. **使用HTTPS**：生产环境建议开启SSL证书
3. **定期备份**：定期备份 `db/` 目录下的数据库文件
4. **访问控制**：可通过宝塔面板设置IP白名单
5. **邮箱授权码**：使用专门的授权码，不要使用邮箱登录密码
6. **代理安全**：如使用代理，确保代理服务器的安全性
7. **Python安全**：定期更新Python和相关库

## 更新说明

### 版本 2.0.0 🆕
- 新增Python邮件处理器，支持更好的代理功能
- 增强HTTP和SOCKS5代理支持
- 自动回退机制，确保系统稳定性
- 完整的错误诊断和日志记录
- 向后兼容，无需修改现有配置

### 版本 1.0.0
- 支持IMAP/POP3协议
- 完整的管理员后台
- 响应式前端界面
- SQLite数据库存储
- SSL安全连接支持

## 技术支持

如遇到问题，请检查：

1. PHP版本和扩展
2. 文件权限设置
3. 数据库文件状态
4. 邮箱服务器配置
5. 网络连接状况

---

**注意**：本项目专为宝塔面板环境设计，确保按照部署指南正确配置环境。