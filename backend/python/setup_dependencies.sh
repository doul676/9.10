#!/bin/bash
# Python邮件处理器依赖安装脚本

echo "开始安装Python邮件处理器依赖..."

# 检查Python版本
python3 --version
if [ $? -ne 0 ]; then
    echo "错误: 未找到Python3，请先安装Python3"
    exit 1
fi

# 安装PySocks用于SOCKS代理支持（可选）
echo "安装PySocks库（SOCKS代理支持）..."
pip3 install PySocks --user

if [ $? -eq 0 ]; then
    echo "✅ PySocks安装成功"
else
    echo "⚠️  PySocks安装失败，SOCKS代理功能将不可用（HTTP代理仍然可用）"
fi

# 安装requests用于HTTP代理支持（可选）
echo "安装requests库（HTTP代理支持）..."
pip3 install requests --user

if [ $? -eq 0 ]; then
    echo "✅ requests安装成功"
else
    echo "⚠️  requests安装失败，高级HTTP代理功能将不可用"
fi

echo "Python邮件处理器依赖安装完成！"
echo ""
echo "注意："
echo "- Python邮件处理器使用标准库imaplib和poplib，无需额外依赖即可基本使用"
echo "- PySocks库用于SOCKS5代理支持"
echo "- requests库用于高级HTTP代理功能"
echo "- 如果依赖安装失败，基本邮件功能仍然可用"