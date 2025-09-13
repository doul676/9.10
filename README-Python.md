# 邮件查看系统 - Python 版本部署指南

本项目已完全从 PHP 重构为 Python Flask 应用，提供了与原 PHP 版本完全相同的功能。

## 🌟 主要特性

- ✅ **完整功能迁移**: 所有 PHP 功能已迁移到 Python Flask
- ✅ **前端兼容**: 保留原有 HTML/CSS/JS 界面设计
- ✅ **管理后台**: 完整的管理员控制面板
- ✅ **邮件获取**: 支持 IMAP/POP3 协议
- ✅ **代理支持**: HTTP/SOCKS5 代理配置
- ✅ **数据库兼容**: 使用相同的 SQLite 数据库结构
- ✅ **API 兼容**: 前端 API 调用无需修改

## 📁 Python 版本文件结构

```
邮件查看系统-Python版/
├── app.py                    # Flask 主应用
├── requirements-flask.txt    # Python 依赖包
├── start.sh                  # 启动脚本
├── templates/                # Jinja2 模板
│   ├── admin_login.html      # 管理员登录页面
│   ├── admin_home.html       # 管理员首页
│   └── admin_proxy.html      # 代理管理页面
├── frontend/                 # 前端文件（已更新API路径）
│   └── index.html            # 用户邮件查看页面
├── python/                   # Python 模块
│   ├── mail_fetcher.py       # 邮件获取服务
│   └── requirements.txt      # 邮件模块依赖
├── db/                       # 数据库文件
│   ├── mail.sqlite           # SQLite 数据库
│   └── init.sql              # 数据库初始化脚本
└── README-Python.md          # 本文档
```

## 🛠️ 环境要求

### Python 环境
- **Python 版本**: 3.7 或以上
- **操作系统**: Linux、Windows、macOS
- **内存要求**: 最低 256MB
- **磁盘空间**: 最低 100MB

### 必需的 Python 包
```
Flask>=2.3.0
imapclient>=2.3.1
requests>=2.31.0
pysocks>=1.7.1
charset-normalizer>=3.3.2
```

## 🚀 快速部署

### 方法一：使用启动脚本（推荐）

1. **下载并解压项目文件**
2. **运行启动脚本**：
```bash
cd 邮件查看系统-Python版
chmod +x start.sh
./start.sh
```

### 方法二：手动安装

1. **安装 Python 依赖**：
```bash
pip3 install -r requirements-flask.txt
```

2. **初始化数据库**：
```bash
python3 -c "
import sqlite3
import os

conn = sqlite3.connect('db/mail.sqlite')
with open('db/init.sql', 'r', encoding='utf-8') as f:
    conn.executescript(f.read())

conn.execute('''CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)''')

conn.execute('INSERT OR IGNORE INTO admin_users (username, password) VALUES (?, ?)', ('admin', 'admin'))
conn.commit()
conn.close()
"
```

3. **启动应用**：
```bash
python3 app.py
```

## 🌐 访问应用

启动成功后，可以通过以下地址访问：

- **前端页面**: http://localhost:8000/
- **管理后台**: http://localhost:8000/admin
- **默认账号**: admin / admin

## 📋 使用指南

### 管理员操作

1. **登录管理后台**
   - 访问 `http://localhost:8000/admin`
   - 使用默认账号 `admin` / `admin` 登录
   - **重要**: 登录后请立即修改默认密码

2. **邮箱账号管理**
   - 在管理后台添加邮箱账号
   - 支持 IMAP/POP3 协议
   - 可配置 SSL 连接
   - 支持连接测试功能

3. **代理设置**
   - 访问 `http://localhost:8000/admin/proxy`
   - 支持 HTTP 和 SOCKS5 代理
   - 可启用/禁用代理功能
   - 支持代理认证

### 用户操作

1. **查看邮件**
   - 访问前端页面 `http://localhost:8000/`
   - 输入已添加的邮箱地址
   - 点击"获取邮件"查看最新邮件
   - 支持图片和附件显示/下载

## 🔧 配置说明

### Flask 应用配置

在 `app.py` 中可以修改以下配置：

```python
# 修改服务器配置
app.run(debug=False, host='0.0.0.0', port=8000)

# 修改密钥（生产环境必须修改）
app.secret_key = 'your-secret-key-change-in-production'
```

### 数据库配置

数据库文件位于 `db/mail.sqlite`，包含以下主要表：
- `mail_accounts`: 邮箱账号信息
- `admin_users`: 管理员账号
- `proxy_config`: 代理配置
- `http_proxies`: HTTP 代理列表
- `socks5_proxies`: SOCKS5 代理列表

### 邮件服务器配置参考

**QQ邮箱（推荐）**
- 服务器：imap.qq.com
- 端口：993
- 协议：IMAP
- SSL：启用
- 密码：授权码（非QQ密码）

**163邮箱**
- 服务器：imap.163.com
- 端口：993
- 协议：IMAP
- SSL：启用
- 密码：授权码

**Gmail**
- 服务器：imap.gmail.com
- 端口：993
- 协议：IMAP
- SSL：启用
- 密码：应用专用密码

## 🔒 安全建议

1. **修改默认密码**: 登录后立即修改管理员密码
2. **使用 HTTPS**: 生产环境建议配置 SSL 证书
3. **防火墙设置**: 限制访问端口，配置 IP 白名单
4. **定期备份**: 定期备份 `db/` 目录下的数据库文件
5. **邮箱授权码**: 使用专门的授权码，不要使用邮箱登录密码
6. **更新密钥**: 修改 Flask 应用的 `secret_key`

## 🚀 生产环境部署

### 使用 Gunicorn 部署

1. **安装 Gunicorn**：
```bash
pip3 install gunicorn
```

2. **启动应用**：
```bash
gunicorn -w 4 -b 0.0.0.0:8000 app:app
```

### 使用 Nginx 反向代理

```nginx
server {
    listen 80;
    server_name your-domain.com;
    
    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

### 使用 systemd 服务

创建服务文件 `/etc/systemd/system/mail-system.service`：

```ini
[Unit]
Description=Mail Viewing System
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/path/to/mail-system
Environment="PATH=/path/to/mail-system"
ExecStart=/usr/bin/python3 /path/to/mail-system/app.py
Restart=always

[Install]
WantedBy=multi-user.target
```

启用服务：
```bash
sudo systemctl enable mail-system
sudo systemctl start mail-system
```

## 🐛 故障排除

### 常见问题

1. **IMAP扩展错误**
   - Python 版本不需要 PHP IMAP 扩展
   - 使用 `imapclient` 库处理邮件连接

2. **依赖包安装失败**
   ```bash
   # 使用国内镜像加速安装
   pip3 install -r requirements-flask.txt -i https://pypi.tuna.tsinghua.edu.cn/simple
   ```

3. **数据库权限问题**
   ```bash
   # 确保数据库目录有写权限
   chmod 755 db/
   chmod 644 db/mail.sqlite
   ```

4. **端口占用问题**
   ```bash
   # 查看端口使用情况
   netstat -tulpn | grep 8000
   
   # 修改端口（在 app.py 中）
   app.run(debug=True, host='0.0.0.0', port=8001)
   ```

### 日志调试

启用 Flask 调试模式查看详细错误信息：

```python
# 在 app.py 中
app.run(debug=True, host='0.0.0.0', port=8000)
```

## 📈 性能优化

1. **使用生产级 WSGI 服务器**（如 Gunicorn）
2. **配置反向代理**（如 Nginx）
3. **启用缓存**（如 Redis）
4. **数据库优化**（定期清理日志）
5. **静态文件 CDN**（生产环境）

## 🔄 从 PHP 版本迁移

如果你之前使用的是 PHP 版本，可以直接使用现有的数据库文件：

1. **保留数据库**：将 `db/mail.sqlite` 文件复制到 Python 版本目录
2. **启动 Python 版本**：按照上述部署步骤启动
3. **验证功能**：测试所有功能是否正常工作
4. **更新书签**：更新访问地址和端口

## 📞 技术支持

如遇到问题，请检查：

1. **Python 版本和依赖包**
2. **数据库文件状态和权限**
3. **网络连接和防火墙设置**
4. **邮箱服务器配置**
5. **应用日志和错误信息**

## 📝 更新日志

### 版本 2.0.0 (Python 重构版)
- ✅ 完全使用 Python Flask 重写
- ✅ 保持所有原有功能
- ✅ 改进的用户界面
- ✅ 更好的错误处理
- ✅ 增强的安全性
- ✅ 更易部署和维护

---

**注意**：本项目现在完全基于 Python Flask，不再需要 PHP 环境。所有功能都已成功迁移到 Python 版本中。