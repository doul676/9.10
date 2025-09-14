#!/usr/bin/env python3
"""
邮件查看系统 - Flask 应用主文件（完整增强版）
基于原有 PHP 版本完全重构，保持所有功能和 UI 一致
支持多数据库、完整的邮箱管理、代理池、卡密系统等功能
"""

import os
import sqlite3
import secrets
import json
import subprocess
import sys
import time
import requests
import threading
from datetime import datetime, timezone
from flask import Flask, render_template, request, session, redirect, url_for, flash, jsonify, g
from werkzeug.security import check_password_hash, generate_password_hash

app = Flask(__name__)

# 配置
app.config['SECRET_KEY'] = secrets.token_hex(16)
app.config['SESSION_TYPE'] = 'filesystem'
app.config['SESSION_PERMANENT'] = False
app.config['DATABASE'] = os.path.join(os.path.dirname(__file__), 'db', 'mail.sqlite')
app.config['DATABASE_TYPE'] = os.environ.get('DATABASE_TYPE', 'sqlite')  # sqlite, mysql, postgresql

# 确保数据库目录存在
os.makedirs(os.path.dirname(app.config['DATABASE']), exist_ok=True)

def get_db():
    """获取数据库连接（支持多数据库）"""
    db = getattr(g, '_database', None)
    if db is None:
        db_type = app.config['DATABASE_TYPE']
        
        if db_type == 'sqlite':
            db = g._database = sqlite3.connect(app.config['DATABASE'])
            db.row_factory = sqlite3.Row
        elif db_type == 'mysql':
            # MySQL连接（需要安装 mysql-connector-python）
            import mysql.connector
            db = g._database = mysql.connector.connect(
                host=os.environ.get('MYSQL_HOST', 'localhost'),
                user=os.environ.get('MYSQL_USER', 'root'),
                password=os.environ.get('MYSQL_PASSWORD', ''),
                database=os.environ.get('MYSQL_DATABASE', 'mail_system')
            )
        elif db_type == 'postgresql':
            # PostgreSQL连接（需要安装 psycopg2-binary）
            import psycopg2
            from psycopg2.extras import RealDictCursor
            db = g._database = psycopg2.connect(
                host=os.environ.get('POSTGRES_HOST', 'localhost'),
                user=os.environ.get('POSTGRES_USER', 'postgres'),
                password=os.environ.get('POSTGRES_PASSWORD', ''),
                database=os.environ.get('POSTGRES_DATABASE', 'mail_system'),
                cursor_factory=RealDictCursor
            )
    return db

def init_db():
    """初始化数据库（支持多数据库）"""
    with app.app_context():
        db = get_db()
        db_type = app.config['DATABASE_TYPE']
        
        # 读取并执行初始化SQL
        init_sql_path = os.path.join(os.path.dirname(__file__), 'db', 'init.sql')
        if os.path.exists(init_sql_path):
            with open(init_sql_path, 'r', encoding='utf-8') as f:
                sql_content = f.read()
                
                # 根据数据库类型调整SQL语句
                if db_type == 'mysql':
                    sql_content = sql_content.replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY')
                    sql_content = sql_content.replace('DATETIME DEFAULT CURRENT_TIMESTAMP', 'DATETIME DEFAULT CURRENT_TIMESTAMP')
                elif db_type == 'postgresql':
                    sql_content = sql_content.replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'SERIAL PRIMARY KEY')
                    sql_content = sql_content.replace('DATETIME', 'TIMESTAMP')
                
                # 执行SQL
                if db_type == 'sqlite':
                    db.executescript(sql_content)
                else:
                    cursor = db.cursor()
                    for statement in sql_content.split(';'):
                        if statement.strip():
                            cursor.execute(statement)
                    db.commit()
        
        # 创建管理员用户表（兼容原有PHP版本）
        if db_type == 'sqlite':
            db.execute('''
                CREATE TABLE IF NOT EXISTS admin_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ''')
        elif db_type == 'mysql':
            cursor = db.cursor()
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS admin_users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(255) NOT NULL UNIQUE,
                    password TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ''')
        elif db_type == 'postgresql':
            cursor = db.cursor()
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS admin_users (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(255) NOT NULL UNIQUE,
                    password TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
        
        # 检查是否有默认管理员，如果没有则创建
        if db_type == 'sqlite':
            admin = db.execute('SELECT * FROM admin_users WHERE username = ?', ('admin',)).fetchone()
            if not admin:
                db.execute('INSERT INTO admin_users (username, password) VALUES (?, ?)', 
                          ('admin', 'admin'))  # 简单密码，生产环境应使用hash
        else:
            cursor = db.cursor()
            cursor.execute('SELECT * FROM admin_users WHERE username = %s', ('admin',))
            admin = cursor.fetchone()
            if not admin:
                cursor.execute('INSERT INTO admin_users (username, password) VALUES (%s, %s)', 
                              ('admin', 'admin'))
        
        if db_type != 'sqlite':
            db.commit()
        else:
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
    """邮箱管理 API（增强版）"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    if request.method == 'GET':
        # 获取邮箱列表（支持分页和搜索）
        page = int(request.args.get('page', 1))
        per_page = int(request.args.get('per_page', 10))
        search = request.args.get('search', '').strip()
        
        offset = (page - 1) * per_page
        
        # 构建查询条件
        where_clause = ""
        params = []
        if search:
            where_clause = "WHERE email LIKE ? OR server LIKE ? OR remarks LIKE ?"
            search_param = f"%{search}%"
            params = [search_param, search_param, search_param]
        
        # 获取总数
        if db_type == 'sqlite':
            count_sql = f"SELECT COUNT(*) as count FROM mail_accounts {where_clause}"
            count_result = db.execute(count_sql, params).fetchone()
            total = count_result['count']
            
            # 获取分页数据
            sql = f"""
                SELECT * FROM mail_accounts {where_clause}
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            """
            accounts = db.execute(sql, params + [per_page, offset]).fetchall()
        else:
            cursor = db.cursor()
            if db_type == 'mysql':
                placeholder = '%s'
            else:  # postgresql
                placeholder = '%s'
            
            where_mysql = where_clause.replace('?', placeholder) if where_clause else ""
            count_sql = f"SELECT COUNT(*) as count FROM mail_accounts {where_mysql}"
            cursor.execute(count_sql, params)
            total = cursor.fetchone()['count'] if db_type == 'postgresql' else cursor.fetchone()[0]
            
            sql = f"""
                SELECT * FROM mail_accounts {where_mysql}
                ORDER BY created_at DESC 
                LIMIT {per_page} OFFSET {offset}
            """
            cursor.execute(sql, params)
            accounts = cursor.fetchall()
        
        return jsonify({
            'success': True,
            'data': [dict(account) for account in accounts],
            'pagination': {
                'page': page,
                'per_page': per_page,
                'total': total,
                'pages': (total + per_page - 1) // per_page
            }
        })
    
    elif request.method == 'POST':
        # 添加或编辑邮箱
        data = request.get_json()
        action = data.get('action')
        
        if action == 'add':
            return _add_mailbox(db, data)
        elif action == 'batch_add':
            return _batch_add_mailbox(db, data)
        elif action == 'edit':
            return _edit_mailbox(db, data)
        elif action == 'test':
            return _test_mailbox(db, data)
        elif action == 'test_new':
            return _test_new_mailbox(data)
        elif action == 'batch_delete':
            return _batch_delete_mailbox(db, data)
    
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
            if app.config['DATABASE_TYPE'] == 'sqlite':
                db.execute('DELETE FROM mail_accounts WHERE id = ?', (account_id,))
                db.commit()
            else:
                cursor = db.cursor()
                cursor.execute('DELETE FROM mail_accounts WHERE id = %s', (account_id,))
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

def _add_mailbox(db, data):
    """添加单个邮箱"""
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
        if app.config['DATABASE_TYPE'] == 'sqlite':
            existing = db.execute('SELECT id FROM mail_accounts WHERE email = ?', (email,)).fetchone()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT id FROM mail_accounts WHERE email = %s', (email,))
            existing = cursor.fetchone()
            
        if existing:
            return jsonify({
                'success': False,
                'message': '邮箱已存在'
            })
        
        # 插入新邮箱
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute('''
                INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ''', (email, username, password, server, port, protocol, ssl, remarks, now, now))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute('''
                INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
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

def _batch_add_mailbox(db, data):
    """批量添加邮箱"""
    batch_content = data.get('batch_content', '').strip()
    server = data.get('server', '').strip()
    port = int(data.get('port', 0))
    protocol = data.get('protocol', 'imap')
    ssl = 1 if data.get('ssl') else 0
    remarks = data.get('remarks', '').strip()
    
    if not batch_content or not server or not port:
        return jsonify({
            'success': False,
            'message': '请填写批量内容和服务器信息'
        })
    
    # 解析批量内容（格式：账号----密码）
    lines = batch_content.split('\n')
    success_count = 0
    error_count = 0
    errors = []
    
    for line in lines:
        line = line.strip()
        if not line:
            continue
            
        if '----' not in line:
            error_count += 1
            errors.append(f'格式错误：{line}')
            continue
        
        try:
            email, password = line.split('----', 1)
            email = email.strip()
            password = password.strip()
            
            if not email or not password:
                error_count += 1
                errors.append(f'账号或密码为空：{line}')
                continue
            
            # 检查邮箱是否已存在
            if app.config['DATABASE_TYPE'] == 'sqlite':
                existing = db.execute('SELECT id FROM mail_accounts WHERE email = ?', (email,)).fetchone()
            else:
                cursor = db.cursor()
                cursor.execute('SELECT id FROM mail_accounts WHERE email = %s', (email,))
                existing = cursor.fetchone()
                
            if existing:
                error_count += 1
                errors.append(f'邮箱已存在：{email}')
                continue
            
            # 插入邮箱
            now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            if app.config['DATABASE_TYPE'] == 'sqlite':
                db.execute('''
                    INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ''', (email, email, password, server, port, protocol, ssl, remarks, now, now))
            else:
                cursor = db.cursor()
                cursor.execute('''
                    INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ''', (email, email, password, server, port, protocol, ssl, remarks, now, now))
            
            success_count += 1
            
        except Exception as e:
            error_count += 1
            errors.append(f'处理失败：{line} - {str(e)}')
    
    try:
        db.commit()
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'数据库提交失败: {str(e)}'
        })
    
    message = f'批量添加完成：成功 {success_count} 个，失败 {error_count} 个'
    if errors:
        message += f'\n错误详情：\n' + '\n'.join(errors[:10])  # 只显示前10个错误
    
    return jsonify({
        'success': True,
        'message': message,
        'details': {
            'success_count': success_count,
            'error_count': error_count,
            'errors': errors
        }
    })

def _edit_mailbox(db, data):
    """编辑邮箱"""
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
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute('''
                UPDATE mail_accounts 
                SET email=?, username=?, password=?, server=?, port=?, protocol=?, ssl=?, remarks=?, updated_at=?
                WHERE id=?
            ''', (email, email, password, server, port, protocol, ssl, remarks, now, account_id))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute('''
                UPDATE mail_accounts 
                SET email=%s, username=%s, password=%s, server=%s, port=%s, protocol=%s, ssl=%s, remarks=%s, updated_at=%s
                WHERE id=%s
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

def _test_mailbox(db, data):
    """测试邮箱连接"""
    account_id = data.get('id')
    
    try:
        # 获取邮箱信息
        if app.config['DATABASE_TYPE'] == 'sqlite':
            account = db.execute('SELECT * FROM mail_accounts WHERE id = ?', (account_id,)).fetchone()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT * FROM mail_accounts WHERE id = %s', (account_id,))
            account = cursor.fetchone()
        
        if not account:
            return jsonify({
                'success': False,
                'message': '邮箱不存在'
            })
        
        # 调用实际的邮箱连接测试
        try:
            result = subprocess.run([
                sys.executable, 
                os.path.join(os.path.dirname(__file__), 'python', 'mail_fetcher.py'),
                account['email'] if app.config['DATABASE_TYPE'] == 'sqlite' else account[1],
                '--test-connection'
            ], capture_output=True, text=True, timeout=30)
            
            if result.returncode == 0:
                # 解析JSON输出
                response_data = json.loads(result.stdout)
                test_success = response_data.get('success', False)
                test_result = response_data.get('message', '测试完成')
                
                # 如果有诊断信息，添加到结果中
                if 'diagnostics' in response_data:
                    diagnostics = response_data['diagnostics']
                    test_result += f"\n详细信息: {diagnostics.get('connection_test', '')}"
                    if 'proxy_status' in diagnostics:
                        test_result += f"\n代理状态: {diagnostics['proxy_status']}"
            else:
                test_success = False
                test_result = f'测试失败: {result.stderr or "未知错误"}'
                
        except subprocess.TimeoutExpired:
            test_success = False
            test_result = '邮箱连接测试超时，请稍后重试'
        except json.JSONDecodeError:
            test_success = False
            test_result = '邮箱服务响应格式错误'
        except Exception as e:
            test_success = False
            test_result = f'邮箱测试错误: {str(e)}'
        
        # 更新测试结果
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute('''
                UPDATE mail_accounts 
                SET last_test=?, test_result=?
                WHERE id=?
            ''', (now, test_result, account_id))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute('''
                UPDATE mail_accounts 
                SET last_test=%s, test_result=%s
                WHERE id=%s
            ''', (now, test_result, account_id))
            db.commit()
        
        return jsonify({
            'success': test_success,
            'message': test_result
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'测试失败: {str(e)}'
        })

def _test_new_mailbox(data):
    """测试新邮箱连接（无需保存到数据库）"""
    email = data.get('email', '').strip()
    password = data.get('password', '').strip()
    server = data.get('server', '').strip()
    port = int(data.get('port', 0))
    protocol = data.get('protocol', 'imap')
    ssl = data.get('ssl', True)
    
    if not all([email, password, server, port]):
        return jsonify({
            'success': False,
            'message': '请填写完整的邮箱信息'
        })
    
    try:
        # 创建临时邮箱配置文件进行测试
        import tempfile
        import json
        
        # 创建临时配置用于测试
        temp_data = {
            'email': email,
            'username': email,
            'password': password,
            'server': server,
            'port': port,
            'protocol': protocol,
            'ssl': 1 if ssl else 0
        }
        
        # 创建临时文件
        with tempfile.NamedTemporaryFile(mode='w', suffix='.json', delete=False) as temp_file:
            json.dump(temp_data, temp_file)
            temp_file_path = temp_file.name
        
        try:
            # 使用Python脚本直接测试
            import os
            from python.mail_fetcher import ProxyMailFetcher
            
            fetcher = ProxyMailFetcher(
                server, port, email, password, protocol, ssl
            )
            
            result = fetcher.test_connection()
            test_success = result.get('success', False)
            test_result = result.get('message', '测试完成')
            
            # 添加代理信息
            if 'diagnostics' in result:
                diagnostics = result['diagnostics']
                if 'proxy_status' in diagnostics:
                    test_result += f"\n代理状态: {diagnostics['proxy_status']}"
            
            return jsonify({
                'success': test_success,
                'message': test_result
            })
            
        finally:
            # 清理临时文件
            try:
                os.unlink(temp_file_path)
            except:
                pass
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'测试失败: {str(e)}'
        })

def _batch_delete_mailbox(db, data):
    """批量删除邮箱"""
    account_ids = data.get('ids', [])
    
    if not account_ids:
        return jsonify({
            'success': False,
            'message': '请选择要删除的邮箱'
        })
    
    try:
        if app.config['DATABASE_TYPE'] == 'sqlite':
            placeholders = ','.join(['?' for _ in account_ids])
            db.execute(f'DELETE FROM mail_accounts WHERE id IN ({placeholders})', account_ids)
            db.commit()
        else:
            cursor = db.cursor()
            placeholders = ','.join(['%s' for _ in account_ids])
            cursor.execute(f'DELETE FROM mail_accounts WHERE id IN ({placeholders})', account_ids)
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'成功删除 {len(account_ids)} 个邮箱'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'批量删除失败: {str(e)}'
        })

@app.route('/admin/api/servers', methods=['GET', 'POST', 'DELETE'])
@admin_required
def api_admin_servers():
    """服务器地址管理 API"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    if request.method == 'GET':
        # 获取服务器列表
        if db_type == 'sqlite':
            servers = db.execute('SELECT * FROM server_addresses ORDER BY created_at DESC').fetchall()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT * FROM server_addresses ORDER BY created_at DESC')
            servers = cursor.fetchall()
        
        return jsonify({
            'success': True,
            'data': [dict(server) for server in servers]
        })
    
    elif request.method == 'POST':
        data = request.get_json()
        action = data.get('action')
        
        if action == 'add':
            server_name = data.get('server_name', '').strip()
            server_address = data.get('server_address', '').strip()
            default_port_imap = int(data.get('default_port_imap', 993))
            default_port_pop3 = int(data.get('default_port_pop3', 995))
            ssl_enabled = 1 if data.get('ssl_enabled') else 0
            remarks = data.get('remarks', '').strip()
            
            if not all([server_name, server_address]):
                return jsonify({
                    'success': False,
                    'message': '请填写服务器名称和地址'
                })
            
            try:
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                if db_type == 'sqlite':
                    db.execute('''
                        INSERT INTO server_addresses (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ''', (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, now, now))
                    db.commit()
                else:
                    cursor = db.cursor()
                    cursor.execute('''
                        INSERT INTO server_addresses (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, created_at, updated_at)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    ''', (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, now, now))
                    db.commit()
                
                return jsonify({
                    'success': True,
                    'message': '服务器添加成功'
                })
                
            except Exception as e:
                return jsonify({
                    'success': False,
                    'message': f'添加失败: {str(e)}'
                })
        
        elif action == 'edit':
            server_id = data.get('id')
            if not server_id:
                return jsonify({
                    'success': False,
                    'message': '缺少服务器ID'
                })
            
            server_name = data.get('server_name', '').strip()
            server_address = data.get('server_address', '').strip()
            default_port_imap = int(data.get('default_port_imap', 993))
            default_port_pop3 = int(data.get('default_port_pop3', 995))
            ssl_enabled = 1 if data.get('ssl_enabled') else 0
            remarks = data.get('remarks', '').strip()
            
            try:
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                if db_type == 'sqlite':
                    db.execute('''
                        UPDATE server_addresses 
                        SET server_name=?, server_address=?, default_port_imap=?, default_port_pop3=?, ssl_enabled=?, remarks=?, updated_at=?
                        WHERE id=?
                    ''', (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, now, server_id))
                    db.commit()
                else:
                    cursor = db.cursor()
                    cursor.execute('''
                        UPDATE server_addresses 
                        SET server_name=%s, server_address=%s, default_port_imap=%s, default_port_pop3=%s, ssl_enabled=%s, remarks=%s, updated_at=%s
                        WHERE id=%s
                    ''', (server_name, server_address, default_port_imap, default_port_pop3, ssl_enabled, remarks, now, server_id))
                    db.commit()
                
                return jsonify({
                    'success': True,
                    'message': '服务器更新成功'
                })
                
            except Exception as e:
                return jsonify({
                    'success': False,
                    'message': f'更新失败: {str(e)}'
                })
    
    elif request.method == 'DELETE':
        data = request.get_json()
        server_ids = data.get('ids', [])
        
        if not server_ids:
            return jsonify({
                'success': False,
                'message': '请选择要删除的服务器'
            })
        
        try:
            if db_type == 'sqlite':
                placeholders = ','.join(['?' for _ in server_ids])
                db.execute(f'DELETE FROM server_addresses WHERE id IN ({placeholders})', server_ids)
                db.commit()
            else:
                cursor = db.cursor()
                placeholders = ','.join(['%s' for _ in server_ids])
                cursor.execute(f'DELETE FROM server_addresses WHERE id IN ({placeholders})', server_ids)
                db.commit()
            
            return jsonify({
                'success': True,
                'message': f'成功删除 {len(server_ids)} 个服务器'
            })
            
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'删除失败: {str(e)}'
            })

@app.route('/admin/api/proxies/<proxy_type>', methods=['GET', 'POST', 'DELETE'])
@admin_required
def api_admin_proxies(proxy_type):
    """代理管理 API"""
    if proxy_type not in ['http', 'socks5']:
        return jsonify({
            'success': False,
            'message': '无效的代理类型'
        })
    
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    table_name = f'{proxy_type}_proxies'
    
    if request.method == 'GET':
        # 获取代理列表（支持分页和搜索）
        page = int(request.args.get('page', 1))
        per_page = int(request.args.get('per_page', 10))
        search = request.args.get('search', '').strip()
        
        offset = (page - 1) * per_page
        
        # 构建查询条件
        where_clause = ""
        params = []
        if search:
            where_clause = "WHERE name LIKE ? OR host LIKE ? OR remarks LIKE ?"
            search_param = f"%{search}%"
            params = [search_param, search_param, search_param]
        
        # 获取总数和数据
        if db_type == 'sqlite':
            count_sql = f"SELECT COUNT(*) as count FROM {table_name} {where_clause}"
            count_result = db.execute(count_sql, params).fetchone()
            total = count_result['count']
            
            sql = f"""
                SELECT * FROM {table_name} {where_clause}
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?
            """
            proxies = db.execute(sql, params + [per_page, offset]).fetchall()
        else:
            cursor = db.cursor()
            placeholder = '%s'
            where_mysql = where_clause.replace('?', placeholder) if where_clause else ""
            
            count_sql = f"SELECT COUNT(*) as count FROM {table_name} {where_mysql}"
            cursor.execute(count_sql, params)
            total = cursor.fetchone()['count'] if db_type == 'postgresql' else cursor.fetchone()[0]
            
            sql = f"""
                SELECT * FROM {table_name} {where_mysql}
                ORDER BY created_at DESC 
                LIMIT {per_page} OFFSET {offset}
            """
            cursor.execute(sql, params)
            proxies = cursor.fetchall()
        
        return jsonify({
            'success': True,
            'data': [dict(proxy) for proxy in proxies],
            'pagination': {
                'page': page,
                'per_page': per_page,
                'total': total,
                'pages': (total + per_page - 1) // per_page
            }
        })
    
    elif request.method == 'POST':
        data = request.get_json()
        action = data.get('action')
        
        if action == 'add':
            return _add_proxy(db, table_name, data, proxy_type)
        elif action == 'edit':
            return _edit_proxy(db, table_name, data)
        elif action == 'test':
            return _test_proxy(db, table_name, data, proxy_type)
        elif action == 'test_new':
            return _test_new_proxy(data, proxy_type)
        elif action == 'batch_delete':
            return _batch_delete_proxy(db, table_name, data)
    
    elif request.method == 'DELETE':
        data = request.get_json()
        proxy_id = data.get('id')
        
        if not proxy_id:
            return jsonify({
                'success': False,
                'message': '缺少代理ID'
            })
        
        try:
            if db_type == 'sqlite':
                db.execute(f'DELETE FROM {table_name} WHERE id = ?', (proxy_id,))
                db.commit()
            else:
                cursor = db.cursor()
                cursor.execute(f'DELETE FROM {table_name} WHERE id = %s', (proxy_id,))
                db.commit()
            
            return jsonify({
                'success': True,
                'message': '代理删除成功'
            })
            
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'删除失败: {str(e)}'
            })

def _add_proxy(db, table_name, data, proxy_type):
    """添加代理"""
    name = data.get('name', '').strip()
    host = data.get('host', '').strip()
    port = int(data.get('port', 0))
    username = data.get('username', '').strip()
    password = data.get('password', '').strip()
    remarks = data.get('remarks', '').strip()
    
    if not all([host, port]):
        return jsonify({
            'success': False,
            'message': '请填写代理地址和端口'
        })
    
    # 如果没有提供名称，自动生成
    if not name:
        db_type = app.config['DATABASE_TYPE']
        if db_type == 'sqlite':
            count_result = db.execute(f"SELECT COUNT(*) as count FROM {table_name} WHERE name LIKE '未命名%'").fetchone()
            count = count_result['count']
        else:
            cursor = db.cursor()
            cursor.execute(f"SELECT COUNT(*) FROM {table_name} WHERE name LIKE '未命名%'")
            count = cursor.fetchone()['count'] if db_type == 'postgresql' else cursor.fetchone()[0]
        
        name = f"未命名{count + 1}"
    
    try:
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute(f'''
                INSERT INTO {table_name} (name, host, port, username, password, remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ''', (name, host, port, username, password, remarks, now, now))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute(f'''
                INSERT INTO {table_name} (name, host, port, username, password, remarks, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ''', (name, host, port, username, password, remarks, now, now))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'{proxy_type.upper()}代理添加成功'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'添加失败: {str(e)}'
        })

def _edit_proxy(db, table_name, data):
    """编辑代理"""
    proxy_id = data.get('id')
    if not proxy_id:
        return jsonify({
            'success': False,
            'message': '缺少代理ID'
        })
    
    name = data.get('name', '').strip()
    host = data.get('host', '').strip()
    port = int(data.get('port', 0))
    username = data.get('username', '').strip()
    password = data.get('password', '').strip()
    remarks = data.get('remarks', '').strip()
    
    try:
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute(f'''
                UPDATE {table_name}
                SET name=?, host=?, port=?, username=?, password=?, remarks=?, updated_at=?
                WHERE id=?
            ''', (name, host, port, username, password, remarks, now, proxy_id))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute(f'''
                UPDATE {table_name}
                SET name=%s, host=%s, port=%s, username=%s, password=%s, remarks=%s, updated_at=%s
                WHERE id=%s
            ''', (name, host, port, username, password, remarks, now, proxy_id))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': '代理更新成功'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'更新失败: {str(e)}'
        })

def _test_proxy(db, table_name, data, proxy_type):
    """测试代理"""
    proxy_id = data.get('id')
    
    try:
        # 获取代理信息
        if app.config['DATABASE_TYPE'] == 'sqlite':
            proxy = db.execute(f'SELECT * FROM {table_name} WHERE id = ?', (proxy_id,)).fetchone()
        else:
            cursor = db.cursor()
            cursor.execute(f'SELECT * FROM {table_name} WHERE id = %s', (proxy_id,))
            proxy = cursor.fetchone()
        
        if not proxy:
            return jsonify({
                'success': False,
                'message': '代理不存在'
            })
        
        # 测试代理连接
        test_results = _perform_proxy_test(proxy, proxy_type)
        
        # 更新测试结果
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        response_time = test_results.get('avg_response_time', 0)
        
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute(f'''
                UPDATE {table_name}
                SET last_check=?, response_time=?, status=?
                WHERE id=?
            ''', (now, response_time, 1 if test_results['success'] else 0, proxy_id))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute(f'''
                UPDATE {table_name}
                SET last_check=%s, response_time=%s, status=%s
                WHERE id=%s
            ''', (now, response_time, 1 if test_results['success'] else 0, proxy_id))
            db.commit()
        
        return jsonify({
            'success': test_results['success'],
            'message': test_results['message'],
            'details': test_results
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'测试失败: {str(e)}'
        })

def _test_new_proxy(data, proxy_type):
    """测试新代理（无需保存到数据库）"""
    host = data.get('host', '').strip()
    port = int(data.get('port', 0))
    username = data.get('username', '').strip()
    password = data.get('password', '').strip()
    name = data.get('name', '').strip() or f"临时代理"
    
    if not all([host, port]):
        return jsonify({
            'success': False,
            'message': '请填写代理地址和端口'
        })
    
    try:
        # Create temporary proxy dict for testing
        proxy_dict = {
            'host': host,
            'port': port,
            'username': username or None,
            'password': password or None,
            'name': name
        }
        
        # Test proxy connection
        test_results = _perform_proxy_test(proxy_dict, proxy_type)
        
        return jsonify({
            'success': test_results['success'],
            'message': test_results['message'],
            'details': test_results
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'测试失败: {str(e)}'
        })

def _perform_proxy_test(proxy, proxy_type):
    """执行代理测试"""
    try:
        host = proxy['host']
        port = proxy['port']
        username = proxy['username'] or None
        password = proxy['password'] or None
        
        # 测试目标 - 优先测试baidu.com，163.com作为辅助测试
        test_urls = ['http://baidu.com', 'http://163.com']
        results = []
        
        for url in test_urls:
            start_time = time.time()
            try:
                if proxy_type == 'http':
                    proxies = {
                        'http': f'http://{username}:{password}@{host}:{port}' if username else f'http://{host}:{port}',
                        'https': f'http://{username}:{password}@{host}:{port}' if username else f'http://{host}:{port}'
                    }
                else:  # socks5
                    proxies = {
                        'http': f'socks5://{username}:{password}@{host}:{port}' if username else f'socks5://{host}:{port}',
                        'https': f'socks5://{username}:{password}@{host}:{port}' if username else f'socks5://{host}:{port}'
                    }
                
                response = requests.get(url, proxies=proxies, timeout=10)
                response_time = int((time.time() - start_time) * 1000)
                
                if response.status_code == 200:
                    results.append({
                        'url': url,
                        'success': True,
                        'response_time': response_time,
                        'ip': 'Unknown'  # 这里可以通过其他方式获取真实IP
                    })
                elif response.status_code == 403 and '163.com' in url:
                    # 163.com的403错误视为网站限制，不算失败
                    results.append({
                        'url': url,
                        'success': True,  # 标记为成功，因为代理工作正常
                        'response_time': response_time,
                        'error': '网站限制(403) - 代理工作正常'
                    })
                else:
                    results.append({
                        'url': url,
                        'success': False,
                        'response_time': response_time,
                        'error': f'HTTP {response.status_code}'
                    })
                    
            except Exception as e:
                error_msg = str(e)
                # 对于163.com的连接错误，给出更友好的提示
                if '163.com' in url and ('403' in error_msg or 'Forbidden' in error_msg):
                    results.append({
                        'url': url,
                        'success': True,
                        'response_time': int((time.time() - start_time) * 1000),
                        'error': '网站限制 - 代理工作正常'
                    })
                else:
                    results.append({
                        'url': url,
                        'success': False,
                        'response_time': int((time.time() - start_time) * 1000),
                        'error': error_msg
                    })
        
        # 计算平均响应时间（优先考虑baidu.com的结果）
        successful_tests = [r for r in results if r['success']]
        baidu_success = [r for r in results if r['success'] and 'baidu.com' in r['url']]
        
        if baidu_success:
            # 如果baidu.com成功，优先使用其结果
            avg_response_time = sum(r['response_time'] for r in baidu_success) // len(baidu_success)
            message = f"测试成功，平均延迟: {avg_response_time}ms"
            if len(successful_tests) > 1:
                message += f"，成功: {len(successful_tests)}/{len(results)}"
            return {
                'success': True,
                'message': message,
                'avg_response_time': avg_response_time,
                'results': results
            }
        elif successful_tests:
            # 如果只有其他网站成功
            avg_response_time = sum(r['response_time'] for r in successful_tests) // len(successful_tests)
            message = f"测试成功，平均延迟: {avg_response_time}ms"
            if len(successful_tests) > 1:
                message += f"，成功: {len(successful_tests)}/{len(results)}"
            return {
                'success': True,
                'message': message,
                'avg_response_time': avg_response_time,
                'results': results
            }
        else:
            return {
                'success': False,
                'message': '所有测试都失败了',
                'avg_response_time': 0,
                'results': results
            }
            
    except Exception as e:
        return {
            'success': False,
            'message': f'测试异常: {str(e)}',
            'avg_response_time': 0,
            'results': []
        }

def _batch_delete_proxy(db, table_name, data):
    """批量删除代理"""
    proxy_ids = data.get('ids', [])
    
    if not proxy_ids:
        return jsonify({
            'success': False,
            'message': '请选择要删除的代理'
        })
    
    try:
        if app.config['DATABASE_TYPE'] == 'sqlite':
            placeholders = ','.join(['?' for _ in proxy_ids])
            db.execute(f'DELETE FROM {table_name} WHERE id IN ({placeholders})', proxy_ids)
            db.commit()
        else:
            cursor = db.cursor()
            placeholders = ','.join(['%s' for _ in proxy_ids])
            cursor.execute(f'DELETE FROM {table_name} WHERE id IN ({placeholders})', proxy_ids)
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'成功删除 {len(proxy_ids)} 个代理'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'批量删除失败: {str(e)}'
        })

@app.route('/admin/api/proxy-unified', methods=['GET'])
@admin_required
def api_unified_proxies():
    """统一代理列表 API - 获取按顺序编号的所有代理"""
    try:
        db = get_db()
        db_type = app.config['DATABASE_TYPE']
        
        # 获取所有HTTP代理
        if db_type == 'sqlite':
            http_proxies = db.execute('SELECT *, "http" as proxy_type FROM http_proxies ORDER BY created_at ASC').fetchall()
            socks5_proxies = db.execute('SELECT *, "socks5" as proxy_type FROM socks5_proxies ORDER BY created_at ASC').fetchall()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT *, \'http\' as proxy_type FROM http_proxies ORDER BY created_at ASC')
            http_proxies = cursor.fetchall()
            cursor.execute('SELECT *, \'socks5\' as proxy_type FROM socks5_proxies ORDER BY created_at ASC')
            socks5_proxies = cursor.fetchall()
        
        # 合并并按创建时间排序（为了统一编号）
        all_proxies = []
        
        # 转换为字典列表
        for proxy in http_proxies:
            if db_type == 'sqlite':
                proxy_dict = dict(proxy)
            else:
                columns = [desc[0] for desc in cursor.description]
                proxy_dict = dict(zip(columns, proxy))
            all_proxies.append(proxy_dict)
            
        for proxy in socks5_proxies:
            if db_type == 'sqlite':
                proxy_dict = dict(proxy)
            else:
                columns = [desc[0] for desc in cursor.description]
                proxy_dict = dict(zip(columns, proxy))
            all_proxies.append(proxy_dict)
        
        # 按创建时间排序以确保统一的顺序编号
        all_proxies.sort(key=lambda x: x['created_at'])
        
        # 分配统一序号ID（从1开始）
        for index, proxy in enumerate(all_proxies, 1):
            proxy['unified_id'] = index
        
        return jsonify({
            'success': True,
            'data': all_proxies
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'获取代理列表失败: {str(e)}'
        })

@app.route('/admin/api/proxy-config', methods=['GET', 'POST'])
@admin_required
def api_proxy_config():
    """代理配置管理 API"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    if request.method == 'GET':
        # 获取当前代理配置
        try:
            if db_type == 'sqlite':
                config_data = db.execute('SELECT config_key, config_value FROM proxy_config').fetchall()
            else:
                cursor = db.cursor()
                cursor.execute('SELECT config_key, config_value FROM proxy_config')
                config_data = cursor.fetchall()
            
            config = {}
            for row in config_data:
                if db_type == 'sqlite':
                    config[row['config_key']] = row['config_value']
                else:
                    config[row[0]] = row[1]
            
            return jsonify({
                'success': True,
                'config': config
            })
            
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'获取代理配置失败: {str(e)}'
            })
    
    elif request.method == 'POST':
        # 更新代理配置
        data = request.get_json()
        action = data.get('action')
        
        if action == 'enable_proxy':
            return _enable_proxy(db, data)
        elif action == 'disable_proxy':
            return _disable_proxy(db)
        elif action == 'set_active_proxy':
            return _set_active_proxy(db, data)
        else:
            return jsonify({
                'success': False,
                'message': '无效的操作'
            })

def _enable_proxy(db, data):
    """启用代理功能 - 自动选择ID=1的代理，失效则顺延"""
    try:
        # 获取统一排序的代理列表
        response = api_unified_proxies()
        response_data = response.get_json()
        
        if not response_data.get('success'):
            return jsonify({
                'success': False,
                'message': '无法获取代理列表'
            })
        
        all_proxies = response_data.get('data', [])
        
        if not all_proxies:
            return jsonify({
                'success': False,
                'message': '没有可用的代理配置，请先添加代理'
            })
        
        # 寻找可用的代理（从ID=1开始）
        selected_proxy = None
        for proxy in all_proxies:
            if proxy.get('status') == 1:  # 状态为可用
                selected_proxy = proxy
                break
        
        if not selected_proxy:
            return jsonify({
                'success': False,
                'message': '没有可用的代理，请检查代理状态或添加新的代理'
            })
        
        # 更新代理配置
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        db_type = app.config['DATABASE_TYPE']
        
        config_updates = [
            ('proxy_enabled', '1'),
            ('active_proxy_type', selected_proxy['proxy_type']),
            ('active_proxy_id', str(selected_proxy['id']))
        ]
        
        if db_type == 'sqlite':
            for key, value in config_updates:
                db.execute('''
                    INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                    VALUES (?, ?, ?)
                ''', (key, value, now))
            db.commit()
        else:
            cursor = db.cursor()
            for key, value in config_updates:
                cursor.execute('''
                    INSERT INTO proxy_config (config_key, config_value, updated_at)
                    VALUES (%s, %s, %s)
                    ON DUPLICATE KEY UPDATE config_value = %s, updated_at = %s
                ''', (key, value, now, value, now))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'代理已启用，当前使用: {selected_proxy["name"]} (序号ID: {selected_proxy["unified_id"]})',
            'active_proxy': {
                'unified_id': selected_proxy['unified_id'],
                'name': selected_proxy['name'],
                'type': selected_proxy['proxy_type'],
                'host': selected_proxy['host'],
                'port': selected_proxy['port']
            }
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'启用代理失败: {str(e)}'
        })

def _disable_proxy(db):
    """禁用代理功能"""
    try:
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        db_type = app.config['DATABASE_TYPE']
        
        if db_type == 'sqlite':
            db.execute('''
                INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                VALUES ('proxy_enabled', '0', ?)
            ''', (now,))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute('''
                INSERT INTO proxy_config (config_key, config_value, updated_at)
                VALUES ('proxy_enabled', '0', %s)
                ON DUPLICATE KEY UPDATE config_value = '0', updated_at = %s
            ''', (now, now))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': '代理已禁用，所有连接将使用直连模式'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'禁用代理失败: {str(e)}'
        })

def _set_active_proxy(db, data):
    """设置活动代理"""
    try:
        proxy_type = data.get('proxy_type')
        proxy_id = data.get('proxy_id')
        
        if not proxy_type or not proxy_id:
            return jsonify({
                'success': False,
                'message': '请提供代理类型和ID'
            })
        
        # 验证代理是否存在且可用
        table_name = f'{proxy_type}_proxies'
        db_type = app.config['DATABASE_TYPE']
        
        if db_type == 'sqlite':
            proxy = db.execute(f'SELECT * FROM {table_name} WHERE id = ? AND status = 1', (proxy_id,)).fetchone()
        else:
            cursor = db.cursor()
            cursor.execute(f'SELECT * FROM {table_name} WHERE id = %s AND status = 1', (proxy_id,))
            proxy = cursor.fetchone()
        
        if not proxy:
            return jsonify({
                'success': False,
                'message': '指定的代理不存在或不可用'
            })
        
        # 更新配置
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        config_updates = [
            ('proxy_enabled', '1'),
            ('active_proxy_type', proxy_type),
            ('active_proxy_id', str(proxy_id))
        ]
        
        if db_type == 'sqlite':
            for key, value in config_updates:
                db.execute('''
                    INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                    VALUES (?, ?, ?)
                ''', (key, value, now))
            db.commit()
        else:
            cursor = db.cursor()
            for key, value in config_updates:
                cursor.execute('''
                    INSERT INTO proxy_config (config_key, config_value, updated_at)
                    VALUES (%s, %s, %s)
                    ON DUPLICATE KEY UPDATE config_value = %s, updated_at = %s
                ''', (key, value, now, value, now))
            db.commit()
        
        proxy_name = proxy['name'] if db_type == 'sqlite' else proxy[1]  # 假设name是第二列
        
        return jsonify({
            'success': True,
            'message': f'代理已切换到: {proxy_name} ({proxy_type.upper()})'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'设置代理失败: {str(e)}'
        })

@app.route('/admin/api/cards', methods=['GET', 'POST', 'DELETE'])
@admin_required
def api_admin_cards():
    """卡密管理 API（Stub实现）"""
    return jsonify({
        'success': True,
        'message': '卡密管理功能正在开发中',
        'data': []
    })

@app.route('/admin/api/card-logs')
@admin_required
def api_admin_card_logs():
    """卡密日志 API（Stub实现）"""
    return jsonify({
        'success': True,
        'message': '卡密日志功能正在开发中',
        'data': []
    })

@app.route('/admin/api/mail-logs')
@admin_required
def api_admin_mail_logs():
    """收件日志 API（Stub实现）"""
    return jsonify({
        'success': True,
        'message': '收件日志功能正在开发中',
        'data': []
    })

@app.route('/admin/api/system-config', methods=['GET', 'POST'])
@admin_required
def api_admin_system_config():
    """系统设置 API（Stub实现）"""
    if request.method == 'GET':
        return jsonify({
            'success': True,
            'message': '系统设置功能正在开发中',
            'data': {
                'system_name': '邮件查看系统',
                'version': '2.0.0',
                'database_type': app.config['DATABASE_TYPE']
            }
        })
    else:
        return jsonify({
            'success': True,
            'message': '设置保存成功（开发中）'
        })

if __name__ == '__main__':
    # 初始化数据库
    with app.app_context():
        init_db()
    
    # 启动应用（端口8005）
    port = int(os.environ.get('PORT', 8005))
    app.run(debug=False, host='0.0.0.0', port=port)