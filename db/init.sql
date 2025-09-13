-- 邮件账号管理数据库初始化
CREATE TABLE IF NOT EXISTS mail_accounts (
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

-- 服务器地址管理表
CREATE TABLE IF NOT EXISTS server_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_name TEXT NOT NULL,
    server_address TEXT NOT NULL,
    default_port_imap INTEGER DEFAULT 993,
    default_port_pop3 INTEGER DEFAULT 995,
    ssl_enabled INTEGER DEFAULT 1,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- HTTP代理管理表
CREATE TABLE IF NOT EXISTS http_proxies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    host TEXT NOT NULL,
    port INTEGER NOT NULL,
    username TEXT DEFAULT '',
    password TEXT DEFAULT '',
    status INTEGER DEFAULT 1,
    last_check DATETIME DEFAULT NULL,
    response_time INTEGER DEFAULT 0,
    success_count INTEGER DEFAULT 0,
    fail_count INTEGER DEFAULT 0,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- SOCKS5代理管理表
CREATE TABLE IF NOT EXISTS socks5_proxies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    host TEXT NOT NULL,
    port INTEGER NOT NULL,
    username TEXT DEFAULT '',
    password TEXT DEFAULT '',
    status INTEGER DEFAULT 1,
    last_check DATETIME DEFAULT NULL,
    response_time INTEGER DEFAULT 0,
    success_count INTEGER DEFAULT 0,
    fail_count INTEGER DEFAULT 0,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 全局代理配置表
CREATE TABLE IF NOT EXISTS proxy_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    config_key TEXT NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    description TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 插入默认代理配置
INSERT OR IGNORE INTO proxy_config (config_key, config_value, description) VALUES 
('proxy_enabled', '0', '全局代理启用状态'),
('active_proxy_type', '', '当前激活的代理类型 (http/socks5)'),
('active_proxy_id', '0', '当前激活的代理ID'),
('proxy_timeout', '30', '代理连接超时时间（秒）');

-- 卡密管理表
CREATE TABLE IF NOT EXISTS cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_code TEXT NOT NULL UNIQUE,
    card_type TEXT NOT NULL DEFAULT 'standard',
    status INTEGER NOT NULL DEFAULT 1,
    used_times INTEGER DEFAULT 0,
    max_usage INTEGER DEFAULT 1,
    valid_days INTEGER DEFAULT 30,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    first_used_at DATETIME DEFAULT NULL,
    last_used_at DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT ''
);

-- 卡密使用日志表
CREATE TABLE IF NOT EXISTS card_usage_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_id INTEGER NOT NULL,
    card_code TEXT NOT NULL,
    user_ip TEXT DEFAULT '',
    user_agent TEXT DEFAULT '',
    action TEXT NOT NULL DEFAULT 'use',
    result TEXT NOT NULL DEFAULT 'success',
    message TEXT DEFAULT '',
    used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
);

-- 收件日志表
CREATE TABLE IF NOT EXISTS mail_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    request_ip TEXT DEFAULT '',
    user_agent TEXT DEFAULT '',
    card_code TEXT DEFAULT '',
    success INTEGER DEFAULT 1,
    message TEXT DEFAULT '',
    mail_count INTEGER DEFAULT 0,
    response_time INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 系统配置表
CREATE TABLE IF NOT EXISTS system_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    config_key TEXT NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    config_type TEXT DEFAULT 'string',
    description TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 插入默认系统配置
INSERT OR IGNORE INTO system_config (config_key, config_value, config_type, description) VALUES 
('site_title', '邮件查看系统', 'string', '网站标题'),
('max_daily_usage', '100', 'integer', '每日最大使用次数'),
('default_card_validity', '30', 'integer', '默认卡密有效期（天）'),
('require_card_auth', '0', 'boolean', '是否需要卡密验证'),
('admin_notification', '1', 'boolean', '是否启用管理员通知'),
('auto_cleanup_logs', '1', 'boolean', '是否自动清理日志'),
('log_retention_days', '30', 'integer', '日志保留天数');

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_cards_code ON cards(card_code);
CREATE INDEX IF NOT EXISTS idx_cards_status ON cards(status);
CREATE INDEX IF NOT EXISTS idx_cards_created_at ON cards(created_at);
CREATE INDEX IF NOT EXISTS idx_card_usage_logs_card_id ON card_usage_logs(card_id);
CREATE INDEX IF NOT EXISTS idx_card_usage_logs_used_at ON card_usage_logs(used_at);
CREATE INDEX IF NOT EXISTS idx_mail_logs_email ON mail_logs(email);
CREATE INDEX IF NOT EXISTS idx_mail_logs_created_at ON mail_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_system_config_key ON system_config(config_key);