<?php
session_start();

// 检查登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ./');
    exit();
}

// 处理退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>代理池 - 邮件查看系统</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --sidebar-width: 280px;
            --header-height: 70px;
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            line-height: 1.6;
            color: #334155;
            transition: var(--transition);
        }
        
        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            z-index: 1000;
            transition: var(--transition);
            box-shadow: 4px 0 15px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-logo {
            color: white;
            font-size: 20px;
            font-weight: 700;
            text-align: center;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .sidebar-nav {
            padding: 20px 0;
        }
        
        .nav-item {
            display: block;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 15px 25px;
            transition: var(--transition);
            border-left: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .nav-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.1);
            transform: translateX(-100%);
            transition: var(--transition);
        }
        
        .nav-item:hover::before {
            transform: translateX(0);
        }
        
        .nav-item:hover,
        .nav-item.active {
            color: white;
            background: rgba(255,255,255,0.15);
            border-left-color: white;
            transform: translateX(5px);
        }
        
        .nav-item i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: var(--transition);
        }
        
        .header {
            background: white;
            height: var(--header-height);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #64748b;
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }
        
        /* Content Area */
        .content {
            padding: 30px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 25px;
            transition: var(--transition);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1), 0 4px 10px rgba(0,0,0,0.06);
        }
        
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .header-title {
                font-size: 20px;
            }
            
            .content {
                padding: 20px;
            }
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Search and Filters */
        .search-filters {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .proxy-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .proxy-table th,
        .proxy-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }

        .proxy-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .proxy-table tbody tr {
            transition: var(--transition);
        }

        .proxy-table tbody tr:hover {
            background: #f8fafc;
        }

        .proxy-table .text-center {
            text-align: center;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-verified {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-unverified {
            background: #fef3c7;
            color: #92400e;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal.show {
            display: block;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            z-index: 1999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal-overlay.show {
            display: block;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }

        .modal-header {
            padding: 20px 25px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: #1e293b;
            font-size: 18px;
            font-weight: 600;
        }

        .close {
            color: #64748b;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            color: #1e293b;
        }

        .modal form {
            padding: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pagination-info {
            color: #64748b;
            font-size: 14px;
        }

        .pagination {
            display: flex;
            gap: 5px;
        }

        .pagination button {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #374151;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
        }

        .pagination button:hover:not(:disabled) {
            background: #f8fafc;
            border-color: var(--primary-color);
        }

        .pagination button.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Loading and message styles */
        .loading {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message.info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Card header actions */
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header-actions {
            color: #64748b;
            font-size: 14px;
        }

        /* Action buttons in table */
        .action-btn {
            padding: 4px 8px;
            margin: 0 2px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: var(--transition);
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 500px;
        }
        
        .toast {
            padding: 15px 20px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transform: translateX(550px);
            opacity: 0;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            word-wrap: break-word;
            white-space: pre-wrap;
        }
        
        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .toast.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .toast.error {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .toast.info {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        
        .toast::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255,255,255,0.8);
            animation: toastProgress 3s linear;
        }
        
        @keyframes toastProgress {
            from { width: 100%; }
            to { width: 0%; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">📧 邮件管理系统</div>
        </div>
        <nav class="sidebar-nav">
            <a href="home.php" class="nav-item">
                <i>🏠</i> 首页
            </a>
            <a href="mailbox.php" class="nav-item">
                <i>📫</i> 邮箱管理
            </a>
            <a href="daili.php" class="nav-item active">
                <i>🌐</i> 代理池
            </a>
            <a href="kami.php" class="nav-item">
                <i>🔑</i> 卡密管理
            </a>
            <a href="kamirizhi.php" class="nav-item">
                <i>📝</i> 卡密日志
            </a>
            <a href="shoujian.php" class="nav-item">
                <i>📧</i> 收件日志
            </a>
            <a href="system.php" class="nav-item">
                <i>⚙️</i> 系统设置
            </a>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <h1 class="header-title">代理池</h1>
            <div class="header-actions">
                <div class="user-info">
                    <span>欢迎，<?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                </div>
                <a href="?logout=1" class="logout-btn">退出登录</a>
            </div>
        </div>
        
        <div class="content">
            <!-- Toast notification container -->
            <div id="toast-container" class="toast-container"></div>
            
            <!-- 操作按钮 -->
            <div class="action-buttons">
                <button onclick="showAddModal()" class="btn btn-primary">
                    <i>➕</i> 添加代理
                </button>
                <button onclick="batchDelete()" class="btn btn-danger" id="batchDeleteBtn" style="display: none;">
                    <i>🗑️</i> 批量删除
                </button>
                <button onclick="refreshProxyList()" class="btn btn-secondary">
                    <i>🔄</i> 刷新
                </button>
            </div>

            <!-- 搜索和筛选 -->
            <div class="card">
                <div class="card-body">
                    <div class="search-filters">
                        <div class="filter-group">
                            <input type="text" id="searchInput" placeholder="搜索代理名称、地址或备注..." onkeyup="searchProxies()">
                        </div>
                        <div class="filter-group">
                            <select id="typeFilter" onchange="filterProxies()">
                                <option value="">所有类型</option>
                                <option value="http">HTTP</option>
                                <option value="socks5">SOCKS5</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="statusFilter" onchange="filterProxies()">
                                <option value="">所有状态</option>
                                <option value="1">启用</option>
                                <option value="0">禁用</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 代理列表 -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">代理列表</h2>
                    <div class="card-header-actions">
                        <span id="proxyCount">加载中...</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="proxy-table">
                            <thead>
                                <tr>
                                    <th width="40">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                    </th>
                                    <th>代理名称</th>
                                    <th>类型</th>
                                    <th>地址</th>
                                    <th>端口</th>
                                    <th>状态</th>
                                    <th>验证状态</th>
                                    <th>响应时间</th>
                                    <th>最后测试</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="proxyTableBody">
                                <tr>
                                    <td colspan="10" class="text-center">
                                        <div class="loading">加载中...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- 分页 -->
                    <div class="pagination-container" id="paginationContainer">
                        <!-- 分页内容将由JavaScript生成 -->
                    </div>
                </div>
            </div>
        </div>

        <!-- 添加/编辑代理模态框 -->
        <div id="proxyModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="modalTitle">添加代理</h3>
                    <span class="close" onclick="closeModal()">&times;</span>
                </div>
                <form id="proxyForm">
                    <input type="hidden" id="proxyId" name="id">
                    <div class="form-group">
                        <label for="proxy_name">代理名称:</label>
                        <input type="text" id="proxy_name" name="proxy_name" placeholder="可选，便于识别的名称">
                    </div>
                    <div class="form-group">
                        <label for="proxy_type">代理类型:</label>
                        <select id="proxy_type" name="proxy_type" required>
                            <option value="">请选择代理类型</option>
                            <option value="http">HTTP</option>
                            <option value="socks5">SOCKS5</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="proxy_host">代理地址:</label>
                        <input type="text" id="proxy_host" name="proxy_host" placeholder="例如: 127.0.0.1" required>
                    </div>
                    <div class="form-group">
                        <label for="proxy_port">端口:</label>
                        <input type="number" id="proxy_port" name="proxy_port" placeholder="例如: 8080" min="1" max="65535" required>
                    </div>
                    <div class="form-group">
                        <label for="proxy_username">用户名:</label>
                        <input type="text" id="proxy_username" name="proxy_username" placeholder="可选，需要认证时填写">
                    </div>
                    <div class="form-group">
                        <label for="proxy_password">密码:</label>
                        <input type="password" id="proxy_password" name="proxy_password" placeholder="可选，需要认证时填写">
                    </div>
                    <div class="form-actions">
                        <button type="button" onclick="closeModal()" class="btn btn-secondary">取消</button>
                        <button type="submit" class="btn btn-primary">保存</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 覆盖层 -->
        <div id="modalOverlay" class="modal-overlay" onclick="closeModal()"></div>
    </div>

    <script>
        // 全局变量
        let currentPage = 1;
        let totalPages = 1;
        let isEditing = false;
        let selectedProxies = [];

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            loadProxyList();
        });

        // 加载代理列表
        function loadProxyList(page = 1) {
            const search = document.getElementById('searchInput').value;
            const type = document.getElementById('typeFilter').value;
            const status = document.getElementById('statusFilter').value;

            showLoading();

            const params = new URLSearchParams({
                action: 'list',
                page: page,
                limit: 10,
                search: search,
                type: type,
                status: status
            });

            fetch('proxy_api.php?' + params)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderProxyTable(data.data);
                        renderPagination(data.pagination);
                        updateProxyCount(data.pagination.total);
                        currentPage = page;
                        totalPages = data.pagination.total_pages;
                    } else {
                        showMessage('获取代理列表失败: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('网络错误，请稍后重试', 'error');
                })
                .finally(() => {
                    hideLoading();
                });
        }

        // 渲染代理表格
        function renderProxyTable(proxies) {
            const tbody = document.getElementById('proxyTableBody');
            
            if (proxies.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center">暂无代理数据</td></tr>';
                return;
            }

            tbody.innerHTML = proxies.map(proxy => `
                <tr>
                    <td>
                        <input type="checkbox" value="${proxy.id}" onchange="toggleProxySelection(${proxy.id})">
                    </td>
                    <td>${proxy.proxy_name || '未命名'}</td>
                    <td>
                        <span class="status-badge ${proxy.proxy_type === 'http' ? 'status-verified' : 'status-unverified'}">${proxy.proxy_type.toUpperCase()}</span>
                    </td>
                    <td>${proxy.proxy_host}</td>
                    <td>${proxy.proxy_port}</td>
                    <td>
                        <span class="status-badge ${proxy.is_active ? 'status-active' : 'status-inactive'}">
                            ${proxy.is_active ? '启用' : '禁用'}
                        </span>
                    </td>
                    <td>
                        <span class="status-badge ${proxy.is_verified ? 'status-verified' : (proxy.last_test_time ? 'status-failed' : 'status-unverified')}">
                            ${proxy.is_verified ? '已验证' : (proxy.last_test_time ? '验证失败' : '未验证')}
                        </span>
                    </td>
                    <td>${proxy.response_time ? proxy.response_time + 'ms' : '-'}</td>
                    <td>${proxy.last_test_time ? formatDateTime(proxy.last_test_time) : '从未测试'}</td>
                    <td>
                        <button onclick="testProxyConnection(${proxy.id})" class="action-btn btn-success" title="测试连接">测试</button>
                        <button onclick="editProxy(${proxy.id})" class="action-btn btn-primary" title="编辑">编辑</button>
                        <button onclick="toggleProxyStatus(${proxy.id}, ${proxy.is_active ? 0 : 1})" class="action-btn ${proxy.is_active ? 'btn-warning' : 'btn-success'}" title="${proxy.is_active ? '禁用' : '启用'}">
                            ${proxy.is_active ? '禁用' : '启用'}
                        </button>
                        <button onclick="deleteProxy(${proxy.id})" class="action-btn btn-danger" title="删除">删除</button>
                    </td>
                </tr>
            `).join('');
        }

        // 渲染分页
        function renderPagination(pagination) {
            const container = document.getElementById('paginationContainer');
            
            if (pagination.total_pages <= 1) {
                container.innerHTML = '';
                return;
            }

            const pages = [];
            const maxPages = 5;
            let startPage = Math.max(1, pagination.current_page - Math.floor(maxPages / 2));
            let endPage = Math.min(pagination.total_pages, startPage + maxPages - 1);
            
            if (endPage - startPage + 1 < maxPages) {
                startPage = Math.max(1, endPage - maxPages + 1);
            }

            for (let i = startPage; i <= endPage; i++) {
                pages.push(i);
            }

            container.innerHTML = `
                <div class="pagination-info">
                    显示 ${(pagination.current_page - 1) * pagination.per_page + 1} - ${Math.min(pagination.current_page * pagination.per_page, pagination.total)} 条，共 ${pagination.total} 条
                </div>
                <div class="pagination">
                    <button onclick="loadProxyList(1)" ${pagination.current_page === 1 ? 'disabled' : ''}>首页</button>
                    <button onclick="loadProxyList(${pagination.current_page - 1})" ${pagination.current_page === 1 ? 'disabled' : ''}>上一页</button>
                    ${pages.map(page => `
                        <button onclick="loadProxyList(${page})" ${page === pagination.current_page ? 'class="active"' : ''}>${page}</button>
                    `).join('')}
                    <button onclick="loadProxyList(${pagination.current_page + 1})" ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}>下一页</button>
                    <button onclick="loadProxyList(${pagination.total_pages})" ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}>末页</button>
                </div>
            `;
        }

        // 显示添加代理模态框
        function showAddModal() {
            isEditing = false;
            document.getElementById('modalTitle').textContent = '添加代理';
            document.getElementById('proxyForm').reset();
            document.getElementById('proxyId').value = '';
            showModal();
        }

        // 编辑代理
        function editProxy(id) {
            // 从表格中获取代理数据（或者重新请求API）
            fetch(`proxy_api.php?action=list&search=&type=&status=`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const proxy = data.data.find(p => p.id == id);
                        if (proxy) {
                            isEditing = true;
                            document.getElementById('modalTitle').textContent = '编辑代理';
                            document.getElementById('proxyId').value = proxy.id;
                            document.getElementById('proxy_name').value = proxy.proxy_name || '';
                            document.getElementById('proxy_type').value = proxy.proxy_type;
                            document.getElementById('proxy_host').value = proxy.proxy_host;
                            document.getElementById('proxy_port').value = proxy.proxy_port;
                            document.getElementById('proxy_username').value = proxy.proxy_username || '';
                            document.getElementById('proxy_password').value = proxy.proxy_password || '';
                            showModal();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('获取代理信息失败', 'error');
                });
        }

        // 显示模态框
        function showModal() {
            document.getElementById('proxyModal').classList.add('show');
            document.getElementById('modalOverlay').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // 关闭模态框
        function closeModal() {
            document.getElementById('proxyModal').classList.remove('show');
            document.getElementById('modalOverlay').classList.remove('show');
            document.body.style.overflow = '';
        }

        // 保存代理
        document.getElementById('proxyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', isEditing ? 'update' : 'add');

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = '保存中...';
            submitBtn.disabled = true;

            fetch('proxy_api.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        closeModal();
                        loadProxyList(currentPage);
                    } else {
                        showMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('网络错误，请稍后重试', 'error');
                })
                .finally(() => {
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                });
        });

        // 删除代理
        function deleteProxy(id) {
            if (!confirm('确定要删除这个代理吗？')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('proxy_api.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        loadProxyList(currentPage);
                    } else {
                        showMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('网络错误，请稍后重试', 'error');
                });
        }

        // 切换代理状态
        function toggleProxyStatus(id, status) {
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('id', id);
            formData.append('is_active', status);

            fetch('proxy_api.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        loadProxyList(currentPage);
                    } else {
                        showMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('网络错误，请稍后重试', 'error');
                });
        }

        // 测试代理连接
        function testProxyConnection(id) {
            const formData = new FormData();
            formData.append('action', 'test_proxy');
            formData.append('id', id);

            // 显示测试中状态
            showMessage('正在测试代理连接...', 'info');

            fetch('proxy_api.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(`测试成功：${data.message}，响应时间：${data.response_time}ms`, 'success');
                    } else {
                        showMessage(`测试失败：${data.message}`, 'error');
                    }
                    loadProxyList(currentPage);
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('测试请求失败，请稍后重试', 'error');
                });
        }

        // 搜索代理
        function searchProxies() {
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(() => {
                loadProxyList(1);
            }, 500);
        }

        // 过滤代理
        function filterProxies() {
            loadProxyList(1);
        }

        // 刷新代理列表
        function refreshProxyList() {
            loadProxyList(currentPage);
        }

        // 全选/取消全选
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('tbody input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
                toggleProxySelection(parseInt(checkbox.value), selectAll.checked);
            });
        }

        // 切换代理选择状态
        function toggleProxySelection(id, forceState = null) {
            if (forceState !== null) {
                if (forceState) {
                    if (!selectedProxies.includes(id)) {
                        selectedProxies.push(id);
                    }
                } else {
                    selectedProxies = selectedProxies.filter(proxyId => proxyId !== id);
                }
            } else {
                if (selectedProxies.includes(id)) {
                    selectedProxies = selectedProxies.filter(proxyId => proxyId !== id);
                } else {
                    selectedProxies.push(id);
                }
            }

            // 更新批量删除按钮显示状态
            const batchDeleteBtn = document.getElementById('batchDeleteBtn');
            if (selectedProxies.length > 0) {
                batchDeleteBtn.style.display = 'inline-flex';
                batchDeleteBtn.textContent = `🗑️ 批量删除 (${selectedProxies.length})`;
            } else {
                batchDeleteBtn.style.display = 'none';
            }

            // 更新全选复选框状态
            const checkboxes = document.querySelectorAll('tbody input[type="checkbox"]');
            const selectAll = document.getElementById('selectAll');
            const checkedCount = document.querySelectorAll('tbody input[type="checkbox"]:checked').length;
            
            selectAll.checked = checkedCount === checkboxes.length && checkboxes.length > 0;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }

        // 批量删除代理
        function batchDelete() {
            if (selectedProxies.length === 0) {
                showMessage('请选择要删除的代理', 'error');
                return;
            }

            if (!confirm(`确定要删除选中的 ${selectedProxies.length} 个代理吗？`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'batch_delete');
            selectedProxies.forEach(id => {
                formData.append('ids[]', id);
            });

            fetch('proxy_api.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        selectedProxies = [];
                        document.getElementById('batchDeleteBtn').style.display = 'none';
                        document.getElementById('selectAll').checked = false;
                        loadProxyList(currentPage);
                    } else {
                        showMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('网络错误，请稍后重试', 'error');
                });
        }

        // 工具函数
        function showLoading() {
            document.getElementById('proxyTableBody').innerHTML = '<tr><td colspan="10" class="text-center"><div class="loading">加载中...</div></td></tr>';
        }

        function hideLoading() {
            // Loading will be replaced by actual content
        }

        function showMessage(message, type) {
            showToast(message, type);
        }

        function showToast(message, type = 'info', duration = 3000) {
            const container = document.getElementById('toast-container');
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            // Handle multiline messages
            if (message.includes('\n')) {
                const lines = message.split('\n');
                const content = document.createElement('div');
                lines.forEach((line, index) => {
                    const lineDiv = document.createElement('div');
                    lineDiv.textContent = line;
                    if (index === 0) {
                        lineDiv.style.fontWeight = 'bold';
                        lineDiv.style.marginBottom = '8px';
                    } else if (line.trim().startsWith('•')) {
                        lineDiv.style.marginLeft = '10px';
                        lineDiv.style.fontSize = '13px';
                        lineDiv.style.opacity = '0.9';
                    }
                    content.appendChild(lineDiv);
                });
                toast.appendChild(content);
            } else {
                toast.textContent = message;
            }
            
            container.appendChild(toast);
            
            // Trigger animation
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Auto remove
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (container.contains(toast)) {
                        container.removeChild(toast);
                    }
                }, 300);
            }, duration);
        }

        function updateProxyCount(count) {
            document.getElementById('proxyCount').textContent = `共 ${count} 个代理`;
        }

        function formatDateTime(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const diff = now - date;
            
            if (diff < 60000) { // 1分钟内
                return '刚刚';
            } else if (diff < 3600000) { // 1小时内
                return Math.floor(diff / 60000) + '分钟前';
            } else if (diff < 86400000) { // 1天内
                return Math.floor(diff / 3600000) + '小时前';
            } else {
                return date.toLocaleString('zh-CN', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
        }

        // ESC键关闭模态框
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>