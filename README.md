# 邮件查看系统 (Python Flask 版)

一个基于 Python Flask 框架开发的邮件查看系统，支持IMAP/POP3协议，便于查看验证码邮件等内容。

## 项目特性

- 🐍 **Python Flask**：现代化的 Python Web 框架，易于维护和扩展
- 🌐 **前端界面**：简洁的邮件查看界面，用户只需输入邮箱即可查看最新邮件
- 🔧 **后台管理**：完整的管理员控制面板，支持邮箱账号的增删改查
- 📧 **多协议支持**：支持IMAP和POP3协议，可选择SSL安全连接
- 🌐 **代理支持**：集成HTTP和SOCKS5代理池，支持代理连接
- 🗄️ **SQLite数据库**：轻量级数据库，无需复杂配置
- 🛡️ **安全性**：管理员登录验证，密码加密存储
- 📱 **响应式设计**：支持PC和移动端访问
- 🎨 **美观界面**：渐变背景、动画效果、主题切换

## 技术栈

- **后端**：Python 3.12+、Flask 3.0+
- **前端**：HTML5、CSS3、JavaScript (ES6+)
- **数据库**：SQLite3
- **邮件处理**：imapclient、requests
- **代理支持**：pysocks、socks

## 目录结构

```
邮件查看系统/
├── app.py                      # Flask 主应用文件
├── requirements.txt            # Python 依赖包
├── templates/                  # Jinja2 模板文件
│   ├── base.html              # 基础模板
│   ├── frontend/              # 前端模板
│   │   └── index.html         # 用户邮件查看页面
│   └── admin/                 # 后台管理模板
│       ├── login.html         # 管理员登录页面
│       ├── home.html          # 管理员首页
│       ├── mailbox.html       # 邮箱管理页面
│       ├── daili.html         # 代理池管理页面
│       ├── kami.html          # 卡密管理页面
│       ├── kamirizhi.html     # 卡密日志页面
│       ├── shoujian.html      # 收件日志页面
│       └── system.html        # 系统设置页面
├── python/                    # Python 邮件处理模块
│   ├── mail_fetcher.py        # 邮件获取器（支持代理）
│   └── requirements.txt       # 邮件模块依赖
├── db/                        # 数据库文件
│   ├── init.sql              # 数据库初始化脚本
│   ├── mail.sqlite           # 主数据库（自动创建）
│   └── admin.sqlite          # 管理员数据库（自动创建）
└── README.md                 # 项目说明文档
```

## 部署指南

### 1. 环境要求

- **Python版本**：3.12 或以上
- **系统**：Linux/Windows/macOS
- **Web服务器**：可选（Nginx + uWSGI/Gunicorn）
- **包管理**：pip

### 2. 安装依赖

```bash
# 安装 Python 依赖
pip install -r requirements.txt

# 如果使用 Ubuntu/Debian 系统，可以使用 apt 安装
sudo apt install python3-flask python3-flask-session

# 安装邮件处理依赖
pip install imapclient requests pysocks charset-normalizer
```

### 3. 部署步骤

1. **克隆项目**
   ```bash
   git clone <repository-url>
   cd 邮件查看系统
   ```

2. **安装依赖**
   ```bash
   pip install -r requirements.txt
   ```

3. **初始化数据库**
   ```bash
   # Flask 应用启动时会自动创建数据库和表
   python3 app.py
   ```

4. **配置管理员账号**
   - 默认管理员账号：`admin`
   - 默认密码：`admin`
   - 首次登录后请修改密码

### 4. 启动应用

#### 开发环境
```bash
python3 app.py
```
访问：`http://localhost:5000`

#### 生产环境
```bash
# 使用 Gunicorn
pip install gunicorn
gunicorn -w 4 -b 0.0.0.0:5000 app:app

# 使用 uWSGI
pip install uwsgi
uwsgi --http :5000 --wsgi-file app.py --callable app
```

### 5. 配置反向代理（可选）

**Nginx 配置示例**：
```nginx
server {
    listen 80;
    server_name your-domain.com;

    location / {
        proxy_pass http://127.0.0.1:5000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## 使用说明

### 管理员操作

1. **登录后台**
   - 访问 `http://你的域名/admin`
   - 使用账号 `admin` 密码 `admin` 登录
   - 登录后建议修改密码

2. **添加邮箱账号**
   - 在邮箱管理页面填写邮箱信息
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

4. **代理池配置**
   - 支持HTTP和SOCKS5代理
   - 可配置多个代理服务器
   - 自动故障切换和负载均衡

### 用户操作

1. **查看邮件**
   - 访问首页 `http://你的域名/`
   - 输入已添加的邮箱地址
   - 点击"获取邮件"查看最新邮件

## API 接口

### 邮件获取 API
```http
POST /api/get_mail
Content-Type: application/json

{
    "email": "user@example.com"
}
```

### 邮箱管理 API
```http
# 获取邮箱列表
GET /admin/api/mailbox

# 添加邮箱
POST /admin/api/mailbox
{
    "action": "add",
    "email": "user@example.com",
    "password": "password",
    "server": "imap.example.com",
    "port": 993,
    "protocol": "imap",
    "ssl": true,
    "remarks": "备注"
}

# 删除邮箱
DELETE /admin/api/mailbox
{
    "id": 1
}
```

### 代理管理 API
```http
# 获取代理配置
GET /admin/api/proxy-config

# 开启代理（自动选择）
POST /admin/api/proxy-config
{
    "action": "enable_proxy"
}

# 关闭代理
POST /admin/api/proxy-config
{
    "action": "disable_proxy"
}

# 获取HTTP代理列表
GET /admin/api/proxies/http

# 添加HTTP代理
POST /admin/api/proxies/http
{
    "action": "add",
    "name": "代理名称",
    "host": "127.0.0.1",
    "port": 8080,
    "username": "用户名",
    "password": "密码",
    "remarks": "备注"
}

# 测试代理
POST /admin/api/proxies/http
{
    "action": "test",
    "id": 1
}
```

## 代理功能使用说明

### 代理池管理

系统支持HTTP和SOCKS5两种代理类型：

1. **添加代理**：
   - 在代理池页面点击"添加HTTP代理"或"添加SOCKS5代理"
   - 填写代理信息（地址、端口、用户名、密码等）
   - 可在保存前点击"测试代理"验证连接

2. **开启代理**：
   - 点击"🌐 开启代理"按钮
   - 系统自动选择第一个可用代理（HTTP优先，然后SOCKS5）
   - 按ID升序测试直到找到可用代理

3. **代理状态**：
   - 🟢 在线：代理可用
   - 🔴 离线：代理不可用  
   - 🟡 测试：正在测试中

### 邮件服务代理支持

开启代理后，以下功能强制通过代理连接：
- 前端邮件获取（首页）
- 邮箱管理页面的"测试"功能
- 添加邮箱时的连接验证
```

## 数据库结构

### mail_accounts（邮箱账号表）
```sql
CREATE TABLE mail_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    username TEXT NOT NULL,
    password TEXT NOT NULL,
    server TEXT NOT NULL,
    port INTEGER NOT NULL,
    protocol TEXT NOT NULL DEFAULT 'imap',
    ssl INTEGER NOT NULL DEFAULT 1,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### admin_users（管理员表）
```sql
CREATE TABLE admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## 常见问题

### 1. Python 依赖安装失败

**解决方法**：
1. 更新 pip：`pip install --upgrade pip`
2. 使用清华源：`pip install -r requirements.txt -i https://pypi.tuna.tsinghua.edu.cn/simple`
3. 系统包安装：`sudo apt install python3-flask python3-flask-session`

### 2. 邮箱连接失败

**常见原因**：
1. 未开启IMAP/POP3服务
2. 密码错误（需要使用授权码）
3. 服务器地址或端口错误
4. SSL设置不正确
5. 防火墙阻止连接

**解决方法**：
1. 登录邮箱开启IMAP/POP3服务
2. 获取并使用授权码（不是登录密码）
3. 检查服务器配置信息
4. 根据邮箱服务商要求设置SSL
5. 检查网络和防火墙设置

### 3. 代理连接问题

**解决方法**：
1. 验证代理服务器可用性
2. 检查代理认证信息
3. 确认代理协议类型（HTTP/SOCKS5）
4. 测试代理连接

### 4. 权限问题

**解决方法**：
1. 确保数据库文件有写权限
2. 检查 `db/` 目录权限
3. 确保 Flask 应用有文件系统访问权限

## 安全建议

1. **修改默认密码**：登录后立即修改管理员密码
2. **使用HTTPS**：生产环境建议开启SSL证书
3. **定期备份**：定期备份 `db/` 目录下的数据库文件
4. **访问控制**：配置防火墙和访问控制列表
5. **邮箱授权码**：使用专门的授权码，不要使用邮箱登录密码
6. **更新依赖**：定期更新 Python 依赖包

## 更新日志

### 版本 2.0.0 (Python Flask 版)
- 完全重写为 Python Flask 应用
- 保持与原PHP版本完全一致的UI和功能
- 新增代理池支持
- 优化邮件处理性能
- 改进错误处理和日志记录
- 响应式设计优化

### 版本 1.0.0 (PHP 版)
- 支持IMAP/POP3协议
- 完整的管理员后台
- 响应式前端界面
- SQLite数据库存储
- SSL安全连接支持

## 技术支持

如遇到问题，请检查：

1. Python 版本和依赖包
2. 数据库文件权限
3. 邮箱服务器配置
4. 网络连接状况
5. 防火墙和安全组设置

## 许可证

本项目基于 MIT 许可证开源。

---

**注意**：这是基于 Python Flask 重构的全新版本，保持了与原PHP版本完全一致的界面和功能。