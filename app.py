#!/usr/bin/env python3
"""
邮件查看系统 - Flask 应用主文件（简化版本）
基于原有 PHP 版本完全重构，保持所有功能和 UI 一致
"""

import os
import sqlite3
import secrets
from datetime import datetime, timezone
from flask import Flask, render_template, request, session, redirect, url_for, flash, jsonify, g
from werkzeug.security import check_password_hash, generate_password_hash
import json
import subprocess
import sys

app = Flask(__name__)

# 配置
app.config['SECRET_KEY'] = secrets.token_hex(16)
app.config['SESSION_TYPE'] = 'filesystem'
app.config['SESSION_PERMANENT'] = False
app.config['DATABASE'] = os.path.join(os.path.dirname(__file__), 'db', 'mail.sqlite')

# 确保数据库目录存在
os.makedirs(os.path.dirname(app.config['DATABASE']), exist_ok=True)

def get_db():
    """获取数据库连接"""
    db = getattr(g, '_database', None)
    if db is None:
        db = g._database = sqlite3.connect(app.config['DATABASE'])
        db.row_factory = sqlite3.Row
    return db

def init_db():
    """初始化数据库"""
    with app.app_context():
        db = get_db()
        
        # 读取并执行初始化SQL
        init_sql_path = os.path.join(os.path.dirname(__file__), 'db', 'init.sql')
        if os.path.exists(init_sql_path):
            with open(init_sql_path, 'r', encoding='utf-8') as f:
                db.executescript(f.read())
        
        # 创建管理员用户表（兼容原有PHP版本）
        db.execute('''
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # 检查是否有默认管理员，如果没有则创建
        admin = db.execute('SELECT * FROM admin_users WHERE username = ?', ('admin',)).fetchone()
        if not admin:
            # 创建默认管理员账号（密码: admin）
            db.execute('INSERT INTO admin_users (username, password) VALUES (?, ?)', 
                      ('admin', 'admin'))  # 简单密码，生产环境应使用hash
        
        db.commit()

@app.teardown_appcontext
def close_db(exception):
    """关闭数据库连接"""
    db = getattr(g, '_database', None)
    if db is not None:
        db.close()

def get_account_count():
    """获取邮箱账号总数"""
    try:
        db = get_db()
        result = db.execute('SELECT COUNT(*) as count FROM mail_accounts').fetchone()
        return result['count'] if result else 0
    except:
        return 0

# ===============================
# 前端页面路由
# ===============================

@app.route('/')
def index():
    """前端首页 - 邮件查看"""
    return render_template('frontend/index.html')

# ===============================
# 管理员认证相关路由
# ===============================

@app.route('/admin')
@app.route('/admin/')
def admin_index():
    """管理员后台入口"""
    if session.get('admin_logged_in'):
        return redirect(url_for('admin_home'))
    return redirect(url_for('admin_login'))

@app.route('/admin/login', methods=['GET', 'POST'])
def admin_login():
    """管理员登录"""
    if session.get('admin_logged_in'):
        return redirect(url_for('admin_home'))
    
    error = ''
    
    if request.method == 'POST':
        username = request.form.get('username', '').strip()
        password = request.form.get('password', '').strip()
        
        if username and password:
            try:
                db = get_db()
                admin = db.execute('SELECT * FROM admin_users WHERE username = ?', (username,)).fetchone()
                
                # 简单密码验证（兼容原有PHP版本）
                if admin and admin['password'] == password:
                    session['admin_logged_in'] = True
                    session['admin_id'] = admin['id']
                    session['admin_username'] = admin['username']
                    return redirect(url_for('admin_home'))
                else:
                    error = '用户名或密码错误'
            except Exception as e:
                error = f'数据库连接失败：{str(e)}'
        else:
            error = '请输入用户名和密码'
    
    return render_template('admin/login.html', error=error)

@app.route('/admin/logout')
def admin_logout():
    """管理员退出登录"""
    session.clear()
    return redirect(url_for('admin_login'))

def admin_required(f):
    """管理员权限装饰器"""
    def decorated_function(*args, **kwargs):
        if not session.get('admin_logged_in'):
            return redirect(url_for('admin_login'))
        return f(*args, **kwargs)
    decorated_function.__name__ = f.__name__
    return decorated_function

# ===============================
# 管理员后台页面路由
# ===============================

@app.route('/admin/home')
@admin_required
def admin_home():
    """管理员首页"""
    account_count = get_account_count()
    return render_template('admin/home.html', 
                         admin_username=session.get('admin_username'),
                         account_count=account_count)

@app.route('/admin/mailbox')
@admin_required
def admin_mailbox():
    """邮箱管理页面"""
    return render_template('admin/mailbox.html',
                         admin_username=session.get('admin_username'))

@app.route('/admin/daili')
@admin_required
def admin_daili():
    """代理池管理页面"""
    return render_template('admin/daili.html',
                         admin_username=session.get('admin_username'))

@app.route('/admin/kami')
@admin_required
def admin_kami():
    """卡密管理页面"""
    return render_template('admin/kami.html',
                         admin_username=session.get('admin_username'))

@app.route('/admin/kamirizhi')
@admin_required
def admin_kamirizhi():
    """卡密日志页面"""
    return render_template('admin/kamirizhi.html',
                         admin_username=session.get('admin_username'))

@app.route('/admin/shoujian')
@admin_required
def admin_shoujian():
    """收件日志页面"""
    return render_template('admin/shoujian.html',
                         admin_username=session.get('admin_username'))

@app.route('/admin/system')
@admin_required
def admin_system():
    """系统设置页面"""
    return render_template('admin/system.html',
                         admin_username=session.get('admin_username'))

# ===============================
# API 接口路由
# ===============================

@app.route('/api/get_mail', methods=['POST'])
def api_get_mail():
    """获取邮件 API（简化版本 - 调用现有Python脚本）"""
    try:
        data = request.get_json()
        if not data or not data.get('email'):
            return jsonify({
                'success': False,
                'message': '请提供邮箱地址'
            })
        
        email = data['email'].strip()
        
        # 调用现有的Python邮件获取器脚本
        try:
            result = subprocess.run([
                sys.executable, 
                os.path.join(os.path.dirname(__file__), 'python', 'mail_fetcher.py'),
                email
            ], capture_output=True, text=True, timeout=30)
            
            if result.returncode == 0:
                # 解析JSON输出
                response_data = json.loads(result.stdout)
                return jsonify(response_data)
            else:
                return jsonify({
                    'success': False,
                    'message': f'邮件获取失败: {result.stderr or "未知错误"}'
                })
                
        except subprocess.TimeoutExpired:
            return jsonify({
                'success': False,
                'message': '邮件获取超时，请稍后重试'
            })
        except json.JSONDecodeError:
            return jsonify({
                'success': False,
                'message': '邮件服务响应格式错误'
            })
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'邮件服务错误: {str(e)}'
            })
            
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'服务器错误: {str(e)}'
        })

@app.route('/admin/api/mailbox', methods=['GET', 'POST', 'DELETE'])
@admin_required
def api_admin_mailbox():
    """邮箱管理 API"""
    db = get_db()
    
    if request.method == 'GET':
        # 获取邮箱列表
        accounts = db.execute('''
            SELECT * FROM mail_accounts 
            ORDER BY created_at DESC
        ''').fetchall()
        
        return jsonify({
            'success': True,
            'data': [dict(account) for account in accounts]
        })
    
    elif request.method == 'POST':
        # 添加或编辑邮箱
        data = request.get_json()
        action = data.get('action')
        
        if action == 'add':
            email = data.get('email', '').strip()
            username = email  # 使用邮箱作为用户名
            password = data.get('password', '').strip()
            server = data.get('server', '').strip()
            port = int(data.get('port', 0))
            protocol = data.get('protocol', 'imap')
            ssl = 1 if data.get('ssl') else 0
            remarks = data.get('remarks', '').strip()
            
            if not all([email, password, server, port]):
                return jsonify({
                    'success': False,
                    'message': '请填写所有必需字段'
                })
            
            try:
                # 检查邮箱是否已存在
                existing = db.execute('SELECT id FROM mail_accounts WHERE email = ?', (email,)).fetchone()
                if existing:
                    return jsonify({
                        'success': False,
                        'message': '邮箱已存在'
                    })
                
                # 插入新邮箱
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                db.execute('''
                    INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ''', (email, username, password, server, port, protocol, ssl, remarks, now, now))
                
                db.commit()
                
                return jsonify({
                    'success': True,
                    'message': '邮箱添加成功'
                })
                
            except Exception as e:
                return jsonify({
                    'success': False,
                    'message': f'添加失败: {str(e)}'
                })
        
        elif action == 'edit':
            # 编辑邮箱逻辑
            account_id = data.get('id')
            if not account_id:
                return jsonify({
                    'success': False,
                    'message': '缺少邮箱ID'
                })
            
            # 更新邮箱信息
            email = data.get('email', '').strip()
            password = data.get('password', '').strip()
            server = data.get('server', '').strip()
            port = int(data.get('port', 0))
            protocol = data.get('protocol', 'imap')
            ssl = 1 if data.get('ssl') else 0
            remarks = data.get('remarks', '').strip()
            
            try:
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                db.execute('''
                    UPDATE mail_accounts 
                    SET email=?, username=?, password=?, server=?, port=?, protocol=?, ssl=?, remarks=?, updated_at=?
                    WHERE id=?
                ''', (email, email, password, server, port, protocol, ssl, remarks, now, account_id))
                
                db.commit()
                
                return jsonify({
                    'success': True,
                    'message': '邮箱更新成功'
                })
                
            except Exception as e:
                return jsonify({
                    'success': False,
                    'message': f'更新失败: {str(e)}'
                })
    
    elif request.method == 'DELETE':
        # 删除邮箱
        data = request.get_json()
        account_id = data.get('id')
        
        if not account_id:
            return jsonify({
                'success': False,
                'message': '缺少邮箱ID'
            })
        
        try:
            db.execute('DELETE FROM mail_accounts WHERE id = ?', (account_id,))
            db.commit()
            
            return jsonify({
                'success': True,
                'message': '邮箱删除成功'
            })
            
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'删除失败: {str(e)}'
            })

@app.route('/admin/api/mailbox/batch', methods=['POST'])
@admin_required
def api_admin_mailbox_batch():
    """批量添加邮箱 API"""
    db = get_db()
    data = request.get_json()
    
    mailboxes = data.get('mailboxes', [])
    skip_existing = data.get('skipExisting', True)
    
    if not mailboxes:
        return jsonify({
            'success': False,
            'message': '没有要添加的邮箱数据'
        })
    
    added_count = 0
    skipped_count = 0
    error_count = 0
    errors = []
    
    for mailbox in mailboxes:
        try:
            email = mailbox.get('email', '').strip()
            password = mailbox.get('password', '').strip()
            server = mailbox.get('server', '').strip()
            port = int(mailbox.get('port', 0))
            protocol = mailbox.get('protocol', 'imap')
            ssl = 1 if mailbox.get('ssl') else 0
            remarks = mailbox.get('remarks', '').strip()
            
            if not all([email, password, server, port]):
                errors.append(f'邮箱 {email}: 缺少必需字段')
                error_count += 1
                continue
            
            # 检查邮箱是否已存在
            existing = db.execute('SELECT id FROM mail_accounts WHERE email = ?', (email,)).fetchone()
            if existing:
                if skip_existing:
                    skipped_count += 1
                    continue
                else:
                    errors.append(f'邮箱 {email}: 已存在')
                    error_count += 1
                    continue
            
            # 插入新邮箱
            now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            db.execute('''
                INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ''', (email, email, password, server, port, protocol, ssl, remarks, now, now))
            
            added_count += 1
            
        except Exception as e:
            errors.append(f'邮箱 {mailbox.get("email", "未知")}: {str(e)}')
            error_count += 1
    
    try:
        db.commit()
        
        message = f'批量添加完成：成功 {added_count} 个'
        if skipped_count > 0:
            message += f'，跳过 {skipped_count} 个'
        if error_count > 0:
            message += f'，失败 {error_count} 个'
        
        return jsonify({
            'success': True,
            'message': message,
            'details': {
                'added': added_count,
                'skipped': skipped_count,
                'errors': error_count,
                'error_list': errors
            }
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'批量添加失败: {str(e)}'
        })

@app.route('/admin/api/server-address', methods=['GET', 'POST', 'DELETE'])
@admin_required  
def api_admin_server_address():
    """服务器地址管理 API"""
    db = get_db()
    
    if request.method == 'GET':
        # 获取服务器地址列表
        servers = db.execute('''
            SELECT * FROM server_addresses 
            ORDER BY created_at DESC
        ''').fetchall()
        
        return jsonify({
            'success': True,
            'data': [dict(server) for server in servers]
        })
    
    elif request.method == 'POST':
        # 添加服务器地址
        data = request.get_json()
        
        server_name = data.get('serverName', '').strip()
        server_address = data.get('serverAddress', '').strip()
        default_port_imap = int(data.get('defaultPortImap', 993))
        default_port_pop3 = int(data.get('defaultPortPop3', 995))
        ssl_enabled = 1 if data.get('sslEnabled') else 0
        remarks = data.get('remarks', '').strip()
        
        if not all([server_name, server_address]):
            return jsonify({
                'success': False,
                'message': '请填写服务器名称和地址'
            })
        
        try:
            # 检查服务器是否已存在
            existing = db.execute(
                'SELECT id FROM server_addresses WHERE server_name = ? OR server_address = ?', 
                (server_name, server_address)
            ).fetchone()
            
            if existing:
                return jsonify({
                    'success': False,
                    'message': '服务器名称或地址已存在'
                })
            
            # 插入新服务器地址
            now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            db.execute('''
                INSERT INTO server_addresses (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ''', (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, now, now))
            
            db.commit()
            
            return jsonify({
                'success': True,
                'message': '服务器地址添加成功'
            })
            
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'添加失败: {str(e)}'
            })
    
    elif request.method == 'DELETE':
        # 删除服务器地址
        data = request.get_json()
        server_id = data.get('id')
        
        if not server_id:
            return jsonify({
                'success': False,
                'message': '缺少服务器ID'
            })
        
        try:
            db.execute('DELETE FROM server_addresses WHERE id = ?', (server_id,))
            db.commit()
            
            return jsonify({
                'success': True,
                'message': '服务器地址删除成功'
            })
            
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'删除失败: {str(e)}'
            })

if __name__ == '__main__':
    # 初始化数据库
    with app.app_context():
        init_db()
    
    # 启动应用
    app.run(debug=True, host='0.0.0.0', port=5000)