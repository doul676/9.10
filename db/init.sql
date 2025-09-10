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

-- 代理池管理表
CREATE TABLE IF NOT EXISTS proxy_pool (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proxy_name TEXT NOT NULL DEFAULT '',
    proxy_type TEXT NOT NULL CHECK (proxy_type IN ('http', 'socks5')),
    proxy_host TEXT NOT NULL,
    proxy_port INTEGER NOT NULL,
    proxy_username TEXT DEFAULT '',
    proxy_password TEXT DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 1,
    is_verified INTEGER NOT NULL DEFAULT 0,
    last_test_time DATETIME DEFAULT NULL,
    test_success_count INTEGER DEFAULT 0,
    test_fail_count INTEGER DEFAULT 0,
    response_time INTEGER DEFAULT 0,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_mail_accounts_email ON mail_accounts(email);
CREATE INDEX IF NOT EXISTS idx_mail_accounts_created_at ON mail_accounts(created_at);
CREATE INDEX IF NOT EXISTS idx_server_addresses_name ON server_addresses(server_name);
CREATE INDEX IF NOT EXISTS idx_server_addresses_address ON server_addresses(server_address);
CREATE INDEX IF NOT EXISTS idx_proxy_pool_type ON proxy_pool(proxy_type);
CREATE INDEX IF NOT EXISTS idx_proxy_pool_active ON proxy_pool(is_active);
CREATE INDEX IF NOT EXISTS idx_proxy_pool_host_port ON proxy_pool(proxy_host, proxy_port);