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

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_mail_accounts_email ON mail_accounts(email);
CREATE INDEX IF NOT EXISTS idx_mail_accounts_created_at ON mail_accounts(created_at);

-- 服务器地址管理表
CREATE TABLE IF NOT EXISTS server_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    server_name TEXT NOT NULL UNIQUE,
    server_address TEXT NOT NULL,
    default_port_imap INTEGER DEFAULT 993,
    default_port_pop3 INTEGER DEFAULT 995,
    default_ssl INTEGER DEFAULT 1,
    remarks TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 创建服务器地址索引
CREATE INDEX IF NOT EXISTS idx_server_addresses_name ON server_addresses(server_name);
CREATE INDEX IF NOT EXISTS idx_server_addresses_address ON server_addresses(server_address);

-- 插入一些常用邮箱服务器地址
INSERT OR IGNORE INTO server_addresses (server_name, server_address, default_port_imap, default_port_pop3, default_ssl, remarks) VALUES
('Gmail', 'imap.gmail.com', 993, 995, 1, 'Google Gmail 邮箱服务器'),
('Outlook/Hotmail', 'outlook.office365.com', 993, 995, 1, 'Microsoft Outlook/Hotmail 邮箱服务器'),
('Yahoo', 'imap.mail.yahoo.com', 993, 995, 1, 'Yahoo 邮箱服务器'),
('QQ邮箱', 'imap.qq.com', 993, 995, 1, '腾讯 QQ 邮箱服务器'),
('163邮箱', 'imap.163.com', 993, 995, 1, '网易 163 邮箱服务器'),
('126邮箱', 'imap.126.com', 993, 995, 1, '网易 126 邮箱服务器'),
('189邮箱', 'imap.189.cn', 993, 995, 1, '天翼 189 邮箱服务器'),
('企业微信邮箱', 'imap.exmail.qq.com', 993, 995, 1, '腾讯企业微信邮箱服务器');