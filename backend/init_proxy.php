<?php
/**
 * 数据库初始化和代理池表创建
 */

require_once 'utils/proxy_manager.php';

// 确保代理池表存在
$proxyManager = new ProxyManager();
echo "代理池表已初始化\n";

// 检查代理池状态
$proxies = $proxyManager->getAllAvailableProxies('', false);
echo "当前代理池中有 " . count($proxies) . " 个代理\n";

if (empty($proxies)) {
    echo "代理池为空，您可以通过管理后台添加代理服务器\n";
}

echo "数据库初始化完成\n";
?>