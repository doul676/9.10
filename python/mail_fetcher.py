#!/usr/bin/env python3
"""
Python Email Fetcher Service
Replaces php-imap functionality with proxy support for HTTP/SOCKS5
"""

import json
import sqlite3
import sys
import os
import base64
import email
import socket
import ssl
import imaplib
import socks
import http.client
from datetime import datetime
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.header import decode_header
import logging

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class ProxyMailFetcher:
    def __init__(self, server, port, username, password, protocol='imap', use_ssl=True):
        self.server = server
        self.port = port
        self.username = username
        self.password = password
        self.protocol = protocol.lower()
        self.use_ssl = use_ssl
        self.connection = None
        self.proxy_enabled = False
        self.proxy_info = None
        
        # Check and configure proxy settings
        self._check_proxy_status()
        
    def _check_proxy_status(self):
        """Check proxy configuration from database"""
        try:
            db_path = os.path.join(os.path.dirname(__file__), '..', 'db', 'mail.sqlite')
            
            if not os.path.exists(db_path):
                return
                
            conn = sqlite3.connect(db_path)
            cursor = conn.cursor()
            
            # Check if proxy_config table exists
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='proxy_config'")
            if not cursor.fetchone():
                conn.close()
                return
            
            # Get proxy configuration
            cursor.execute("""
                SELECT config_key, config_value FROM proxy_config 
                WHERE config_key IN ('proxy_enabled', 'active_proxy_type', 'active_proxy_id')
            """)
            
            config = {row[0]: row[1] for row in cursor.fetchall()}
            
            # Check if proxy is enabled
            if config.get('proxy_enabled') == '1':
                proxy_type = config.get('active_proxy_type', '')
                proxy_id = int(config.get('active_proxy_id', '0'))
                
                if proxy_type and proxy_id > 0:
                    # Get proxy details
                    table_name = 'socks5_proxies' if proxy_type == 'socks5' else 'http_proxies'
                    
                    cursor.execute(f"SELECT name FROM sqlite_master WHERE type='table' AND name='{table_name}'")
                    if cursor.fetchone():
                        cursor.execute(f"SELECT * FROM {table_name} WHERE id = ? AND status = 1", (proxy_id,))
                        proxy = cursor.fetchone()
                        
                        if proxy:
                            # Map database columns to proxy info
                            columns = [desc[0] for desc in cursor.description]
                            proxy_dict = dict(zip(columns, proxy))
                            
                            self.proxy_enabled = True
                            self.proxy_info = {
                                'type': proxy_type,
                                'host': proxy_dict.get('host', ''),
                                'port': proxy_dict.get('port', 0),
                                'username': proxy_dict.get('username', ''),
                                'password': proxy_dict.get('password', ''),
                                'name': proxy_dict.get('name', '')
                            }
                            
                            logger.info(f"Proxy configured: {proxy_type} - {self.proxy_info['name']}")
            
            conn.close()
            
        except Exception as e:
            logger.error(f"Error checking proxy status: {e}")
            # Don't fail on proxy configuration errors
            
    def get_proxy_info(self):
        """Get proxy information for response"""
        return {
            'enabled': self.proxy_enabled,
            'info': self.proxy_info
        }
        
    def connect(self):
        """Connect to mail server"""
        connection_method = "未知"
        try:
            if self.proxy_enabled:
                connection_method = f"代理 ({self.proxy_info['type']} - {self.proxy_info['name']})"
                logger.info(f"Attempting connection with proxy: {self.proxy_info['name']} ({self.proxy_info['type']})")
                return self._connect_with_proxy()
            else:
                connection_method = "直连"
                logger.info("Connecting directly to mail server")
                
            if self.protocol == 'imap':
                return self._connect_imap()
            elif self.protocol == 'pop3':
                # POP3 support can be added later if needed
                raise Exception("POP3 protocol not yet implemented in Python version")
            else:
                raise Exception(f"Unsupported protocol: {self.protocol}")
                
        except Exception as e:
            error_message = str(e)
            
            # Don't double-wrap error messages that already contain connection info
            if "代理" not in error_message and "直连" not in error_message:
                if self.proxy_enabled:
                    error_message += f" (通过{connection_method}连接)"
                else:
                    error_message += f" ({connection_method})"
            
            logger.error(f"Connection failed: {error_message}")
            raise Exception(error_message)
    
    def _connect_with_proxy(self):
        """Connect to mail server through proxy"""
        proxy_type = self.proxy_info['type']
        proxy_host = self.proxy_info['host']
        proxy_port = self.proxy_info['port']
        proxy_username = self.proxy_info.get('username', '')
        proxy_password = self.proxy_info.get('password', '')
        
        try:
            if proxy_type == 'socks5':
                return self._connect_socks5_proxy(proxy_host, proxy_port, proxy_username, proxy_password)
            elif proxy_type == 'http':
                return self._connect_http_proxy(proxy_host, proxy_port, proxy_username, proxy_password)
            else:
                raise Exception(f"Unsupported proxy type: {proxy_type}")
                
        except Exception as e:
            error_message = f"Proxy connection failed ({proxy_type}): {str(e)}"
            logger.error(error_message)
            raise Exception(error_message)
    
    def _connect_socks5_proxy(self, proxy_host, proxy_port, proxy_username, proxy_password):
        """Connect using SOCKS5 proxy"""
        original_socket = socket.socket
        
        try:
            # Configure SOCKS5 proxy
            logger.info(f"Configuring SOCKS5 proxy: {proxy_host}:{proxy_port}")
            
            # Test SOCKS5 proxy connectivity first
            try:
                test_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                test_socket.settimeout(10)
                test_result = test_socket.connect_ex((proxy_host, proxy_port))
                test_socket.close()
                
                if test_result != 0:
                    raise Exception(f"无法连接到SOCKS5代理服务器 {proxy_host}:{proxy_port}")
                    
            except socket.error as e:
                raise Exception(f"SOCKS5代理服务器连接失败: {str(e)}")
            
            # Set up SOCKS5 proxy
            try:
                if proxy_username and proxy_password:
                    socks.set_default_proxy(socks.SOCKS5, proxy_host, proxy_port, 
                                           username=proxy_username, password=proxy_password)
                else:
                    socks.set_default_proxy(socks.SOCKS5, proxy_host, proxy_port)
                
                # Monkey patch socket to use SOCKS5
                socket.socket = socks.socksocket
                
                logger.info(f"SOCKS5 proxy configured successfully")
                
                # Now connect via IMAP with the proxied socket
                result = self._connect_imap()
                return result
                
            except socks.ProxyError as e:
                raise Exception(f"SOCKS5代理配置错误: {str(e)}")
            except socks.GeneralProxyError as e:
                raise Exception(f"SOCKS5代理一般错误: {str(e)}")
            except socks.ProxyConnectionError as e:
                raise Exception(f"SOCKS5代理连接错误: {str(e)}")
            except Exception as e:
                error_msg = str(e)
                if "邮箱" in error_msg or "IMAP" in error_msg:
                    # This is an IMAP-related error, not proxy error
                    raise e
                else:
                    raise Exception(f"通过SOCKS5代理连接失败: {error_msg}")
                
        except Exception as e:
            error_msg = str(e)
            if "SOCKS5代理" in error_msg or "无法连接到SOCKS5" in error_msg or "通过SOCKS5代理连接失败" in error_msg:
                raise e
            else:
                raise Exception(f"SOCKS5 proxy connection failed: {error_msg}")
        finally:
            # Always restore original socket
            socket.socket = original_socket
    
    def _connect_http_proxy(self, proxy_host, proxy_port, proxy_username, proxy_password):
        """Connect using HTTP proxy with CONNECT tunneling"""
        try:
            # For HTTP proxy, we need to establish a CONNECT tunnel to the IMAP server
            logger.info(f"Establishing HTTP CONNECT tunnel via {proxy_host}:{proxy_port}")
            
            # Create connection to proxy with better error handling
            try:
                proxy_conn = http.client.HTTPConnection(proxy_host, proxy_port, timeout=30)
                
                # Set up authentication headers if needed
                headers = {}
                if proxy_username and proxy_password:
                    proxy_auth = base64.b64encode(f"{proxy_username}:{proxy_password}".encode()).decode()
                    headers["Proxy-Authorization"] = f"Basic {proxy_auth}"
                
                # Establish CONNECT tunnel
                proxy_conn.set_tunnel(self.server, self.port, headers)
                proxy_conn.connect()
                
                # Get the underlying socket from the HTTP connection
                proxy_socket = proxy_conn.sock
                
            except (http.client.HTTPException, socket.error, OSError) as e:
                raise Exception(f"HTTP代理连接失败: {str(e)}")
            
            # Create IMAP connection using the proxied socket
            try:
                if self.use_ssl:
                    # For SSL, we need to wrap the socket
                    ssl_context = ssl.create_default_context()
                    ssl_context.check_hostname = False
                    ssl_context.verify_mode = ssl.CERT_NONE
                    
                    # Wrap the proxy socket with SSL
                    ssl_socket = ssl_context.wrap_socket(proxy_socket, server_hostname=self.server)
                    
                    # Create IMAP connection manually with the SSL socket
                    self.connection = imaplib.IMAP4(self.server, self.port)
                    self.connection.sock = ssl_socket
                    self.connection.file = ssl_socket.makefile('rb')
                    
                    # Send capability command to verify connection
                    self.connection._cmd('OK', 'CAPABILITY')
                else:
                    # For non-SSL connections
                    self.connection = imaplib.IMAP4(self.server, self.port)
                    self.connection.sock = proxy_socket
                    self.connection.file = proxy_socket.makefile('rb')
                    
                    # Send capability command to verify connection
                    self.connection._cmd('OK', 'CAPABILITY')
                
                # Login to IMAP server
                self.connection.login(self.username, self.password)
                
                # Select INBOX
                self.connection.select('INBOX')
                
                logger.info("HTTP proxy tunnel established successfully")
                return True
                
            except (imaplib.IMAP4.error, socket.error, ssl.SSLError) as e:
                try:
                    proxy_conn.close()
                except:
                    pass
                raise Exception(f"通过HTTP代理连接IMAP服务器失败: {str(e)}")
            
        except Exception as e:
            error_msg = str(e)
            if "代理连接失败" in error_msg or "IMAP服务器失败" in error_msg:
                raise e
            else:
                raise Exception(f"HTTP proxy tunnel failed: {error_msg}")
            
    def _connect_imap(self):
        """Connect using IMAP protocol"""
        try:
            # Set socket timeout for better error handling
            original_timeout = socket.getdefaulttimeout()
            socket.setdefaulttimeout(30)  # 30 second timeout
            
            try:
                # Test basic connectivity first
                logger.info(f"Testing connectivity to {self.server}:{self.port}")
                test_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                test_socket.settimeout(10)
                
                try:
                    test_result = test_socket.connect_ex((self.server, self.port))
                    test_socket.close()
                    
                    if test_result != 0:
                        raise Exception(f"无法连接到邮件服务器 {self.server}:{self.port} (错误码: {test_result})")
                        
                except socket.gaierror as e:
                    raise Exception(f"无法解析邮件服务器地址 {self.server}: {str(e)}")
                except socket.timeout:
                    raise Exception(f"连接邮件服务器 {self.server}:{self.port} 超时")
                except Exception as e:
                    raise Exception(f"网络连接测试失败: {str(e)}")
                
                logger.info("Basic connectivity test passed")
                
                # Create IMAP connection
                logger.info(f"Establishing IMAP connection to {self.server}:{self.port} (SSL: {self.use_ssl})")
                
                if self.use_ssl:
                    try:
                        # Create SSL context with more permissive settings for compatibility
                        ssl_context = ssl.create_default_context()
                        ssl_context.check_hostname = False
                        ssl_context.verify_mode = ssl.CERT_NONE
                        
                        self.connection = imaplib.IMAP4_SSL(
                            self.server, 
                            self.port,
                            ssl_context=ssl_context
                        )
                    except ssl.SSLError as e:
                        raise Exception(f"SSL连接失败: {str(e)}，请检查服务器SSL配置或尝试关闭SSL")
                    except Exception as e:
                        raise Exception(f"SSL IMAP连接失败: {str(e)}")
                else:
                    try:
                        self.connection = imaplib.IMAP4(self.server, self.port)
                    except Exception as e:
                        raise Exception(f"IMAP连接失败: {str(e)}")
                
                logger.info("IMAP connection established, attempting login...")
                
                # Login with better error handling
                try:
                    self.connection.login(self.username, self.password)
                    logger.info("Login successful")
                except imaplib.IMAP4.error as e:
                    error_msg = str(e).lower()
                    if "authentication" in error_msg or "login" in error_msg or "invalid" in error_msg:
                        raise Exception("邮箱用户名或密码错误，请检查登录凭据")
                    else:
                        raise Exception(f"登录失败: {str(e)}")
                
                # Select INBOX
                try:
                    self.connection.select('INBOX')
                    logger.info("INBOX selected successfully")
                except Exception as e:
                    raise Exception(f"无法选择INBOX邮箱: {str(e)}")
                
                return True
                
            finally:
                # Restore original timeout
                socket.setdefaulttimeout(original_timeout)
                
        except Exception as e:
            error_str = str(e).lower()
            logger.error(f"IMAP connection error: {str(e)}")
            
            # Check if it's already a specific error message
            if any(keyword in str(e) for keyword in ["邮箱", "SSL连接失败", "无法连接到", "无法解析", "连接", "超时", "登录失败", "无法选择"]):
                raise e
            
            # Provide more specific error messages for generic errors
            if "authentication" in error_str or "login" in error_str or "invalid credentials" in error_str:
                raise Exception("邮箱用户名或密码错误，请检查登录凭据")
            elif "certificate" in error_str or "ssl" in error_str or "handshake" in error_str:
                raise Exception("SSL连接失败，请检查服务器SSL配置或尝试关闭SSL")
            elif "connection refused" in error_str or "no route to host" in error_str:
                raise Exception("连接被拒绝，请检查服务器地址和端口，或检查网络连接")
            elif "timeout" in error_str or "timed out" in error_str:
                raise Exception("连接超时，请检查网络连接或服务器响应")
            elif "name resolution" in error_str or "nodename nor servname" in error_str:
                raise Exception("无法解析服务器地址，请检查服务器地址是否正确")
            elif "connection reset" in error_str:
                raise Exception("连接被重置，可能是服务器或网络问题")
            else:
                raise Exception(f"IMAP连接失败: {str(e)}")
                
    def get_latest_mail(self):
        """Get the latest email from the mailbox"""
        if not self.connection:
            raise Exception("未连接到邮件服务器")
            
        try:
            # Search for all messages
            status, messages = self.connection.search(None, 'ALL')
            
            if status != 'OK' or not messages[0]:
                return {
                    'success': True,
                    'message': '邮箱中没有邮件',
                    'mail': None
                }
            
            # Get the latest message (highest number)
            mail_ids = messages[0].split()
            latest_id = mail_ids[-1]
            
            # Fetch message data
            status, msg_data = self.connection.fetch(latest_id, '(RFC822)')
            
            if status != 'OK':
                raise Exception("无法获取邮件内容")
            
            # Parse the email
            raw_email = msg_data[0][1]
            email_message = email.message_from_bytes(raw_email)
            
            # Extract email information
            mail_info = self._parse_email(email_message)
            
            return {
                'success': True,
                'mail': mail_info
            }
            
        except Exception as e:
            return {
                'success': False,
                'message': f'获取邮件失败: {str(e)}'
            }
            
    def _parse_email(self, email_message):
        """Parse email message and extract information"""
        # Decode subject
        subject = self._decode_header(email_message.get('Subject', ''))
        
        # Get sender info
        from_header = email_message.get('From', '')
        from_name, from_email = self._parse_address(from_header)
        
        # Get recipient info
        to_header = email_message.get('To', '')
        to_name, to_email = self._parse_address(to_header)
        
        # Get date
        date_header = email_message.get('Date', '')
        formatted_date = self._parse_date(date_header)
        
        # Get message ID
        message_id = email_message.get('Message-ID', '')
        
        # Parse body and attachments
        body_info = self._parse_body(email_message)
        
        mail_data = {
            'subject': subject,
            'from': from_name,
            'from_email': from_email,
            'to': to_email,
            'date': formatted_date,
            'message_id': message_id,
            'size': len(str(email_message))  # Approximate size
        }
        
        # Add body information
        mail_data.update(body_info)
        
        return mail_data
        
    def _decode_header(self, header_value):
        """Decode email header"""
        if not header_value:
            return ''
            
        decoded_parts = decode_header(header_value)
        decoded_string = ''
        
        for part, encoding in decoded_parts:
            if isinstance(part, bytes):
                if encoding:
                    try:
                        decoded_string += part.decode(encoding)
                    except:
                        decoded_string += part.decode('utf-8', errors='ignore')
                else:
                    decoded_string += part.decode('utf-8', errors='ignore')
            else:
                decoded_string += part
                
        return decoded_string
        
    def _parse_address(self, address_header):
        """Parse email address from header"""
        if not address_header:
            return '未知', '未知'
            
        try:
            # Simple parsing - can be enhanced for more complex cases
            if '<' in address_header and '>' in address_header:
                name_part = address_header.split('<')[0].strip().strip('"')
                email_part = address_header.split('<')[1].split('>')[0].strip()
                return self._decode_header(name_part) or email_part, email_part
            else:
                return address_header.strip(), address_header.strip()
        except:
            return address_header, address_header
            
    def _parse_date(self, date_header):
        """Parse email date"""
        if not date_header:
            return '未知'
            
        try:
            # Convert to standard format
            from email.utils import parsedate_tz, mktime_tz
            date_tuple = parsedate_tz(date_header)
            if date_tuple:
                timestamp = mktime_tz(date_tuple)
                return datetime.fromtimestamp(timestamp).strftime('%Y-%m-%d %H:%M:%S')
        except:
            pass
            
        return date_header
        
    def _parse_body(self, email_message):
        """Parse email body and attachments"""
        body_text = ''
        body_html = ''
        images = []
        attachments = []
        
        if email_message.is_multipart():
            for part in email_message.walk():
                result = self._process_part(part)
                if result['type'] == 'text' and not body_text:
                    body_text = result['content']
                elif result['type'] == 'html' and not body_html:
                    body_html = result['content']
                elif result['type'] == 'image':
                    if result['disposition'] == 'attachment':
                        attachments.append(result['data'])
                    else:
                        images.append(result['data'])
                elif result['type'] == 'attachment':
                    attachments.append(result['data'])
        else:
            result = self._process_simple_part(email_message)
            body_text = result[0]
            body_html = result[1]
            
        # Determine main content
        main_content = body_html or body_text
        content_type = 'html' if body_html else 'text'
        
        if not main_content and images:
            content_type = 'image'
            
        return {
            'body_type': content_type,
            'body': main_content or '无法读取邮件内容',
            'images': images,
            'attachments': attachments
        }
        
    def _process_part(self, part):
        """Process individual email part and return result"""
        content_type = part.get_content_type()
        content_disposition = part.get('Content-Disposition', '')
        
        if content_type == 'text/plain' and 'attachment' not in content_disposition:
            payload = part.get_payload(decode=True)
            if payload:
                charset = part.get_content_charset() or 'utf-8'
                try:
                    content = payload.decode(charset, errors='ignore')
                except:
                    content = payload.decode('utf-8', errors='ignore')
                return {'type': 'text', 'content': content}
                    
        elif content_type == 'text/html' and 'attachment' not in content_disposition:
            payload = part.get_payload(decode=True)
            if payload:
                charset = part.get_content_charset() or 'utf-8'
                try:
                    content = payload.decode(charset, errors='ignore')
                except:
                    content = payload.decode('utf-8', errors='ignore')
                return {'type': 'html', 'content': content}
                    
        elif content_type.startswith('image/'):
            filename = part.get_filename() or f'image.{content_type.split("/")[1]}'
            payload = part.get_payload(decode=True)
            if payload:
                image_info = {
                    'filename': filename,
                    'mime_type': content_type,
                    'size': len(payload),
                    'content': base64.b64encode(payload).decode('ascii')
                }
                
                disposition = 'attachment' if 'attachment' in content_disposition else 'inline'
                return {'type': 'image', 'data': image_info, 'disposition': disposition}
                    
        else:
            # Other attachments
            filename = part.get_filename()
            if filename or 'attachment' in content_disposition:
                payload = part.get_payload(decode=True)
                if payload:
                    attachment_info = {
                        'filename': filename or 'attachment',
                        'mime_type': content_type,
                        'size': len(payload),
                        'content': base64.b64encode(payload).decode('ascii')
                    }
                    return {'type': 'attachment', 'data': attachment_info}
                    
        return {'type': 'none'}
    
    def _process_simple_part(self, part):
        """Process simple non-multipart email"""
        body_text = ''
        body_html = ''
        
        content_type = part.get_content_type()
        payload = part.get_payload(decode=True)
        
        if payload:
            charset = part.get_content_charset() or 'utf-8'
            try:
                content = payload.decode(charset, errors='ignore')
            except:
                content = payload.decode('utf-8', errors='ignore')
                
            if content_type == 'text/plain':
                body_text = content
            elif content_type == 'text/html':
                body_html = content
            else:
                body_text = content  # Fallback to text
                
        return body_text, body_html
                    
    def close(self):
        """Close connection"""
        if self.connection:
            try:
                self.connection.close()
                self.connection.logout()
            except:
                pass
            self.connection = None
            
    def test_connection(self):
        """Test connection and provide diagnostics"""
        diagnostics = {
            'server_info': f'{self.server}:{self.port}',
            'protocol_info': f'{self.protocol.upper()} with {"SSL" if self.use_ssl else "no SSL"}',
            'proxy_status': 'Disabled'
        }
        
        if self.proxy_enabled:
            diagnostics['proxy_status'] = f"Enabled - {self.proxy_info['type']} ({self.proxy_info['name']})"
            diagnostics['proxy_info'] = f"{self.proxy_info['host']}:{self.proxy_info['port']}"
        
        connection_method = "直连"
        if self.proxy_enabled:
            connection_method = f"代理 ({self.proxy_info['type']} - {self.proxy_info['name']})"
        
        try:
            logger.info(f"Starting connection test via {connection_method}...")
            
            # Test DNS resolution first (only for direct connections)
            if not self.proxy_enabled:
                try:
                    import socket
                    resolved_ip = socket.gethostbyname(self.server)
                    diagnostics['dns_resolution'] = f'✅ 服务器地址解析成功 (IP: {resolved_ip})'
                except Exception as e:
                    diagnostics['dns_resolution'] = f'❌ DNS解析失败: {str(e)}'
                    return {
                        'success': False,
                        'message': f'❌ DNS解析失败: {str(e)} ({connection_method})',
                        'diagnostics': diagnostics,
                        'error_type': 'dns_error'
                    }
            else:
                diagnostics['dns_resolution'] = '⚠️ 通过代理连接，跳过DNS测试'
            
            # Test basic TCP connection (only for direct connections)
            if not self.proxy_enabled:
                try:
                    test_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
                    test_socket.settimeout(10)
                    result = test_socket.connect_ex((self.server, self.port))
                    test_socket.close()
                    
                    if result == 0:
                        diagnostics['tcp_connection'] = '✅ TCP连接测试成功'
                    else:
                        diagnostics['tcp_connection'] = f'❌ TCP连接失败 (错误码: {result})'
                except Exception as e:
                    diagnostics['tcp_connection'] = f'❌ TCP连接测试失败: {str(e)}'
            else:
                diagnostics['tcp_connection'] = '⚠️ 通过代理连接，跳过TCP测试'
            
            # Test full connection
            connection_success = False
            try:
                connection_success = self.connect()
                if connection_success:
                    self.close()
                    diagnostics['connection_test'] = f'✅ 邮箱连接成功 (通过{connection_method})'
                    diagnostics['auth_status'] = '✅ 身份验证成功'
                    diagnostics['mailbox_access'] = '✅ 邮箱访问正常'
                    
                    return {
                        'success': True,
                        'message': f'✅ 邮箱连接测试成功！(通过{connection_method})',
                        'diagnostics': diagnostics
                    }
                else:
                    diagnostics['connection_test'] = f'❌ 邮箱连接失败 (通过{connection_method})'
            except Exception as e:
                error_message = str(e)
                diagnostics['connection_test'] = f'❌ 连接失败: {error_message}'
                
                # Categorize error types for better user feedback
                error_type = 'unknown'
                if 'ssl' in error_message.lower() or 'certificate' in error_message.lower() or 'handshake' in error_message.lower():
                    error_type = 'ssl_error'
                    diagnostics['ssl_status'] = '❌ SSL连接失败'
                elif 'authentication' in error_message.lower() or 'login' in error_message.lower() or 'credentials' in error_message.lower() or '用户名或密码' in error_message:
                    error_type = 'auth_failed'
                    diagnostics['auth_status'] = '❌ 身份验证失败'
                elif 'connection' in error_message.lower() or 'refused' in error_message.lower() or '连接被拒绝' in error_message:
                    error_type = 'connection_refused'
                    diagnostics['connection_status'] = '❌ 连接被拒绝'
                elif 'timeout' in error_message.lower() or '超时' in error_message:
                    error_type = 'timeout'
                    diagnostics['connection_status'] = '❌ 连接超时'
                elif 'proxy' in error_message.lower() or '代理' in error_message:
                    error_type = 'proxy_error'
                    diagnostics['proxy_status'] = '❌ 代理连接失败'
                elif 'dns' in error_message.lower() or '解析' in error_message:
                    error_type = 'dns_error'
                    diagnostics['dns_resolution'] = f'❌ {error_message}'
                else:
                    diagnostics['error_details'] = error_message
                    
                return {
                    'success': False,
                    'message': f'❌ 连接测试失败: {error_message}',
                    'diagnostics': diagnostics,
                    'error_type': error_type
                }
            
            return {
                'success': False,
                'message': f'❌ 邮箱连接测试失败 (通过{connection_method})',
                'diagnostics': diagnostics,
                'error_type': 'connection_failed'
            }
                
        except Exception as e:
            error_message = str(e)
            logger.error(f"Connection test exception: {error_message}")
            
            diagnostics['exception_error'] = f'❌ 测试过程异常: {error_message}'
            
            return {
                'success': False,
                'message': f'❌ 连接测试异常: {error_message}',
                'diagnostics': diagnostics,
                'error_type': 'test_exception'
            }


def main():
    """Main function for CLI usage"""
    if len(sys.argv) < 2:
        print("Usage: python mail_fetcher.py <email> [--test-connection]")
        sys.exit(1)
        
    email_address = sys.argv[1]
    test_mode = len(sys.argv) > 2 and sys.argv[2] == '--test-connection'
    
    try:
        # Get email account from database
        db_path = os.path.join(os.path.dirname(__file__), '..', 'db', 'mail.sqlite')
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        cursor.execute('SELECT * FROM mail_accounts WHERE email = ?', (email_address,))
        account = cursor.fetchone()
        
        if not account:
            result = {
                'success': False,
                'message': '邮箱账号不存在，请联系管理员添加'
            }
        else:
            # Map database columns
            columns = [desc[0] for desc in cursor.description]
            account_dict = dict(zip(columns, account))
            
            # Create fetcher instance
            fetcher = ProxyMailFetcher(
                account_dict['server'],
                account_dict['port'],
                account_dict['username'],
                account_dict['password'],
                account_dict['protocol'],
                bool(account_dict['ssl'])
            )
            
            if test_mode:
                # Run connection test
                result = fetcher.test_connection()
                proxy_info = fetcher.get_proxy_info()
                result['proxy'] = proxy_info
            else:
                # Connect and get latest mail
                if fetcher.connect():
                    result = fetcher.get_latest_mail()
                    proxy_info = fetcher.get_proxy_info()
                    result['proxy'] = proxy_info
                    fetcher.close()
                else:
                    proxy_info = fetcher.get_proxy_info()
                    result = {
                        'success': False,
                        'message': '无法连接到邮件服务器，请检查邮箱配置',
                        'proxy': proxy_info
                    }
        
        conn.close()
        
        # Output JSON result
        print(json.dumps(result, ensure_ascii=False))
        
    except Exception as e:
        result = {
            'success': False,
            'message': f'服务器错误: {str(e)}'
        }
        print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    main()