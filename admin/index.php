<?php
/**
 * 管理员控制面板入口
 * /admin 路径统一入口，根据登录状态跳转到相应页面
 */

session_start();

// 检查登录状态
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    // 已登录，重定向到控制面板
    header('Location: home.php');
    exit();
} else {
    // 未登录，重定向到登录页面
    header('Location: login.php');
    exit();
}
?>