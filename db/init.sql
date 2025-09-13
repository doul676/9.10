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
CREATE TABLE IF NOT EXISTS kami_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_key TEXT NOT NULL UNIQUE,
    card_type TEXT NOT NULL DEFAULT 'monthly',
    duration INTEGER NOT NULL DEFAULT 30,
    price DECIMAL(10,2) DEFAULT 0.00,
    status INTEGER DEFAULT 1,
    used_by TEXT DEFAULT NULL,
    used_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 卡密使用日志表
CREATE TABLE IF NOT EXISTS kami_usage_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_id INTEGER NOT NULL,
    card_key TEXT NOT NULL,
    user_email TEXT NOT NULL,
    action TEXT NOT NULL DEFAULT 'activate',
    ip_address TEXT DEFAULT '',
    user_agent TEXT DEFAULT '',
    additional_info TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (card_id) REFERENCES kami_cards(id) ON DELETE CASCADE
);

-- 收件日志表
CREATE TABLE IF NOT EXISTS mail_receive_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mail_account_id INTEGER NOT NULL,
    email_address TEXT NOT NULL,
    subject TEXT DEFAULT '',
    sender TEXT DEFAULT '',
    recipient TEXT DEFAULT '',
    message_id TEXT DEFAULT '',
    received_at DATETIME DEFAULT NULL,
    email_size INTEGER DEFAULT 0,
    status TEXT DEFAULT 'received',
    error_message TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mail_account_id) REFERENCES mail_accounts(id) ON DELETE CASCADE
);

-- 系统配置表
CREATE TABLE IF NOT EXISTS system_config (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    config_key TEXT NOT NULL UNIQUE,
    config_value TEXT NOT NULL,
    config_type TEXT DEFAULT 'string',
    description TEXT DEFAULT '',
    is_editable INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 插入默认系统配置
INSERT OR IGNORE INTO system_config (config_key, config_value, config_type, description, is_editable) VALUES 
('site_title', '邮件查看系统', 'string', '网站标题', 1),
('site_description', '专业的邮件管理和查看系统', 'string', '网站描述', 1),
('max_email_check_interval', '300', 'integer', '邮件检查最大间隔（秒）', 1),
('enable_registration', '0', 'boolean', '是否允许用户注册', 1),
('enable_email_forwarding', '1', 'boolean', '是否启用邮件转发', 1),
('max_storage_size', '1000', 'integer', '最大存储大小（MB）', 1),
('cleanup_days', '30', 'integer', '自动清理天数', 1),
('admin_email', 'admin@example.com', 'string', '管理员邮箱', 1),
('smtp_host', '', 'string', 'SMTP服务器', 1),
('smtp_port', '587', 'integer', 'SMTP端口', 1),
('smtp_username', '', 'string', 'SMTP用户名', 1),
('smtp_password', '', 'string', 'SMTP密码', 1),
('system_timezone', 'Asia/Shanghai', 'string', '系统时区', 1);

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_mail_accounts_email ON mail_accounts(email);
CREATE INDEX IF NOT EXISTS idx_mail_accounts_created_at ON mail_accounts(created_at);
CREATE INDEX IF NOT EXISTS idx_server_addresses_name ON server_addresses(server_name);
CREATE INDEX IF NOT EXISTS idx_server_addresses_address ON server_addresses(server_address);
CREATE INDEX IF NOT EXISTS idx_http_proxies_host_port ON http_proxies(host, port);
CREATE INDEX IF NOT EXISTS idx_http_proxies_status ON http_proxies(status);
CREATE INDEX IF NOT EXISTS idx_socks5_proxies_host_port ON socks5_proxies(host, port);
CREATE INDEX IF NOT EXISTS idx_socks5_proxies_status ON socks5_proxies(status);
CREATE INDEX IF NOT EXISTS idx_proxy_config_key ON proxy_config(config_key);
CREATE INDEX IF NOT EXISTS idx_kami_cards_key ON kami_cards(card_key);
CREATE INDEX IF NOT EXISTS idx_kami_cards_status ON kami_cards(status);
CREATE INDEX IF NOT EXISTS idx_kami_usage_logs_card_id ON kami_usage_logs(card_id);
CREATE INDEX IF NOT EXISTS idx_kami_usage_logs_user_email ON kami_usage_logs(user_email);
CREATE INDEX IF NOT EXISTS idx_mail_receive_logs_account_id ON mail_receive_logs(mail_account_id);
CREATE INDEX IF NOT EXISTS idx_mail_receive_logs_email ON mail_receive_logs(email_address);
CREATE INDEX IF NOT EXISTS idx_mail_receive_logs_created_at ON mail_receive_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_system_config_key ON system_config(config_key);