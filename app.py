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
import logging
from datetime import datetime, timezone
from flask import Flask, render_template, request, session, redirect, url_for, flash, jsonify, g
from werkzeug.security import check_password_hash, generate_password_hash

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

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
    """获取数据库连接（支持多数据库）- 优化版本"""
    db = getattr(g, '_database', None)
    if db is None:
        db_type = app.config['DATABASE_TYPE']
        
        try:
            if db_type == 'sqlite':
                db = g._database = sqlite3.connect(
                    app.config['DATABASE'],
                    timeout=30.0,  # 30秒超时
                    check_same_thread=False
                )
                db.row_factory = sqlite3.Row
                # 启用WAL模式提高并发性能
                db.execute('PRAGMA journal_mode=WAL')
                db.execute('PRAGMA synchronous=NORMAL')
                db.execute('PRAGMA cache_size=10000')
                db.execute('PRAGMA temp_store=MEMORY')
            elif db_type == 'mysql':
                # MySQL连接池优化（需要安装 mysql-connector-python）
                import mysql.connector
                from mysql.connector import pooling
                
                config = {
                    'host': os.environ.get('MYSQL_HOST', 'localhost'),
                    'user': os.environ.get('MYSQL_USER', 'root'),
                    'password': os.environ.get('MYSQL_PASSWORD', ''),
                    'database': os.environ.get('MYSQL_DATABASE', 'mail_system'),
                    'charset': 'utf8mb4',
                    'use_unicode': True,
                    'autocommit': False,
                    'connect_timeout': 30,
                    'sql_mode': 'STRICT_TRANS_TABLES',
                }
                
                # 创建连接池（如果不存在）
                if not hasattr(app, '_mysql_pool'):
                    app._mysql_pool = pooling.MySQLConnectionPool(
                        pool_name="mail_pool",
                        pool_size=5,
                        pool_reset_session=True,
                        **config
                    )
                
                db = g._database = app._mysql_pool.get_connection()
                
            elif db_type == 'postgresql':
                # PostgreSQL连接优化（需要安装 psycopg2-binary）
                import psycopg2
                from psycopg2.extras import RealDictCursor
                from psycopg2 import pool
                
                # 创建连接池（如果不存在）
                if not hasattr(app, '_postgres_pool'):
                    app._postgres_pool = psycopg2.pool.SimpleConnectionPool(
                        1, 10,  # 最小1个，最大10个连接
                        host=os.environ.get('POSTGRES_HOST', 'localhost'),
                        user=os.environ.get('POSTGRES_USER', 'postgres'),
                        password=os.environ.get('POSTGRES_PASSWORD', ''),
                        database=os.environ.get('POSTGRES_DATABASE', 'mail_system'),
                        cursor_factory=RealDictCursor
                    )
                
                db = g._database = app._postgres_pool.getconn()
        
        except Exception as e:
            logger.error(f"Database connection failed: {e}")
            raise Exception(f"数据库连接失败: {str(e)}")
            
    return db

def init_db():
    """初始化数据库（支持多数据库）- 优化版本"""
    with app.app_context():
        db = get_db()
        db_type = app.config['DATABASE_TYPE']
        
        try:
            # 使用事务确保原子性
            if db_type != 'sqlite':
                db.autocommit = False
                
            # 读取并执行初始化SQL
            init_sql_path = os.path.join(os.path.dirname(__file__), 'db', 'init.sql')
            if os.path.exists(init_sql_path):
                with open(init_sql_path, 'r', encoding='utf-8') as f:
                    sql_content = f.read()
                    
                    # 根据数据库类型调整SQL语句
                    if db_type == 'mysql':
                        sql_content = sql_content.replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY')
                        sql_content = sql_content.replace('DATETIME DEFAULT CURRENT_TIMESTAMP', 'DATETIME DEFAULT CURRENT_TIMESTAMP')
                        sql_content = sql_content.replace('INSERT OR IGNORE', 'INSERT IGNORE')
                        sql_content = sql_content.replace('INSERT OR REPLACE', 'INSERT INTO ... ON DUPLICATE KEY UPDATE')
                    elif db_type == 'postgresql':
                        sql_content = sql_content.replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'SERIAL PRIMARY KEY')
                        sql_content = sql_content.replace('DATETIME', 'TIMESTAMP')
                        sql_content = sql_content.replace('INSERT OR IGNORE', 'INSERT ... ON CONFLICT DO NOTHING')
                        sql_content = sql_content.replace('INSERT OR REPLACE', 'INSERT ... ON CONFLICT ... DO UPDATE SET')
                    
                    # 执行SQL
                    if db_type == 'sqlite':
                        db.executescript(sql_content)
                    else:
                        cursor = db.cursor()
                        # 分割并执行每个语句
                        statements = [stmt.strip() for stmt in sql_content.split(';') if stmt.strip()]
                        for statement in statements:
                            try:
                                cursor.execute(statement)
                            except Exception as e:
                                logger.warning(f"SQL statement failed (continuing): {statement[:100]}... Error: {e}")
                        cursor.close()
            
            # 数据库迁移：为现有代理表添加unified_id列
            migrate_proxy_tables(db, db_type)
            
            # 数据库迁移：为cards表添加新字段
            migrate_cards_table(db, db_type)
            
            # 创建管理员用户表（兼容原有PHP版本）
            create_admin_table(db, db_type)
            
            # 检查是否有默认管理员，如果没有则创建
            create_default_admin(db, db_type)
            
            # 提交事务
            if db_type != 'sqlite':
                db.commit()
            else:
                db.commit()
                
            logger.info("Database initialization completed successfully")
            
        except Exception as e:
            logger.error(f"Database initialization failed: {e}")
            # 回滚事务
            if db_type != 'sqlite':
                try:
                    db.rollback()
                except:
                    pass
            raise

def create_admin_table(db, db_type):
    """创建管理员用户表"""
    try:
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ''')
            cursor.close()
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
            cursor.close()
    except Exception as e:
        logger.error(f"Failed to create admin table: {e}")
        raise

def create_default_admin(db, db_type):
    """创建默认管理员用户"""
    try:
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
            cursor.close()
    except Exception as e:
        logger.error(f"Failed to create default admin: {e}")
        raise

def migrate_proxy_tables(db, db_type):
    """迁移代理表，添加unified_id字段"""
    try:
        # 检查http_proxies表是否有unified_id列
        if db_type == 'sqlite':
            result = db.execute("PRAGMA table_info(http_proxies)").fetchall()
            columns = [col[1] for col in result]
            
            if 'unified_id' not in columns:
                db.execute('ALTER TABLE http_proxies ADD COLUMN unified_id INTEGER DEFAULT 0')
                logger.info("Added unified_id column to http_proxies table")
                
        else:
            cursor = db.cursor()
            try:
                if db_type == 'mysql':
                    cursor.execute("SHOW COLUMNS FROM http_proxies LIKE 'unified_id'")
                    if not cursor.fetchone():
                        cursor.execute('ALTER TABLE http_proxies ADD COLUMN unified_id INT DEFAULT 0')
                        logger.info("Added unified_id column to http_proxies table")
                elif db_type == 'postgresql':
                    cursor.execute("SELECT column_name FROM information_schema.columns WHERE table_name='http_proxies' AND column_name='unified_id'")
                    if not cursor.fetchone():
                        cursor.execute('ALTER TABLE http_proxies ADD COLUMN unified_id INTEGER DEFAULT 0')
                        logger.info("Added unified_id column to http_proxies table")
            except Exception as e:
                logger.error(f"Error checking/adding unified_id to http_proxies: {e}")
        
        # 检查socks5_proxies表是否有unified_id列
        if db_type == 'sqlite':
            result = db.execute("PRAGMA table_info(socks5_proxies)").fetchall()
            columns = [col[1] for col in result]
            
            if 'unified_id' not in columns:
                db.execute('ALTER TABLE socks5_proxies ADD COLUMN unified_id INTEGER DEFAULT 0')
                logger.info("Added unified_id column to socks5_proxies table")
                
        else:
            cursor = db.cursor()
            try:
                if db_type == 'mysql':
                    cursor.execute("SHOW COLUMNS FROM socks5_proxies LIKE 'unified_id'")
                    if not cursor.fetchone():
                        cursor.execute('ALTER TABLE socks5_proxies ADD COLUMN unified_id INT DEFAULT 0')
                        logger.info("Added unified_id column to socks5_proxies table")
                elif db_type == 'postgresql':
                    cursor.execute("SELECT column_name FROM information_schema.columns WHERE table_name='socks5_proxies' AND column_name='unified_id'")
                    if not cursor.fetchone():
                        cursor.execute('ALTER TABLE socks5_proxies ADD COLUMN unified_id INTEGER DEFAULT 0')
                        logger.info("Added unified_id column to socks5_proxies table")
            except Exception as e:
                logger.error(f"Error checking/adding unified_id to socks5_proxies: {e}")
        
        # 为现有代理分配统一ID
        assign_unified_ids_to_existing_proxies(db, db_type)
        
        if db_type != 'sqlite':
            db.commit()
        else:
            db.commit()
            
    except Exception as e:
        logger.error(f"Error during proxy table migration: {e}")

def migrate_cards_table(db, db_type):
    """迁移cards表，添加新的邮件管理字段"""
    try:
        # 检查cards表是否有新字段
        new_columns = [
            ('bound_email_id', 'INTEGER DEFAULT NULL'),
            ('email_days_filter', 'INTEGER DEFAULT 1'),
            ('sender_filter', 'TEXT DEFAULT \'\'')
        ]
        
        for column_name, column_def in new_columns:
            if db_type == 'sqlite':
                # 检查列是否存在
                result = db.execute("PRAGMA table_info(cards)").fetchall()
                existing_columns = [col[1] for col in result]
                
                if column_name not in existing_columns:
                    if db_type == 'sqlite':
                        if column_name == 'bound_email_id':
                            db.execute('ALTER TABLE cards ADD COLUMN bound_email_id INTEGER DEFAULT NULL')
                        elif column_name == 'email_days_filter':
                            db.execute('ALTER TABLE cards ADD COLUMN email_days_filter INTEGER DEFAULT 1')
                        elif column_name == 'sender_filter':
                            db.execute('ALTER TABLE cards ADD COLUMN sender_filter TEXT DEFAULT \'\'')
                    logger.info(f"Added {column_name} column to cards table")
                    
            else:
                cursor = db.cursor()
                try:
                    if db_type == 'mysql':
                        cursor.execute(f"SHOW COLUMNS FROM cards LIKE '{column_name}'")
                        if not cursor.fetchone():
                            mysql_def = column_def.replace('INTEGER', 'INT').replace('TEXT', 'TEXT')
                            cursor.execute(f'ALTER TABLE cards ADD COLUMN {column_name} {mysql_def}')
                            logger.info(f"Added {column_name} column to cards table")
                    elif db_type == 'postgresql':
                        cursor.execute(f"SELECT column_name FROM information_schema.columns WHERE table_name='cards' AND column_name='{column_name}'")
                        if not cursor.fetchone():
                            pg_def = column_def.replace('INTEGER', 'INTEGER').replace('TEXT', 'TEXT')
                            cursor.execute(f'ALTER TABLE cards ADD COLUMN {column_name} {pg_def}')
                            logger.info(f"Added {column_name} column to cards table")
                except Exception as e:
                    logger.error(f"Error checking/adding {column_name} to cards: {e}")
        
        if db_type != 'sqlite':
            db.commit()
        else:
            db.commit()
            
        logger.info("Cards table migration completed successfully")
        
    except Exception as e:
        logger.error(f"Error during cards table migration: {e}")

def assign_unified_ids_to_existing_proxies(db, db_type):
    """为现有代理分配统一ID（按创建时间顺序，确保ID连续）"""
    try:
        # 获取所有需要分配unified_id的代理，按创建时间排序
        all_proxies = []
        
        if db_type == 'sqlite':
            # 获取HTTP代理
            http_proxies = db.execute('SELECT id, created_at FROM http_proxies WHERE unified_id = 0 ORDER BY created_at ASC, id ASC').fetchall()
            for proxy in http_proxies:
                all_proxies.append(('http', proxy['id'], proxy['created_at']))
            
            # 获取SOCKS5代理
            socks5_proxies = db.execute('SELECT id, created_at FROM socks5_proxies WHERE unified_id = 0 ORDER BY created_at ASC, id ASC').fetchall()
            for proxy in socks5_proxies:
                all_proxies.append(('socks5', proxy['id'], proxy['created_at']))
        else:
            cursor = db.cursor()
            # 获取HTTP代理
            cursor.execute('SELECT id, created_at FROM http_proxies WHERE unified_id = 0 ORDER BY created_at ASC, id ASC')
            http_proxies = cursor.fetchall()
            for proxy in http_proxies:
                all_proxies.append(('http', proxy[0], proxy[1]))
                
            # 获取SOCKS5代理
            cursor.execute('SELECT id, created_at FROM socks5_proxies WHERE unified_id = 0 ORDER BY created_at ASC, id ASC')
            socks5_proxies = cursor.fetchall()
            for proxy in socks5_proxies:
                all_proxies.append(('socks5', proxy[0], proxy[1]))
        
        # 按创建时间排序所有代理，确保ID是连续的
        all_proxies.sort(key=lambda x: (x[2], x[1]))  # 按创建时间，然后按ID排序
        
        # 为每个代理分配统一ID
        for proxy_type, proxy_id, created_at in all_proxies:
            unified_id = get_next_unified_proxy_id(db, proxy_type, proxy_id)
            table_name = f'{proxy_type}_proxies'
            update_proxy_unified_id(db, table_name, proxy_id, unified_id)
        
        logger.info(f"Assigned unified IDs to {len(all_proxies)} existing proxies")
        
    except Exception as e:
        logger.error(f"Error assigning unified IDs to existing proxies: {e}")

@app.teardown_appcontext
def close_db(exception):
    """关闭数据库连接 - 优化版本"""
    db = getattr(g, '_database', None)
    if db is not None:
        db_type = app.config['DATABASE_TYPE']
        try:
            if db_type == 'sqlite':
                db.close()
            elif db_type == 'mysql':
                if hasattr(app, '_mysql_pool'):
                    # 返回连接到连接池
                    db.close()
                else:
                    db.close()
            elif db_type == 'postgresql':
                if hasattr(app, '_postgres_pool'):
                    # 返回连接到连接池
                    app._postgres_pool.putconn(db)
                else:
                    db.close()
        except Exception as e:
            logger.error(f"Error closing database connection: {e}")
        finally:
            g._database = None

def get_account_count():
    """获取邮箱账号总数"""
    try:
        db = get_db()
        result = db.execute('SELECT COUNT(*) as count FROM mail_accounts').fetchone()
        return result['count'] if result else 0
    except:
        return 0

def get_next_unified_proxy_id(db, proxy_type, proxy_table_id):
    """获取下一个统一代理ID"""
    try:
        db_type = app.config['DATABASE_TYPE']
        
        # 插入到统一ID管理表
        if db_type == 'sqlite':
            db.execute('''
                INSERT INTO unified_proxy_ids (proxy_type, proxy_table_id)
                VALUES (?, ?)
            ''', (proxy_type, proxy_table_id))
            # 获取刚插入的ID
            result = db.execute('SELECT last_insert_rowid() as id').fetchone()
            unified_id = result['id']
        else:
            cursor = db.cursor()
            cursor.execute('''
                INSERT INTO unified_proxy_ids (proxy_type, proxy_table_id)
                VALUES (%s, %s)
            ''', (proxy_type, proxy_table_id))
            unified_id = cursor.lastrowid
        
        return unified_id
    except Exception as e:
        logger.error(f"Error getting unified proxy ID: {e}")
        raise

def reorder_unified_proxy_ids(db, db_type):
    """重新排序统一代理ID，确保删除后ID连续"""
    try:
        # 首先为没有unified_id的代理分配ID
        assign_unified_ids_to_existing_proxies(db, db_type)
        
        # 获取所有有效的统一ID记录，按创建时间和ID排序
        if db_type == 'sqlite':
            unified_records = db.execute('''
                SELECT upi.id, upi.proxy_type, upi.proxy_table_id, upi.created_at
                FROM unified_proxy_ids upi
                WHERE EXISTS (
                    SELECT 1 FROM http_proxies hp WHERE hp.id = upi.proxy_table_id AND upi.proxy_type = 'http'
                    UNION
                    SELECT 1 FROM socks5_proxies sp WHERE sp.id = upi.proxy_table_id AND upi.proxy_type = 'socks5'
                )
                ORDER BY upi.created_at, upi.id
            ''').fetchall()
        else:
            cursor = db.cursor()
            cursor.execute('''
                SELECT upi.id, upi.proxy_type, upi.proxy_table_id, upi.created_at
                FROM unified_proxy_ids upi
                WHERE EXISTS (
                    SELECT 1 FROM http_proxies hp WHERE hp.id = upi.proxy_table_id AND upi.proxy_type = 'http'
                    UNION
                    SELECT 1 FROM socks5_proxies sp WHERE sp.id = upi.proxy_table_id AND upi.proxy_type = 'socks5'
                )
                ORDER BY upi.created_at, upi.id
            ''')
            unified_records = cursor.fetchall()
        
        # 如果没有记录，直接返回
        if not unified_records:
            logger.info("No proxy records to reorder")
            return
        
        # 创建一个临时表来重新分配ID
        temp_table = 'unified_proxy_ids_temp'
        
        if db_type == 'sqlite':
            # 创建临时表
            db.execute(f'''
                CREATE TABLE {temp_table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    proxy_type TEXT NOT NULL,
                    proxy_table_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            
            # 按顺序重新插入数据（自动分配新的连续ID）
            for record in unified_records:
                proxy_type = record[1]
                proxy_table_id = record[2]
                created_at = record[3]
                
                db.execute(f'''
                    INSERT INTO {temp_table} (proxy_type, proxy_table_id, created_at)
                    VALUES (?, ?, ?)
                ''', (proxy_type, proxy_table_id, created_at))
            
            # 删除原表并重命名
            db.execute('DROP TABLE unified_proxy_ids')
            db.execute(f'ALTER TABLE {temp_table} RENAME TO unified_proxy_ids')
            
            # 更新代理表中的unified_id
            http_records = db.execute('''
                SELECT upi.id, upi.proxy_table_id 
                FROM unified_proxy_ids upi 
                WHERE upi.proxy_type = 'http'
            ''').fetchall()
            
            for unified_id, proxy_table_id in http_records:
                db.execute('UPDATE http_proxies SET unified_id = ? WHERE id = ?', (unified_id, proxy_table_id))
            
            socks5_records = db.execute('''
                SELECT upi.id, upi.proxy_table_id 
                FROM unified_proxy_ids upi 
                WHERE upi.proxy_type = 'socks5'
            ''').fetchall()
            
            for unified_id, proxy_table_id in socks5_records:
                db.execute('UPDATE socks5_proxies SET unified_id = ? WHERE id = ?', (unified_id, proxy_table_id))
                
        else:
            # MySQL/PostgreSQL处理（类似逻辑）
            cursor = db.cursor()
            
            # 创建临时表
            if db_type == 'mysql':
                cursor.execute(f'''
                    CREATE TABLE {temp_table} (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        proxy_type VARCHAR(50) NOT NULL,
                        proxy_table_id INT NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ''')
            else:  # PostgreSQL
                cursor.execute(f'''
                    CREATE TABLE {temp_table} (
                        id SERIAL PRIMARY KEY,
                        proxy_type VARCHAR(50) NOT NULL,
                        proxy_table_id INTEGER NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ''')
            
            # 重新插入数据
            for record in unified_records:
                proxy_type = record[1]
                proxy_table_id = record[2]
                created_at = record[3]
                
                cursor.execute(f'''
                    INSERT INTO {temp_table} (proxy_type, proxy_table_id, created_at)
                    VALUES (%s, %s, %s)
                ''', (proxy_type, proxy_table_id, created_at))
            
            # 删除原表并重命名
            cursor.execute('DROP TABLE unified_proxy_ids')
            cursor.execute(f'ALTER TABLE {temp_table} RENAME TO unified_proxy_ids')
            
            # 更新代理表
            cursor.execute('''
                UPDATE http_proxies hp 
                SET unified_id = (
                    SELECT upi.id FROM unified_proxy_ids upi 
                    WHERE upi.proxy_type = 'http' AND upi.proxy_table_id = hp.id
                )
                WHERE EXISTS (
                    SELECT 1 FROM unified_proxy_ids upi 
                    WHERE upi.proxy_type = 'http' AND upi.proxy_table_id = hp.id
                )
            ''')
            
            cursor.execute('''
                UPDATE socks5_proxies sp 
                SET unified_id = (
                    SELECT upi.id FROM unified_proxy_ids upi 
                    WHERE upi.proxy_type = 'socks5' AND upi.proxy_table_id = sp.id
                )
                WHERE EXISTS (
                    SELECT 1 FROM unified_proxy_ids upi 
                    WHERE upi.proxy_type = 'socks5' AND upi.proxy_table_id = sp.id
                )
            ''')
        
        if db_type != 'sqlite':
            db.commit()
        else:
            db.commit()
            
        logger.info("Proxy unified IDs reordered successfully")
        
    except Exception as e:
        logger.error(f"Error reordering proxy unified IDs: {e}")
        if db_type != 'sqlite':
            try:
                db.rollback()
            except:
                pass

def reorder_mailbox_ids(db, db_type):
    """重新排序邮箱ID，确保删除后ID连续"""
    try:
        # 获取所有邮箱记录，按ID排序确保稳定的顺序
        if db_type == 'sqlite':
            mailboxes = db.execute('SELECT * FROM mail_accounts ORDER BY id ASC').fetchall()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT * FROM mail_accounts ORDER BY id ASC')
            mailboxes = cursor.fetchall()
        
        if not mailboxes:
            return
        
        # 创建临时表来重新插入数据
        temp_table_name = f'mail_accounts_temp_{int(time.time())}'
        
        if db_type == 'sqlite':
            # 创建临时表
            db.execute(f'''
                CREATE TABLE {temp_table_name} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL UNIQUE,
                    username TEXT NOT NULL,
                    password TEXT NOT NULL,
                    server TEXT NOT NULL,
                    port INTEGER NOT NULL,
                    protocol TEXT NOT NULL DEFAULT 'imap',
                    ssl INTEGER NOT NULL DEFAULT 1,
                    remarks TEXT DEFAULT '',
                    status INTEGER DEFAULT 1,
                    last_test DATETIME DEFAULT NULL,
                    test_result TEXT DEFAULT '',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            
            # 重新插入数据（ID将自动重新排序）
            for mailbox in mailboxes:
                mailbox_dict = dict(mailbox)
                db.execute(f'''
                    INSERT INTO {temp_table_name} 
                    (email, username, password, server, port, protocol, ssl, remarks, status, last_test, test_result, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ''', (
                    mailbox_dict['email'], mailbox_dict['username'], mailbox_dict['password'],
                    mailbox_dict['server'], mailbox_dict['port'], mailbox_dict['protocol'],
                    mailbox_dict['ssl'], mailbox_dict['remarks'], mailbox_dict['status'],
                    mailbox_dict['last_test'], mailbox_dict['test_result'],
                    mailbox_dict['created_at'], mailbox_dict['updated_at']
                ))
            
            # 删除原表并重命名临时表
            db.execute('DROP TABLE mail_accounts')
            db.execute(f'ALTER TABLE {temp_table_name} RENAME TO mail_accounts')
            
        else:
            # MySQL/PostgreSQL处理方式
            cursor = db.cursor()
            
            # 创建临时表
            if db_type == 'mysql':
                cursor.execute(f'''
                    CREATE TABLE {temp_table_name} (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email VARCHAR(255) NOT NULL UNIQUE,
                        username TEXT NOT NULL,
                        password TEXT NOT NULL,
                        server TEXT NOT NULL,
                        port INT NOT NULL,
                        protocol VARCHAR(50) NOT NULL DEFAULT 'imap',
                        ssl TINYINT NOT NULL DEFAULT 1,
                        remarks TEXT DEFAULT '',
                        status TINYINT DEFAULT 1,
                        last_test DATETIME DEFAULT NULL,
                        test_result TEXT DEFAULT '',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ''')
            else:  # PostgreSQL
                cursor.execute(f'''
                    CREATE TABLE {temp_table_name} (
                        id SERIAL PRIMARY KEY,
                        email VARCHAR(255) NOT NULL UNIQUE,
                        username TEXT NOT NULL,
                        password TEXT NOT NULL,
                        server TEXT NOT NULL,
                        port INTEGER NOT NULL,
                        protocol VARCHAR(50) NOT NULL DEFAULT 'imap',
                        ssl INTEGER NOT NULL DEFAULT 1,
                        remarks TEXT DEFAULT '',
                        status INTEGER DEFAULT 1,
                        last_test TIMESTAMP DEFAULT NULL,
                        test_result TEXT DEFAULT '',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )
                ''')
            
            # 重新插入数据
            for mailbox in mailboxes:
                cursor.execute(f'''
                    INSERT INTO {temp_table_name} 
                    (email, username, password, server, port, protocol, ssl, remarks, status, last_test, test_result, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ''', mailbox[1:])  # 跳过原有的ID
            
            # 删除原表并重命名临时表
            cursor.execute('DROP TABLE mail_accounts')
            cursor.execute(f'ALTER TABLE {temp_table_name} RENAME TO mail_accounts')
            
        if db_type != 'sqlite':
            db.commit()
        else:
            db.commit()
            
        logger.info("Mailbox IDs reordered successfully")
        
    except Exception as e:
        logger.error(f"Error reordering mailbox IDs: {e}")
        if db_type != 'sqlite':
            try:
                db.rollback()
            except:
                pass

def update_proxy_unified_id(db, table_name, proxy_id, unified_id):
    """更新代理的统一ID"""
    try:
        db_type = app.config['DATABASE_TYPE']
        
        if db_type == 'sqlite':
            db.execute(f'''
                UPDATE {table_name} SET unified_id = ? WHERE id = ?
            ''', (unified_id, proxy_id))
        else:
            cursor = db.cursor()
            cursor.execute(f'''
                UPDATE {table_name} SET unified_id = %s WHERE id = %s
            ''', (unified_id, proxy_id))
            
    except Exception as e:
        logger.error(f"Error updating proxy unified ID: {e}")
        raise

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
            
            # 获取分页数据 - 按ID排序确保ID稳定显示
            sql = f"""
                SELECT * FROM mail_accounts {where_clause}
                ORDER BY id ASC 
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
                ORDER BY id ASC 
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
        
        # 调用Python邮件获取器进行测试
        try:
            if app.config['DATABASE_TYPE'] == 'sqlite':
                account_dict = dict(account)
            else:
                columns = [desc[0] for desc in cursor.description]
                account_dict = dict(zip(columns, account))
            
            result = subprocess.run([
                sys.executable, 
                os.path.join(os.path.dirname(__file__), 'python', 'mail_fetcher.py'),
                account_dict['email'],
                '--test-connection'
            ], capture_output=True, text=True, timeout=30)
            
            if result.returncode == 0:
                # 解析JSON输出
                test_result = json.loads(result.stdout)
                test_success = test_result.get('success', False)
                test_message = test_result.get('message', '测试完成')
                
                # 更新测试结果
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                if app.config['DATABASE_TYPE'] == 'sqlite':
                    db.execute('''
                        UPDATE mail_accounts 
                        SET last_test=?, test_result=?
                        WHERE id=?
                    ''', (now, test_message, account_id))
                    db.commit()
                else:
                    cursor = db.cursor()
                    cursor.execute('''
                        UPDATE mail_accounts 
                        SET last_test=%s, test_result=%s
                        WHERE id=%s
                    ''', (now, test_message, account_id))
                    db.commit()
                
                return jsonify({
                    'success': test_success,
                    'message': test_message,
                    'proxy_info': test_result.get('proxy', {}),
                    'diagnostics': test_result.get('diagnostics', {})
                })
            else:
                error_message = result.stderr or "邮箱测试失败"
                
                # 更新测试结果
                now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                if app.config['DATABASE_TYPE'] == 'sqlite':
                    db.execute('''
                        UPDATE mail_accounts 
                        SET last_test=?, test_result=?
                        WHERE id=?
                    ''', (now, error_message, account_id))
                    db.commit()
                else:
                    cursor = db.cursor()
                    cursor.execute('''
                        UPDATE mail_accounts 
                        SET last_test=%s, test_result=%s
                        WHERE id=%s
                    ''', (now, error_message, account_id))
                    db.commit()
                
                return jsonify({
                    'success': False,
                    'message': error_message
                })
                
        except subprocess.TimeoutExpired:
            return jsonify({
                'success': False,
                'message': '邮箱连接测试超时，请检查网络连接或服务器配置'
            })
        except json.JSONDecodeError:
            return jsonify({
                'success': False,
                'message': '邮箱测试服务响应格式错误'
            })
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'邮箱测试服务错误: {str(e)}'
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
        # 临时保存邮箱信息到数据库进行测试
        with app.app_context():
            db = get_db()
            temp_email = f"temp_test_{email}_{int(time.time())}"
            
            # 插入临时邮箱记录
            now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            if app.config['DATABASE_TYPE'] == 'sqlite':
                db.execute('''
                    INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ''', (temp_email, email, password, server, port, protocol, 1 if ssl else 0, '临时测试邮箱', now, now))
                db.commit()
            else:
                cursor = db.cursor()
                cursor.execute('''
                    INSERT INTO mail_accounts (email, username, password, server, port, protocol, ssl, remarks, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ''', (temp_email, email, password, server, port, protocol, 1 if ssl else 0, '临时测试邮箱', now, now))
                db.commit()
            
            try:
                # 调用Python邮件获取器进行测试
                result = subprocess.run([
                    sys.executable, 
                    os.path.join(os.path.dirname(__file__), 'python', 'mail_fetcher.py'),
                    temp_email,
                    '--test-connection'
                ], capture_output=True, text=True, timeout=30)
                
                if result.returncode == 0:
                    # 解析JSON输出
                    test_result = json.loads(result.stdout)
                    test_success = test_result.get('success', False)
                    test_message = test_result.get('message', '测试完成')
                    
                    return jsonify({
                        'success': test_success,
                        'message': test_message,
                        'proxy_info': test_result.get('proxy', {}),
                        'diagnostics': test_result.get('diagnostics', {})
                    })
                else:
                    error_message = result.stderr or "邮箱测试失败"
                    return jsonify({
                        'success': False,
                        'message': error_message
                    })
                    
            except subprocess.TimeoutExpired:
                return jsonify({
                    'success': False,
                    'message': '邮箱连接测试超时'
                })
            except json.JSONDecodeError:
                return jsonify({
                    'success': False,
                    'message': '邮箱测试服务响应格式错误'
                })
            except Exception as e:
                return jsonify({
                    'success': False,
                    'message': f'邮箱测试服务错误: {str(e)}'
                })
                
            finally:
                # 删除临时邮箱记录
                try:
                    if app.config['DATABASE_TYPE'] == 'sqlite':
                        db.execute('DELETE FROM mail_accounts WHERE email = ?', (temp_email,))
                        db.commit()
                    else:
                        cursor = db.cursor()
                        cursor.execute('DELETE FROM mail_accounts WHERE email = %s', (temp_email,))
                        db.commit()
                except:
                    pass  # 忽略删除错误
        
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
        else:
            return jsonify({
                'success': False,
                'message': '无效的操作类型'
            })
    
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
            
            # 重新排序统一代理ID
            reorder_unified_proxy_ids(db, db_type)
            
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
    port_value = data.get('port')
    
    # Handle port conversion more safely
    try:
        port = int(port_value) if port_value is not None else 0
    except (ValueError, TypeError):
        port = 0
    
    username = data.get('username', '').strip()
    password = data.get('password', '').strip()
    remarks = data.get('remarks', '').strip()
    
    if not all([host, port]):
        return jsonify({
            'success': False,
            'message': '请填写代理地址和端口'
        })
    
    # 如果没有提供名称，保持为空字符串
    if not name:
        name = ""
    
    try:
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        
        # 先插入代理记录（不包含unified_id）
        if app.config['DATABASE_TYPE'] == 'sqlite':
            cursor = db.execute(f'''
                INSERT INTO {table_name} (name, host, port, username, password, remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ''', (name, host, port, username, password, remarks, now, now))
            proxy_id = cursor.lastrowid
        else:
            cursor = db.cursor()
            cursor.execute(f'''
                INSERT INTO {table_name} (name, host, port, username, password, remarks, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            ''', (name, host, port, username, password, remarks, now, now))
            proxy_id = cursor.lastrowid
            
        # 获取统一ID
        unified_id = get_next_unified_proxy_id(db, proxy_type, proxy_id)
        
        # 更新代理记录的unified_id（如果列存在）
        try:
            update_proxy_unified_id(db, table_name, proxy_id, unified_id)
        except:
            # 如果unified_id列不存在，继续执行
            pass
        
        db.commit()
        
        return jsonify({
            'success': True,
            'message': f'{proxy_type.upper()}代理添加成功'
        })
        
    except Exception as e:
        logger.error(f"Error adding proxy: {e}")
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
    port_value = data.get('port')
    
    # Handle port conversion more safely
    try:
        port = int(port_value) if port_value is not None else 0
    except (ValueError, TypeError):
        port = 0
    
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
    port_value = data.get('port')
    
    # Handle port conversion more safely
    try:
        port = int(port_value) if port_value is not None else 0
    except (ValueError, TypeError):
        port = 0
    
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
    """执行代理测试 - 优化版本"""
    try:
        host = proxy['host']
        port = proxy['port']
        username = proxy['username'] or None
        password = proxy['password'] or None
        
        # 优化测试目标 - 使用baidu.com和163.com进行测试
        test_urls = [
            ('http://baidu.com', 10),          # 百度网站
            ('http://163.com', 10)             # 网易163网站
        ]
        results = []
        
        for url, timeout in test_urls:
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
                
                # 优化请求设置
                response = requests.get(
                    url, 
                    proxies=proxies, 
                    timeout=timeout,
                    headers={
                        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language': 'en-US,en;q=0.5',
                        'Connection': 'keep-alive',
                        'Cache-Control': 'no-cache'
                    },
                    allow_redirects=True,
                    verify=False  # 跳过SSL验证以提高速度
                )
                response_time = int((time.time() - start_time) * 1000)
                
                if response.status_code == 200:
                    results.append({
                        'url': url,
                        'success': True,
                        'response_time': response_time,
                        'status_code': response.status_code
                    })
                    # 注释掉早期中断，确保测试所有URL以显示完整的测试结果
                    # if url == test_urls[0][0]:
                    #     break
                elif response.status_code == 403 and ('163.com' in url or 'baidu.com' in url):
                    # 这些网站的403错误视为网站限制，不算失败
                    results.append({
                        'url': url,
                        'success': True,  # 标记为成功，因为代理工作正常
                        'response_time': response_time,
                        'error': '网站限制(403) - 代理工作正常',
                        'status_code': response.status_code
                    })
                else:
                    results.append({
                        'url': url,
                        'success': False,
                        'response_time': response_time,
                        'error': f'HTTP {response.status_code}',
                        'status_code': response.status_code
                    })
                    
            except requests.exceptions.ConnectTimeout:
                results.append({
                    'url': url,
                    'success': False,
                    'response_time': int((time.time() - start_time) * 1000),
                    'error': '连接超时'
                })
            except requests.exceptions.ProxyError as e:
                results.append({
                    'url': url,
                    'success': False,
                    'response_time': int((time.time() - start_time) * 1000),
                    'error': f'代理错误: {str(e)}'
                })
            except requests.exceptions.RequestException as e:
                error_msg = str(e)
                # 对于特定网站的限制，给出更友好的提示
                if ('163.com' in url or 'baidu.com' in url) and ('403' in error_msg or 'Forbidden' in error_msg):
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
            except Exception as e:
                results.append({
                    'url': url,
                    'success': False,
                    'response_time': int((time.time() - start_time) * 1000),
                    'error': str(e)
                })
        
        # 计算结果 - 优先考虑成功的测试
        successful_tests = [r for r in results if r['success']]
        
        if successful_tests:
            # 使用第一个成功测试的响应时间
            avg_response_time = successful_tests[0]['response_time']
            message = f"测试成功，延迟: {avg_response_time}ms"
            
            if len(successful_tests) > 1:
                message += f"，成功: {len(successful_tests)}/{len(results)}"
                
            return {
                'success': True,
                'message': message,
                'avg_response_time': avg_response_time,
                'results': results
            }
        else:
            # 所有测试都失败 - 简化错误消息，避免显示复杂的堆栈信息
            return {
                'success': False,
                'message': "测试失败",
                'avg_response_time': 0,
                'results': results
            }
            
    except Exception as e:
        return {
            'success': False,
            'message': '测试失败',
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
        
        # 重新排序统一代理ID
        reorder_unified_proxy_ids(db, app.config['DATABASE_TYPE'])
        
        return jsonify({
            'success': True,
            'message': f'成功删除 {len(proxy_ids)} 个代理'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'批量删除失败: {str(e)}'
        })

@app.route('/admin/api/proxy-config', methods=['GET', 'POST'])
@admin_required
def api_admin_proxy_config():
    """代理配置管理 API"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    if request.method == 'GET':
        # 获取代理配置
        try:
            if db_type == 'sqlite':
                config_rows = db.execute('SELECT * FROM proxy_config').fetchall()
            else:
                cursor = db.cursor()
                cursor.execute('SELECT * FROM proxy_config')
                config_rows = cursor.fetchall()
            
            # 转换为字典
            config = {}
            for row in config_rows:
                if db_type == 'sqlite':
                    config[row['config_key']] = row['config_value']
                else:
                    config[row[1]] = row[2]  # config_key, config_value
            
            # 获取当前激活的代理信息
            active_proxy = None
            if config.get('proxy_enabled') == '1':
                proxy_type = config.get('active_proxy_type', '')
                proxy_id = int(config.get('active_proxy_id', '0'))
                
                if proxy_type and proxy_id > 0:
                    table_name = 'socks5_proxies' if proxy_type == 'socks5' else 'http_proxies'
                    
                    if db_type == 'sqlite':
                        proxy = db.execute(f'SELECT * FROM {table_name} WHERE id = ?', (proxy_id,)).fetchone()
                    else:
                        cursor = db.cursor()
                        cursor.execute(f'SELECT * FROM {table_name} WHERE id = %s', (proxy_id,))
                        proxy = cursor.fetchone()
                    
                    if proxy:
                        if db_type == 'sqlite':
                            active_proxy = dict(proxy)
                        else:
                            columns = [desc[0] for desc in cursor.description]
                            active_proxy = dict(zip(columns, proxy))
                        active_proxy['proxy_type'] = proxy_type
            
            return jsonify({
                'success': True,
                'config': config,
                'active_proxy': active_proxy
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
        
        try:
            if action == 'enable_proxy':
                # 开启代理功能 - 测试所有代理，自动选择延迟最低的代理启用
                
                # 获取所有可用的代理
                all_proxies = []
                
                # 获取HTTP代理
                if db_type == 'sqlite':
                    http_proxies = db.execute('SELECT * FROM http_proxies WHERE status = 1').fetchall()
                else:
                    cursor = db.cursor()
                    cursor.execute('SELECT * FROM http_proxies WHERE status = 1')
                    http_proxies = cursor.fetchall()
                
                if http_proxies:
                    for proxy in http_proxies:
                        if db_type == 'sqlite':
                            proxy_dict = dict(proxy)
                        else:
                            columns = [desc[0] for desc in cursor.description]
                            proxy_dict = dict(zip(columns, proxy))
                        proxy_dict['proxy_type'] = 'http'
                        all_proxies.append(proxy_dict)
                
                # 获取SOCKS5代理
                if db_type == 'sqlite':
                    socks5_proxies = db.execute('SELECT * FROM socks5_proxies WHERE status = 1').fetchall()
                else:
                    cursor = db.cursor()
                    cursor.execute('SELECT * FROM socks5_proxies WHERE status = 1')
                    socks5_proxies = cursor.fetchall()
                
                if socks5_proxies:
                    for proxy in socks5_proxies:
                        if db_type == 'sqlite':
                            proxy_dict = dict(proxy)
                        else:
                            columns = [desc[0] for desc in cursor.description]
                            proxy_dict = dict(zip(columns, proxy))
                        proxy_dict['proxy_type'] = 'socks5'
                        all_proxies.append(proxy_dict)
                
                if not all_proxies:
                    return jsonify({
                        'success': False,
                        'message': '没有找到可用的代理配置'
                    })
                
                # 测试所有代理，选择延迟最低的
                best_proxy = None
                best_response_time = float('inf')
                test_results = []
                
                for proxy in all_proxies:
                    try:
                        # 测试代理连接
                        test_result = _perform_proxy_test(proxy, proxy['proxy_type'])
                        test_results.append({
                            'proxy': proxy,
                            'result': test_result
                        })
                        
                        # 如果测试成功且延迟更低，更新最佳代理
                        if test_result['success'] and test_result['avg_response_time'] < best_response_time:
                            best_proxy = proxy
                            best_response_time = test_result['avg_response_time']
                            
                            # 更新数据库中的响应时间
                            table_name = f"{proxy['proxy_type']}_proxies"
                            now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                            
                            if db_type == 'sqlite':
                                db.execute(f'''
                                    UPDATE {table_name}
                                    SET last_check=?, response_time=?
                                    WHERE id=?
                                ''', (now, test_result['avg_response_time'], proxy['id']))
                            else:
                                cursor = db.cursor()
                                cursor.execute(f'''
                                    UPDATE {table_name}
                                    SET last_check=%s, response_time=%s
                                    WHERE id=%s
                                ''', (now, test_result['avg_response_time'], proxy['id']))
                        
                    except Exception as e:
                        test_results.append({
                            'proxy': proxy,
                            'result': {
                                'success': False,
                                'message': f'测试失败: {str(e)}',
                                'avg_response_time': 0
                            }
                        })
                
                if not best_proxy:
                    # 如果没有测试成功的代理，选择ID最小的作为备用
                    all_proxies.sort(key=lambda x: x['id'])
                    best_proxy = all_proxies[0]
                    proxy_type = best_proxy['proxy_type']
                    proxy_id = best_proxy['id']
                    
                    # 更新代理配置
                    config_updates = [
                        ('proxy_enabled', '1'),
                        ('active_proxy_type', proxy_type),
                        ('active_proxy_id', str(proxy_id))
                    ]
                    
                    for key, value in config_updates:
                        if db_type == 'sqlite':
                            db.execute('''
                                INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                                VALUES (?, ?, CURRENT_TIMESTAMP)
                            ''', (key, value))
                        else:
                            cursor = db.cursor()
                            cursor.execute('''
                                INSERT INTO proxy_config (config_key, config_value, updated_at)
                                VALUES (%s, %s, CURRENT_TIMESTAMP)
                                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = CURRENT_TIMESTAMP
                            ''' if db_type == 'mysql' else '''
                                INSERT INTO proxy_config (config_key, config_value, updated_at)
                                VALUES (%s, %s, CURRENT_TIMESTAMP)
                                ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_at = CURRENT_TIMESTAMP
                            ''', (key, value))
                    
                    if db_type != 'sqlite':
                        db.commit()
                    else:
                        db.commit()
                    
                    proxy_name = best_proxy.get('name', '')
                    return jsonify({
                        'success': True,
                        'message': f'🟢 代理状态：已启用\n当前代理: {proxy_type.upper()}--{proxy_name}--地址: {best_proxy["host"]}:{best_proxy["port"]}，所有代理测试均失败，已选择ID最小的代理'
                    })
                
                # 找到最佳代理，更新配置
                proxy_type = best_proxy['proxy_type']
                proxy_id = best_proxy['id']
                
                config_updates = [
                    ('proxy_enabled', '1'),
                    ('active_proxy_type', proxy_type),
                    ('active_proxy_id', str(proxy_id))
                ]
                
                for key, value in config_updates:
                    if db_type == 'sqlite':
                        db.execute('''
                            INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                            VALUES (?, ?, CURRENT_TIMESTAMP)
                        ''', (key, value))
                    else:
                        cursor = db.cursor()
                        cursor.execute('''
                            INSERT INTO proxy_config (config_key, config_value, updated_at)
                            VALUES (%s, %s, CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = CURRENT_TIMESTAMP
                        ''' if db_type == 'mysql' else '''
                            INSERT INTO proxy_config (config_key, config_value, updated_at)
                            VALUES (%s, %s, CURRENT_TIMESTAMP)
                            ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_at = CURRENT_TIMESTAMP
                        ''', (key, value))
                
                if db_type != 'sqlite':
                    db.commit()
                else:
                    db.commit()
                
                proxy_name = best_proxy.get('name', '')
                return jsonify({
                    'success': True,
                    'message': f'🟢 代理状态：已启用\n当前代理: {proxy_type.upper()}--{proxy_name}--地址: {best_proxy["host"]}:{best_proxy["port"]}，平均延迟: {best_response_time}ms'
                })
                
            elif action == 'disable_proxy':
                # 关闭代理功能
                if db_type == 'sqlite':
                    db.execute('''
                        INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                        VALUES ('proxy_enabled', '0', CURRENT_TIMESTAMP)
                    ''')
                    db.commit()
                else:
                    cursor = db.cursor()
                    cursor.execute('''
                        INSERT INTO proxy_config (config_key, config_value, updated_at)
                        VALUES (%s, %s, CURRENT_TIMESTAMP)
                        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = CURRENT_TIMESTAMP
                    ''' if db_type == 'mysql' else '''
                        INSERT INTO proxy_config (config_key, config_value, updated_at)
                        VALUES (%s, %s, CURRENT_TIMESTAMP)
                        ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_at = CURRENT_TIMESTAMP
                    ''', ('proxy_enabled', '0'))
                    db.commit()
                
                return jsonify({
                    'success': True,
                    'message': '代理已关闭'
                })
                
            elif action == 'switch_proxy':
                # 切换代理
                proxy_type = data.get('proxy_type')
                proxy_id = int(data.get('proxy_id', 0))
                
                if not proxy_type or not proxy_id:
                    return jsonify({
                        'success': False,
                        'message': '请提供代理类型和ID'
                    })
                
                # 验证代理是否存在
                table_name = 'socks5_proxies' if proxy_type == 'socks5' else 'http_proxies'
                
                if db_type == 'sqlite':
                    proxy = db.execute(f'SELECT * FROM {table_name} WHERE id = ? AND status = 1', (proxy_id,)).fetchone()
                else:
                    cursor = db.cursor()
                    cursor.execute(f'SELECT * FROM {table_name} WHERE id = %s AND status = 1', (proxy_id,))
                    proxy = cursor.fetchone()
                
                if not proxy:
                    return jsonify({
                        'success': False,
                        'message': '代理不存在或已禁用'
                    })
                
                # 更新配置
                config_updates = [
                    ('proxy_enabled', '1'),
                    ('active_proxy_type', proxy_type),
                    ('active_proxy_id', str(proxy_id))
                ]
                
                for key, value in config_updates:
                    if db_type == 'sqlite':
                        db.execute('''
                            INSERT OR REPLACE INTO proxy_config (config_key, config_value, updated_at)
                            VALUES (?, ?, CURRENT_TIMESTAMP)
                        ''', (key, value))
                    else:
                        cursor = db.cursor()
                        cursor.execute('''
                            INSERT INTO proxy_config (config_key, config_value, updated_at)
                            VALUES (%s, %s, CURRENT_TIMESTAMP)
                            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = CURRENT_TIMESTAMP
                        ''' if db_type == 'mysql' else '''
                            INSERT INTO proxy_config (config_key, config_value, updated_at)
                            VALUES (%s, %s, CURRENT_TIMESTAMP)
                            ON CONFLICT (config_key) DO UPDATE SET config_value = EXCLUDED.config_value, updated_at = CURRENT_TIMESTAMP
                        ''', (key, value))
                
                if db_type != 'sqlite':
                    db.commit()
                else:
                    db.commit()
                
                # 获取代理信息
                if db_type == 'sqlite':
                    proxy_dict = dict(proxy)
                else:
                    cursor.execute(f'DESCRIBE {table_name}' if db_type == 'mysql' else 
                                 f'SELECT column_name FROM information_schema.columns WHERE table_name = \'{table_name}\'')
                    columns = [row[0] for row in cursor.fetchall()]
                    proxy_dict = dict(zip(columns, proxy))
                
                proxy_name = proxy_dict.get('name', '')
                return jsonify({
                    'success': True,
                    'message': f'🟢 代理状态：已启用\n当前代理: {proxy_type.upper()}--{proxy_name}--地址: {proxy_dict["host"]}:{proxy_dict["port"]}'
                })
            
            else:
                return jsonify({
                    'success': False,
                    'message': '无效的操作'
                })
                
        except Exception as e:
            return jsonify({
                'success': False,
                'message': f'操作失败: {str(e)}'
            })

@app.route('/admin/api/cards', methods=['GET', 'POST', 'DELETE'])
@admin_required
def api_admin_cards():
    """卡密管理 API"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    if request.method == 'GET':
        # 获取卡密列表（支持分页和搜索）
        page = int(request.args.get('page', 1))
        per_page = int(request.args.get('per_page', 10))
        search = request.args.get('search', '').strip()
        
        offset = (page - 1) * per_page
        
        # 构建查询条件
        where_clause = ""
        params = []
        if search:
            where_clause = "WHERE card_key LIKE ? OR remarks LIKE ?"
            search_param = f"%{search}%"
            params = [search_param, search_param]
        
        # 获取总数
        if db_type == 'sqlite':
            count_sql = f"SELECT COUNT(*) as count FROM cards {where_clause}"
            count_result = db.execute(count_sql, params).fetchone()
            total = count_result['count']
            
            # 获取分页数据
            sql = f"""
                SELECT *, 
                    (SELECT created_at FROM card_logs WHERE card_id = cards.id ORDER BY created_at DESC LIMIT 1) as last_used_at
                FROM cards {where_clause}
                ORDER BY id ASC 
                LIMIT ? OFFSET ?
            """
            cards = db.execute(sql, params + [per_page, offset]).fetchall()
        else:
            cursor = db.cursor()
            placeholder = '%s'
            where_mysql = where_clause.replace('?', placeholder) if where_clause else ""
            
            count_sql = f"SELECT COUNT(*) as count FROM cards {where_mysql}"
            cursor.execute(count_sql, params)
            total = cursor.fetchone()[0]
            
            sql = f"""
                SELECT c.*, 
                    (SELECT created_at FROM card_logs WHERE card_id = c.id ORDER BY created_at DESC LIMIT 1) as last_used_at
                FROM cards c {where_mysql}
                ORDER BY c.id ASC 
                LIMIT {per_page} OFFSET {offset}
            """
            cursor.execute(sql, params)
            cards = cursor.fetchall()
        
        return jsonify({
            'success': True,
            'data': [dict(card) for card in cards],
            'pagination': {
                'page': page,
                'per_page': per_page,
                'total': total,
                'pages': (total + per_page - 1) // per_page
            }
        })
    
    elif request.method == 'POST':
        # 添加或处理卡密
        data = request.get_json()
        action = data.get('action')
        
        if action == 'generate':
            return _generate_card(db, data)
        elif action == 'batch_generate':
            return _batch_generate_cards(db, data)
        elif action == 'bind_email':
            return _bind_email_to_card(db, data)
        elif action == 'edit':
            return _edit_card(db, data)
        else:
            return jsonify({
                'success': False,
                'message': '无效的操作类型'
            })
    
    elif request.method == 'DELETE':
        # 删除卡密
        data = request.get_json()
        
        if 'action' in data and data['action'] == 'batch_delete':
            return _batch_delete_cards(db, data)
        else:
            card_id = data.get('id')
            if not card_id:
                return jsonify({
                    'success': False,
                    'message': '缺少卡密ID'
                })
            
            try:
                if db_type == 'sqlite':
                    db.execute('DELETE FROM cards WHERE id = ?', (card_id,))
                    db.commit()
                else:
                    cursor = db.cursor()
                    cursor.execute('DELETE FROM cards WHERE id = %s', (card_id,))
                    db.commit()
                
                return jsonify({
                    'success': True,
                    'message': '卡密删除成功'
                })
                
            except Exception as e:
                return jsonify({
                    'success': False,
                    'message': f'删除失败: {str(e)}'
                })

@app.route('/admin/api/cards/stats')
@admin_required
def api_admin_card_stats():
    """卡密统计 API"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    try:
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        
        if db_type == 'sqlite':
            # 总数
            total_result = db.execute('SELECT COUNT(*) as count FROM cards').fetchone()
            total = total_result['count']
            
            # 可用数量（状态正常、未过期、未用完）
            active_result = db.execute('''
                SELECT COUNT(*) as count FROM cards 
                WHERE status = 1 
                AND (expired_at IS NULL OR expired_at > ?) 
                AND used_count < usage_limit
            ''', (now,)).fetchone()
            active = active_result['count']
            
            # 已使用完
            used_result = db.execute('''
                SELECT COUNT(*) as count FROM cards 
                WHERE used_count >= usage_limit
            ''').fetchone()
            used = used_result['count']
            
            # 已过期
            expired_result = db.execute('''
                SELECT COUNT(*) as count FROM cards 
                WHERE expired_at IS NOT NULL AND expired_at <= ?
            ''', (now,)).fetchone()
            expired = expired_result['count']
            
        else:
            cursor = db.cursor()
            
            # 总数
            cursor.execute('SELECT COUNT(*) as count FROM cards')
            total = cursor.fetchone()[0]
            
            # 可用数量
            cursor.execute('''
                SELECT COUNT(*) as count FROM cards 
                WHERE status = 1 
                AND (expired_at IS NULL OR expired_at > %s) 
                AND used_count < usage_limit
            ''', (now,))
            active = cursor.fetchone()[0]
            
            # 已使用完
            cursor.execute('''
                SELECT COUNT(*) as count FROM cards 
                WHERE used_count >= usage_limit
            ''')
            used = cursor.fetchone()[0]
            
            # 已过期
            cursor.execute('''
                SELECT COUNT(*) as count FROM cards 
                WHERE expired_at IS NOT NULL AND expired_at <= %s
            ''', (now,))
            expired = cursor.fetchone()[0]
        
        return jsonify({
            'success': True,
            'stats': {
                'total': total,
                'active': active,
                'used': used,
                'expired': expired
            }
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'获取统计数据失败: {str(e)}'
        })

def _generate_card_key():
    """生成12位随机小写字母和数字的卡密"""
    import random
    import string
    
    # 小写字母和数字
    chars = string.ascii_lowercase + string.digits
    return ''.join(random.choice(chars) for _ in range(12))

def _generate_card(db, data):
    """生成单个卡密"""
    usage_limit = data.get('usage_limit', 1)
    expired_at = data.get('expired_at')
    remarks = data.get('remarks', '')
    
    try:
        # 生成唯一卡密
        max_attempts = 10
        for _ in range(max_attempts):
            card_key = _generate_card_key()
            
            # 检查是否已存在
            if app.config['DATABASE_TYPE'] == 'sqlite':
                existing = db.execute('SELECT id FROM cards WHERE card_key = ?', (card_key,)).fetchone()
            else:
                cursor = db.cursor()
                cursor.execute('SELECT id FROM cards WHERE card_key = %s', (card_key,))
                existing = cursor.fetchone()
            
            if not existing:
                break
        else:
            return jsonify({
                'success': False,
                'message': '生成卡密失败，请重试'
            })
        
        # 插入卡密
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        if app.config['DATABASE_TYPE'] == 'sqlite':
            db.execute('''
                INSERT INTO cards (card_key, usage_limit, expired_at, remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ''', (card_key, usage_limit, expired_at, remarks, now, now))
            db.commit()
        else:
            cursor = db.cursor()
            cursor.execute('''
                INSERT INTO cards (card_key, usage_limit, expired_at, remarks, created_at, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s)
            ''', (card_key, usage_limit, expired_at, remarks, now, now))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'卡密生成成功：{card_key}',
            'card_key': card_key
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'生成卡密失败: {str(e)}'
        })

def _batch_generate_cards(db, data):
    """批量生成卡密"""
    count = data.get('count', 1)
    usage_limit = data.get('usage_limit', 1)
    expired_at = data.get('expired_at')
    remarks = data.get('remarks', '')
    
    if count > 100:
        return jsonify({
            'success': False,
            'message': '一次最多生成100个卡密'
        })
    
    try:
        generated_cards = []
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        
        for i in range(count):
            # 生成唯一卡密
            max_attempts = 10
            for _ in range(max_attempts):
                card_key = _generate_card_key()
                
                # 检查是否已存在（包括已生成的）
                if card_key not in generated_cards:
                    if app.config['DATABASE_TYPE'] == 'sqlite':
                        existing = db.execute('SELECT id FROM cards WHERE card_key = ?', (card_key,)).fetchone()
                    else:
                        cursor = db.cursor()
                        cursor.execute('SELECT id FROM cards WHERE card_key = %s', (card_key,))
                        existing = cursor.fetchone()
                    
                    if not existing:
                        generated_cards.append(card_key)
                        break
            else:
                return jsonify({
                    'success': False,
                    'message': f'生成第{i+1}个卡密失败，请重试'
                })
        
        # 批量插入
        if app.config['DATABASE_TYPE'] == 'sqlite':
            for card_key in generated_cards:
                db.execute('''
                    INSERT INTO cards (card_key, usage_limit, expired_at, remarks, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ''', (card_key, usage_limit, expired_at, remarks, now, now))
            db.commit()
        else:
            cursor = db.cursor()
            for card_key in generated_cards:
                cursor.execute('''
                    INSERT INTO cards (card_key, usage_limit, expired_at, remarks, created_at, updated_at)
                    VALUES (%s, %s, %s, %s, %s, %s)
                ''', (card_key, usage_limit, expired_at, remarks, now, now))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'批量生成成功，共生成 {len(generated_cards)} 个卡密',
            'generated_count': len(generated_cards),
            'card_keys': generated_cards
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'批量生成失败: {str(e)}'
        })

def _batch_delete_cards(db, data):
    """批量删除卡密"""
    card_ids = data.get('ids', [])
    
    if not card_ids:
        return jsonify({
            'success': False,
            'message': '请选择要删除的卡密'
        })
    
    try:
        if app.config['DATABASE_TYPE'] == 'sqlite':
            placeholders = ','.join(['?' for _ in card_ids])
            db.execute(f'DELETE FROM cards WHERE id IN ({placeholders})', card_ids)
            db.commit()
        else:
            cursor = db.cursor()
            placeholders = ','.join(['%s' for _ in card_ids])
            cursor.execute(f'DELETE FROM cards WHERE id IN ({placeholders})', card_ids)
            db.commit()
        
        return jsonify({
            'success': True,
            'message': f'成功删除 {len(card_ids)} 个卡密'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'批量删除失败: {str(e)}'
        })

def _edit_card(db, data):
    """编辑卡密"""
    card_id = data.get('card_id')
    usage_limit = data.get('usage_limit', 1)
    expired_at = data.get('expired_at')
    remarks = data.get('remarks', '')
    bound_email_id = data.get('bound_email_id')
    email_days_filter = data.get('email_days_filter', 7)
    sender_filter = data.get('sender_filter', '')
    
    if not card_id:
        return jsonify({
            'success': False,
            'message': '缺少卡密ID'
        })
    
    try:
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        
        # 验证bound_email_id是否有效（如果提供）
        if bound_email_id:
            if app.config['DATABASE_TYPE'] == 'sqlite':
                email_exists = db.execute('SELECT id FROM mail_accounts WHERE id = ?', (bound_email_id,)).fetchone()
            else:
                cursor = db.cursor()
                cursor.execute('SELECT id FROM mail_accounts WHERE id = %s', (bound_email_id,))
                email_exists = cursor.fetchone()
            
            if not email_exists:
                return jsonify({
                    'success': False,
                    'message': '指定的邮箱不存在'
                })
        
        if app.config['DATABASE_TYPE'] == 'sqlite':
            # 检查卡密是否存在
            card = db.execute('SELECT * FROM cards WHERE id = ?', (card_id,)).fetchone()
            if not card:
                return jsonify({
                    'success': False,
                    'message': '卡密不存在'
                })
            
            # 更新卡密
            db.execute('''
                UPDATE cards 
                SET usage_limit = ?, expired_at = ?, remarks = ?, 
                    bound_email_id = ?, email_days_filter = ?, sender_filter = ?, updated_at = ?
                WHERE id = ?
            ''', (usage_limit, expired_at, remarks, bound_email_id, email_days_filter, sender_filter, now, card_id))
            db.commit()
        else:
            cursor = db.cursor()
            # 检查卡密是否存在
            cursor.execute('SELECT * FROM cards WHERE id = %s', (card_id,))
            card = cursor.fetchone()
            if not card:
                return jsonify({
                    'success': False,
                    'message': '卡密不存在'
                })
            
            # 更新卡密
            cursor.execute('''
                UPDATE cards 
                SET usage_limit = %s, expired_at = %s, remarks = %s, 
                    bound_email_id = %s, email_days_filter = %s, sender_filter = %s, updated_at = %s
                WHERE id = %s
            ''', (usage_limit, expired_at, remarks, bound_email_id, email_days_filter, sender_filter, now, card_id))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': '卡密编辑成功'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'编辑卡密失败: {str(e)}'
        })

def _bind_email_to_card(db, data):
    """绑定邮箱到卡密"""
    card_id = data.get('card_id')
    email_id = data.get('email_id')
    
    if not card_id or not email_id:
        return jsonify({
            'success': False,
            'message': '请提供卡密ID和邮箱ID'
        })
    
    try:
        # 验证卡密和邮箱是否存在
        if app.config['DATABASE_TYPE'] == 'sqlite':
            card = db.execute('SELECT * FROM cards WHERE id = ?', (card_id,)).fetchone()
            email = db.execute('SELECT * FROM mail_accounts WHERE id = ?', (email_id,)).fetchone()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT * FROM cards WHERE id = %s', (card_id,))
            card = cursor.fetchone()
            cursor.execute('SELECT * FROM mail_accounts WHERE id = %s', (email_id,))
            email = cursor.fetchone()
        
        if not card:
            return jsonify({
                'success': False,
                'message': '卡密不存在'
            })
        
        if not email:
            return jsonify({
                'success': False,
                'message': '邮箱不存在'
            })
        
        # 更新卡密备注，记录绑定的邮箱
        if app.config['DATABASE_TYPE'] == 'sqlite':
            email_dict = dict(email)
            new_remarks = f"绑定邮箱: {email_dict['email']}"
            db.execute('UPDATE cards SET remarks = ? WHERE id = ?', (new_remarks, card_id))
            db.commit()
        else:
            cursor = db.cursor()
            # 获取邮箱信息
            cursor.execute('SELECT email FROM mail_accounts WHERE id = %s', (email_id,))
            email_address = cursor.fetchone()[0]
            new_remarks = f"绑定邮箱: {email_address}"
            cursor.execute('UPDATE cards SET remarks = %s WHERE id = %s', (new_remarks, card_id))
            db.commit()
        
        return jsonify({
            'success': True,
            'message': '邮箱绑定成功'
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'绑定失败: {str(e)}'
        })

@app.route('/admin/api/cards/<int:card_id>/available-emails', methods=['GET'])
@admin_required
def api_admin_card_available_emails(card_id):
    """获取指定卡密可绑定的邮箱列表（排除已绑定的邮箱）"""
    db = get_db()
    db_type = app.config['DATABASE_TYPE']
    
    try:
        # 获取所有邮箱
        if db_type == 'sqlite':
            all_emails = db.execute('SELECT * FROM mail_accounts ORDER BY email ASC').fetchall()
        else:
            cursor = db.cursor()
            cursor.execute('SELECT * FROM mail_accounts ORDER BY email ASC')
            all_emails = cursor.fetchall()
        
        # 获取已绑定的邮箱ID（排除当前卡密）
        if db_type == 'sqlite':
            bound_email_ids = db.execute('''
                SELECT DISTINCT bound_email_id 
                FROM cards 
                WHERE bound_email_id IS NOT NULL AND id != ?
            ''', (card_id,)).fetchall()
            bound_ids = [row['bound_email_id'] for row in bound_email_ids]
        else:
            cursor = db.cursor()
            cursor.execute('''
                SELECT DISTINCT bound_email_id 
                FROM cards 
                WHERE bound_email_id IS NOT NULL AND id != %s
            ''', (card_id,))
            bound_email_ids = cursor.fetchall()
            bound_ids = [row[0] for row in bound_email_ids]
        
        # 过滤掉已绑定的邮箱
        available_emails = []
        for email in all_emails:
            email_id = email['id'] if db_type == 'sqlite' else email[0]
            if email_id not in bound_ids:
                available_emails.append(dict(email) if db_type == 'sqlite' else dict(zip([desc[0] for desc in cursor.description], email)))
        
        return jsonify({
            'success': True,
            'data': available_emails
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'获取可绑定邮箱列表失败: {str(e)}'
        })

@app.route('/admin/api/cards/generate-api/<card_key>', methods=['GET'])
def api_admin_generate_card_api_page(card_key):
    """为卡密生成API页面"""
    try:
        # 获取数据库连接
        db = get_db()
        db_type = app.config['DATABASE_TYPE']
        
        # 查询卡密信息，包括绑定的邮箱
        if db_type == 'sqlite':
            card_query = """
                SELECT c.*, e.email, e.server 
                FROM cards c 
                LEFT JOIN mail_accounts e ON c.bound_email_id = e.id 
                WHERE c.card_key = ?
            """
            card_result = db.execute(card_query, (card_key,)).fetchone()
            if card_result:
                card_result = dict(card_result)  # Convert Row to dict
        else:
            cursor = db.cursor()
            card_query = """
                SELECT c.*, e.email, e.server 
                FROM cards c 
                LEFT JOIN mail_accounts e ON c.bound_email_id = e.id 
                WHERE c.card_key = %s
            """
            cursor.execute(card_query, (card_key,))
            card_result = cursor.fetchone()
            if card_result and hasattr(card_result, '_asdict'):
                card_result = card_result._asdict()
            elif card_result:
                # Handle tuple result
                columns = [desc[0] for desc in cursor.description]
                card_result = dict(zip(columns, card_result))
        
        if not card_result:
            return jsonify({
                'success': False,
                'message': '卡密不存在'
            }), 404
        
        # 检查卡密是否已绑定邮箱
        has_bound_email = card_result.get('bound_email_id') is not None
        bound_email = card_result.get('email') if has_bound_email else None
        
        # 根据绑定状态生成不同的API页面内容
        if has_bound_email:
            # 已绑定邮箱：无输入框，直接显示获取邮件按钮
            input_section = f"""
            <div class="bound-email-info">
                <div class="email-info">
                    <span class="email-label">绑定邮箱:</span>
                    <span class="email-address">{bound_email}</span>
                </div>
                <p class="info-text">此卡密已绑定邮箱，点击下方按钮直接获取邮件</p>
            </div>
            
            <div class="action-group">
                <button class="get-mail-btn" onclick="getMail()">获取邮件</button>
            </div>"""
        else:
            # 未绑定邮箱：有输入框，可手动输入邮箱
            input_section = f"""
            <div class="unbound-email-info">
                <p class="info-text">此卡密未绑定邮箱，请输入已添加的邮箱地址进行API取件</p>
            </div>
            
            <div class="input-group">
                <input type="email" id="emailInput" placeholder="请输入邮箱地址进行API取件" required>
                <button class="get-mail-btn" onclick="getMail()">API取件</button>
            </div>"""
        
        api_content = f"""<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API取件页面 - {card_key}</title>
    <style>
        * {{
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }}
        
        body {{
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }}
        
        .container {{
            max-width: 800px;
            margin: 0 auto;
        }}
        
        .header {{
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }}
        
        .header h1 {{
            font-size: 32px;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }}
        
        .header p {{
            font-size: 16px;
            opacity: 0.9;
        }}
        
        .main-card {{
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }}
        
        .input-group {{
            display: flex;
            margin-bottom: 20px;
            gap: 15px;
        }}
        
        .action-group {{
            text-align: center;
            margin-bottom: 20px;
        }}
        
        .bound-email-info {{
            margin-bottom: 25px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }}
        
        .unbound-email-info {{
            margin-bottom: 20px;
            padding: 15px;
            background: #fef3c7;
            border-radius: 12px;
            border-left: 4px solid #f59e0b;
        }}
        
        .email-info {{
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }}
        
        .email-label {{
            font-weight: 600;
            color: #374151;
        }}
        
        .email-address {{
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }}
        
        .info-text {{
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }}
        
        .input-group input {{
            flex: 1;
            padding: 15px 20px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
        }}
        
        .input-group input:focus {{
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }}
        
        .get-mail-btn {{
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }}
        
        .get-mail-btn:hover {{
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }}
        
        .get-mail-btn:disabled {{
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }}
        
        .loading {{
            display: none;
            text-align: center;
            padding: 20px;
            color: #667eea;
            font-size: 16px;
        }}
        
        .loading .spinner {{
            width: 40px;
            height: 40px;
            border: 4px solid #f3f4f6;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }}
        
        @keyframes spin {{
            0% {{ transform: rotate(0deg); }}
            100% {{ transform: rotate(360deg); }}
        }}
        
        .message {{
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            font-weight: 500;
        }}
        
        .message.success {{
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }}
        
        .message.error {{
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }}
        
        .message.info {{
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }}
        
        .mail-display {{
            display: none;
            background: #f8fafc;
            border-radius: 15px;
            padding: 25px;
            margin-top: 25px;
        }}
        
        .mail-header {{
            margin-bottom: 20px;
        }}
        
        .mail-subject {{
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
        }}
        
        .mail-meta {{
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }}
        
        .mail-meta-item {{
            background: white;
            padding: 12px 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }}
        
        .mail-meta-label {{
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            display: block;
            margin-bottom: 5px;
        }}
        
        .mail-body {{
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
        }}
        
        .api-info {{
            background: #f1f5f9;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 14px;
            color: #475569;
        }}
        
        .card-key {{
            background: #667eea;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📧 API邮件查看</h1>
            <p>专用API取件页面</p>
        </div>
        
        <div class="main-card">
            <div class="api-info">
                <strong>当前卡密:</strong> <span class="card-key">{card_key}</span>
                <br><br>
                此页面专为卡密 <strong>{card_key}</strong> 生成，可用于API自动取件。
            </div>
            
            {input_section}
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <div>正在通过API获取邮件，请稍候...</div>
            </div>
            
            <div id="message"></div>
        </div>
        
        <div class="mail-display" id="mailDisplay">
            <div class="mail-header">
                <div class="mail-subject" id="mailSubject"></div>
                <div class="mail-meta">
                    <div class="mail-meta-item">
                        <span class="mail-meta-label">发件人:</span>
                        <span id="mailFrom"></span>
                    </div>
                    <div class="mail-meta-item">
                        <span class="mail-meta-label">收件人:</span>
                        <span id="mailTo"></span>
                    </div>
                    <div class="mail-meta-item">
                        <span class="mail-meta-label">时间:</span>
                        <span id="mailDate"></span>
                    </div>
                    <div class="mail-meta-item">
                        <span class="mail-meta-label">大小:</span>
                        <span id="mailSize"></span>
                    </div>
                </div>
            </div>
            
            <div class="mail-body" id="mailBody"></div>
        </div>
    </div>
    
    <script>
        // 检查是否已绑定邮箱
        const hasBoundEmail = {str(has_bound_email).lower()};
        const boundEmail = "{bound_email if bound_email else ''}";
        
        // 回车键触发获取邮件（仅在有输入框时）
        const emailInput = document.getElementById('emailInput');
        if (emailInput) {{
            emailInput.addEventListener('keypress', function(e) {{
                if (e.key === 'Enter') {{
                    getMail();
                }}
            }});
        }}
        
        async function getMail() {{
            const loading = document.getElementById('loading');
            const message = document.getElementById('message');
            const mailDisplay = document.getElementById('mailDisplay');
            const getMailBtn = document.querySelector('.get-mail-btn');
            
            let email;
            
            if (hasBoundEmail) {{
                // 已绑定邮箱，直接使用绑定的邮箱
                email = boundEmail;
            }} else {{
                // 未绑定邮箱，从输入框获取
                const emailInput = document.getElementById('emailInput');
                email = emailInput.value.trim();
                
                if (!email) {{
                    showMessage('请输入邮箱地址', 'error');
                    return;
                }}
                
                if (!isValidEmail(email)) {{
                    showMessage('请输入有效的邮箱地址', 'error');
                    return;
                }}
            }}
            
            // 显示加载状态
            loading.style.display = 'block';
            getMailBtn.disabled = true;
            getMailBtn.textContent = hasBoundEmail ? '获取中...' : 'API取件中...';
            message.innerHTML = '';
            mailDisplay.style.display = 'none';
            
            try {{
                const response = await fetch('/api/get_mail', {{
                    method: 'POST',
                    headers: {{
                        'Content-Type': 'application/json',
                        'X-Card-Key': '{card_key}'
                    }},
                    body: JSON.stringify({{ 
                        email: email,
                        card_key: '{card_key}'
                    }})
                }});
                
                const data = await response.json();
                
                if (data.success) {{
                    if (data.mail) {{
                        displayMail(data.mail);
                        showMessage('邮件获取成功', 'success');
                    }} else {{
                        showMessage('邮箱中暂无邮件', 'info');
                    }}
                }} else {{
                    showMessage(data.message || '获取邮件失败', 'error');
                }}
                
            }} catch (error) {{
                console.error('API请求失败:', error);
                showMessage('网络请求失败，请检查网络连接', 'error');
            }} finally {{
                // 隐藏加载状态
                loading.style.display = 'none';
                getMailBtn.disabled = false;
                getMailBtn.textContent = hasBoundEmail ? '获取邮件' : 'API取件';
            }}
        }}
        
        function displayMail(mail) {{
            document.getElementById('mailSubject').textContent = mail.subject || '(无主题)';
            document.getElementById('mailFrom').textContent = mail.from || '未知';
            document.getElementById('mailTo').textContent = mail.to || '未知';
            document.getElementById('mailDate').textContent = mail.date || '未知';
            document.getElementById('mailSize').textContent = formatFileSize(mail.size || 0);
            document.getElementById('mailBody').textContent = mail.body || '(邮件内容为空)';
            
            document.getElementById('mailDisplay').style.display = 'block';
        }}
        
        function showMessage(text, type) {{
            const message = document.getElementById('message');
            message.innerHTML = `<div class="message ${{type}}">${{text}}</div>`;
        }}
        
        function isValidEmail(email) {{
            const re = /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/;
            return re.test(email);
        }}
        
        function formatFileSize(bytes) {{
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }}
    </script>
</body>
</html>"""
        
        return api_content, 200, {'Content-Type': 'text/html; charset=utf-8'}
        
    except Exception as e:
        return jsonify({
            'success': False,
            'message': f'生成API页面失败: {str(e)}'
        }), 500

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