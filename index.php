<?php
/**
 * 邮件查看系统 - 主页
 * 直接提供前端页面内容
 */

// 读取并输出前端页面内容
$frontendContent = file_get_contents(__DIR__ . '/frontend/index.html');

// 更新API路径：从 ../admin/api/ 改为 admin/api/
$frontendContent = str_replace('../admin/api/', 'admin/api/', $frontendContent);

// 输出内容
echo $frontendContent;
?>