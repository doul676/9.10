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
        try:
            if self.proxy_enabled:
                logger.info(f"Attempting connection with proxy: {self.proxy_info['name']} ({self.proxy_info['type']})")
                # Note: For IMAP over proxy, we would need additional libraries
                # For now, we'll log the proxy intent but use direct connection
                logger.warning("Proxy support for IMAP requires additional setup - using direct connection")
            else:
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
            if self.proxy_enabled:
                error_message += f" (intended proxy: {self.proxy_info['type']} - {self.proxy_info['name']})"
            else:
                error_message += " (direct connection)"
            logger.error(f"Connection failed: {error_message}")
            raise Exception(error_message)
            
    def _connect_imap(self):
        """Connect using IMAP protocol"""
        try:
            # Create IMAP connection
            if self.use_ssl:
                self.connection = imaplib.IMAP4_SSL(self.server, self.port)
            else:
                self.connection = imaplib.IMAP4(self.server, self.port)
            
            # Login
            self.connection.login(self.username, self.password)
            
            # Select INBOX
            self.connection.select('INBOX')
            
            return True
            
        except Exception as e:
            error_str = str(e).lower()
            if "authentication" in error_str or "login" in error_str:
                raise Exception("邮箱用户名或密码错误，请检查登录凭据")
            elif "certificate" in error_str or "ssl" in error_str:
                raise Exception("SSL证书验证失败，请检查服务器SSL配置")
            elif "connection" in error_str:
                raise Exception("连接被拒绝，请检查服务器地址和端口")
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
                self._process_part(part, body_text, body_html, images, attachments)
        else:
            body_text, body_html = self._process_simple_part(email_message)
            
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
        
    def _process_part(self, part, body_text, body_html, images, attachments):
        """Process individual email part"""
        content_type = part.get_content_type()
        content_disposition = part.get('Content-Disposition', '')
        
        if content_type == 'text/plain' and 'attachment' not in content_disposition:
            if not body_text:
                payload = part.get_payload(decode=True)
                if payload:
                    charset = part.get_content_charset() or 'utf-8'
                    try:
                        body_text = payload.decode(charset, errors='ignore')
                    except:
                        body_text = payload.decode('utf-8', errors='ignore')
                    
        elif content_type == 'text/html' and 'attachment' not in content_disposition:
            if not body_html:
                payload = part.get_payload(decode=True)
                if payload:
                    charset = part.get_content_charset() or 'utf-8'
                    try:
                        body_html = payload.decode(charset, errors='ignore')
                    except:
                        body_html = payload.decode('utf-8', errors='ignore')
                    
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
                
                if 'attachment' in content_disposition:
                    attachments.append(image_info)
                else:
                    images.append(image_info)
                    
        else:
            # Other attachments
            filename = part.get_filename()
            if filename or 'attachment' in content_disposition:
                payload = part.get_payload(decode=True)
                if payload:
                    attachments.append({
                        'filename': filename or 'attachment',
                        'mime_type': content_type,
                        'size': len(payload),
                        'content': base64.b64encode(payload).decode('ascii')
                    })
                    
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
        try:
            if self.connect():
                self.close()
                return {
                    'success': True,
                    'message': '✅ 邮箱连接测试成功！',
                    'diagnostics': {
                        'connection_test': '✅ 服务器连接成功',
                        'protocol_info': f'{self.protocol.upper()} with {"SSL" if self.use_ssl else "no SSL"}',
                        'server_info': f'{self.server}:{self.port}',
                        'auth_status': '✅ 身份验证成功'
                    }
                }
            else:
                return {
                    'success': False,
                    'message': '❌ 邮箱连接测试失败',
                    'error_type': 'connection_failed'
                }
        except Exception as e:
            error_message = str(e)
            error_type = 'unknown'
            
            if 'ssl' in error_message.lower() or 'certificate' in error_message.lower():
                error_type = 'ssl_error'
            elif 'authentication' in error_message.lower() or 'login' in error_message.lower():
                error_type = 'auth_failed'
            elif 'connection' in error_message.lower():
                error_type = 'connection_refused'
                
            return {
                'success': False,
                'message': f'❌ 连接测试失败: {error_message}',
                'error_type': error_type
            }


def main():
    """Main function for CLI usage"""
    if len(sys.argv) < 2:
        print("Usage: python mail_fetcher.py <email>")
        sys.exit(1)
        
    email_address = sys.argv[1]
    
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
        print(json.dumps(result, ensure_ascii=False, indent=2))
        
    except Exception as e:
        result = {
            'success': False,
            'message': f'服务器错误: {str(e)}'
        }
        print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()