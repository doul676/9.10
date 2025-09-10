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

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_mail_accounts_email ON mail_accounts(email);
CREATE INDEX IF NOT EXISTS idx_mail_accounts_created_at ON mail_accounts(created_at);
CREATE INDEX IF NOT EXISTS idx_server_addresses_name ON server_addresses(server_name);
CREATE INDEX IF NOT EXISTS idx_server_addresses_address ON server_addresses(server_address);