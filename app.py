#!/usr/bin/env python3
"""
Flask Web Application - Complete Python rewrite of PHP email viewing system
Replaces all PHP functionality with Python Flask routes
"""

from flask import Flask, render_template, request, jsonify, session, redirect, url_for, send_from_directory
import sqlite3
import os
import json
import hashlib
from datetime import datetime
import sys

# Add python directory to path for mail fetcher
sys.path.insert(0, os.path.join(os.path.dirname(__file__), 'python'))

from python.mail_fetcher import ProxyMailFetcher

app = Flask(__name__)
app.secret_key = 'your-secret-key-change-in-production'  # Should be changed in production

# Database path
DB_PATH = os.path.join(os.path.dirname(__file__), 'db', 'mail.sqlite')
ADMIN_DB_PATH = os.path.join(os.path.dirname(__file__), 'db', 'admin.sqlite')

# Initialize database if it doesn't exist
def init_database():
    """Initialize database with required tables"""
    if not os.path.exists('db'):
        os.makedirs('db')
    
    # Initialize mail database
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    
    # Read and execute init.sql
    init_sql_path = os.path.join(os.path.dirname(__file__), 'db', 'init.sql')
    if os.path.exists(init_sql_path):
        with open(init_sql_path, 'r', encoding='utf-8') as f:
            cursor.executescript(f.read())
    
    # Create admin_users table if it doesn't exist
    cursor.execute('''CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )''')
    
    # Insert default admin user if none exists
    cursor.execute('SELECT COUNT(*) FROM admin_users')
    if cursor.fetchone()[0] == 0:
        cursor.execute('INSERT INTO admin_users (username, password) VALUES (?, ?)', ('admin', 'admin'))
    
    conn.commit()
    conn.close()

# Initialize database on startup
init_database()

# Routes

@app.route('/')
def index():
    """Main page - serve the frontend interface"""
    return send_from_directory('frontend', 'index.html')

@app.route('/admin')
@app.route('/admin/')
def admin_index():
    """Admin panel index - redirect to login if not authenticated"""
    if 'admin_logged_in' in session and session['admin_logged_in']:
        return redirect(url_for('admin_home'))
    return redirect(url_for('admin_login'))

@app.route('/admin/login', methods=['GET', 'POST'])
def admin_login():
    """Admin login page"""
    if request.method == 'POST':
        username = request.form.get('username', '')
        password = request.form.get('password', '')
        
        if username and password:
            try:
                conn = sqlite3.connect(DB_PATH)
                cursor = conn.cursor()
                
                cursor.execute('SELECT * FROM admin_users WHERE username = ?', (username,))
                admin = cursor.fetchone()
                
                if admin and admin[2] == password:  # Simple password check (admin[2] is password)
                    session['admin_logged_in'] = True
                    session['admin_id'] = admin[0]
                    session['admin_username'] = admin[1]
                    conn.close()
                    return redirect(url_for('admin_home'))
                else:
                    error = '用户名或密码错误'
                    conn.close()
                    return render_template('admin_login.html', error=error)
                    
            except Exception as e:
                error = f'数据库连接失败：{str(e)}'
                return render_template('admin_login.html', error=error)
        else:
            error = '请输入用户名和密码'
            return render_template('admin_login.html', error=error)
    
    # GET request - show login form
    return render_template('admin_login.html')

@app.route('/admin/logout')
def admin_logout():
    """Admin logout"""
    session.clear()
    return redirect(url_for('admin_login'))

@app.route('/admin/home')
def admin_home():
    """Admin home page"""
    if not session.get('admin_logged_in'):
        return redirect(url_for('admin_login'))
    
    try:
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        
        # Get mail accounts
        cursor.execute('SELECT * FROM mail_accounts ORDER BY created_at DESC')
        accounts = cursor.fetchall()
        
        # Get proxy status
        cursor.execute('SELECT config_key, config_value FROM proxy_config')
        proxy_config = {row[0]: row[1] for row in cursor.fetchall()}
        
        conn.close()
        
        return render_template('admin_home.html', 
                            accounts=accounts, 
                            proxy_config=proxy_config,
                            admin_username=session.get('admin_username'))
                                    
    except Exception as e:
        return f"Database error: {str(e)}"

@app.route('/admin/api/mail', methods=['POST'])
def admin_manage_mail():
    """Manage mail accounts (add, edit, delete)"""
    if not session.get('admin_logged_in'):
        return jsonify({'success': False, 'message': '未登录'})
    
    action = request.json.get('action')
    
    try:
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        
        if action == 'add':
            email = request.json.get('email')
            username = request.json.get('username')
            password = request.json.get('password')
            server = request.json.get('server')
            port = request.json.get('port', 993)
            protocol = request.json.get('protocol', 'imap')
            ssl = 1 if request.json.get('ssl') else 0
            remarks = request.json.get('remarks', '')
            
            cursor.execute('''INSERT INTO mail_accounts 
                             (email, username, password, server, port, protocol, ssl, remarks)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)''',
                          (email, username, password, server, port, protocol, ssl, remarks))
            
            conn.commit()
            return jsonify({'success': True, 'message': '邮箱账号添加成功'})
            
        elif action == 'delete':
            account_id = request.json.get('id')
            cursor.execute('DELETE FROM mail_accounts WHERE id = ?', (account_id,))
            conn.commit()
            return jsonify({'success': True, 'message': '邮箱账号删除成功'})
            
        elif action == 'edit':
            account_id = request.json.get('id')
            email = request.json.get('email')
            username = request.json.get('username')
            password = request.json.get('password')
            server = request.json.get('server')
            port = request.json.get('port', 993)
            protocol = request.json.get('protocol', 'imap')
            ssl = 1 if request.json.get('ssl') else 0
            remarks = request.json.get('remarks', '')
            
            cursor.execute('''UPDATE mail_accounts 
                             SET email=?, username=?, password=?, server=?, port=?, 
                                 protocol=?, ssl=?, remarks=?, updated_at=CURRENT_TIMESTAMP
                             WHERE id=?''',
                          (email, username, password, server, port, protocol, ssl, remarks, account_id))
            
            conn.commit()
            return jsonify({'success': True, 'message': '邮箱账号更新成功'})
        
        conn.close()
        
    except Exception as e:
        return jsonify({'success': False, 'message': f'操作失败: {str(e)}'})

@app.route('/admin/api/test_connection', methods=['POST'])
def test_mail_connection():
    """Test mail connection"""
    if not session.get('admin_logged_in'):
        return jsonify({'success': False, 'message': '未登录'})
    
    try:
        data = request.json
        account_id = data.get('id')
        
        if account_id:
            # Get account from database
            conn = sqlite3.connect(DB_PATH)
            cursor = conn.cursor()
            
            cursor.execute('SELECT * FROM mail_accounts WHERE id = ?', (account_id,))
            account = cursor.fetchone()
            
            if not account:
                conn.close()
                return jsonify({'success': False, 'message': '账号不存在'})
            
            # Map database columns to account info
            cursor.execute('PRAGMA table_info(mail_accounts)')
            columns = [row[1] for row in cursor.fetchall()]
            account_dict = dict(zip(columns, account))
            conn.close()
            
            # Create fetcher and test connection
            fetcher = ProxyMailFetcher(
                account_dict['server'],
                account_dict['port'],
                account_dict['username'],
                account_dict['password'],
                account_dict['protocol'],
                bool(account_dict['ssl'])
            )
        else:
            # Test with provided parameters
            email = data.get('email')
            username = data.get('username')
            password = data.get('password')
            server = data.get('server')
            port = data.get('port', 993)
            protocol = data.get('protocol', 'imap')
            ssl = data.get('ssl', True)
            
            # Create fetcher and test connection
            fetcher = ProxyMailFetcher(server, port, username, password, protocol, ssl)
        
        result = fetcher.test_connection()
        proxy_info = fetcher.get_proxy_info()
        result['proxy'] = proxy_info
        
        return jsonify(result)
        
    except Exception as e:
        return jsonify({'success': False, 'message': f'测试失败: {str(e)}'})

@app.route('/backend/api/get_mail', methods=['POST'])
@app.route('/admin/api/get_mail', methods=['POST'])
def get_mail():
    """Get latest mail for an email account - main API endpoint"""
    try:
        data = request.get_json()
        email = data.get('email', '').strip()
        
        if not email:
            return jsonify({'success': False, 'message': '请输入邮箱地址'})
        
        # Get account from database
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        
        cursor.execute('SELECT * FROM mail_accounts WHERE email = ?', (email,))
        account = cursor.fetchone()
        
        if not account:
            conn.close()
            return jsonify({'success': False, 'message': '邮箱账号不存在，请联系管理员添加'})
        
        # Map database columns to account info
        cursor.execute('PRAGMA table_info(mail_accounts)')
        columns = [row[1] for row in cursor.fetchall()]
        account_dict = dict(zip(columns, account))
        
        conn.close()
        
        # Create fetcher and get mail
        fetcher = ProxyMailFetcher(
            account_dict['server'],
            account_dict['port'],
            account_dict['username'],
            account_dict['password'],
            account_dict['protocol'],
            bool(account_dict['ssl'])
        )
        
        # Try to connect and get mail
        if fetcher.connect():
            result = fetcher.get_latest_mail()
            proxy_info = fetcher.get_proxy_info()
            result['proxy'] = proxy_info
            fetcher.close()
            return jsonify(result)
        else:
            proxy_info = fetcher.get_proxy_info()
            return jsonify({
                'success': False,
                'message': '无法连接到邮件服务器，请检查邮箱配置',
                'proxy': proxy_info
            })
            
    except Exception as e:
        return jsonify({'success': False, 'message': f'服务器错误: {str(e)}'})

@app.route('/admin/proxy')
def admin_proxy():
    """Proxy management page"""
    if not session.get('admin_logged_in'):
        return redirect(url_for('admin_login'))
    
    try:
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        
        # Get proxy configuration
        cursor.execute('SELECT config_key, config_value FROM proxy_config')
        proxy_config = {row[0]: row[1] for row in cursor.fetchall()}
        
        # Get HTTP proxies
        cursor.execute('SELECT * FROM http_proxies ORDER BY created_at DESC')
        http_proxies = cursor.fetchall()
        
        # Get SOCKS5 proxies
        cursor.execute('SELECT * FROM socks5_proxies ORDER BY created_at DESC')
        socks5_proxies = cursor.fetchall()
        
        conn.close()
        
        return render_template('admin_proxy.html',
                            proxy_config=proxy_config,
                            http_proxies=http_proxies,
                            socks5_proxies=socks5_proxies,
                            admin_username=session.get('admin_username'))
                                    
    except Exception as e:
        return f"Database error: {str(e)}"

@app.route('/admin/api/proxy', methods=['POST'])
def admin_manage_proxy():
    """Manage proxy settings"""
    if not session.get('admin_logged_in'):
        return jsonify({'success': False, 'message': '未登录'})
    
    try:
        data = request.json
        action = data.get('action')
        
        conn = sqlite3.connect(DB_PATH)
        cursor = conn.cursor()
        
        if action == 'add_http':
            name = data.get('name', '').strip()
            host = data.get('host', '').strip()
            port = data.get('port', 8080)
            username = data.get('username', '').strip()
            password = data.get('password', '').strip()
            remarks = data.get('remarks', '').strip()
            
            if not name:
                # Auto-generate name
                cursor.execute("SELECT name FROM http_proxies WHERE name LIKE '未命名%' ORDER BY name")
                existing_names = [row[0] for row in cursor.fetchall()]
                cursor.execute("SELECT name FROM socks5_proxies WHERE name LIKE '未命名%' ORDER BY name")
                existing_names.extend([row[0] for row in cursor.fetchall()])
                
                max_num = 0
                for existing_name in existing_names:
                    if existing_name.startswith('未命名'):
                        try:
                            num = int(existing_name[2:])
                            max_num = max(max_num, num)
                        except:
                            pass
                name = f'未命名{max_num + 1}'
            
            cursor.execute('''INSERT INTO http_proxies 
                             (name, host, port, username, password, remarks)
                             VALUES (?, ?, ?, ?, ?, ?)''',
                          (name, host, port, username, password, remarks))
            conn.commit()
            return jsonify({'success': True, 'message': 'HTTP代理添加成功'})
            
        elif action == 'add_socks5':
            name = data.get('name', '').strip()
            host = data.get('host', '').strip()
            port = data.get('port', 1080)
            username = data.get('username', '').strip()
            password = data.get('password', '').strip()
            remarks = data.get('remarks', '').strip()
            
            if not name:
                # Auto-generate name (same logic as HTTP)
                cursor.execute("SELECT name FROM http_proxies WHERE name LIKE '未命名%' ORDER BY name")
                existing_names = [row[0] for row in cursor.fetchall()]
                cursor.execute("SELECT name FROM socks5_proxies WHERE name LIKE '未命名%' ORDER BY name")
                existing_names.extend([row[0] for row in cursor.fetchall()])
                
                max_num = 0
                for existing_name in existing_names:
                    if existing_name.startswith('未命名'):
                        try:
                            num = int(existing_name[2:])
                            max_num = max(max_num, num)
                        except:
                            pass
                name = f'未命名{max_num + 1}'
            
            cursor.execute('''INSERT INTO socks5_proxies 
                             (name, host, port, username, password, remarks)
                             VALUES (?, ?, ?, ?, ?, ?)''',
                          (name, host, port, username, password, remarks))
            conn.commit()
            return jsonify({'success': True, 'message': 'SOCKS5代理添加成功'})
            
        elif action == 'enable_proxy':
            proxy_type = data.get('proxy_type')  # 'http' or 'socks5'
            proxy_id = data.get('proxy_id')
            
            # Update proxy config
            cursor.execute('UPDATE proxy_config SET config_value = ? WHERE config_key = ?', ('1', 'proxy_enabled'))
            cursor.execute('UPDATE proxy_config SET config_value = ? WHERE config_key = ?', (proxy_type, 'active_proxy_type'))
            cursor.execute('UPDATE proxy_config SET config_value = ? WHERE config_key = ?', (str(proxy_id), 'active_proxy_id'))
            
            conn.commit()
            return jsonify({'success': True, 'message': f'{proxy_type.upper()}代理已启用'})
            
        elif action == 'disable_proxy':
            cursor.execute('UPDATE proxy_config SET config_value = ? WHERE config_key = ?', ('0', 'proxy_enabled'))
            cursor.execute('UPDATE proxy_config SET config_value = ? WHERE config_key = ?', ('', 'active_proxy_type'))
            cursor.execute('UPDATE proxy_config SET config_value = ? WHERE config_key = ?', ('0', 'active_proxy_id'))
            
            conn.commit()
            return jsonify({'success': True, 'message': '代理已禁用'})
            
        elif action == 'delete_http':
            proxy_id = data.get('proxy_id')
            cursor.execute('DELETE FROM http_proxies WHERE id = ?', (proxy_id,))
            conn.commit()
            return jsonify({'success': True, 'message': 'HTTP代理删除成功'})
            
        elif action == 'delete_socks5':
            proxy_id = data.get('proxy_id')
            cursor.execute('DELETE FROM socks5_proxies WHERE id = ?', (proxy_id,))
            conn.commit()
            return jsonify({'success': True, 'message': 'SOCKS5代理删除成功'})
        
        conn.close()
        
    except Exception as e:
        return jsonify({'success': False, 'message': f'操作失败: {str(e)}'})

if __name__ == '__main__':
    # Run the Flask app
    app.run(debug=True, host='0.0.0.0', port=8005)