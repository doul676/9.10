# Python邮件处理器技术文档

## 概述

Python邮件处理器是为了解决php-imap扩展代理支持不足而开发的替代方案。它使用Python的标准库`imaplib`和`poplib`提供邮件获取功能，并通过可选的第三方库支持HTTP和SOCKS5代理。

## 架构设计

### 核心组件

1. **mail_handler.py** - Python邮件处理脚本
2. **python_mail_fetcher.php** - PHP桥接类
3. **mail_fetcher.php** - 统一接口，自动选择实现

### 工作流程

```
PHP API调用 → MailFetcher → PythonMailFetcher → Python脚本 → 返回JSON
                     ↓ (如果失败)
                 OriginalMailFetcher (PHP-IMAP)
```

## Python脚本详解

### 主要类

#### ProxyConfig
代理配置存储类，包含：
- enabled: 是否启用代理
- proxy_type: 代理类型（http/socks5）
- host/port: 代理服务器地址和端口
- username/password: 认证信息
- name: 代理名称

#### MailHandler
主要邮件处理类，提供：
- 连接邮件服务器（IMAP/POP3）
- 获取最新邮件
- 解析邮件内容（文本、HTML、附件、图片）
- 测试连接
- 代理支持

### 关键方法

#### _load_proxy_config()
从SQLite数据库加载代理配置：
1. 检查proxy_config表是否存在
2. 读取代理启用状态和活动代理信息
3. 从相应的代理表获取详细配置

#### _setup_socks_proxy()
设置SOCKS5代理：
1. 检查PySocks库是否可用
2. 配置全局socket代理
3. 支持用户名/密码认证

#### connect()
建立邮件服务器连接：
1. 根据协议选择IMAP或POP3
2. 处理SSL/TLS连接
3. 应用代理设置
4. 提供详细的错误诊断

#### _parse_email()
解析邮件内容：
1. 解码邮件头信息（主题、发件人、收件人、日期）
2. 处理多部分邮件结构
3. 提取文本、HTML内容
4. 处理附件和内嵌图片
5. 字符编码转换

### 命令行接口

脚本支持以下操作：

```bash
python3 mail_handler.py get_mail <server> <port> <username> <password> <protocol> <ssl>
python3 mail_handler.py test_connection <server> <port> <username> <password> <protocol> <ssl>
```

返回JSON格式的结果，包含成功状态、邮件数据、代理信息等。

## PHP桥接

### PythonMailFetcher类

主要功能：
- 构建Python命令行调用
- 处理参数转义和安全性
- 解析Python脚本返回的JSON数据
- 提供与原有MailFetcher兼容的接口

### 关键方法

#### buildCommand()
构建安全的Python命令：
1. 查找Python解释器
2. 转义所有参数
3. 添加错误重定向

#### findPython()
查找可用的Python解释器：
1. 尝试常见的Python命令（python3, python）
2. 检查常见安装路径
3. 提供详细的错误信息

#### executeCommand()
执行Python命令：
1. 记录执行日志
2. 捕获输出和错误
3. 处理执行失败的情况

## 代理支持

### 支持的代理类型

#### HTTP代理
- 使用标准的HTTP CONNECT方法
- 支持基本认证
- 通过环境变量配置

#### SOCKS5代理
- 需要PySocks库支持
- 支持用户名/密码认证
- 全局socket替换

### 代理配置流程

1. 从数据库读取代理配置
2. 检查代理类型和状态
3. 设置相应的代理环境
4. 在连接时应用代理设置

## 错误处理

### 分层错误处理

1. **Python层**：捕获连接错误、解析错误等
2. **PHP桥接层**：处理Python执行失败、JSON解析错误等
3. **统一接口层**：自动回退到备用实现

### 错误类型分类

#### 连接错误
- SSL证书问题
- 服务器拒绝连接
- 认证失败
- 网络超时
- 主机名解析失败

#### 代理错误
- 代理服务器不可达
- 代理认证失败
- SOCKS库缺失

#### 系统错误
- Python解释器不可用
- 脚本文件缺失
- 权限问题

## 性能优化

### 连接池
目前每次请求建立新连接，未来可以考虑：
- 连接复用
- 连接池管理
- 长连接支持

### 缓存机制
- 代理配置缓存
- 邮件内容缓存（可选）

### 内存管理
- 大附件流式处理
- 及时释放资源

## 安全考虑

### 输入验证
- 严格的参数类型检查
- SQL注入防护
- 命令注入防护

### 敏感信息保护
- 密码不在日志中显示
- 安全的参数传递
- SSL证书验证选项

### 权限控制
- 最小权限原则
- 文件权限检查
- 执行环境隔离

## 测试和调试

### 单元测试
- Python脚本功能测试
- PHP桥接测试
- 集成测试

### 调试工具
- 详细的日志记录
- 错误诊断功能
- 系统状态检查

### 性能监控
- 连接时间监控
- 代理性能统计
- 错误率统计

## 部署和维护

### 依赖管理
- Python标准库（无额外依赖）
- 可选依赖：PySocks、requests
- 自动依赖检查

### 升级策略
- 向后兼容设计
- 平滑升级路径
- 回滚机制

### 监控和告警
- 系统健康检查
- 错误率监控
- 性能指标收集

## 未来发展

### 计划功能
- 邮件搜索和过滤
- 批量邮件处理
- 更多协议支持（Exchange等）
- 高级代理功能

### 性能提升
- 异步处理
- 并发连接
- 智能缓存

### 安全增强
- 更严格的证书验证
- 加密传输
- 访问控制列表