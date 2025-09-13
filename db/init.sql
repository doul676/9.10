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