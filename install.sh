#!/bin/bash

# 邮件查看系统一键安装脚本 (Debian/Ubuntu)
# 支持自动安装所有依赖和启动服务，端口8005

set -e

echo "=========================================="
echo "    邮件查看系统一键安装脚本 v2.0        "
echo "=========================================="
echo ""

# 检查是否为root用户
if [ "$EUID" -ne 0 ]; then
    echo "❌ 请使用root权限运行此脚本"
    echo "   sudo bash install.sh"
    exit 1
fi

# 检查系统版本
if ! command -v apt-get &> /dev/null; then
    echo "❌ 此脚本仅支持Debian/Ubuntu系统"
    exit 1
fi

echo "🔍 检测系统信息..."
OS_VERSION=$(lsb_release -d 2>/dev/null | cut -f2 || echo "Unknown")
echo "   操作系统: $OS_VERSION"

# 获取当前脚本目录
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$SCRIPT_DIR"

echo "   安装目录: $APP_DIR"
echo ""

# 更新系统包
echo "📦 更新系统包列表..."
apt-get update -qq

# 安装Python 3和pip
echo "🐍 安装Python 3和相关工具..."
apt-get install -y python3 python3-pip python3-venv python3-dev

# 安装系统依赖
echo "🔧 安装系统依赖..."
apt-get install -y \
    build-essential \
    libssl-dev \
    libffi-dev \
    libpq-dev \
    libmysqlclient-dev \
    pkg-config \
    curl \
    wget \
    git \
    supervisor \
    nginx

# 创建虚拟环境
echo "🌐 创建Python虚拟环境..."
cd "$APP_DIR"

if [ ! -d "venv" ]; then
    python3 -m venv venv
fi

# 激活虚拟环境
source venv/bin/activate

# 升级pip
echo "⬆️  升级pip..."
pip install --upgrade pip

# 安装Python依赖
echo "📚 安装Python依赖包..."
if [ -f "requirements.txt" ]; then
    pip install -r requirements.txt
else
    # 如果没有requirements.txt，手动安装核心依赖
    pip install \
        Flask>=3.0.0 \
        Flask-Session>=0.7.0 \
        werkzeug>=3.0.0 \
        imapclient>=2.3.1 \
        requests>=2.31.0 \
        pysocks>=1.7.1 \
        charset-normalizer>=3.3.2 \
        mysql-connector-python>=8.2.0 \
        psycopg2-binary>=2.9.9
fi

# 初始化数据库
echo "🗄️  初始化数据库..."
if [ -f "app.py" ]; then
    # 设置环境变量
    export FLASK_APP=app.py
    export DATABASE_TYPE=${DATABASE_TYPE:-sqlite}
    export PORT=${PORT:-8005}
    
    # 运行一次以初始化数据库
    python3 app.py &
    FLASK_PID=$!
    sleep 3
    kill $FLASK_PID 2>/dev/null || true
    wait $FLASK_PID 2>/dev/null || true
    
    echo "   ✅ 数据库初始化完成"
else
    echo "   ⚠️  未找到app.py文件"
fi

# 创建systemd服务文件
echo "🔧 创建系统服务..."
cat > /etc/systemd/system/mail-system.service << EOF
[Unit]
Description=Mail View System
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=$APP_DIR
Environment=PATH=$APP_DIR/venv/bin
Environment=DATABASE_TYPE=sqlite
Environment=PORT=8005
ExecStart=$APP_DIR/venv/bin/python app.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# 重新加载systemd并启用服务
systemctl daemon-reload
systemctl enable mail-system

# 创建Nginx配置文件（可选）
echo "🌐 配置Nginx反向代理..."
cat > /etc/nginx/sites-available/mail-system << EOF
server {
    listen 80;
    server_name _;

    location / {
        proxy_pass http://127.0.0.1:8005;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        # WebSocket support (if needed in future)
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
EOF

# 启用Nginx站点
if [ ! -L "/etc/nginx/sites-enabled/mail-system" ]; then
    ln -s /etc/nginx/sites-available/mail-system /etc/nginx/sites-enabled/
fi

# 测试Nginx配置
nginx -t
if [ $? -eq 0 ]; then
    echo "   ✅ Nginx配置测试通过"
else
    echo "   ⚠️  Nginx配置有问题，请检查"
fi

# 创建日志目录
mkdir -p /var/log/mail-system
chown -R root:root /var/log/mail-system

# 设置文件权限
echo "🔐 设置文件权限..."
chown -R root:root "$APP_DIR"
chmod +x "$APP_DIR/app.py"

# 创建启动脚本
cat > "$APP_DIR/start.sh" << EOF
#!/bin/bash
cd "$APP_DIR"
source venv/bin/activate
export DATABASE_TYPE=\${DATABASE_TYPE:-sqlite}
export PORT=\${PORT:-8005}
python3 app.py
EOF

chmod +x "$APP_DIR/start.sh"

# 创建停止脚本
cat > "$APP_DIR/stop.sh" << EOF
#!/bin/bash
systemctl stop mail-system
EOF

chmod +x "$APP_DIR/stop.sh"

# 创建重启脚本
cat > "$APP_DIR/restart.sh" << EOF
#!/bin/bash
systemctl restart mail-system
systemctl restart nginx
EOF

chmod +x "$APP_DIR/restart.sh"

# 创建状态检查脚本
cat > "$APP_DIR/status.sh" << EOF
#!/bin/bash
echo "邮件查看系统状态："
systemctl status mail-system --no-pager -l
echo ""
echo "Nginx状态："
systemctl status nginx --no-pager -l
echo ""
echo "端口占用情况："
netstat -tlnp | grep :8005 || echo "端口8005未被占用"
netstat -tlnp | grep :80 || echo "端口80未被占用"
EOF

chmod +x "$APP_DIR/status.sh"

# 启动服务
echo "🚀 启动服务..."
systemctl start mail-system
systemctl restart nginx

# 等待服务启动
echo "⏳ 等待服务启动..."
sleep 5

# 检查服务状态
echo "📊 检查服务状态..."
if systemctl is-active --quiet mail-system; then
    echo "   ✅ 邮件查看系统服务运行正常"
else
    echo "   ❌ 邮件查看系统服务启动失败"
    echo "   请运行以下命令查看日志："
    echo "   journalctl -u mail-system -f"
fi

if systemctl is-active --quiet nginx; then
    echo "   ✅ Nginx服务运行正常"
else
    echo "   ❌ Nginx服务启动失败"
    echo "   请运行以下命令查看日志："
    echo "   journalctl -u nginx -f"
fi

# 获取IP地址
IP_ADDRESS=$(hostname -I | awk '{print $1}')

echo ""
echo "=========================================="
echo "            安装完成！                    "
echo "=========================================="
echo ""
echo "🎉 邮件查看系统安装成功！"
echo ""
echo "📍 访问地址："
echo "   本地访问: http://localhost:8005"
echo "   本地访问: http://127.0.0.1:8005"
if [ -n "$IP_ADDRESS" ]; then
echo "   网络访问: http://$IP_ADDRESS:8005"
echo "   网络访问: http://$IP_ADDRESS (通过Nginx)"
fi
echo ""
echo "👤 默认管理员账号："
echo "   用户名: admin"
echo "   密码: admin"
echo "   ⚠️  首次登录后请立即修改密码！"
echo ""
echo "🔧 管理命令："
echo "   启动服务: systemctl start mail-system"
echo "   停止服务: systemctl stop mail-system"
echo "   重启服务: systemctl restart mail-system"
echo "   查看日志: journalctl -u mail-system -f"
echo "   查看状态: $APP_DIR/status.sh"
echo ""
echo "📁 项目目录: $APP_DIR"
echo "📁 数据库文件: $APP_DIR/db/"
echo "📁 日志目录: /var/log/mail-system"
echo ""
echo "🌐 功能特点："
echo "   ✅ 完整的邮箱管理（支持批量添加、测试连接、分页搜索）"
echo "   ✅ 代理池管理（HTTP/SOCKS5代理，支持测试和批量操作）"
echo "   ✅ 服务器地址管理（快捷选择，自动端口切换）"
echo "   ✅ 多数据库支持（SQLite/MySQL/PostgreSQL）"
echo "   ✅ 响应式设计（支持PC和移动端）"
echo "   ✅ 安全认证和权限管理"
echo ""
echo "📖 使用说明："
echo "   1. 访问上述地址进入系统"
echo "   2. 使用默认账号登录管理后台"
echo "   3. 在邮箱管理中添加邮箱账号"
echo "   4. 配置代理池（可选）"
echo "   5. 开始使用邮件查看功能"
echo ""
echo "❓ 如需帮助，请查看项目文档或联系技术支持。"
echo ""

# 如果有防火墙，提示开放端口
if command -v ufw &> /dev/null; then
    echo "🔥 防火墙设置提醒："
    echo "   如果启用了ufw防火墙，请运行以下命令开放端口："
    echo "   sudo ufw allow 8005"
    echo "   sudo ufw allow 80"
    echo ""
fi

# 设置环境变量持久化（可选）
cat >> /etc/environment << EOF
# Mail System Environment
DATABASE_TYPE=sqlite
PORT=8005
EOF

echo "安装脚本执行完成。"