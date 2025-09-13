#!/usr/bin/env python3
"""
Python Email Handler
支持IMAP/POP3协议和HTTP/SOCKS5代理的邮件获取工具
替代php-imap扩展，提供更好的代理支持
"""

import imaplib
import poplib
import email
import email.header
import socket
import sys
import json
import ssl
import re
import base64
import sqlite3
import os
from datetime import datetime
from typing import Dict, List, Optional, Tuple, Any

# 尝试导入SOCKS支持
try:
    import socks
    SOCKS_AVAILABLE = True
except ImportError:
    SOCKS_AVAILABLE = False

# 尝试导入requests用于HTTP代理
try:
    import requests
    REQUESTS_AVAILABLE = True
except ImportError:
    REQUESTS_AVAILABLE = False

class ProxyConfig:
    """代理配置类"""
    def __init__(self):
        self.enabled = False
        self.proxy_type = None
        self.host = None
        self.port = None
        self.username = None
        self.password = None
        self.name = None

class MailHandler:
    """邮件处理类"""
    
    def __init__(self, server: str, port: int, username: str, password: str, 
                 protocol: str = 'imap', use_ssl: bool = True, 
                 db_path: str = None):
        self.server = server
        self.port = port
        self.username = username
        self.password = password
        self.protocol = protocol.lower()
        self.use_ssl = use_ssl
        self.connection = None
        self.proxy_config = ProxyConfig()
        
        # 数据库路径
        if db_path is None:
            self.db_path = os.path.join(os.path.dirname(__file__), '../../db/mail.sqlite')
        else:
            self.db_path = db_path
            
        # 加载代理配置
        self._load_proxy_config()
        
    def _load_proxy_config(self):
        """从数据库加载代理配置"""
        try:
            if not os.path.exists(self.db_path):
                return
                
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # 检查proxy_config表是否存在
            cursor.execute("""
                SELECT name FROM sqlite_master 
                WHERE type='table' AND name='proxy_config'
            """)
            if not cursor.fetchone():
                conn.close()
                return
                
            # 获取代理配置
            cursor.execute("""
                SELECT config_key, config_value FROM proxy_config 
                WHERE config_key IN ('proxy_enabled', 'active_proxy_type', 'active_proxy_id')
            """)
            config = dict(cursor.fetchall())
            
            if config.get('proxy_enabled') == '1':
                proxy_type = config.get('active_proxy_type', '')
                proxy_id = int(config.get('active_proxy_id', 0))
                
                if proxy_type and proxy_id > 0:
                    table_name = 'socks5_proxies' if proxy_type == 'socks5' else 'http_proxies'
                    
                    # 检查代理表是否存在
                    cursor.execute(f"""
                        SELECT name FROM sqlite_master 
                        WHERE type='table' AND name='{table_name}'
                    """)
                    if not cursor.fetchone():
                        conn.close()
                        return
                    
                    # 获取代理详情
                    cursor.execute(f"""
                        SELECT * FROM {table_name} 
                        WHERE id = ? AND status = 1
                    """, (proxy_id,))
                    proxy_data = cursor.fetchone()
                    
                    if proxy_data:
                        # 获取列名
                        cursor.execute(f"PRAGMA table_info({table_name})")
                        columns = [col[1] for col in cursor.fetchall()]
                        proxy_dict = dict(zip(columns, proxy_data))
                        
                        self.proxy_config.enabled = True
                        self.proxy_config.proxy_type = proxy_type
                        self.proxy_config.host = proxy_dict.get('host', '')
                        self.proxy_config.port = int(proxy_dict.get('port', 0))
                        self.proxy_config.username = proxy_dict.get('username', '')
                        self.proxy_config.password = proxy_dict.get('password', '')
                        self.proxy_config.name = proxy_dict.get('name', '')
                        
            conn.close()
            
        except Exception as e:
            print(f"加载代理配置失败: {e}", file=sys.stderr)
            
    def _setup_socks_proxy(self):
        """设置SOCKS代理"""
        if not self.proxy_config.enabled or self.proxy_config.proxy_type != 'socks5':
            return
            
        if not SOCKS_AVAILABLE:
            raise Exception("SOCKS代理需要安装PySocks库: pip install PySocks")
            
        try:
            # 设置SOCKS代理
            if self.proxy_config.username and self.proxy_config.password:
                socks.set_default_proxy(
                    socks.SOCKS5, 
                    self.proxy_config.host, 
                    self.proxy_config.port,
                    username=self.proxy_config.username,
                    password=self.proxy_config.password
                )
            else:
                socks.set_default_proxy(
                    socks.SOCKS5, 
                    self.proxy_config.host, 
                    self.proxy_config.port
                )
            socket.socket = socks.socksocket
            
        except Exception as e:
            raise Exception(f"设置SOCKS代理失败: {e}")
            
    def _create_ssl_context(self):
        """创建SSL上下文"""
        context = ssl.create_default_context()
        # 允许不验证证书以解决SSL问题
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        return context
        
    def connect(self) -> bool:
        """连接到邮件服务器"""
        try:
            # 如果启用了SOCKS代理，先设置代理
            if self.proxy_config.enabled and self.proxy_config.proxy_type == 'socks5':
                self._setup_socks_proxy()
                
            if self.protocol == 'imap':
                return self._connect_imap()
            elif self.protocol == 'pop3':
                return self._connect_pop3()
            else:
                raise Exception(f'不支持的协议: {self.protocol}')
                
        except Exception as e:
            error_msg = str(e)
            if self.proxy_config.enabled:
                error_msg += f" (使用{self.proxy_config.proxy_type}代理: {self.proxy_config.name})"
            raise Exception(error_msg)
            
    def _connect_imap(self) -> bool:
        """IMAP连接"""
        try:
            if self.use_ssl:
                # SSL连接
                context = self._create_ssl_context()
                self.connection = imaplib.IMAP4_SSL(
                    self.server, 
                    self.port, 
                    ssl_context=context
                )
            else:
                # 普通连接
                self.connection = imaplib.IMAP4(self.server, self.port)
                
            # 登录
            self.connection.login(self.username, self.password)
            
            # 选择邮箱
            self.connection.select('INBOX')
            
            return True
            
        except Exception as e:
            error_msg = self._parse_imap_error(str(e))
            raise Exception(error_msg)
            
    def _connect_pop3(self) -> bool:
        """POP3连接"""
        try:
            if self.use_ssl:
                # SSL连接
                context = self._create_ssl_context()
                self.connection = poplib.POP3_SSL(
                    self.server, 
                    self.port, 
                    context=context
                )
            else:
                # 普通连接
                self.connection = poplib.POP3(self.server, self.port)
                
            # 登录
            self.connection.user(self.username)
            self.connection.pass_(self.password)
            
            return True
            
        except Exception as e:
            error_msg = self._parse_pop3_error(str(e))
            raise Exception(error_msg)
            
    def _parse_imap_error(self, error: str) -> str:
        """解析IMAP错误信息"""
        if 'certificate verify failed' in error.lower() or 'ssl' in error.lower():
            return 'SSL证书验证失败，请检查服务器SSL配置'
        elif 'connection refused' in error.lower():
            return '连接被拒绝，请检查服务器地址和端口'
        elif 'authentication failed' in error.lower() or 'login failed' in error.lower():
            return '邮箱用户名或密码错误，请检查登录凭据'
        elif 'name or service not known' in error.lower() or 'nodename nor servname provided' in error.lower():
            return '服务器地址无法解析，请检查服务器地址'
        elif 'timeout' in error.lower():
            return '连接超时，请检查网络连接'
        else:
            return f'IMAP连接失败: {error}'
            
    def _parse_pop3_error(self, error: str) -> str:
        """解析POP3错误信息"""
        if 'certificate verify failed' in error.lower() or 'ssl' in error.lower():
            return 'SSL证书验证失败，请检查服务器SSL配置'
        elif 'connection refused' in error.lower():
            return '连接被拒绝，请检查服务器地址和端口'
        elif 'authentication failed' in error.lower() or 'auth' in error.lower():
            return '邮箱用户名或密码错误，请检查登录凭据'
        elif 'name or service not known' in error.lower():
            return '服务器地址无法解析，请检查服务器地址'
        elif 'timeout' in error.lower():
            return '连接超时，请检查网络连接'
        else:
            return f'POP3连接失败: {error}'
            
    def get_latest_mail(self) -> Dict[str, Any]:
        """获取最新邮件"""
        if not self.connection:
            raise Exception('未连接到邮件服务器')
            
        try:
            if self.protocol == 'imap':
                return self._get_latest_mail_imap()
            elif self.protocol == 'pop3':
                return self._get_latest_mail_pop3()
        except Exception as e:
            return {
                'success': False,
                'message': f'获取邮件失败: {str(e)}'
            }
            
    def _get_latest_mail_imap(self) -> Dict[str, Any]:
        """IMAP获取最新邮件"""
        # 搜索邮件
        status, messages = self.connection.search(None, 'ALL')
        if status != 'OK':
            return {
                'success': True,
                'message': '邮箱中暂无邮件',
                'mail': None
            }
            
        mail_ids = messages[0].split()
        if not mail_ids:
            return {
                'success': True,
                'message': '邮箱中暂无邮件',
                'mail': None
            }
            
        # 获取最新邮件
        latest_mail_id = mail_ids[-1]
        status, mail_data = self.connection.fetch(latest_mail_id, '(RFC822)')
        
        if status != 'OK':
            raise Exception('无法获取邮件内容')
            
        # 解析邮件
        raw_email = mail_data[0][1]
        email_message = email.message_from_bytes(raw_email)
        
        mail_info = self._parse_email(email_message)
        
        return {
            'success': True,
            'mail': mail_info
        }
        
    def _get_latest_mail_pop3(self) -> Dict[str, Any]:
        """POP3获取最新邮件"""
        # 获取邮件数量
        num_messages = len(self.connection.list()[1])
        
        if num_messages == 0:
            return {
                'success': True,
                'message': '邮箱中暂无邮件',
                'mail': None
            }
            
        # 获取最新邮件
        response, raw_lines, octets = self.connection.retr(num_messages)
        raw_email = b'\n'.join(raw_lines)
        email_message = email.message_from_bytes(raw_email)
        
        mail_info = self._parse_email(email_message)
        
        return {
            'success': True,
            'mail': mail_info
        }
        
    def _parse_email(self, email_message: email.message.EmailMessage) -> Dict[str, Any]:
        """解析邮件内容"""
        # 解码主题
        subject = self._decode_header(email_message.get('Subject', ''))
        
        # 发件人信息
        from_header = email_message.get('From', '')
        from_name, from_email = self._parse_address(from_header)
        
        # 收件人信息
        to_header = email_message.get('To', '')
        to_name, to_email = self._parse_address(to_header)
        
        # 邮件日期
        date_header = email_message.get('Date', '')
        formatted_date = self._parse_date(date_header)
        
        # 邮件ID和大小
        message_id = email_message.get('Message-ID', '')
        
        # 解析邮件正文和附件
        body_info = self._parse_body(email_message)
        
        mail_data = {
            'subject': subject,
            'from': from_name,
            'from_email': from_email,
            'to': to_email,
            'date': formatted_date,
            'message_id': message_id,
            'size': len(str(email_message)),
            'body_type': body_info.get('type', 'text'),
            'body': body_info.get('content', ''),
            'images': body_info.get('images', []),
            'attachments': body_info.get('attachments', [])
        }
        
        return mail_data
        
    def _decode_header(self, header: str) -> str:
        """解码邮件头"""
        if not header:
            return ''
            
        decoded_parts = []
        for part, encoding in email.header.decode_header(header):
            if isinstance(part, bytes):
                if encoding:
                    try:
                        decoded_parts.append(part.decode(encoding))
                    except (UnicodeDecodeError, LookupError):
                        decoded_parts.append(part.decode('utf-8', errors='ignore'))
                else:
                    decoded_parts.append(part.decode('utf-8', errors='ignore'))
            else:
                decoded_parts.append(part)
                
        return ''.join(decoded_parts)
        
    def _parse_address(self, address_header: str) -> Tuple[str, str]:
        """解析邮件地址"""
        if not address_header:
            return '未知', '未知'
            
        decoded_header = self._decode_header(address_header)
        
        # 尝试解析邮件地址
        match = re.search(r'<([^>]+)>', decoded_header)
        if match:
            email_addr = match.group(1)
            name_part = decoded_header.replace(f'<{email_addr}>', '').strip()
            name_part = name_part.strip('"\'')
            return name_part if name_part else email_addr, email_addr
        else:
            # 如果没有<>格式，尝试直接作为邮件地址
            email_match = re.search(r'([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})', decoded_header)
            if email_match:
                email_addr = email_match.group(1)
                return email_addr, email_addr
                
        return decoded_header, decoded_header
        
    def _parse_date(self, date_header: str) -> str:
        """解析邮件日期"""
        if not date_header:
            return ''
            
        try:
            # 尝试解析日期
            from email.utils import parsedate_to_datetime
            dt = parsedate_to_datetime(date_header)
            return dt.strftime('%Y-%m-%d %H:%M:%S')
        except:
            return date_header
            
    def _parse_body(self, email_message: email.message.EmailMessage) -> Dict[str, Any]:
        """解析邮件正文和附件"""
        body_data = {
            'text_body': '',
            'html_body': '',
            'images': [],
            'attachments': []
        }
        
        if email_message.is_multipart():
            # 多部分邮件
            for part in email_message.walk():
                self._process_email_part(part, body_data)
        else:
            # 单部分邮件
            self._process_email_part(email_message, body_data)
            
        # 选择内容
        main_content = body_data['html_body'] if body_data['html_body'] else body_data['text_body']
        content_type = 'html' if body_data['html_body'] else 'text'
        
        # 如果没有文本内容但有图片，标记为图片类型
        if not main_content and body_data['images']:
            content_type = 'image'
            
        return {
            'type': content_type,
            'content': main_content if main_content else '无法读取邮件内容',
            'images': body_data['images'],
            'attachments': body_data['attachments']
        }
        
    def _process_email_part(self, part: email.message.EmailMessage, body_data: Dict[str, Any]):
        """处理邮件部分"""
        content_type = part.get_content_type()
        content_disposition = part.get('Content-Disposition', '')
        
        # 获取文件名
        filename = part.get_filename()
        if filename:
            filename = self._decode_header(filename)
            
        # 处理文本内容
        if content_type == 'text/plain' and 'attachment' not in content_disposition:
            if not body_data['text_body']:  # 只取第一个文本部分
                charset = part.get_content_charset() or 'utf-8'
                try:
                    content = part.get_payload(decode=True)
                    if isinstance(content, bytes):
                        body_data['text_body'] = content.decode(charset, errors='ignore')
                    else:
                        body_data['text_body'] = str(content)
                except:
                    body_data['text_body'] = str(part.get_payload())
                    
        elif content_type == 'text/html' and 'attachment' not in content_disposition:
            if not body_data['html_body']:  # 只取第一个HTML部分
                charset = part.get_content_charset() or 'utf-8'
                try:
                    content = part.get_payload(decode=True)
                    if isinstance(content, bytes):
                        body_data['html_body'] = content.decode(charset, errors='ignore')
                    else:
                        body_data['html_body'] = str(content)
                except:
                    body_data['html_body'] = str(part.get_payload())
                    
        # 处理图片
        elif content_type.startswith('image/'):
            try:
                content = part.get_payload(decode=True)
                if content:
                    image_info = {
                        'filename': filename or f'image.{content_type.split("/")[1]}',
                        'mime_type': content_type,
                        'size': len(content),
                        'content': base64.b64encode(content).decode(),
                        'subtype': content_type.split('/')[-1]
                    }
                    
                    if 'inline' in content_disposition or not content_disposition:
                        body_data['images'].append(image_info)
                    else:
                        body_data['attachments'].append(image_info)
            except:
                pass
                
        # 处理其他附件
        elif filename or 'attachment' in content_disposition:
            try:
                content = part.get_payload(decode=True)
                if content:
                    body_data['attachments'].append({
                        'filename': filename or 'attachment',
                        'mime_type': content_type,
                        'size': len(content),
                        'content': base64.b64encode(content).decode(),
                        'type': content_type.split('/')[0]
                    })
            except:
                pass
                
    def close(self):
        """关闭连接"""
        if self.connection:
            try:
                if self.protocol == 'imap':
                    self.connection.close()
                    self.connection.logout()
                elif self.protocol == 'pop3':
                    self.connection.quit()
            except:
                pass
            finally:
                self.connection = None
                
    def test_connection(self) -> Dict[str, Any]:
        """测试连接"""
        try:
            if self.connect():
                self.close()
                return {
                    'success': True,
                    'message': '✅ 邮箱连接测试成功！',
                    'diagnostics': {
                        'connection_test': '✅ 服务器连接成功',
                        'protocol_info': f'{self.protocol.upper()}{"" if not self.use_ssl else " with SSL/TLS"}',
                        'server_info': f'{self.server}:{self.port}',
                        'auth_status': '✅ 身份验证成功',
                        'proxy_info': f'代理: {self.proxy_config.name}' if self.proxy_config.enabled else '直连'
                    }
                }
            else:
                return {
                    'success': False,
                    'message': '❌ 邮箱连接测试失败',
                    'diagnostics': {
                        'connection_issue': '❌ 无法建立服务器连接',
                        'suggestion': '请检查服务器地址、端口和网络连接'
                    }
                }
        except Exception as e:
            return {
                'success': False,
                'message': f'❌ 连接测试失败: {str(e)}',
                'diagnostics': {
                    'error_details': str(e),
                    'proxy_info': f'代理: {self.proxy_config.name}' if self.proxy_config.enabled else '直连'
                }
            }
            
    def get_proxy_info(self) -> Dict[str, Any]:
        """获取代理信息"""
        return {
            'enabled': self.proxy_config.enabled,
            'info': {
                'type': self.proxy_config.proxy_type,
                'host': self.proxy_config.host,
                'port': self.proxy_config.port,
                'name': self.proxy_config.name
            } if self.proxy_config.enabled else None
        }


def main():
    """命令行接口"""
    if len(sys.argv) < 2:
        print(json.dumps({
            'success': False,
            'message': '缺少操作参数'
        }))
        sys.exit(1)
        
    try:
        action = sys.argv[1]
        
        if action == 'get_mail':
            # 获取邮件
            if len(sys.argv) < 8:
                print(json.dumps({
                    'success': False,
                    'message': '参数不足'
                }))
                sys.exit(1)
                
            server = sys.argv[2]
            port = int(sys.argv[3])
            username = sys.argv[4]
            password = sys.argv[5]
            protocol = sys.argv[6]
            use_ssl = sys.argv[7].lower() == 'true'
            
            handler = MailHandler(server, port, username, password, protocol, use_ssl)
            
            if handler.connect():
                result = handler.get_latest_mail()
                proxy_info = handler.get_proxy_info()
                handler.close()
                
                result['proxy'] = proxy_info
                print(json.dumps(result, ensure_ascii=False))
            else:
                proxy_info = handler.get_proxy_info()
                print(json.dumps({
                    'success': False,
                    'message': '无法连接到邮件服务器',
                    'proxy': proxy_info
                }, ensure_ascii=False))
                
        elif action == 'test_connection':
            # 测试连接
            if len(sys.argv) < 8:
                print(json.dumps({
                    'success': False,
                    'message': '参数不足'
                }))
                sys.exit(1)
                
            server = sys.argv[2]
            port = int(sys.argv[3])
            username = sys.argv[4]
            password = sys.argv[5]
            protocol = sys.argv[6]
            use_ssl = sys.argv[7].lower() == 'true'
            
            handler = MailHandler(server, port, username, password, protocol, use_ssl)
            result = handler.test_connection()
            proxy_info = handler.get_proxy_info()
            result['proxy'] = proxy_info
            
            print(json.dumps(result, ensure_ascii=False))
            
        else:
            print(json.dumps({
                'success': False,
                'message': f'未知操作: {action}'
            }))
            sys.exit(1)
            
    except Exception as e:
        print(json.dumps({
            'success': False,
            'message': f'执行失败: {str(e)}'
        }, ensure_ascii=False))
        sys.exit(1)


if __name__ == '__main__':
    main()