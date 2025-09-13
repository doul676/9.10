#!/bin/bash

# 邮件查看系统 - Python版本启动脚本
# Email Viewing System - Python Version Startup Script

echo "🚀 启动邮件查看系统 Python 版本..."
echo "🚀 Starting Email Viewing System Python Version..."

# 检查 Python 3 是否安装
if ! command -v python3 &> /dev/null; then
    echo "❌ Python 3 未安装，请先安装 Python 3.7 或更高版本"
    echo "❌ Python 3 is not installed. Please install Python 3.7 or higher first"
    exit 1
fi

echo "✅ Python 版本: $(python3 --version)"

# 检查 pip 是否安装
if ! command -v pip3 &> /dev/null; then
    echo "❌ pip3 未安装，请先安装 pip3"
    echo "❌ pip3 is not installed. Please install pip3 first"
    exit 1
fi

# 安装依赖包
echo "📦 安装 Python 依赖包..."
echo "📦 Installing Python dependencies..."

pip3 install -r requirements-flask.txt --user

if [ $? -ne 0 ]; then
    echo "❌ 依赖包安装失败"
    echo "❌ Failed to install dependencies"
    exit 1
fi

echo "✅ 依赖包安装完成"

# 检查数据库目录
if [ ! -d "db" ]; then
    echo "📁 创建数据库目录..."
    mkdir -p db
fi

# 初始化数据库（如果需要）
echo "🗄️ 初始化数据库..."
python3 -c "
import sqlite3
import os

# 初始化数据库
if os.path.exists('db/init.sql'):
    conn = sqlite3.connect('db/mail.sqlite')
    with open('db/init.sql', 'r', encoding='utf-8') as f:
        conn.executescript(f.read())
    
    # 创建管理员用户表
    conn.execute('''CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )''')
    
    # 插入默认管理员
    conn.execute('INSERT OR IGNORE INTO admin_users (username, password) VALUES (?, ?)', ('admin', 'admin'))
    conn.commit()
    conn.close()
    print('✅ 数据库初始化完成')
else:
    print('⚠️  数据库初始化脚本不存在，跳过初始化')
"

# 设置环境变量
export FLASK_APP=app.py
export FLASK_ENV=production

echo ""
echo "🌟 邮件查看系统 Python 版本"
echo "🌟 Email Viewing System Python Version"
echo ""
echo "📋 系统信息:"
echo "   - Python 版本: $(python3 --version)"
echo "   - Flask 应用: app.py"
echo "   - 访问地址: http://localhost:8000"
echo "   - 管理后台: http://localhost:8000/admin"
echo "   - 默认账号: admin / admin"
echo ""
echo "🚀 启动服务器..."
echo "🚀 Starting server..."
echo ""

# 启动 Flask 应用
python3 app.py